<?php

namespace BuybackManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use BuybackManager\Services\AppraisalService;
use BuybackManager\Models\BuybackSetting;

class AppraisalController extends Controller
{
    protected AppraisalService $appraisalService;

    public function __construct(AppraisalService $appraisalService)
    {
        $this->appraisalService = $appraisalService;
    }

    public function index()
    {
        $corporations = BuybackSetting::with('corporation')
            ->where('enabled', true)
            ->get();

        return view('buyback-manager::appraisal.index', compact('corporations'));
    }

    public function appraise(Request $request)
    {
        $request->validate([
            'corporation_id' => 'required|exists:buyback_settings,corporation_id',
            'items' => 'required|string',
        ]);

        // Parse items from input (expecting format like EVE copy-paste or custom format)
        $items = $this->parseItems($request->input('items'));

        if (empty($items)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid items found',
            ]);
        }

        $appraisal = $this->appraisalService->appraise($items, $request->input('corporation_id'));

        return response()->json($appraisal);
    }

    protected function parseItems(string $input): array
    {
        $items = [];
        $lines = explode("\n", $input);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Try to parse format: "Item Name\tQuantity" or "Item Name  Quantity"
            $parts = preg_split('/\s{2,}|\t/', $line);
            
            if (count($parts) >= 2) {
                $itemName = trim($parts[0]);
                $quantity = (int) preg_replace('/[^\d]/', '', $parts[1]);

                // Look up type_id by name
                $type = \Seat\Eveapi\Models\Sde\InvType::where('typeName', $itemName)->first();
                
                if ($type && $quantity > 0) {
                    $items[] = [
                        'type_id' => $type->typeID,
                        'quantity' => $quantity,
                    ];
                }
            }
        }

        return $items;
    }

    public function quick(Request $request, int $typeId)
    {
        $request->validate([
            'corporation_id' => 'required|exists:buyback_settings,corporation_id',
            'quantity' => 'required|integer|min:1',
        ]);

        $items = [[
            'type_id' => $typeId,
            'quantity' => $request->input('quantity'),
        ]];

        $appraisal = $this->appraisalService->appraise($items, $request->input('corporation_id'));

        return response()->json($appraisal);
    }
}
