<?php

namespace BuybackManager\Services;

use Illuminate\Support\Facades\DB;

/**
 * Finds items, groups and categories in SeAT's SDE by name or id, for the
 * pricing rule form. Each item comes with its group and category, so a
 * director can see where an ore sits before choosing how wide a rule to make.
 */
final class TypeFinder
{
    private const NONE = ['items' => [], 'groups' => [], 'categories' => []];

    /**
     * A number matches by id as well as by name: 1230 is Veldspar's typeID,
     * and also part of names like "1230mm". An exact id comes first.
     *
     * @return array{items: array<int, array>, groups: array<int, array>, categories: array<int, array>}
     */
    public function search(string $query, int $limit = 15): array
    {
        $query = trim($query);
        $id = ctype_digit($query) ? (int) $query : null;
        if ($id === null && mb_strlen($query) < 2) {
            return self::NONE;
        }

        return [
            'items' => $this->items($query, $id, $limit),
            'groups' => $this->groups($query, $id, 8),
            'categories' => $this->categories($query, $id, 5),
        ];
    }

    private function items(string $query, ?int $id, int $limit): array
    {
        return DB::table('invTypes as t')
            ->leftJoin('invGroups as g', 'g.groupID', '=', 't.groupID')
            ->leftJoin('invCategories as c', 'c.categoryID', '=', 'g.categoryID')
            ->where(function ($match) use ($query, $id) {
                // Unpublished types never reach a paste, so a name search
                // leaves them out. An exact id still finds one.
                $match->where(fn ($name) => $name->where('t.typeName', 'like', '%' . $query . '%')->where('t.published', 1));
                if ($id !== null) {
                    $match->orWhere('t.typeID', $id);
                }
            })
            ->orderByRaw('CASE WHEN t.typeID = ? THEN 0 WHEN t.typeName LIKE ? THEN 1 ELSE 2 END', [$id ?? -1, $query . '%'])
            ->orderBy('t.typeName')
            ->limit($limit)
            ->get(['t.typeID', 't.typeName', 'g.groupID', 'g.groupName', 'c.categoryID', 'c.categoryName'])
            ->map(fn ($row) => [
                'id' => (int) $row->typeID,
                'name' => (string) $row->typeName,
                'group' => $row->groupID === null ? null : ['id' => (int) $row->groupID, 'name' => (string) $row->groupName],
                'category' => $row->categoryID === null ? null : ['id' => (int) $row->categoryID, 'name' => (string) $row->categoryName],
            ])
            ->all();
    }

    private function groups(string $query, ?int $id, int $limit): array
    {
        return DB::table('invGroups as g')
            ->leftJoin('invCategories as c', 'c.categoryID', '=', 'g.categoryID')
            ->where(function ($match) use ($query, $id) {
                $match->where('g.groupName', 'like', '%' . $query . '%');
                if ($id !== null) {
                    $match->orWhere('g.groupID', $id);
                }
            })
            ->orderByRaw('CASE WHEN g.groupID = ? THEN 0 WHEN g.groupName LIKE ? THEN 1 ELSE 2 END', [$id ?? -1, $query . '%'])
            ->orderBy('g.groupName')
            ->limit($limit)
            ->get(['g.groupID', 'g.groupName', 'c.categoryID', 'c.categoryName'])
            ->map(fn ($row) => [
                'id' => (int) $row->groupID,
                'name' => (string) $row->groupName,
                'category' => $row->categoryID === null ? null : ['id' => (int) $row->categoryID, 'name' => (string) $row->categoryName],
            ])
            ->all();
    }

    private function categories(string $query, ?int $id, int $limit): array
    {
        return DB::table('invCategories')
            ->where(function ($match) use ($query, $id) {
                $match->where('categoryName', 'like', '%' . $query . '%');
                if ($id !== null) {
                    $match->orWhere('categoryID', $id);
                }
            })
            ->orderByRaw('CASE WHEN categoryID = ? THEN 0 WHEN categoryName LIKE ? THEN 1 ELSE 2 END', [$id ?? -1, $query . '%'])
            ->orderBy('categoryName')
            ->limit($limit)
            ->get(['categoryID', 'categoryName'])
            ->map(fn ($row) => ['id' => (int) $row->categoryID, 'name' => (string) $row->categoryName])
            ->all();
    }
}
