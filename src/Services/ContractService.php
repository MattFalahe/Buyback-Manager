<?php

namespace BuybackManager\Services;

use Seat\Eseye\Exceptions\RequestFailedException;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\RefreshToken;
use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackContractItem;
use BuybackManager\Models\BuybackSetting;
use Seat\Eseye\Cache\NullCache;
use Seat\Eseye\Configuration;
use Seat\Eseye\Containers\EsiAuthentication;
use Seat\Eseye\Eseye;

class ContractService
{
    protected AppraisalService $appraisalService;

    public function __construct(AppraisalService $appraisalService)
    {
        $this->appraisalService = $appraisalService;
    }

    public function syncContracts(): void
    {
        $settings = BuybackSetting::where('enabled', true)->get();

        foreach ($settings as $setting) {
            $this->syncCorporationContracts($setting);
        }
    }

    protected function syncCorporationContracts(BuybackSetting $setting): void
    {
        try {
            $token = $this->getToken($setting->corporation_id);
            
            if (!$token) {
                logger()->warning("Buyback Manager - No token found for corporation {$setting->corporation_id}");
                return;
            }

            $esi = $this->getEsiClient($token);

            // Fetch corporation contracts
            $contracts = $esi->invoke('get', '/corporations/{corporation_id}/contracts/', [
                'corporation_id' => $setting->corporation_id,
            ]);

            foreach ($contracts as $contract) {
                // Only process item_exchange contracts
                if ($contract->type !== 'item_exchange') {
                    continue;
                }

                // Check if contract is issued to corporation or specific character
                $isTargeted = false;
                
                if ($setting->character_id && $contract->assignee_id == $setting->character_id) {
                    $isTargeted = true;
                } elseif ($contract->assignee_id == $setting->corporation_id) {
                    $isTargeted = true;
                }

                if (!$isTargeted) {
                    continue;
                }

                $this->processContract($contract, $setting, $esi);
            }
        } catch (RequestFailedException $e) {
            logger()->error('Buyback Manager - ESI request failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            logger()->error('Buyback Manager - Contract sync error: ' . $e->getMessage());
        }
    }

    protected function processContract($contract, BuybackSetting $setting, Eseye $esi): void
    {
        // Check if contract already exists
        $existingContract = BuybackContract::where('contract_id', $contract->contract_id)->first();

        if ($existingContract && $existingContract->status === $contract->status) {
            return; // No update needed
        }

        // Fetch contract items
        $items = $esi->invoke('get', '/corporations/{corporation_id}/contracts/{contract_id}/items/', [
            'corporation_id' => $setting->corporation_id,
            'contract_id' => $contract->contract_id,
        ]);

        // Prepare items for appraisal
        $itemsForAppraisal = [];
        foreach ($items as $item) {
            if ($item->is_included) {
                $itemsForAppraisal[] = [
                    'type_id' => $item->type_id,
                    'quantity' => $item->quantity,
                ];
            }
        }

        // Appraise items
        $appraisal = $this->appraisalService->appraise($itemsForAppraisal, $setting->corporation_id);

        if (!$appraisal['success']) {
            return;
        }

        // Create or update contract
        $buybackContract = BuybackContract::updateOrCreate(
            ['contract_id' => $contract->contract_id],
            [
                'corporation_id' => $setting->corporation_id,
                'issuer_id' => $contract->issuer_id,
                'status' => $contract->status,
                'total_value' => $appraisal['total_value'],
                'items_count' => count($appraisal['items']),
                'issued_date' => $contract->date_issued,
                'completed_date' => $contract->date_completed ?? null,
            ]
        );

        // Delete old items
        BuybackContractItem::where('contract_id', $buybackContract->id)->delete();

        // Insert new items
        foreach ($appraisal['items'] as $item) {
            BuybackContractItem::create([
                'contract_id' => $buybackContract->id,
                'type_id' => $item['type_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['buyback_price'],
                'total_value' => $item['total_value'],
                'category_id' => $item['category_id'],
                'group_id' => $item['group_id'],
            ]);
        }
    }

    protected function getToken(int $corporationId): ?RefreshToken
    {
        return RefreshToken::whereHas('character.corporation_history', function ($query) use ($corporationId) {
            $query->where('corporation_id', $corporationId)
                ->whereNull('is_deleted');
        })
        ->whereHas('scopes', function ($query) {
            $query->where('scope', 'esi-contracts.read_corporation_contracts.v1');
        })
        ->first();
    }

    protected function getEsiClient(RefreshToken $token): Eseye
    {
        $config = Configuration::getInstance();
        $config->cache = NullCache::class;

        $esi = new Eseye($this->getEsiAuthentication($token));

        return $esi;
    }

    protected function getEsiAuthentication(RefreshToken $token): EsiAuthentication
    {
        return new EsiAuthentication([
            'client_id' => config('eveapi.config.eseye.client_id'),
            'secret' => config('eveapi.config.eseye.secret'),
            'access_token' => $token->token,
            'refresh_token' => $token->refresh_token,
            'scopes' => $token->scopes->pluck('scope')->toArray(),
            'token_expires' => $token->expires_on,
        ]);
    }
}
