<?php

namespace BuybackManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackPricingRule;
use Seat\Eveapi\Models\Sde\InvCategory;
use Seat\Eveapi\Models\Sde\InvGroup;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class SettingsController extends Controller
{
    public function index()
    {
        $settings = BuybackSetting::with(['corporation', 'pricingRules'])->get();
        $corporations = CorporationInfo::all();

        return view('buyback-manager::settings.index', compact('settings', 'corporations'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'corporation_id' => 'required|exists:corporation_infos,corporation_id',
            'character_id' => 'nullable|exists:character_infos,character_id',
            'enabled' => 'boolean',
            'base_percentage' => 'required|numeric|min:0|max:100',
            'price_source' => 'required|in:jita,region',
            'region_id' => 'nullable|required_if:price_source,region|integer',
        ]);

        $validated['enabled'] = $request->has('enabled');

        $setting = BuybackSetting::updateOrCreate(
            ['corporation_id' => $validated['corporation_id']],
            $validated
        );

        return redirect()->route('buyback-manager.settings.index')
            ->with('success', 'Buyback settings saved successfully');
    }

    public function destroy(int $id)
    {
        $setting = BuybackSetting::findOrFail($id);
        $setting->delete();

        return redirect()->route('buyback-manager.settings.index')
            ->with('success', 'Buyback setting deleted successfully');
    }

    public function rules(int $settingId)
    {
        $setting = BuybackSetting::with(['pricingRules'])->findOrFail($settingId);
        $categories = InvCategory::orderBy('categoryName')->get();
        $groups = InvGroup::orderBy('groupName')->get();

        return view('buyback-manager::settings.rules', compact('setting', 'categories', 'groups'));
    }

    public function storeRule(Request $request, int $settingId)
    {
        $setting = BuybackSetting::findOrFail($settingId);

        $validated = $request->validate([
            'type' => 'required|in:category,group,item',
            'type_id' => 'required|integer',
            'percentage' => 'nullable|numeric|min:0|max:100',
            'excluded' => 'boolean',
            'priority' => 'nullable|integer',
        ]);

        $validated['setting_id'] = $setting->id;
        $validated['excluded'] = $request->has('excluded');
        $validated['priority'] = $validated['priority'] ?? 0;

        // Set priority based on type if not provided
        if (!$request->has('priority')) {
            $validated['priority'] = match($validated['type']) {
                'item' => 30,
                'group' => 20,
                'category' => 10,
                default => 0,
            };
        }

        BuybackPricingRule::create($validated);

        return redirect()->route('buyback-manager.settings.rules', $settingId)
            ->with('success', 'Pricing rule added successfully');
    }

    public function destroyRule(int $settingId, int $ruleId)
    {
        $rule = BuybackPricingRule::where('setting_id', $settingId)
            ->where('id', $ruleId)
            ->firstOrFail();
        
        $rule->delete();

        return redirect()->route('buyback-manager.settings.rules', $settingId)
            ->with('success', 'Pricing rule deleted successfully');
    }
}
