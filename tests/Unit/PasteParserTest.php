<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Services\Parsing\PasteParserService;
use BuybackManager\Tests\KnownTypes;
use PHPUnit\Framework\TestCase;

class PasteParserTest extends TestCase
{
    /** @return array<string, int> quantity by type name */
    private function items(string $paste, ?KnownTypes $parser = null): array
    {
        $quantities = [];
        foreach (($parser ?? new KnownTypes())->parse($paste)['items'] as $item) {
            $quantities[$item['type_name']] = $item['quantity'];
        }

        return $quantities;
    }

    public function test_an_inventory_or_contract_copy_reads_its_quantity_column(): void
    {
        $paste = "Tritanium\t1,000\tMineral\t\t\t10 m3\t5,000.00 ISK\n"
            . "Pyerite\t2.500\tMineral\t\t\t25 m3\n"
            . "Rifter\t\tFrigate\t\t\t27,289 m3";

        $this->assertSame(['Tritanium' => 1000, 'Pyerite' => 2500, 'Rifter' => 1], $this->items($paste));
    }

    public function test_one_item_per_line_reads_in_every_common_form(): void
    {
        foreach (['1,000 x Tritanium', '1000x Tritanium', '1000 Tritanium', 'Tritanium 1000', 'Tritanium x1000', 'tritanium 1000'] as $line) {
            $this->assertSame(['Tritanium' => 1000], $this->items($line), $line);
        }
    }

    public function test_thousands_grouped_with_spaces_or_dots_are_one_quantity(): void
    {
        $this->assertSame(['Tritanium' => 1000000], $this->items('1 000 000 Tritanium'));
        $this->assertSame(['Pyerite' => 1000], $this->items("1\u{00A0}000 Pyerite"));
        $this->assertSame(['Mexallon' => 2500], $this->items('Mexallon 2 500'));
    }

    public function test_a_name_ending_in_a_number_is_never_taken_for_a_quantity(): void
    {
        $this->assertSame(['Cap Booster 400' => 1], $this->items('Cap Booster 400'));
        $this->assertSame(['Cap Booster 400' => 10], $this->items('Cap Booster 400 x10'));
        $this->assertSame(['Cap Booster 400' => 2], $this->items('2 Cap Booster 400'));
        $this->assertSame(['Tritanium' => 400], $this->items('Tritanium 400'));
    }

    public function test_an_eft_fit_counts_the_ship_modules_drones_and_cargo_but_not_loaded_charges(): void
    {
        $fit = "[Rifter, Tackle]\n"
            . "Damage Control I\n"
            . "200mm AutoCannon I, EMP S\n"
            . "200mm AutoCannon I, EMP S\n"
            . "[Empty High slot]\n"
            . "1MN Afterburner I /offline\n"
            . "\n"
            . "Hobgoblin I x2\n"
            . "\n"
            . "EMP S x100\n";

        $parsed = (new KnownTypes())->parse($fit);

        $this->assertSame([], $parsed['unrecognised']);
        $this->assertSame([
            'Rifter' => 1,
            'Damage Control I' => 1,
            '200mm AutoCannon I' => 2,
            '1MN Afterburner I' => 1,
            'Hobgoblin I' => 2,
            'EMP S' => 100,
        ], $this->items($fit));
    }

    public function test_a_name_with_a_comma_in_it_is_read_whole(): void
    {
        $this->assertSame(['Exotic Dancers, Female' => 5], $this->items('5 x Exotic Dancers, Female'));
        $this->assertSame(['Exotic Dancers, Female' => 1], $this->items('Exotic Dancers, Female'));
    }

    public function test_the_same_item_on_several_lines_is_added_up(): void
    {
        $this->assertSame(['Tritanium' => 1350], $this->items("Tritanium 100\nTritanium\t250\n1,000 x Tritanium"));
    }

    public function test_lines_that_name_nothing_are_handed_back_in_paste_order(): void
    {
        $parsed = (new KnownTypes())->parse("Nonsense line\n\nTritanium 5\n1000\nTritanium 1.5");

        $this->assertSame(['Nonsense line', '1000', 'Tritanium 1.5'], $parsed['unrecognised']);
        $this->assertSame(1, count($parsed['items']));
        $this->assertSame('Tritanium', $parsed['items'][0]['type_name']);
    }

    public function test_only_so_many_unrecognised_lines_are_handed_back(): void
    {
        $paste = implode("\n", array_map(fn ($n) => "Junk $n", range(1, 150)));

        $unrecognised = (new KnownTypes())->parse($paste)['unrecognised'];

        $this->assertCount(PasteParserService::MAX_UNRECOGNISED, $unrecognised);
        $this->assertSame('Junk 1', $unrecognised[0]);
    }

    public function test_a_paste_holds_so_many_item_types_and_says_when_it_stopped(): void
    {
        $types = [];
        foreach (range(1, PasteParserService::MAX_ITEMS + 1) as $n) {
            $types["Ore sample $n"] = [2000 + $n, 1, 1];
        }
        $paste = implode("\n", array_keys($types));

        $parsed = (new KnownTypes($types))->parse($paste);

        $this->assertCount(PasteParserService::MAX_ITEMS, $parsed['items']);
        $this->assertTrue($parsed['truncated']);
    }

    public function test_a_paste_is_read_up_to_so_many_lines(): void
    {
        $parsed = (new KnownTypes())->parse(str_repeat("Tritanium\n", PasteParserService::MAX_LINES + 1));

        $this->assertSame(PasteParserService::MAX_LINES, $parsed['items'][0]['quantity']);
        $this->assertTrue($parsed['truncated']);
    }

    public function test_every_name_is_looked_up_once_in_one_go(): void
    {
        $parser = new KnownTypes();
        $parser->parse("Tritanium 5\nTritanium 7\ntritanium 9\nPyerite 3");

        $this->assertCount(1, $parser->lookups);
        $this->assertSame(array_values(array_unique(array_map('mb_strtolower', $parser->lookups[0]))), array_map('mb_strtolower', $parser->lookups[0]));
    }

    public function test_a_line_too_long_to_be_a_name_is_not_looked_up(): void
    {
        $parser = new KnownTypes();
        $parsed = $parser->parse(str_repeat('Tritanium', 20));

        $this->assertSame([], $parser->lookups);
        $this->assertCount(1, $parsed['unrecognised']);
    }

    public function test_a_quantity_as_typed_or_copied(): void
    {
        $this->assertSame(1000, PasteParserService::quantity('1,000'));
        $this->assertSame(1000, PasteParserService::quantity('1 000'));
        $this->assertSame(1000, PasteParserService::quantity("1\u{202F}000"));
        $this->assertSame(1000, PasteParserService::quantity('1.000'));
        $this->assertSame(1234567, PasteParserService::quantity('1.234.567'));
        $this->assertSame(999999999999, PasteParserService::quantity('999,999,999,999'));

        // EVE has no fractional quantities, and nobody means thirteen digits.
        $this->assertNull(PasteParserService::quantity('1.5'));
        $this->assertNull(PasteParserService::quantity('12.5000'));
        $this->assertNull(PasteParserService::quantity('1,000,000,000,000'));
        $this->assertNull(PasteParserService::quantity(''));
    }
}
