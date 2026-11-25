<?php

namespace BuybackManager\Services\Parsing;

use Seat\Eveapi\Models\Sde\InvType;

/**
 * Reads a pasted list into SDE item types and quantities. Every price
 * provider prices by type id, so this is the only parser.
 *
 * It reads:
 *   - Inventory, asset and contract copies, tab separated: "Tritanium\t1,000\t...",
 *     or with the tabs turned into runs of spaces on the way
 *   - "1000 x Tritanium", "1000 Tritanium", "Tritanium 1000", "Tritanium x1000"
 *   - EFT fits: the ship from the header line, each module without its
 *     loaded charge, drones and cargo from their "x5" lines
 *   - A name alone, as one of it
 *
 * A line can read more than one way: "Cap Booster 400" is one of an item,
 * "Tritanium 400" is 400 of one. Every reading is looked up and the first
 * that names a real type wins, so a name ending in a number is never taken
 * for a quantity.
 */
class PasteParserService
{
    /** Item types one quote can hold. */
    public const MAX_ITEMS = 1000;

    /** Lines read from one paste. */
    public const MAX_LINES = 5000;

    /** Lines that named nothing, as many as are handed back to show the seller. */
    public const MAX_UNRECOGNISED = 100;

    /** Every name in the SDE is shorter than this. */
    private const MAX_NAME_LENGTH = 100;

    /** Longer than this is not a quantity anyone means. */
    private const MAX_QUANTITY_DIGITS = 12;

    private const LOOKUP_CHUNK = 500;

    /** "1 000 000": the thousands grouping some locales copy with. */
    private const SPACED_QUANTITY = '\d{1,3}(?: \d{3})+';

    /** "1000", "1,000" or "1.000". */
    private const PLAIN_QUANTITY = '\d[\d.,]*';

    /**
     * @return array{
     *     items: array<int, array{type_id: int, type_name: string, group_id: ?int, category_id: ?int, quantity: int}>,
     *     unrecognised: string[],
     *     truncated: bool
     * }
     */
    public function parse(string $input): array
    {
        $read = $this->candidates($input);

        $names = [];
        foreach ($read['lines'] as $line) {
            foreach ($line['options'] as $option) {
                $names[mb_strtolower($option['name'])] = $option['name'];
            }
        }

        $types = empty($names) ? [] : $this->lookupTypes(array_values($names));

        $items = [];
        $unrecognised = [];
        $truncated = $read['truncated'];

        foreach ($read['lines'] as $line) {
            $type = null;
            $quantity = 0;
            foreach ($line['options'] as $option) {
                $type = $types[mb_strtolower($option['name'])] ?? null;
                if ($type !== null) {
                    $quantity = $option['quantity'];
                    break;
                }
            }

            if ($type === null) {
                $unrecognised[] = $line['text'];
                continue;
            }

            // EVE splits a big stack over several lines; a quote is per type.
            $typeId = $type['type_id'];
            if (isset($items[$typeId])) {
                $items[$typeId]['quantity'] += $quantity;
                continue;
            }

            if (count($items) >= self::MAX_ITEMS) {
                $truncated = true;
                continue;
            }

            $items[$typeId] = $type + ['quantity' => $quantity];
        }

        return [
            'items' => array_values($items),
            'unrecognised' => array_slice($unrecognised, 0, self::MAX_UNRECOGNISED),
            'truncated' => $truncated,
        ];
    }

    /**
     * Each line of the paste with the ways it can be read, best first. A line
     * that reads as nothing has no options. Needs no SDE.
     *
     * @return array{lines: array<int, array{text: string, options: array<int, array{name: string, quantity: int}>}>, truncated: bool}
     */
    public function candidates(string $input): array
    {
        $lines = [];
        $truncated = false;

        foreach (preg_split('/\r\n|\r|\n/', $input) as $raw) {
            // Copies from some locales group thousands with no-break spaces.
            $text = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $raw));

            if ($text === '' || preg_match('/^\[empty [a-z ]+slot\]$/i', $text)) {
                continue;
            }

            if (count($lines) >= self::MAX_LINES) {
                $truncated = true;
                break;
            }

