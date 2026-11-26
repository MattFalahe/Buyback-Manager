<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Http\Controllers\SettingsController;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\Pricing\PriceProviderService;
use PHPUnit\Framework\TestCase;

/**
 * Who the members are and who may sell, as a save stores them and as the
 * programme then reads them back.
 */
class SettingsMembersTest extends TestCase
{
    private const ALLIANCE = 99000001;

    /**
     * What a save keeps. $validated is what validation hands on: a field the
     * form did not send is absent.
     *
     * @return array{0: array, 1: ?string}
     */
    private function save(array $validated, ?BuybackSetting $existing = null): array
    {
        $controller = new class (new PriceProviderService()) extends SettingsController {
            public function members(array $validated, ?BuybackSetting $existing): array
            {
                return $this->withMembers($validated, $existing);
            }
        };

        return $controller->members($validated, $existing);
    }

    /** The programme as it reads after the save. */
    private function saved(array $validated, ?BuybackSetting $existing = null): BuybackSetting
    {
        [$fields, $problem] = $this->save($validated, $existing);
        $this->assertNull($problem);

        return ($existing ?? new BuybackSetting(['corporation_id' => 98000001]))->fill($fields);
    }

    public function test_an_alliance_with_none_picked_is_refused(): void
    {
        [, $problem] = $this->save(['member_scope' => 'alliance', 'seller_scope' => 'members', 'seller_alliance_id' => null]);

        $this->assertSame('Pick the alliance whose members these are, or make the members the corporation that runs the programme.', $problem);
    }

    public function test_members_only_is_stored_as_the_members_group(): void
    {
        $alliance = $this->saved(['member_scope' => 'alliance', 'seller_scope' => 'members', 'seller_alliance_id' => self::ALLIANCE]);
        $corporation = $this->saved(['member_scope' => 'corporation', 'seller_scope' => 'members', 'seller_alliance_id' => null]);

        $this->assertSame(BuybackSetting::SELLERS_ALLIANCE, $alliance->seller_scope);
        $this->assertSame(BuybackSetting::MEMBERS_ALLIANCE, $alliance->memberScope());
        $this->assertSame(BuybackSetting::SELLERS_ALLIANCE, $alliance->sellerScope());
        $this->assertSame(self::ALLIANCE, $alliance->seller_alliance_id);

        $this->assertSame(BuybackSetting::SELLERS_CORPORATION, $corporation->seller_scope);
        $this->assertSame(BuybackSetting::SELLERS_CORPORATION, $corporation->sellerScope());
    }

    public function test_anyone_may_sell_while_an_alliance_gets_member_rates(): void
    {
        $setting = $this->saved(['member_scope' => 'alliance', 'seller_scope' => 'any', 'seller_alliance_id' => self::ALLIANCE, 'member_bonus' => 5]);

        $this->assertSame(BuybackSetting::SELLERS_ANY, $setting->sellerScope());
        $this->assertSame(BuybackSetting::MEMBERS_ALLIANCE, $setting->memberScope());
        $this->assertSame(self::ALLIANCE, $setting->seller_alliance_id);
    }

    public function test_the_corporation_as_members_clears_the_alliance(): void
    {
        $stored = new BuybackSetting(['corporation_id' => 98000001, 'member_scope' => 'alliance', 'seller_scope' => 'alliance', 'seller_alliance_id' => self::ALLIANCE]);

        $setting = $this->saved(['member_scope' => 'corporation', 'seller_scope' => 'members', 'seller_alliance_id' => self::ALLIANCE], $stored);

        $this->assertNull($setting->seller_alliance_id);
        $this->assertSame(BuybackSetting::MEMBERS_CORPORATION, $setting->memberScope());
        $this->assertSame(BuybackSetting::SELLERS_CORPORATION, $setting->sellerScope());
    }

    public function test_a_form_that_sends_neither_keeps_what_is_stored(): void
    {
        $stored = new BuybackSetting(['corporation_id' => 98000001, 'member_scope' => 'alliance', 'seller_scope' => 'alliance', 'seller_alliance_id' => self::ALLIANCE]);

        [$fields] = $this->save(['enabled' => true], $stored);

        $this->assertSame(['enabled' => true], $fields);
        $this->assertSame(BuybackSetting::SELLERS_ALLIANCE, $stored->fill($fields)->sellerScope());
        $this->assertSame(self::ALLIANCE, $stored->seller_alliance_id);
    }

    public function test_an_alliance_sent_without_its_group_is_not_saved(): void
    {
        $stored = new BuybackSetting(['corporation_id' => 98000001, 'member_scope' => 'corporation', 'seller_scope' => 'any']);

        [$fields] = $this->save(['seller_alliance_id' => self::ALLIANCE], $stored);

        $this->assertArrayNotHasKey('seller_alliance_id', $fields);
    }

    public function test_members_only_alone_takes_the_stored_group(): void
    {
        $stored = new BuybackSetting(['corporation_id' => 98000001, 'member_scope' => 'alliance', 'seller_scope' => 'any', 'seller_alliance_id' => self::ALLIANCE]);

        $setting = $this->saved(['seller_scope' => 'members'], $stored);

        $this->assertSame(BuybackSetting::SELLERS_ALLIANCE, $setting->seller_scope);
        $this->assertSame(self::ALLIANCE, $setting->seller_alliance_id);
    }

    public function test_the_old_who_can_sell_values_still_save_as_their_group(): void
    {
        $corporation = $this->saved(['seller_scope' => 'corporation', 'seller_alliance_id' => self::ALLIANCE]);
        $alliance = $this->saved(['seller_scope' => 'alliance', 'seller_alliance_id' => self::ALLIANCE]);

        $this->assertSame(BuybackSetting::MEMBERS_CORPORATION, $corporation->member_scope);
        $this->assertNull($corporation->seller_alliance_id);
        $this->assertSame(BuybackSetting::MEMBERS_ALLIANCE, $alliance->member_scope);
        $this->assertSame(BuybackSetting::SELLERS_ALLIANCE, $alliance->sellerScope());
    }
}
