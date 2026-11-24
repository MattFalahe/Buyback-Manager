<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Jobs\FetchPackagedVolumes;
use BuybackManager\Models\BuybackLocationRule;
use BuybackManager\Models\BuybackPackagedVolume;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\VolumeLookup;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Haul fees and the volumes they are charged on, when SeAT has no copy of
 * CCP's static data to read them from (SdeVolumesTest has that): ships then
 * count packaged from Janice or ESI, with ESI asked once per ship and never
 * inside a quote.
 */
class HaulFeeTest extends TestCase
{
    private const RIFTER = 587;
    private const BADGER = 648;
    private const TRITANIUM = 34;
    private const VELDSPAR = 1230;

    private object $bus;

    protected function setUp(): void
    {
        Sqlite::migrate('000001', '000003', '000014', '000016', '000017');
        Carbon::setTestNow('2026-11-22 12:00:00');

        Schema::create('invTypes', function (Blueprint $table) {
            $table->unsignedInteger('typeID')->primary();
            $table->unsignedInteger('groupID');
            $table->double('volume')->nullable();
        });
        Schema::create('invGroups', function (Blueprint $table) {
            $table->unsignedInteger('groupID')->primary();
            $table->unsignedInteger('categoryID');
        });
        // SeAT's SDE as it is: assembled volumes only.
        DB::table('invGroups')->insert([['groupID' => 25, 'categoryID' => 6], ['groupID' => 28, 'categoryID' => 6], ['groupID' => 18, 'categoryID' => 4], ['groupID' => 462, 'categoryID' => 25]]);
        DB::table('invTypes')->insert([
            ['typeID' => self::RIFTER, 'groupID' => 25, 'volume' => 27289],
            ['typeID' => self::BADGER, 'groupID' => 28, 'volume' => 250000],
            ['typeID' => self::TRITANIUM, 'groupID' => 18, 'volume' => 0.01],
            ['typeID' => self::VELDSPAR, 'groupID' => 462, 'volume' => 0.1],
        ]);

        $app = Facade::getFacadeApplication();
        Container::setInstance($app);
        $this->bus = new class {
            public array $jobs = [];

            public function dispatch($job)
            {
                $this->jobs[] = $job;
            }
        };
        $app->instance(Dispatcher::class, $this->bus);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        Carbon::setTestNow();
        Sqlite::close();
    }

    /** @return int[][] the ships each queued job asks ESI for */
    private function queued(): array
    {
        return array_map(fn (FetchPackagedVolumes $job) => $job->typeIds, $this->bus->jobs);
    }

    public function test_a_ship_nobody_knows_counts_assembled_and_esi_is_asked_once(): void
    {
        $found = (new VolumeLookup())->unitVolumes([self::RIFTER, self::TRITANIUM]);

        $this->assertEquals([self::RIFTER => 27289.0, self::TRITANIUM => 0.01], $found['volumes']);
        $this->assertSame([self::RIFTER], $found['assembled']);
        $this->assertSame([[self::RIFTER]], $this->queued());

        // The same ship again inside the hour asks nothing more.
        (new VolumeLookup())->unitVolumes([self::RIFTER]);
        $this->assertCount(1, $this->bus->jobs);
    }

    public function test_a_ship_janice_or_esi_gave_before_counts_packaged_with_no_call(): void
    {
        VolumeLookup::remember([self::RIFTER => 2500.0]);

        $found = (new VolumeLookup())->unitVolumes([self::RIFTER, self::VELDSPAR]);

        $this->assertSame([self::RIFTER => 2500.0, self::VELDSPAR => 0.1], $found['volumes']);
        $this->assertSame([], $found['assembled']);
        $this->assertSame([], $this->queued());
    }

    public function test_an_old_figure_still_counts_while_esi_is_asked_again(): void
    {
        BuybackPackagedVolume::create(['type_id' => self::RIFTER, 'packaged_volume' => 2500, 'fetched_at' => '2026-08-01 00:00:00']);

        $found = (new VolumeLookup())->unitVolumes([self::RIFTER]);

        $this->assertSame([self::RIFTER => 2500.0], $found['volumes']);
        $this->assertSame([], $found['assembled']);
        $this->assertSame([[self::RIFTER]], $this->queued());
    }

    public function test_esi_figures_are_kept_and_a_refusal_stops_the_batch(): void
    {
        Http::fake([
            'esi.evetech.net/latest/universe/types/587/*' => Http::response(['type_id' => 587, 'group_id' => 25, 'packaged_volume' => 2500, 'volume' => 27289]),
            'esi.evetech.net/latest/universe/types/648/*' => Http::response(['error' => 'Error limited'], 420),
            '*' => Http::response(['packaged_volume' => 1], 200),
        ]);

        (new FetchPackagedVolumes([self::RIFTER, self::BADGER, 11132]))->handle();

        $this->assertSame([self::RIFTER => 2500.0], BuybackPackagedVolume::pluck('packaged_volume', 'type_id')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([BuybackPackagedVolume::SOURCE_ESI, 25], [BuybackPackagedVolume::find(self::RIFTER)->source, BuybackPackagedVolume::find(self::RIFTER)->group_id]);
        $this->assertCount(2, Http::recorded());
    }

    public function test_a_type_esi_gives_no_packaged_volume_keeps_its_own(): void
    {
        Http::fake(['*' => Http::response(['type_id' => 34, 'group_id' => 18, 'volume' => 0.01])]);

        (new FetchPackagedVolumes([self::TRITANIUM]))->handle();

        $this->assertSame(0.01, BuybackPackagedVolume::find(self::TRITANIUM)->packaged_volume);
    }

    public function test_a_fee_is_charged_on_the_volume_and_never_more_than_the_quote(): void
    {
        $this->assertSame(250.0, BuybackSetting::haulFee(250.0, 1.0, 10000.0));
        $this->assertSame(10000.0, BuybackSetting::haulFee(250.0, 400.0, 10000.0));
        $this->assertSame(0.0, BuybackSetting::haulFee(250.0, 0.0, 10000.0));
    }

    public function test_the_quote_lists_the_price_at_each_location_with_a_fee(): void
    {
        $setting = BuybackSetting::create(['corporation_id' => 98000001, 'enabled' => true, 'target_type' => BuybackSetting::TARGET_MY_CORP]);
        BuybackLocationRule::create(['setting_id' => $setting->id, 'location_type' => 'station', 'location_id' => 60003760, 'location_name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant']);
        BuybackLocationRule::create(['setting_id' => $setting->id, 'location_type' => 'system', 'location_id' => 30002187, 'location_name' => 'Amarr', 'haul_fee_per_m3' => 300]);

        $setting->setRelation('corporation', null);
        $quotes = $setting->haulFeeQuotes(1000000.0, 2500.0);

        $this->assertSame([['location' => 'Amarr (system)', 'per_m3' => 300.0, 'fee' => 750000.0, 'price' => 250000.0]], $quotes);
        $this->assertTrue($setting->chargesHaulFees());
        $this->assertSame(['Jita IV - Moon 4 - Caldari Navy Assembly Plant (station)', 'Amarr (system), haul fee 300 ISK/m3'], $setting->allowedLocationLabels());
        $this->assertStringContainsString('for the location you contract from', $setting->publicContractInstructions()[2]);
    }


}
