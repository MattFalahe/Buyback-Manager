<?php

namespace BuybackManager\Services\Parsing;

use Seat\Eveapi\Models\Sde\InvType;

/**
 * Minimal EVE paste parser used when Manager Core is unavailable.
 *
 * Supports the formats that account for ~95% of paste use in practice:
 *   - Tab-delimited inventory copy:        "Tritanium\t1,000\tMineral\t..."
 *   - "Qty x Item" cargo-scan form:        "1000 x Tritanium"
 *   - "Qty Item" simple form:              "1000 Tritanium"
 *   - "Item Qty" reversed form:            "Tritanium 1000"
 *   - Item name alone (implied qty = 1):   "Tritanium"
 *
 * Manager Core's ParserService handles many more formats (EFT fits,
 * killmail shards, asset dumps) — that's exactly why MC is a recommended
 * companion, not why buyback should refuse to run without it.
 */
class StandaloneParserService
{
    private const MAX_ITEMS = 1000;

    public function parse(string $input): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $input);
        $candidates = [];
        $unparsed = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            $candidate = $this->parseLine($line);
            if ($candidate === null) {
                $unparsed[] = $rawLine;
                continue;
            }

            $candidates[] = $candidate;
        }

        if (empty($candidates)) {
            return [
                'success' => false,
                'items' => [],
                'unparsed' => $unparsed,
                'parser' => 'buyback_standalone',
            ];
        }

        $names = array_values(array_unique(array_map(
            fn($c) => $c['name'],
            $candidates
        )));

        $types = InvType::with('group')
            ->whereIn('typeName', $names)
            ->get()
            ->keyBy(fn($t) => mb_strtolower($t->typeName));

        $items = [];
        $truncated = false;
        foreach ($candidates as $candidate) {
            $type = $types->get(mb_strtolower($candidate['name']));

            if (! $type) {
                $unparsed[] = $candidate['name'];
                continue;
            }

            if (count($items) >= self::MAX_ITEMS) {
                // Stop accepting more items but surface the truncation
                // so the controller can warn the user instead of silently
                // dropping their last N lines.
                $truncated = true;
                break;
            }

            $items[] = (object) [
                'type_id' => (int) $type->typeID,
                'type_name' => $type->typeName,
                'group_id' => $type->groupID !== null ? (int) $type->groupID : null,
                'category_id' => $type->group?->categoryID !== null ? (int) $type->group->categoryID : null,
                'quantity' => (int) $candidate['quantity'],
                'total_volume' => (float) ($type->volume ?? 0) * (int) $candidate['quantity'],
                'sell_price' => 0.0,
            ];
        }

        return [
            'success' => ! empty($items),
            'items' => $items,
            'unparsed' => $unparsed,
            'truncated' => $truncated,
            'parser' => 'buyback_standalone',
        ];
    }

    /**
     * @return array{name: string, quantity: int}|null
     */
    protected function parseLine(string $line): ?array
    {
        if (str_contains($line, "\t")) {
            $parts = explode("\t", $line);
            $name = trim($parts[0]);
            $qty = isset($parts[1]) ? $this->normalizeQuantity($parts[1]) : 1;
            if ($name === '' || $qty === null) {
                return null;
            }
            return ['name' => $name, 'quantity' => max(1, $qty)];
        }

        if (preg_match('/^(?<qty>[\d.,]+)\s*x\s+(?<name>.+)$/i', $line, $m)) {
            $qty = $this->normalizeQuantity($m['qty']);
            if ($qty === null) {
                return null;
            }
            return ['name' => trim($m['name']), 'quantity' => max(1, $qty)];
        }

        if (preg_match('/^(?<qty>[\d.,]+)\s+(?<name>.+)$/', $line, $m)) {
            $qty = $this->normalizeQuantity($m['qty']);
            if ($qty !== null && ! is_numeric(trim($m['name']))) {
                return ['name' => trim($m['name']), 'quantity' => max(1, $qty)];
            }
        }

        if (preg_match('/^(?<name>.+?)\s+(?<qty>[\d.,]+)$/', $line, $m)) {
            $qty = $this->normalizeQuantity($m['qty']);
            $name = trim($m['name']);
            if ($qty !== null && $name !== '') {
                return ['name' => $name, 'quantity' => max(1, $qty)];
            }
        }

        // Final fallback: bare item name (implied qty = 1). Apply a
        // minimal sanity check so absurd lines (script tags, line noise,
        // 500-char prose) don't burn an SDE lookup. The validation here
        // is intentionally permissive: any EVE item name contains
        // alphanumerics + the occasional hyphen/apostrophe/parentheses,
        // and ≤ 100 chars covers everything in the SDE.
        $trimmed = trim($line);
        if (strlen($trimmed) > 100) {
            return null;
        }
        if (! preg_match('/[A-Za-z0-9]/', $trimmed)) {
            return null;
        }
        return ['name' => $trimmed, 'quantity' => 1];
    }

    protected function normalizeQuantity(string $raw): ?int
    {
        $normalized = str_replace([',', ' ', "\u{00A0}"], '', trim($raw));
        if (! preg_match('/^\d+$/', $normalized)) {
            return null;
        }
        return (int) $normalized;
    }
}
