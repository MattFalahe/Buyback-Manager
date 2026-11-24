<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Http\Controllers\LocationController;
use BuybackManager\Http\Controllers\ProgrammeAccessController;
use BuybackManager\Http\Controllers\SettingsController;
use BuybackManager\Http\Controllers\Traits\ScopesCorporationAccess;
use BuybackManager\Http\Controllers\WebhookController;
use BuybackManager\Http\Middleware\ManagesProgrammes;
use BuybackManager\Http\Middleware\SeesProgrammes;
use BuybackManager\Models\BuybackLocationRule;
use BuybackManager\Models\BuybackPriceStatus;
use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackProgrammeAccess;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSyncStatus;
use BuybackManager\Models\BuybackWebhook;
use BuybackManager\Services\LocationResolver;
use BuybackManager\Services\Pricing\PriceProviderService;
use BuybackManager\Services\ProgrammeAccess;
use BuybackManager\Services\WebhookDispatcher;
use BuybackManager\Tests\Sqlite;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Who reaches which programme. A main corporation's buyback run by a team,
 * and a member's own buyback under an alt corporation, side by side: having
 * a character in a corporation grants nothing, only being named does.
 */
class CorporationAccessTest extends TestCase
{
    private const MAIN = 98000001;
    private const ALT = 98000002;
    private const THIRD = 98000003;

    /** Runs a buyback under the alt corporation, with a character in both. */
    private const OWNER = 1;
    /** On the main corporation's buyback team, with an alt in the alt corporation. */
    private const TEAM = 2;
    /** A director of the main corporation who should only watch. */
    private const DIRECTOR = 3;

    private const TEAM_ROLE = 7;
    private const DIRECTORS_ROLE = 8;
    private const EMPTY_ROLE = 9;

    private BuybackSetting $main;
    private BuybackSetting $alt;

