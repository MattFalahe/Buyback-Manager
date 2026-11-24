<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Http\Controllers\DiagnosticController;
use BuybackManager\Jobs\FetchPackagedVolumes;
use BuybackManager\Jobs\ImportPackagedVolumes;
use BuybackManager\Models\BuybackPackagedVolume;
use BuybackManager\Services\SdeVolumes;
use BuybackManager\Services\VolumeLookup;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Packaged volumes read from the copy of CCP's static data SeAT downloads.
 * The rows are CCP's own, as build 3720952 has them.
 */
class SdeVolumesTest extends TestCase
{
    private const BUILD = 3720952;

    private const TRITANIUM = 34;
    private const RIFTER = 587;
    private const BADGER = 648;
    private const VELDSPAR = 1230;
    private const MEDIUM_STANDARD_CONTAINER = 3293;
    private const MEDIUM_ARMOR_REPAIRER = 3528;
    private const CAPITAL_ARMOR_REPAIRER = 20701;
    /** A capital armor repairer CCP adds after the build SeAT has. */
    private const NEWER_CAPITAL_REPAIRER = 99000001;
    /** An armor repairer CCP gives no packaged volume. */
    private const REPAIRER_WITHOUT_PACKAGED = 99000002;

    private const TYPES = [
        ['_key' => 34, 'groupID' => 18, 'volume' => 0.01, 'packagedVolume' => 0.01, 'name' => ['en' => 'Tritanium'], 'published' => true],
        ['_key' => 587, 'groupID' => 25, 'volume' => 27289, 'packagedVolume' => 2500, 'name' => ['en' => 'Rifter'], 'published' => true],
        ['_key' => 648, 'groupID' => 28, 'volume' => 250000, 'packagedVolume' => 20000, 'name' => ['en' => 'Badger'], 'published' => true],
        ['_key' => 1230, 'groupID' => 462, 'volume' => 0.1, 'packagedVolume' => 0.1, 'name' => ['en' => 'Veldspar'], 'published' => true],
        ['_key' => 3293, 'groupID' => 12, 'volume' => 325, 'packagedVolume' => 33, 'name' => ['en' => 'Medium Standard Container'], 'published' => true],
        ['_key' => 3528, 'groupID' => 62, 'volume' => 10, 'packagedVolume' => 10, 'name' => ['en' => 'Medium Armor Repairer I'], 'published' => true],
        ['_key' => 20701, 'groupID' => 62, 'volume' => 4000, 'packagedVolume' => 1000, 'name' => ['en' => 'Capital Armor Repairer I'], 'published' => true],
        ['_key' => 99000002, 'groupID' => 62, 'volume' => 5, 'name' => ['en' => 'Armor repairer without a packaged volume'], 'published' => false],
    ];

    private string $dir;

    private object $bus;

    protected function setUp(): void
    {
        Sqlite::migrate('000003', '000014');
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
        // SeAT's tables as they are: the assembled volume only.
        DB::table('invGroups')->insert([
            ['groupID' => 18, 'categoryID' => 4], ['groupID' => 25, 'categoryID' => 6], ['groupID' => 28, 'categoryID' => 6],
            ['groupID' => 462, 'categoryID' => 25], ['groupID' => 12, 'categoryID' => 2], ['groupID' => 62, 'categoryID' => 7],
        ]);
        DB::table('invTypes')->insert(array_merge(
            array_map(fn ($t) => ['typeID' => $t['_key'], 'groupID' => $t['groupID'], 'volume' => $t['volume']], self::TYPES),
            [['typeID' => self::NEWER_CAPITAL_REPAIRER, 'groupID' => 62, 'volume' => 4000]]
        ));

        $this->dir = sys_get_temp_dir() . '/bb-sde-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->writeSde(self::BUILD, self::TYPES);

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
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        Container::setInstance(null);
        Carbon::setTestNow();
        Sqlite::close();
    }

    private function writeSde(int $build, array $types, string $tail = ''): void
    {
        file_put_contents($this->dir . '/_sde.jsonl', json_encode(['_key' => 'sde', 'buildNumber' => $build, 'releaseDate' => '2026-11-19T11:07:08Z']) . "\n");
        file_put_contents($this->dir . '/types.jsonl', implode("\n", array_map('json_encode', $types)) . "\n" . $tail);
    }

