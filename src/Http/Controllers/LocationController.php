<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Models\BuybackLocationRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\LocationResolver;
use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;

/**
 * Admin CRUD for a corp's buyback location allow-list, plus the SDE name
 * search backing the location picker. Gated by buyback-manager.settings.
 */
class LocationController extends Controller
{
    protected LocationResolver $resolver;

    public function __construct(LocationResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function index(int $settingId)
    {
        $setting = BuybackSetting::with('corporation')->findOrFail($settingId);
        $rules = $setting->locationRules()
            ->orderBy('location_type')
            ->orderBy('location_name')
            ->get();

        return view('buyback-manager::settings.locations', compact('setting', 'rules'));
    }

    public function store(Request $request, int $settingId)
    {
        $setting = BuybackSetting::findOrFail($settingId);

        $validated = $request->validate([
            'location_type' => 'required|in:' . implode(',', BuybackLocationRule::ALL_TYPES),
            'location_id' => 'required|integer|min:1',
            'location_name' => 'nullable|string|max:255',
        ]);

        // Resolve a fresh name from the SDE rather than trusting the posted
        // one; fall back to the posted name, then nothing.
        $name = $this->resolver->nameFor($validated['location_type'], (int) $validated['location_id'])
            ?: ($validated['location_name'] ?? null);

        BuybackLocationRule::firstOrCreate(
            [
                'setting_id' => $setting->id,
                'location_type' => $validated['location_type'],
                'location_id' => (int) $validated['location_id'],
            ],
            ['location_name' => $name]
        );

        return redirect()->route('buyback-manager.settings.locations.index', $setting->id)
            ->with('success', 'Location added.');
    }

    public function destroy(int $settingId, int $ruleId)
    {
        $rule = BuybackLocationRule::where('setting_id', $settingId)
            ->where('id', $ruleId)
            ->firstOrFail();
        $rule->delete();

        return redirect()->route('buyback-manager.settings.locations.index', $settingId)
            ->with('success', 'Location removed.');
    }

    /**
     * AJAX name search for the location picker. Returns JSON
     * [{id, name}, ...] for the requested type.
     */
    public function search(Request $request)
    {
        $request->validate([
            'type' => 'required|in:' . implode(',', BuybackLocationRule::ALL_TYPES),
            'q' => 'required|string|max:100',
        ]);

        return response()->json(
            $this->resolver->search($request->input('type'), (string) $request->input('q'))
        );
    }
}
