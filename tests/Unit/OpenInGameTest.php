<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\BuybackManagerServiceProvider;
use BuybackManager\Services\OpenInGame;
use BuybackManager\Tests\Sqlite;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether "Open in game" can work: the scope on SeAT's list, SeAT's default
 * profile asking for it, and which of a player's logins hold it.
 */
class OpenInGameTest extends TestCase
{
    /** The start and end of SeAT 5's own eveapi.scopes, which lists esi-ui.open_window.v1 only in a comment. */
    private const SEAT_SCOPES = [
        'publicData',
        'esi-alliances.read_contacts.v1',
        'esi-assets.read_assets.v1',
        'esi-skills.read_skills.v1',
        'esi-universe.read_structures.v1',
        'esi-wallet.read_character_wallet.v1',
        'esi-wallet.read_corporation_wallets.v1',
        'esi-corporations.read_projects.v1',
    ];

    private const USER = 5;
    private const MAIN = 2112000001;

    protected function tearDown(): void
    {
        Sqlite::close();
    }

    /** A config repository as Laravel's answers get and set, on a container. */
    private static function app(array $config, bool $cached = false): Container
    {
        $app = $cached
            ? new class extends Container implements CachesConfiguration {
                public function configurationIsCached()
                {
                    return true;
                }

                public function getCachedConfigPath()
                {
                    return '';
                }

                public function getCachedServicesPath()
                {
                    return '';
                }
            }
            : new Container();

        $app->instance('config', new class ($config) {
            public function __construct(public array $items)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return Arr::get($this->items, $key, $default);
            }

            public function set(string $key, mixed $value): void
            {
                Arr::set($this->items, $key, $value);
            }
        });

        return $app;
    }

    /** What eveapi's own service provider does with its scope list. */
    private static function seatMerges(Container $app): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scopes');
        file_put_contents($path, '<?php return ' . var_export(self::SEAT_SCOPES, true) . ';');

        try {
            (new class ($app) extends ServiceProvider {
                public function merge(string $path): void
                {
                    $this->mergeConfigFrom($path, 'eveapi.scopes');
                }
            })->merge($path);
        } finally {
            unlink($path);
        }
    }

    private static function assertScopeAddedOnce(Container $app): void
    {
        $scopes = $app->make('config')->get('eveapi.scopes');

        self::assertSame(1, count(array_keys($scopes, OpenInGame::SCOPE, true)));
        self::assertSame(self::SEAT_SCOPES, array_values(array_diff($scopes, [OpenInGame::SCOPE])));
    }

    public function test_the_scope_joins_seats_list_once_and_keeps_seats_own(): void
    {
        $app = self::app(['eveapi' => ['scopes' => self::SEAT_SCOPES]]);

        (new BuybackManagerServiceProvider($app))->register();

        self::assertScopeAddedOnce($app);
    }

    public function test_the_scope_is_kept_when_seat_reads_its_list_after_the_plugin(): void
    {
        $app = self::app([]);

        (new BuybackManagerServiceProvider($app))->register();
        self::seatMerges($app);

        self::assertScopeAddedOnce($app);
    }

    public function test_a_cached_config_is_left_as_it_was_until_seat_restarts(): void
    {
        $app = self::app(['eveapi' => ['scopes' => self::SEAT_SCOPES]], true);

        (new BuybackManagerServiceProvider($app))->register();

        $this->assertSame(self::SEAT_SCOPES, $app->make('config')->get('eveapi.scopes'));
    }

    /** OpenInGame reading the profiles given, shaped as SeAT's setting() returns them. */
    private static function withProfiles(mixed $profiles): OpenInGame
    {
        return new class ($profiles) extends OpenInGame {
            public function __construct(private mixed $given)
            {
            }

            protected function profiles(): mixed
            {
                return $this->given;
            }
        };
    }

    /** @return object[] the profiles as SeAT decodes them from its settings table */
    private static function profiles(array $profiles): array
    {
        return json_decode(json_encode($profiles));
    }

    public function test_seats_built_in_profile_asks_for_the_scope(): void
    {
        // SeAT's own default profile, used until an admin saves one, lists it.
        $builtIn = self::profiles([['id' => 0, 'name' => 'default', 'default' => true, 'scopes' => ['publicData', 'esi-skills.read_skills.v1', OpenInGame::SCOPE]]]);

        $this->assertSame(['name' => 'default', 'asks' => true], self::withProfiles($builtIn)->defaultProfile());
        $this->assertTrue(self::withProfiles($builtIn)->defaultProfileAsks());
    }

    public function test_the_profile_marked_default_is_the_one_read(): void
    {
        $saved = self::profiles([
            ['id' => 0, 'name' => 'default', 'default' => false, 'scopes' => ['publicData', OpenInGame::SCOPE]],
            ['id' => 1, 'name' => 'minimal', 'default' => true, 'scopes' => ['publicData', 'esi-ui.write_waypoint.v1']],
        ]);

        $this->assertSame(['name' => 'minimal', 'asks' => false], self::withProfiles($saved)->defaultProfile());
        $this->assertFalse(self::withProfiles($saved)->defaultProfileAsks());
    }

    public function test_with_no_profile_marked_default_or_none_read_nothing_is_claimed(): void
    {
        $none = self::profiles([['id' => 1, 'name' => 'alts', 'default' => false, 'scopes' => [OpenInGame::SCOPE]]]);

        $this->assertNull(self::withProfiles($none)->defaultProfile());
        $this->assertNull(self::withProfiles(null)->defaultProfile());
        $this->assertNull(self::withProfiles([])->defaultProfile());
        $this->assertFalse(self::withProfiles(null)->defaultProfileAsks());
    }

    private function logins(): void
    {
        Sqlite::migrate('000001');
        Schema::create('users', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->bigInteger('main_character_id')->nullable();
        });
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->bigInteger('character_id')->primary();
            $table->unsignedInteger('user_id');
            $table->text('scopes')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('character_infos', function (Blueprint $table) {
            $table->bigInteger('character_id')->primary();
            $table->string('name');
        });

        $with = json_encode(['publicData', OpenInGame::SCOPE, 'esi-skills.read_skills.v1']);
        $without = json_encode(['publicData', 'esi-ui.write_waypoint.v1']);
        DB::table('users')->insert(['id' => self::USER, 'main_character_id' => self::MAIN]);
        foreach ([
            [self::MAIN, 'Zed Main', $with, null],
            [2112000002, 'Alpha Alt', $with, null],
            [2112000003, 'Beta Alt', $without, null],
            [2112000004, 'Gone Alt', $with, '2026-11-20 10:00:00'],
            [2112000005, 'Other Player', $with, null, 6],
        ] as $row) {
            [$id, $name, $scopes, $deleted] = $row;
            DB::table('refresh_tokens')->insert(['character_id' => $id, 'user_id' => $row[4] ?? self::USER, 'scopes' => $scopes, 'deleted_at' => $deleted]);
            DB::table('character_infos')->insert(['character_id' => $id, 'name' => $name]);
        }
    }

    public function test_a_players_characters_holding_the_scope_come_main_first(): void
    {
        $this->logins();

        $this->assertSame([self::MAIN => 'Zed Main', 2112000002 => 'Alpha Alt'], (new OpenInGame())->charactersFor(self::USER));
        $this->assertSame([], (new OpenInGame())->charactersFor(null));
        $this->assertSame([], (new OpenInGame())->charactersFor(99));
    }

    public function test_every_live_login_holding_the_scope_is_counted(): void
    {
        $this->logins();

        $this->assertSame(3, (new OpenInGame())->charactersWithScope());
    }
}