    protected function setUp(): void
    {
        Sqlite::migrate('000001', '000002', '000003', '000010', '000012', '000013', '000015', '000016', '000017');

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id')->primary();
            $table->unsignedInteger('user_id');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('character_affiliations', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id')->primary();
            $table->unsignedBigInteger('corporation_id');
        });
        Schema::create('character_infos', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id')->primary();
            $table->string('name');
        });
        Schema::create('corporation_infos', function (Blueprint $table) {
            $table->unsignedBigInteger('corporation_id')->primary();
            $table->string('name');
        });
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedBigInteger('main_character_id')->nullable();
            $table->boolean('admin')->default(false);
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->unsignedInteger('role_id');
            $table->unsignedInteger('user_id');
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('role_id');
        });

        DB::table('users')->insert([
            ['id' => self::OWNER, 'name' => 'Owner Main', 'main_character_id' => 90000001, 'admin' => false],
            ['id' => self::TEAM, 'name' => 'Team Main', 'main_character_id' => 90000003, 'admin' => false],
            ['id' => self::DIRECTOR, 'name' => 'Director Main', 'main_character_id' => 90000005, 'admin' => false],
            ['id' => 4, 'name' => 'Seat Admin', 'main_character_id' => null, 'admin' => true],
        ]);
        DB::table('character_infos')->insert([
            ['character_id' => 90000001, 'name' => 'Owner Main'],
            ['character_id' => 90000002, 'name' => 'Owner Alt'],
            ['character_id' => 90000003, 'name' => 'Team Main'],
            ['character_id' => 90000004, 'name' => 'Team Alt'],
            ['character_id' => 90000005, 'name' => 'Director Main'],
        ]);
        DB::table('refresh_tokens')->insert([
            ['character_id' => 90000001, 'user_id' => self::OWNER, 'deleted_at' => null],
            ['character_id' => 90000002, 'user_id' => self::OWNER, 'deleted_at' => null],
            ['character_id' => 90000003, 'user_id' => self::TEAM, 'deleted_at' => null],
            ['character_id' => 90000004, 'user_id' => self::TEAM, 'deleted_at' => null],
            ['character_id' => 90000005, 'user_id' => self::DIRECTOR, 'deleted_at' => null],
        ]);
        DB::table('character_affiliations')->insert([
            ['character_id' => 90000001, 'corporation_id' => self::MAIN],
            ['character_id' => 90000002, 'corporation_id' => self::ALT],
            ['character_id' => 90000003, 'corporation_id' => self::MAIN],
            ['character_id' => 90000004, 'corporation_id' => self::ALT],
            ['character_id' => 90000005, 'corporation_id' => self::MAIN],
        ]);
        DB::table('corporation_infos')->insert([
            ['corporation_id' => self::MAIN, 'name' => 'Main Corporation'],
            ['corporation_id' => self::ALT, 'name' => 'Alt Corporation'],
        ]);
        DB::table('roles')->insert([
            ['id' => self::TEAM_ROLE, 'title' => 'Buyback team'],
            ['id' => self::DIRECTORS_ROLE, 'title' => 'Directors'],
            ['id' => self::EMPTY_ROLE, 'title' => 'Line members'],
        ]);
        DB::table('role_user')->insert([
            ['role_id' => self::TEAM_ROLE, 'user_id' => self::TEAM],
            ['role_id' => self::DIRECTORS_ROLE, 'user_id' => self::DIRECTOR],
        ]);
        DB::table('permissions')->insert([
            ['id' => 1, 'title' => 'buyback-manager.settings'],
            ['id' => 2, 'title' => 'buyback-manager.view'],
            ['id' => 3, 'title' => 'buyback-manager.admin'],
        ]);
        DB::table('permission_role')->insert([
            ['permission_id' => 1, 'role_id' => self::TEAM_ROLE],
            ['permission_id' => 2, 'role_id' => self::TEAM_ROLE],
            ['permission_id' => 2, 'role_id' => self::DIRECTORS_ROLE],
        ]);

        $this->main = $this->setting(self::MAIN);
        $this->alt = $this->setting(self::ALT);

        // What a controller hands back once it is done: here, only that it was reached.
        Container::getInstance()->instance('redirect', new class {
            public function route(...$arguments): static
            {
                return $this;
            }

            public function to(...$arguments): static
            {
                return $this;
            }

            public function with(...$arguments): static
            {
                return $this;
            }
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        Sqlite::close();
    }

    /** A signed-in request from this account, holding these permissions. */
    private function request(int $userId, string ...$permissions): Request
    {
        $user = new class ($userId, $permissions) {
            public function __construct(public int $id, private array $permissions)
            {
            }

            public function can(string $ability): bool
            {
                return in_array($ability, $this->permissions, true);
            }
        };

        $request = Request::create('/buyback-manager/settings');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function admin(): Request
    {
        return $this->request(4, 'buyback-manager.admin');
    }

    private function access(): object
    {
        return new class {
            use ScopesCorporationAccess {
                allowedCorporationIds as public;
                manageableCorporationIds as public;
                authorizeCorporation as public;
                authorizedSetting as public;
            }
        };
    }

    private function nameOn(BuybackSetting $setting, string $access, string $type, int $id): void
    {
        $this->assertNull((new ProgrammeAccess())->name($setting->id, $access, $type, $id));
    }

    private function setting(int $corporationId): BuybackSetting
    {
        return BuybackSetting::create(['corporation_id' => $corporationId, 'enabled' => true, 'target_type' => BuybackSetting::TARGET_MY_CORP]);
    }

    private function webhook(?int $corporationId): BuybackWebhook
    {
        return BuybackWebhook::create([
            'corporation_id' => $corporationId,
            'name' => 'buyback',
            'url' => 'https://discord.com/api/webhooks/1/x',
            'enabled' => true,
            'categories' => ['contract_matched'],
        ]);
    }

    private function assertDenied(callable $action, string $why): void
    {
        try {
            $action();
        } catch (AccessDeniedHttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), $why);

            return;
        }

        $this->fail($why);
    }

    public function test_a_character_in_a_corporation_grants_nothing(): void
    {
        $owner = $this->request(self::OWNER, 'buyback-manager.settings', 'buyback-manager.view');

        $this->assertSame([], $this->access()->manageableCorporationIds($owner));
        $this->assertSame([], $this->access()->allowedCorporationIds($owner));
        $this->assertDenied(fn () => $this->access()->authorizeCorporation($owner, self::MAIN), 'the main corporation');
        $this->assertDenied(fn () => $this->access()->authorizeCorporation($owner, self::ALT), 'their own alt corporation, before being named');
    }

    public function test_the_alt_corporations_owner_reaches_their_programme_and_not_the_main_one(): void
    {
        $this->nameOn($this->alt, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::USER, self::OWNER);
        $owner = $this->request(self::OWNER, 'buyback-manager.settings');

        $this->assertSame([self::ALT], $this->access()->manageableCorporationIds($owner));
        $this->assertSame([self::ALT], $this->access()->allowedCorporationIds($owner), 'a manager sees what they manage, with no view permission');
        $this->access()->authorizeCorporation($owner, self::ALT);
        $this->assertDenied(fn () => $this->access()->authorizeCorporation($owner, self::MAIN), 'the main corporation they have a character in');
        $this->assertDenied(fn () => (new SettingsController(new PriceProviderService()))->rules($owner, $this->main->id), 'the main corporation\'s rules');
    }

    public function test_naming_a_role_names_everyone_holding_it_and_nobody_else(): void
    {
        $this->nameOn($this->main, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::ROLE, self::TEAM_ROLE);
        $team = $this->request(self::TEAM, 'buyback-manager.settings', 'buyback-manager.view');
        $owner = $this->request(self::OWNER, 'buyback-manager.settings', 'buyback-manager.view');

        $this->assertSame([self::MAIN], $this->access()->manageableCorporationIds($team));
        $this->assertDenied(fn () => $this->access()->authorizeCorporation($team, self::ALT), 'the alt corporation the team member has an alt in');
        $this->assertSame([], $this->access()->manageableCorporationIds($owner), 'not in the role');
    }

    public function test_a_name_does_nothing_without_the_permission_behind_it(): void
    {
        $this->nameOn($this->alt, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::USER, self::OWNER);
        $this->nameOn($this->main, BuybackProgrammeAccess::VIEWER, BuybackProgrammeAccess::USER, self::OWNER);

        $nothing = $this->request(self::OWNER);
        $this->assertSame([], $this->access()->manageableCorporationIds($nothing));
        $this->assertSame([], $this->access()->allowedCorporationIds($nothing));

        $viewOnly = $this->request(self::OWNER, 'buyback-manager.view');
        $this->assertSame([], $this->access()->manageableCorporationIds($viewOnly));
        $this->assertSame([self::MAIN], $this->access()->allowedCorporationIds($viewOnly), 'named a viewer, holding view');
    }

    public function test_a_viewer_sees_the_programme_but_cannot_change_it(): void
    {
        $this->nameOn($this->main, BuybackProgrammeAccess::VIEWER, BuybackProgrammeAccess::ROLE, self::DIRECTORS_ROLE);
        $director = $this->request(self::DIRECTOR, 'buyback-manager.view');

        $this->assertSame([self::MAIN], $this->access()->allowedCorporationIds($director));
        $this->assertSame([], $this->access()->manageableCorporationIds($director));
        $this->assertDenied(fn () => $this->access()->authorizeCorporation($director, self::MAIN), 'changing what they watch');
    }

    public function test_an_admin_reaches_every_programme_named_or_not(): void
    {
        $this->assertNull($this->access()->manageableCorporationIds($this->admin()));
        $this->assertNull($this->access()->allowedCorporationIds($this->admin()));
        $this->access()->authorizeCorporation($this->admin(), self::THIRD);
        $this->access()->authorizeCorporation($this->admin(), null);
        $this->assertSame(self::ALT, $this->access()->authorizedSetting($this->admin(), $this->alt->id)->corporation_id);
    }

    public function test_only_an_admin_adds_or_deletes_a_programme(): void
    {
        $this->nameOn($this->alt, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::USER, self::OWNER);
        $this->nameOn($this->main, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::ROLE, self::TEAM_ROLE);
        $owner = $this->request(self::OWNER, 'buyback-manager.settings');
        $settings = new SettingsController(new PriceProviderService());

        $adding = $this->request(self::OWNER, 'buyback-manager.settings');
        $adding->merge(['corporation_id' => self::ALT]);
        $this->assertDenied(fn () => $settings->store($adding), 'adding a programme, even for their own corporation');
        $this->assertDenied(fn () => $settings->destroy($owner, $this->alt->id), 'deleting the programme they manage');
        $this->assertSame(2, BuybackSetting::count());

        $settings->destroy($this->admin(), $this->alt->id);
        $this->assertSame([self::MAIN], BuybackSetting::pluck('corporation_id')->all());
        $this->assertSame([$this->main->id], BuybackProgrammeAccess::pluck('setting_id')->all(), 'the deleted programme\'s names go with it');
    }

    public function test_deleting_a_programme_takes_everything_keyed_to_it(): void
    {
        foreach ([$this->main, $this->alt] as $setting) {
            BuybackPricingRule::create(['setting_id' => $setting->id, 'type' => 'item', 'type_id' => 34, 'percentage' => 90]);
            BuybackLocationRule::create(['setting_id' => $setting->id, 'location_type' => 'station', 'location_id' => 60003760]);
            BuybackPriceStatus::create(['setting_id' => $setting->id, 'provider' => 'fuzzwork', 'market' => 'jita']);
            BuybackSyncStatus::create(['setting_id' => $setting->id, 'contracts_seen' => 3]);
            $this->nameOn($setting, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::USER, self::OWNER);
        }

        (new SettingsController(new PriceProviderService()))->destroy($this->admin(), $this->alt->id);

        foreach ([BuybackPricingRule::class, BuybackLocationRule::class, BuybackPriceStatus::class, BuybackSyncStatus::class, BuybackProgrammeAccess::class] as $model) {
            $this->assertSame([$this->main->id], $model::pluck('setting_id')->map(fn ($id) => (int) $id)->all(), class_basename($model) . ' keeps only the programme still there');
        }
        $this->assertSame([self::MAIN], BuybackSetting::pluck('corporation_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_only_an_admin_names_or_removes_anyone(): void
    {
        $this->nameOn($this->alt, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::USER, self::OWNER);
        $owner = $this->request(self::OWNER, 'buyback-manager.settings');
        $controller = new ProgrammeAccessController();

        $naming = $this->request(self::OWNER, 'buyback-manager.settings');
        $naming->merge(['access' => 'manager', 'subject_type' => 'user', 'subject_id' => self::TEAM]);
        $this->assertDenied(fn () => $controller->store($naming, $this->alt->id), 'a manager naming another');
        $this->assertDenied(fn () => $controller->destroy($owner, $this->alt->id, BuybackProgrammeAccess::first()->id), 'a manager removing a name');
        $this->assertDenied(fn () => $controller->accounts($owner), 'a manager searching accounts');
        $this->assertSame(1, BuybackProgrammeAccess::count());

        $adminNaming = $this->admin();
        $adminNaming->merge(['access' => 'viewer', 'subject_type' => 'role', 'subject_id' => self::DIRECTORS_ROLE]);
        $controller->store($adminNaming, $this->alt->id);
        $controller->store($adminNaming, $this->alt->id);
        $this->assertSame(1, BuybackProgrammeAccess::where('access', 'viewer')->count(), 'naming twice changes nothing');

        $controller->destroy($this->admin(), $this->alt->id, BuybackProgrammeAccess::where('access', 'manager')->first()->id);
        $this->assertSame(['viewer'], BuybackProgrammeAccess::pluck('access')->all());
    }

    public function test_a_name_must_be_a_seat_account_or_role_that_exists(): void
    {
        $access = new ProgrammeAccess();

        $this->assertSame('That SeAT account does not exist.', $access->name($this->main->id, 'manager', 'user', 999));
        $this->assertSame('That SeAT role does not exist.', $access->name($this->main->id, 'viewer', 'role', 999));
        $this->assertSame('Pick manager or viewer.', $access->name($this->main->id, 'owner', 'user', self::OWNER));
        $this->assertSame('Pick a SeAT account or a SeAT role.', $access->name($this->main->id, 'manager', 'squad', 1));
        $this->assertSame(0, BuybackProgrammeAccess::count());
    }

    public function test_programme_access_marks_a_name_that_will_not_work(): void
    {
        $this->nameOn($this->main, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::ROLE, self::TEAM_ROLE);
        $this->nameOn($this->main, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::USER, self::DIRECTOR);
        $this->nameOn($this->main, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::ROLE, self::EMPTY_ROLE);
        $this->nameOn($this->main, BuybackProgrammeAccess::VIEWER, BuybackProgrammeAccess::USER, 4);
        $this->nameOn($this->main, BuybackProgrammeAccess::VIEWER, BuybackProgrammeAccess::USER, self::OWNER);
        DB::table('users')->where('id', self::OWNER)->delete();

        $entries = collect((new ProgrammeAccess())->entries([$this->main->id])[$this->main->id])->keyBy(fn ($e) => $e['access'] . ':' . $e['name']);

        $this->assertNull($entries['manager:Buyback team']['lacks'], 'the role carries settings');
        $this->assertSame('buyback-manager.settings', $entries['manager:Director Main']['lacks'], 'the director holds view only');
        $this->assertSame('buyback-manager.settings', $entries['manager:Line members']['lacks'], 'a role with no permission');
        $this->assertNull($entries['viewer:Seat Admin']['lacks'], 'a SeAT admin account holds everything');
        $this->assertTrue($entries['viewer:Account #' . self::OWNER]['missing'], 'an account gone from SeAT');
    }

    public function test_an_account_is_found_by_any_of_its_characters(): void
    {
        $found = (new ProgrammeAccess())->searchAccounts('Alt');

        $this->assertSame([
            ['id' => self::OWNER, 'name' => 'Owner Main', 'matched' => 'Owner Alt'],
            ['id' => self::TEAM, 'name' => 'Team Main', 'matched' => 'Team Alt'],
        ], $found);
        $this->assertSame([], (new ProgrammeAccess())->searchAccounts('A'), 'too short to search');
    }

    public function test_a_programmes_webhooks_follow_its_managers(): void
    {
        $this->nameOn($this->alt, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::USER, self::OWNER);
        $mains = $this->webhook(self::MAIN);
        $global = $this->webhook(null);
        $own = $this->webhook(self::ALT);
        $owner = $this->request(self::OWNER, 'buyback-manager.settings');
        $controller = new WebhookController(new WebhookDispatcher());

        $this->assertDenied(fn () => $controller->destroy($owner, $mains->id), 'the main corporation\'s webhook');
        $this->assertDenied(fn () => $controller->destroy($owner, $global->id), 'the global webhook');
        $this->assertDenied(fn () => $controller->testFire($owner, $global->id), 'test-posting the global webhook');

        $controller->destroy($owner, $own->id);
        $this->assertEqualsCanonicalizing([$mains->id, $global->id], BuybackWebhook::pluck('id')->all());
    }

    public function test_another_programmes_locations_cannot_be_touched(): void
    {
        $this->nameOn($this->alt, BuybackProgrammeAccess::MANAGER, BuybackProgrammeAccess::USER, self::OWNER);
        BuybackLocationRule::create(['setting_id' => $this->main->id, 'location_type' => 'station', 'location_id' => 60003760]);
        $owner = $this->request(self::OWNER, 'buyback-manager.settings');

        $this->assertDenied(fn () => (new LocationController(new LocationResolver()))->destroy($owner, $this->main->id, 1), 'removing a location');
        $this->assertSame(1, BuybackLocationRule::count());
    }

    public function test_settings_open_for_settings_or_admin_and_reports_for_any_of_the_three(): void
    {
        $settings = new ManagesProgrammes();
        $reports = new SeesProgrammes();
        $next = fn () => 'opened';

        $this->assertSame('opened', $settings->handle($this->request(1, 'buyback-manager.settings'), $next));
        $this->assertSame('opened', $settings->handle($this->request(1, 'buyback-manager.admin'), $next));
        $this->assertDenied(fn () => $settings->handle($this->request(1, 'buyback-manager.view'), $next), 'settings with view alone');

        foreach (['buyback-manager.view', 'buyback-manager.settings', 'buyback-manager.admin'] as $permission) {
            $this->assertSame('opened', $reports->handle($this->request(1, $permission), $next), $permission);
        }
        $this->assertDenied(fn () => $reports->handle($this->request(1), $next), 'reports with no permission');
    }
}
