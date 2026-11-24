<?php

namespace BuybackManager\Services\Pricing;

use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\AppraisalService;
use Illuminate\Support\Facades\DB;

/**
 * What an item is worth as the materials it reprocesses into.
 *
 * Raw ore barely trades, so a mining buyback pays on the minerals instead: a
 * refining rule values each unit at its share of one batch's
 * output, at the yield the corporation can actually refine at. The recipes
 * come from SeAT's SDE (invTypeMaterials, and the batch size in
 * invTypes.portionSize), so a new ore needs nothing more than an SDE update.
 */
class Reprocessing
{
    /**
     * The recipe of every item given that reprocesses into anything. An item
     * with no materials is left out, so it is priced as itself.
     *
     * @param  int[]  $typeIds
     * @return array<int, array{portion: int, materials: array<int, int>}>
     */
    public function recipesFor(array $typeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $typeIds), fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $portions = DB::table('invTypes')->whereIn('typeID', $ids)->pluck('portionSize', 'typeID');
        $recipes = [];
        foreach (DB::table('invTypeMaterials')->whereIn('typeID', $ids)->get(['typeID', 'materialTypeID', 'quantity']) as $row) {
            $typeId = (int) $row->typeID;
            $recipes[$typeId]['portion'] = max(1, (int) ($portions[$typeId] ?? 1));
            $recipes[$typeId]['materials'][(int) $row->materialTypeID] = (int) $row->quantity;
        }

        return $recipes;
    }

    /**
     * The recipes a paste needs: those of the items whose rule pays on what
     * they refine into. Read before pricing, so the materials are priced in
     * the same request as the items.
     *
     * @param  array  $items  The parser's items
     * @return array<int, array{portion: int, materials: array<int, int>}>
     */
    public function recipesForPaste(array $items, BuybackSetting $setting): array
    {
        $refined = [];
        foreach ($items as $item) {
            $rule = $setting->getRuleForItem((int) $item['type_id'], $item['category_id'] ?? null, $item['group_id'] ?? null);
            if (($rule['refine_yield'] ?? null) !== null) {
                $refined[] = (int) $item['type_id'];
            }
        }

        return $this->recipesFor($refined);
    }

    /**
     * Every item and material a quote has to price. A refined item is valued
     * by its materials alone, so its own price is never asked for: raw ore
     * that barely trades would only be subscribed and fetched for nothing.
     *
     * @param  int[]  $typeIds
     * @param  array<int, array{portion: int, materials: array<int, int>}>  $recipes
     * @return int[]
     */
    public static function typesToPrice(array $typeIds, array $recipes): array
    {
        $all = array_values(array_filter(array_map('intval', $typeIds), fn ($id) => ! isset($recipes[$id])));
        foreach ($recipes as $recipe) {
            $all = array_merge($all, array_keys($recipe['materials']));
        }

        return array_values(array_unique($all));
    }

    /**
     * One unit's share of a batch's output at this yield, on this side of
     * the market. Null when any material has no price there: a refined value
     * missing a mineral would be a guess, and a quote never guesses.
     *
     * @param  array{portion: int, materials: array<int, int>}  $recipe
     * @param  array  $prices  [typeId => ['buy' => ?float, 'sell' => ?float]]
     */
    public static function unitValue(array $recipe, float $yield, array $prices, string $side): ?float
    {
        $batch = 0.0;
        foreach ($recipe['materials'] as $materialId => $quantity) {
            $price = AppraisalService::pickSidePrice($prices[$materialId] ?? null, $side);
            if ($price === null) {
                return null;
            }
            $batch += $quantity * $price;
        }

        return $batch * ($yield / 100) / max(1, $recipe['portion']);
    }

    /**
     * Whether a rule's target lies in the Asteroid category, the only one
     * the programme's refining yield reaches.
     */
    public function inAsteroidCategory(string $type, int $typeId): bool
    {
        $category = match ($type) {
            'category' => $typeId,
            'group' => DB::table('invGroups')->where('groupID', $typeId)->value('categoryID'),
            'item' => DB::table('invTypes')
                ->join('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
                ->where('invTypes.typeID', $typeId)
                ->value('invGroups.categoryID'),
            default => null,
        };

        return $category !== null && (int) $category === BuybackSetting::CATEGORY_ASTEROID;
    }

    /**
     * The materials the refining rules of a programme can turn into, so
     * Manager Core holds their prices before the first quote needs them.
     * Ore and ice refine into a few dozen materials whatever the rule
     * covers, so even a category rule names only those.
     *
     * @return int[]
     */
    public function ruleMaterials(BuybackSetting $setting): array
    {
        $rules = BuybackPricingRule::where('setting_id', $setting->id)
            ->where('excluded', false)
            ->where('refined', true)
            ->get(['type', 'type_id', 'refine_yield']);

        if ($rules->isEmpty()) {
            return [];
        }

        // A rule on the programme's yield refines only ore, ice and moon ore
        // (BuybackSetting::refineYieldFor), so it names only their materials.
        $parts = [
            'own' => $rules->whereNotNull('refine_yield'),
            'programme' => $rules->whereNull('refine_yield'),
        ];

        $query = DB::table('invTypeMaterials')
            ->join('invTypes', 'invTypes.typeID', '=', 'invTypeMaterials.typeID')
            ->join('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
            ->where(function ($scope) use ($parts) {
                foreach ($parts as $yield => $picked) {
                    if ($picked->isEmpty()) {
                        continue;
                    }
                    $scope->orWhere(function ($part) use ($yield, $picked) {
                        $part->where(function ($covers) use ($picked) {
                            foreach (['item' => 'invTypes.typeID', 'group' => 'invTypes.groupID', 'category' => 'invGroups.categoryID'] as $type => $column) {
                                $ids = $picked->where('type', $type)->pluck('type_id')->all();
                                if ($ids !== []) {
                                    $covers->orWhereIn($column, $ids);
                                }
                            }
                        });
                        if ($yield === 'programme') {
                            $part->where('invGroups.categoryID', BuybackSetting::CATEGORY_ASTEROID);
                        }
                    });
                }
            });

        return $query->distinct()->pluck('invTypeMaterials.materialTypeID')->map(fn ($id) => (int) $id)->all();
    }
}