    private function import(): void
    {
        (new ImportPackagedVolumes($this->dir))->handle();
    }

    private static function stored(): array
    {
        return BuybackPackagedVolume::orderBy('type_id')->get()
            ->mapWithKeys(fn ($row) => [$row->type_id => [$row->packaged_volume, $row->source, $row->sde_build]])
            ->all();
    }

    public function test_every_type_of_a_group_where_something_repackages_is_kept(): void
    {
        // A line cut short, as a download that stopped would leave it.
        $this->writeSde(self::BUILD, self::TYPES, '{"_key": 11132, "groupID": 25, "vol');

        $rows = SdeVolumes::read($this->dir);

        $this->assertEquals([
            self::RIFTER => ['group_id' => 25, 'packaged_volume' => 2500.0],
            self::BADGER => ['group_id' => 28, 'packaged_volume' => 20000.0],
            self::MEDIUM_STANDARD_CONTAINER => ['group_id' => 12, 'packaged_volume' => 33.0],
            // Packs no smaller, but its group has one that does, so it is known.
            self::MEDIUM_ARMOR_REPAIRER => ['group_id' => 62, 'packaged_volume' => 10.0],
            self::CAPITAL_ARMOR_REPAIRER => ['group_id' => 62, 'packaged_volume' => 1000.0],
            self::REPAIRER_WITHOUT_PACKAGED => ['group_id' => 62, 'packaged_volume' => 5.0],
        ], $rows);
        $this->assertSame(self::BUILD, SdeVolumes::build($this->dir));
    }

    public function test_without_seats_files_there_is_nothing_to_read(): void
    {
        $empty = $this->dir . '/none';

        $this->assertNull(SdeVolumes::build($empty));
        $this->assertSame([], SdeVolumes::read($empty));
        $this->assertFalse(ImportPackagedVolumes::dispatchIfDue($empty));
    }

    public function test_each_build_is_read_once(): void
    {
        $this->assertTrue(ImportPackagedVolumes::dispatchIfDue($this->dir));
        $this->assertFalse(ImportPackagedVolumes::dispatchIfDue($this->dir), 'queued already');
        $this->assertCount(1, $this->bus->jobs);

        $this->bus->jobs[0]->handle();
        $this->assertSame(self::BUILD, ImportPackagedVolumes::importedBuild());
        $this->assertSame([2500.0, 'sde', self::BUILD], self::stored()[self::RIFTER]);
        $this->assertCount(6, self::stored());

        // Past the hour the queued mark is gone, and the build read is what stops it.
        Cache::forget('buyback-manager:packaged-volume-import:' . self::BUILD);
        $this->assertFalse(ImportPackagedVolumes::dispatchIfDue($this->dir), 'read already');
    }

    public function test_a_new_build_replaces_the_last_and_drops_what_left_it(): void
    {
        $this->import();

        $next = array_values(array_filter(self::TYPES, fn ($t) => $t['_key'] !== self::MEDIUM_STANDARD_CONTAINER));
        $this->writeSde(self::BUILD + 1, $next);
        $this->assertTrue(ImportPackagedVolumes::dispatchIfDue($this->dir));
        $this->import();

        $stored = self::stored();
        $this->assertArrayNotHasKey(self::MEDIUM_STANDARD_CONTAINER, $stored);
        $this->assertSame([1000.0, 'sde', self::BUILD + 1], $stored[self::CAPITAL_ARMOR_REPAIRER]);
        $this->assertSame(self::BUILD + 1, ImportPackagedVolumes::importedBuild());
    }

    public function test_a_file_that_reads_as_nothing_keeps_the_volumes_already_read(): void
    {
        $this->import();
        $this->writeSde(self::BUILD + 1, []);

        $this->import();

        $this->assertCount(6, self::stored());
        $this->assertSame(self::BUILD, ImportPackagedVolumes::importedBuild());
    }

