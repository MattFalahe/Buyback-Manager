<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackLocationRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\LocationResolver;
use BuybackManager\Tests\Sqlite;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class LocationRuleTest extends TestCase
{
    private const JITA_44 = 60003760;
    private const JITA = 30000142;
    private const KIMOTORO = 20000020;
    private const THE_FORGE = 10000002;
    private const AMARR_VIII = 60008494;
    private const DOMAIN = 10000043;

    /** A citadel SeAT knows, in Jita. */
    private const KNOWN_CITADEL = 1035466617946;

    /** A citadel no SeAT character has docked in, so universe_structures has no row. */
    private const UNKNOWN_CITADEL = 1043661023026;

    private BuybackSetting $setting;

    protected function setUp(): void
    {
        Sqlite::migrate('000001', '000003', '000016', '000017');
        $this->setting = BuybackSetting::create(['corporation_id' => 98000001, 'enabled' => true, 'target_type' => BuybackSetting::TARGET_MY_CORP]);
    }

    protected function tearDown(): void
    {
        Sqlite::close();
    }

    private function sde(): void
    {
        Schema::create('mapDenormalize', function (Blueprint $table) {
            $table->unsignedBigInteger('itemID')->primary();
            $table->unsignedInteger('groupID');
            $table->unsignedInteger('solarSystemID')->nullable();
            $table->unsignedInteger('constellationID')->nullable();
            $table->unsignedInteger('regionID')->nullable();
        });
        DB::table('mapDenormalize')->insert([
            ['itemID' => self::JITA_44, 'groupID' => 15, 'solarSystemID' => self::JITA, 'constellationID' => self::KIMOTORO, 'regionID' => self::THE_FORGE],
            ['itemID' => self::JITA, 'groupID' => 5, 'solarSystemID' => null, 'constellationID' => self::KIMOTORO, 'regionID' => self::THE_FORGE],
            ['itemID' => self::AMARR_VIII, 'groupID' => 15, 'solarSystemID' => 30002187, 'constellationID' => 20000322, 'regionID' => self::DOMAIN],
        ]);

        Schema::create('universe_structures', function (Blueprint $table) {
            $table->unsignedBigInteger('structure_id')->primary();
            $table->unsignedInteger('solar_system_id')->nullable();
        });
        DB::table('universe_structures')->insert(['structure_id' => self::KNOWN_CITADEL, 'solar_system_id' => self::JITA]);
    }

    private function allow(string $type, int $id): void
    {
        BuybackLocationRule::create(['setting_id' => $this->setting->id, 'location_type' => $type, 'location_id' => $id]);
    }

    private function allowed(int $locationId): bool
    {
        return (new LocationResolver())->isAllowed($locationId, $this->setting);
    }

    public function test_with_no_rules_anywhere_is_accepted(): void
    {
        $this->assertTrue($this->allowed(self::UNKNOWN_CITADEL));
    }

    public function test_a_region_or_system_rule_covers_the_stations_and_citadels_inside_it(): void
    {
        $this->sde();
        $this->allow(BuybackLocationRule::TYPE_REGION, self::THE_FORGE);

        $this->assertTrue($this->allowed(self::JITA_44));
        $this->assertTrue($this->allowed(self::KNOWN_CITADEL));
        $this->assertFalse($this->allowed(self::AMARR_VIII));
        $this->assertFalse($this->allowed(self::UNKNOWN_CITADEL), 'a citadel SeAT cannot place cannot be proven inside the region');
    }

    public function test_a_citadel_seat_has_no_record_of_matches_its_own_rule(): void
    {
        $this->sde();
        $this->allow(BuybackLocationRule::TYPE_STRUCTURE, self::UNKNOWN_CITADEL);

        $this->assertTrue($this->allowed(self::UNKNOWN_CITADEL));
        $this->assertFalse($this->allowed(self::KNOWN_CITADEL));
    }

    public function test_a_named_station_matches_even_without_the_sde_and_nothing_else_does(): void
    {
        // No mapDenormalize at all: every lookup fails.
        $this->allow(BuybackLocationRule::TYPE_STATION, self::JITA_44);
        $this->allow(BuybackLocationRule::TYPE_REGION, self::DOMAIN);

        $this->assertTrue($this->allowed(self::JITA_44));
        $this->assertFalse($this->allowed(self::AMARR_VIII));
    }

    public function test_a_station_rule_is_only_that_station(): void
    {
        $this->sde();
        $this->allow(BuybackLocationRule::TYPE_STATION, self::JITA_44);

        $this->assertFalse($this->allowed(self::KNOWN_CITADEL), 'a citadel in the same system');
        $this->assertFalse($this->allowed(self::JITA), 'the system itself');
    }

    private function fee(string $type, int $id, ?float $perM3): void
    {
        BuybackLocationRule::create(['setting_id' => $this->setting->id, 'location_type' => $type, 'location_id' => $id, 'haul_fee_per_m3' => $perM3]);
    }

    private function feeAt(int $locationId): float
    {
        return (new LocationResolver())->haulFeePerM3($locationId, $this->setting);
    }

    public function test_the_narrowest_rule_a_contract_falls_under_sets_its_haul_fee(): void
    {
        $this->sde();
        $this->fee(BuybackLocationRule::TYPE_REGION, self::THE_FORGE, 400);
        $this->fee(BuybackLocationRule::TYPE_SYSTEM, self::JITA, 150);
        $this->fee(BuybackLocationRule::TYPE_STATION, self::JITA_44, null);

        $this->assertSame(0.0, $this->feeAt(self::JITA_44), 'the station has no fee of its own');
        $this->assertSame(150.0, $this->feeAt(self::KNOWN_CITADEL), 'a citadel in Jita pays the system fee');
        $this->assertSame(0.0, $this->feeAt(self::AMARR_VIII), 'outside every rule');
    }

    public function test_a_region_fee_covers_everything_in_it_without_a_narrower_rule(): void
    {
        $this->sde();
        $this->fee(BuybackLocationRule::TYPE_REGION, self::THE_FORGE, 400);
        $this->fee(BuybackLocationRule::TYPE_REGION, self::DOMAIN, null);

        $this->assertSame(400.0, $this->feeAt(self::JITA_44));
        $this->assertSame(0.0, $this->feeAt(self::AMARR_VIII));
        $this->assertTrue($this->allowed(self::AMARR_VIII));
    }
}
