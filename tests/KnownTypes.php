<?php

namespace BuybackManager\Tests;

use BuybackManager\Services\Parsing\PasteParserService;

/**
 * The paste parser with a few SDE types to find, matched without regard to
 * case the way MySQL matches typeName. Only names matter to the parser, so the
 * ids, groups and categories are the test's own.
 */
class KnownTypes extends PasteParserService
{
    public const TYPES = [
        'Tritanium' => [1001, 18, 4],
        'Pyerite' => [1002, 18, 4],
        'Mexallon' => [1003, 18, 4],
        'Rifter' => [1010, 25, 6],
        'Damage Control I' => [1020, 60, 7],
        '200mm AutoCannon I' => [1021, 55, 7],
        '1MN Afterburner I' => [1022, 46, 7],
        'Hobgoblin I' => [1030, 100, 18],
        'EMP S' => [1040, 83, 8],
        'Cap Booster 400' => [1041, 87, 8],
        'Exotic Dancers, Female' => [1050, 314, 17],
    ];

    /** @var array<int, string[]> the names each lookup asked for */
    public array $lookups = [];

    /** @var array<string, array{0: int, 1: int, 2: int}> */
    private array $types;

    public function __construct(?array $types = null)
    {
        $this->types = $types ?? self::TYPES;
    }

    protected function lookupTypes(array $names): array
    {
        $this->lookups[] = $names;

        $byName = [];
        foreach ($this->types as $name => [$typeId, $groupId, $categoryId]) {
            $byName[mb_strtolower($name)] = ['type_id' => $typeId, 'type_name' => $name, 'group_id' => $groupId, 'category_id' => $categoryId];
        }

        $found = [];
        foreach ($names as $name) {
            $key = mb_strtolower($name);
            if (isset($byName[$key])) {
                $found[$key] = $byName[$key];
            }
        }

        return $found;
    }
}