    public function test_capital_modules_and_containers_count_packaged_and_only_a_newer_type_goes_to_esi(): void
    {
        $this->import();

        $found = (new VolumeLookup())->unitVolumes([
            self::RIFTER, self::CAPITAL_ARMOR_REPAIRER, self::MEDIUM_ARMOR_REPAIRER, self::MEDIUM_STANDARD_CONTAINER,
            self::TRITANIUM, self::VELDSPAR, self::NEWER_CAPITAL_REPAIRER,
        ]);

        $this->assertEquals([
            self::RIFTER => 2500.0,
            self::CAPITAL_ARMOR_REPAIRER => 1000.0,
            self::MEDIUM_ARMOR_REPAIRER => 10.0,
            self::MEDIUM_STANDARD_CONTAINER => 33.0,
            self::TRITANIUM => 0.01,
            self::VELDSPAR => 0.1,
            self::NEWER_CAPITAL_REPAIRER => 4000.0,
        ], $found['volumes']);
        $this->assertSame([self::NEWER_CAPITAL_REPAIRER], $found['assembled']);
        $this->assertSame([[self::NEWER_CAPITAL_REPAIRER]], array_map(fn (FetchPackagedVolumes $job) => $job->typeIds, $this->bus->jobs));
    }

    public function test_without_seats_static_data_only_ships_are_looked_up(): void
    {
        $found = (new VolumeLookup())->unitVolumes([self::RIFTER, self::CAPITAL_ARMOR_REPAIRER]);

        $this->assertSame([self::RIFTER], $found['assembled']);
        $this->assertSame(4000.0, $found['volumes'][self::CAPITAL_ARMOR_REPAIRER]);
    }

    public function test_janice_and_esi_never_replace_a_figure_from_seats_static_data(): void
    {
        $this->import();

        VolumeLookup::remember([self::RIFTER => 9999.0, self::NEWER_CAPITAL_REPAIRER => 1000.0], BuybackPackagedVolume::SOURCE_ESI, [self::NEWER_CAPITAL_REPAIRER => 62]);

        $stored = self::stored();
        $this->assertSame([2500.0, 'sde', self::BUILD], $stored[self::RIFTER]);
        $this->assertSame([1000.0, 'esi', null], $stored[self::NEWER_CAPITAL_REPAIRER]);
        // An ESI figure is not SeAT's, so it adds no group to look up.
        VolumeLookup::remember([self::TRITANIUM => 0.01], BuybackPackagedVolume::SOURCE_ESI, [self::TRITANIUM => 18]);
        $groups = array_keys((new VolumeLookup())->repackagingGroups());
        sort($groups);
        $this->assertSame([12, 25, 28, 62], $groups);
    }

    private function volumesCheck(): array
    {
        $diagnostics = (new \ReflectionClass(DiagnosticController::class))->newInstanceWithoutConstructor();
        $check = new \ReflectionMethod($diagnostics, 'checkItemVolumes');
        $check->setAccessible(true);

        return $check->invoke($diagnostics, $this->dir);
    }

    public function test_diagnostics_say_where_packaged_volumes_come_from(): void
    {
        $check = $this->volumesCheck();
        $this->assertSame('info', $check['status']);
        $this->assertStringContainsString('has not read yet', $check['message']);
        $this->assertSame('build ' . self::BUILD, $check['details']["SeAT's static data"]);
        $this->assertSame('not yet', $check['details']['Read by Buyback Manager']);

        $this->import();
        VolumeLookup::remember([self::NEWER_CAPITAL_REPAIRER => 1000.0], BuybackPackagedVolume::SOURCE_ESI);
        $check = $this->volumesCheck();
        $this->assertSame('ok', $check['status']);
        $this->assertSame('build ' . self::BUILD . ', 6 item types in 4 groups', $check['details']['Read by Buyback Manager']);
        $this->assertSame('1 item type', $check['details']['From Janice or ESI']);

        foreach (glob($this->dir . '/*') as $file) {
            unlink($file);
        }
        BuybackPackagedVolume::query()->delete();
        $check = $this->volumesCheck();
        $this->assertSame('info', $check['status']);
        $this->assertStringContainsString('only ships are looked up', $check['message']);
    }
}