            $lines[] = ['text' => $text, 'options' => $this->readings($text)];
        }

        return ['lines' => $lines, 'truncated' => $truncated];
    }

    /**
     * A quantity as typed or copied: "1000", "1,000", "1 000" or "1.000".
     */
    public static function quantity(string $raw): ?int
    {
        $raw = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $raw));

        // A dot groups thousands only in exactly this shape. EVE has no
        // fractional quantities, so "1.5" is not one.
        $digits = preg_match('/^\d{1,3}(?:\.\d{3})+$/', $raw)
            ? str_replace('.', '', $raw)
            : str_replace([',', ' '], '', $raw);

        if (! preg_match('/^\d{1,' . self::MAX_QUANTITY_DIGITS . '}$/', $digits)) {
            return null;
        }

        return (int) $digits;
    }

    /**
     * @return array<int, array{name: string, quantity: int}>
     */
    protected function readings(string $line): array
    {
        if (str_contains($line, "\t")) {
            $columns = explode("\t", $line);
            $raw = trim($columns[1] ?? '');

            // A ship or container copied assembled has no quantity.
            $quantity = $raw === '' ? 1 : self::quantity($raw);

            return $quantity === null ? [] : $this->keep([[$columns[0], $quantity]]);
        }

        // EFT header: "[Rifter, My fit]" is the ship.
        if (preg_match('/^\[(?<ship>[^,\[\]]+),[^\[\]]*\]$/u', $line, $m)) {
            return $this->keep([[$m['ship'], 1]]);
        }

        $line = preg_replace('/\s+\/offline$/i', '', $line);
        $readings = [];

        // An inventory copy that went through a chat, a forum post or an
        // editor can arrive with every tab turned into spaces. No item name
        // holds two spaces in a row, so a run of them is a column break and
        // the line reads as the copy it was.
        $columns = preg_split('/ {2,}/', $line);
        if (count($columns) > 1 && ($quantity = self::quantity($columns[1])) !== null) {
            $readings[] = [$columns[0], $quantity];
        }

        foreach ([self::SPACED_QUANTITY, self::PLAIN_QUANTITY] as $q) {
            foreach ([
                '/^(?<qty>' . $q . ')\s*x\s+(?<name>.+)$/iu',
                '/^(?<name>.+?)\s+x\s?(?<qty>' . $q . ')$/iu',
                '/^(?<qty>' . $q . ')\s+(?<name>.+)$/u',
                '/^(?<name>.+?)\s+(?<qty>' . $q . ')$/u',
            ] as $pattern) {
                if (preg_match($pattern, $line, $m)) {
                    $quantity = self::quantity($m['qty']);
                    if ($quantity !== null) {
                        $readings[] = [$m['name'], $quantity];
                    }
                }
            }
        }

        $readings[] = [$line, 1];

        // An EFT module with its loaded charge: the charge is in the module.
        // Read after the whole line, since a few item names hold a comma.
        if (preg_match('/^(?<module>[^,]+),\s*\S/u', $line, $m)) {
            $readings[] = [$m['module'], 1];
        }

        // A ship copied assembled has no quantity, and with spaces for tabs
        // its empty column is gone too, so the name is one of it.
        if (count($columns) > 1) {
            $readings[] = [$columns[0], 1];
        }

        return $this->keep($readings);
    }

    /**
     * The SDE type each name belongs to, keyed by the lower-case name. Where
     * a retired, unpublished type shares a name with a live one, the live one
     * wins.
     *
     * @param  string[]  $names
     * @return array<string, array{type_id: int, type_name: string, group_id: ?int, category_id: ?int}>
     */
    protected function lookupTypes(array $names): array
    {
        $types = [];
        $live = [];

        foreach (array_chunk($names, self::LOOKUP_CHUNK) as $chunk) {
            foreach (InvType::with('group')->whereIn('typeName', $chunk)->get() as $type) {
                $key = mb_strtolower((string) $type->typeName);
                $published = (bool) $type->published;

                if (isset($types[$key]) && ($live[$key] || ! $published)) {
                    continue;
                }

                $types[$key] = [
                    'type_id' => (int) $type->typeID,
                    'type_name' => (string) $type->typeName,
                    'group_id' => $type->groupID !== null ? (int) $type->groupID : null,
                    'category_id' => $type->group?->categoryID !== null ? (int) $type->group->categoryID : null,
                ];
                $live[$key] = $published;
            }
        }

        return $types;
    }

    /**
     * The readings worth a lookup, once each.
     *
     * @param  array<int, array{0: string, 1: int}>  $readings
     * @return array<int, array{name: string, quantity: int}>
     */
    private function keep(array $readings): array
    {
        $kept = [];

        foreach ($readings as [$name, $quantity]) {
            $name = trim($name);
            $key = mb_strtolower($name);

            if ($quantity < 1 || isset($kept[$key]) || mb_strlen($name) > self::MAX_NAME_LENGTH || ! preg_match('/\pL/u', $name)) {
                continue;
            }

            $kept[$key] = ['name' => $name, 'quantity' => $quantity];
        }

        return array_values($kept);
    }
}
