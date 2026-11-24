<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Http\Controllers\SettingsController;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\Pricing\PriceProviderService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class SettingsDefaultsTest extends TestCase
{
    /**
     * The defaults as a save applies them. $validated is what validation
     * hands on: a blank field arrives as null, a field not sent is absent.
     */
    private function save(array $posted, array $validated, ?BuybackSetting $existing): array
    {
        $controller = new class (new PriceProviderService()) extends SettingsController {
            public function defaults(array $validated, Request $request, ?BuybackSetting $existing): array
            {
                return $this->withDefaults($validated, $request, $existing);
            }
        };

        return $controller->defaults($validated, Request::create('/buyback-manager/settings', 'POST', $posted), $existing);
    }

    public function test_a_new_programme_starts_at_the_add_forms_defaults(): void
    {
        $this->assertSame([
            'max_deviation_percent' => 1.00,
            'thin_market_guard_percent' => 10.00,
            'appraisal_stale_hours' => 48,
            'auto_nudge_hours' => 48,
            'appraisal_item_retention_days' => 14,
            'appraisal_retention_days' => 180,
        ], $this->save([], [], null));
    }

    public function test_an_edit_keeps_what_the_form_did_not_send(): void
    {
        $stored = new BuybackSetting(['auto_nudge_hours' => 0, 'appraisal_item_retention_days' => 30, 'appraisal_retention_days' => 365]);

        $saved = $this->save(
            ['max_deviation_percent' => '2.5', 'appraisal_stale_hours' => ''],
            ['max_deviation_percent' => '2.5', 'appraisal_stale_hours' => null],
            $stored
        );

        // Nothing about the three left out, so the update leaves them as stored.
        $this->assertSame(['max_deviation_percent' => '2.5', 'appraisal_stale_hours' => 48], $saved);
    }

    public function test_a_field_sent_is_saved_and_zero_still_turns_the_nudge_off(): void
    {
        $stored = new BuybackSetting(['auto_nudge_hours' => 24, 'appraisal_item_retention_days' => 30, 'appraisal_retention_days' => 365]);
        $posted = ['max_deviation_percent' => '1', 'appraisal_stale_hours' => '12', 'auto_nudge_hours' => '0', 'appraisal_item_retention_days' => '7', 'appraisal_retention_days' => ''];

        $saved = $this->save($posted, ['auto_nudge_hours' => 0, 'appraisal_item_retention_days' => 7, 'appraisal_retention_days' => null] + $posted, $stored);

        $this->assertSame(0, $saved['auto_nudge_hours']);
        $this->assertSame(7, $saved['appraisal_item_retention_days']);
        $this->assertSame(180, $saved['appraisal_retention_days'], 'a field emptied on purpose takes the default');
    }
}
