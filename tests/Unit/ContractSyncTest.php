<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackAppraisalItem;
use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackLocationRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSyncStatus;
use BuybackManager\Services\AppraisalMatcher;
use BuybackManager\Services\AppraisalRecordService;
use BuybackManager\Services\ContractService;
use BuybackManager\Services\LocationResolver;
use BuybackManager\Tests\RecordingPublisher;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The real ContractService against SeAT's contract tables, built with the
 * columns SeAT gives them, in an in-memory database.
 */
class ContractSyncTest extends TestCase
{
    private const CORP = 98000001;
    private const OTHER_CORP = 98000002;
    private const THIRD_CORP = 98000003;
    private const ALLIANCE = 99000001;
    private const OTHER_ALLIANCE = 99000002;
    private const SELLER = 2112000001;
    private const SECOND_SELLER = 2112000002;
    private const OPERATOR = 2112000099;
    private const TRITANIUM = 34;

    private RecordingPublisher $events;

    private int $nextContractId = 200000001;

    protected function setUp(): void
    {
        Sqlite::migrate();
        self::seatTables();
        Carbon::setTestNow('2026-11-21 12:00:00');
        $this->events = new RecordingPublisher();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Sqlite::close();
    }

    private static function seatTables(): void
    {
        // The columns of SeAT 5's own migrations, less the ones the sync never reads.
        Schema::create('contract_details', function (Blueprint $table) {
            $table->bigInteger('contract_id')->primary();
            $table->bigInteger('issuer_id');
            $table->bigInteger('issuer_corporation_id');
            $table->bigInteger('assignee_id');
            $table->bigInteger('acceptor_id');
            $table->bigInteger('start_location_id')->nullable();
            $table->string('type');
            $table->string('status');
            $table->string('title')->nullable();
            $table->boolean('for_corporation');
            $table->string('availability');
            $table->dateTime('date_issued');
            $table->dateTime('date_expired');
            $table->dateTime('date_completed')->nullable();
            $table->double('price')->nullable();
        });
        Schema::create('corporation_contracts', function (Blueprint $table) {
            $table->bigInteger('corporation_id');
            $table->bigInteger('contract_id');
            $table->primary(['corporation_id', 'contract_id']);
        });
        Schema::create('character_contracts', function (Blueprint $table) {
            $table->bigInteger('character_id');
            $table->bigInteger('contract_id');
            $table->primary(['character_id', 'contract_id']);
        });
        Schema::create('contract_items', function (Blueprint $table) {
            $table->bigInteger('contract_id');
            $table->bigInteger('record_id');
            $table->integer('type_id');
            $table->integer('quantity');
            $table->boolean('is_singleton');
            $table->boolean('is_included');
            $table->timestamps();
            $table->primary(['contract_id', 'record_id']);
        });
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->bigInteger('character_id')->primary();
            $table->unsignedInteger('user_id');
            $table->text('scopes')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('character_affiliations', function (Blueprint $table) {
            $table->bigInteger('character_id')->primary();
            $table->bigInteger('corporation_id');
            $table->bigInteger('alliance_id')->nullable();
        });
        Schema::create('alliance_members', function (Blueprint $table) {
            $table->integer('alliance_id');
            $table->bigInteger('corporation_id');
            $table->primary(['alliance_id', 'corporation_id']);
        });
        Schema::create('corporation_infos', function (Blueprint $table) {
            $table->bigInteger('corporation_id')->primary();
            $table->string('name');
        });
        Schema::create('character_infos', function (Blueprint $table) {
            $table->bigInteger('character_id')->primary();
            $table->string('name');
        });
        Schema::create('users', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->bigInteger('main_character_id')->nullable();
        });
        DB::table('corporation_infos')->insert(['corporation_id' => self::CORP, 'name' => 'Test Corp']);
    }

    private function service(): ContractService
    {
        $records = new AppraisalRecordService();

        return new ContractService(new AppraisalMatcher($records), $records, $this->events, new LocationResolver());
    }

    private function setting(array $attributes = []): BuybackSetting
    {
        return BuybackSetting::create($attributes + [
            'corporation_id' => self::CORP,
            'enabled' => true,
            'target_type' => BuybackSetting::TARGET_MY_CORP,
            'auto_nudge_hours' => 48,
            'appraisal_stale_hours' => 48,
            'appraisal_retention_days' => 180,
            'max_deviation_percent' => 1,
        ]);
    }

    private function appraisal(string $key, float $value = 1000.0, int $quantity = 100, string $createdAt = '2026-11-21 10:00:00'): BuybackAppraisal
    {
        $appraisal = BuybackAppraisal::create([
            'public_id' => $key,
            'corporation_id' => self::CORP,
            'total_market_value' => $value,
            'total_buyback_value' => $value,
            'market' => 'jita',
            'provider' => 'fuzzwork',
            'excluded_json' => [],
        ]);
        DB::table('buyback_manager_appraisals')->where('id', $appraisal->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
        BuybackAppraisalItem::insert([[
            'appraisal_id' => $appraisal->id,
            'type_id' => self::TRITANIUM,
            'type_name' => 'Tritanium',
            'quantity' => $quantity,
            'market_price' => $value / $quantity,
            'buyback_price' => $value / $quantity,
            'percentage' => 100,
            'total_market' => $value,
            'total_buyback' => $value,
        ]]);

        return $appraisal->fresh();
    }

    /**
     * A contract in SeAT's tables, in the given feed, to the given assignee.
     */
    private function contract(array $attributes = [], ?array $feed = null, int $quantity = 100): int
    {
        $id = $this->nextContractId++;
        DB::table('contract_details')->insert($attributes + [
            'contract_id' => $id,
            'issuer_id' => self::SELLER,
            'issuer_corporation_id' => self::OTHER_CORP,
            'assignee_id' => self::CORP,
            'acceptor_id' => 0,
            'start_location_id' => 60003760,
            'type' => 'item_exchange',
            'status' => 'outstanding',
            'title' => 'bb-abcd2345',
            'for_corporation' => false,
            'availability' => 'personal',
            'date_issued' => '2026-11-21 11:00:00',
            'date_expired' => '2026-12-05 11:00:00',
            'date_completed' => null,
            'price' => 1000.0,
        ]);
        [$table, $column, $owner] = $feed ?? ['corporation_contracts', 'corporation_id', self::CORP];
        DB::table($table)->insert([$column => $owner, 'contract_id' => $id]);
        DB::table('contract_items')->insert([
            'contract_id' => $id, 'record_id' => 1, 'type_id' => self::TRITANIUM, 'quantity' => $quantity,
            'is_singleton' => false, 'is_included' => true,
        ]);

        return $id;
    }

    private function setStatus(int $contractId, string $status, ?string $completed = null): void
    {
        DB::table('contract_details')->where('contract_id', $contractId)->update(['status' => $status, 'date_completed' => $completed]);
    }

    public function test_a_feed_past_the_placeholder_limit_still_syncs(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345');

        // More rows than SQLite or MySQL accept as bound values in one query.
        // The old sync bound every one of them and failed on every run.
        $rows = [];
        for ($i = 1; $i <= 70000; $i++) {
            $rows[] = ['corporation_id' => self::CORP, 'contract_id' => 100000000 + $i];
            if (count($rows) === 400) {
                DB::table('corporation_contracts')->insert($rows);
                $rows = [];
            }
        }
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched'], $this->events->names());
        $this->assertSame($contractId, $this->events->events[0]['payload']['contract_id']);

        $status = BuybackSyncStatus::where('setting_id', $setting->id)->first();
        $this->assertNull($status->last_error);
        $this->assertSame(1, $status->contracts_new);
        $this->assertTrue($status->isHealthy());
    }

    public function test_a_clean_contract_announces_matched_once_and_claims_its_key(): void
    {
        $setting = $this->setting();
        $appraisal = $this->appraisal('bb-abcd2345');
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);
        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched'], $this->events->names());
        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([], $row->flags());
        $this->assertSame($row->id, (int) $appraisal->fresh()->matched_contract_id);
        $this->assertSame('2026-12-05 11:00:00', $row->expires_at->toDateTimeString());
        $this->assertSame(1, $row->items()->count());
    }

    public function test_a_small_quote_contracted_at_the_copied_price_is_clean(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345', 2.48);
        $contractId = $this->contract(['price' => (float) BuybackSetting::contractPrice(2.48)]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched'], $this->events->names());
        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([], $row->flags());
        $this->assertSame(0.0, (float) $row->deviation_percent);
    }

    /** EVE drops the cents, so a 2.48 ISK quote reaches the game asking 2. */
    public function test_a_small_quote_contracted_at_whole_isk_is_clean_and_keeps_its_difference(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345', 2.48);
        $contractId = $this->contract(['price' => 2.0]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched'], $this->events->names());
        $this->assertSame([], $this->events->events[0]['payload']['flags']);
        $this->assertSame(-19.35, $this->events->events[0]['payload']['deviation_percent']);
        $this->assertSame(-19.35, (float) BuybackContract::where('contract_id', $contractId)->first()->deviation_percent);
    }

    /** Past the column's range, the difference is held at its cap and still flagged. */
    public function test_an_asked_price_far_above_a_tiny_quote_is_capped_and_flagged(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345', 2.48);
        $contractId = $this->contract(['price' => 2000000.0]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.flagged'], $this->events->names());
        $this->assertSame(999999.99, $this->events->events[0]['payload']['deviation_percent']);
        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertNotNull($row);
        $this->assertSame([BuybackContract::FLAG_PRICE_MISMATCH], $row->flags());
        $this->assertSame(999999.99, (float) $row->deviation_percent);
    }

    public function test_a_quote_just_under_a_million_is_not_held_to_the_tolerance(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345', 999999.99);
        $contractId = $this->contract(['price' => 900000.0]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched'], $this->events->names());
        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([], $row->flags());
        $this->assertSame(-10.0, (float) $row->deviation_percent);
    }

    public function test_a_quote_just_over_a_million_is_held_to_the_tolerance(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345', 1000000.01);
        $contractId = $this->contract(['price' => 900000.0]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.flagged'], $this->events->names());
        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([BuybackContract::FLAG_PRICE_MISMATCH], $row->flags());
        $this->assertSame(-10.0, (float) $row->deviation_percent);
    }

    /** A small quote may not ask for a large sum unflagged. */
    public function test_asking_a_million_or_more_on_a_small_quote_is_held_to_the_tolerance(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345', 500000.0);
        $this->contract(['price' => 1500000.0]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.flagged'], $this->events->names());
        $this->assertSame([BuybackContract::FLAG_PRICE_MISMATCH], $this->events->events[0]['payload']['flags']);
        $this->assertSame(200.0, $this->events->events[0]['payload']['deviation_percent']);
    }

    public function test_the_price_check_starts_at_a_million_on_either_side(): void
    {
        $this->assertFalse(ContractService::checksPrice(999999.99, 999999.99));
        $this->assertTrue(ContractService::checksPrice(1000000.0, 0.0));
        $this->assertTrue(ContractService::checksPrice(0.0, 1000000.0));
        $this->assertTrue(ContractService::checksPrice(2.48, 1000000.0));
    }

    public function test_a_contract_already_finished_when_first_seen_announces_its_completion(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345');
        $this->contract(['status' => 'finished', 'date_completed' => '2026-11-21 11:10:00']);

        $this->service()->syncContractsForSetting($setting);
        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched', 'buyback.contract.completed'], $this->events->names());
        $completed = $this->events->events[1]['payload'];
        $this->assertNull($completed['previous_status']);
        $this->assertSame(self::SELLER, $completed['issuer_id']);
        $this->assertSame(1000.0, $completed['total_value']);
    }

    public function test_a_contract_cancelled_before_first_sight_announces_the_cancellation(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345');
        $this->contract(['status' => 'deleted']);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched', 'buyback.contract.cancelled'], $this->events->names());
    }

    public function test_status_changes_are_followed_and_announced_once(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345');
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);
        $this->setStatus($contractId, 'finished', '2026-11-21 12:30:00');
        $this->service()->syncContractsForSetting($setting);
        $this->assertSame(1, (int) BuybackSyncStatus::where('setting_id', $setting->id)->value('contracts_updated'));

        // Finished is final, so the next run leaves it alone.
        $this->service()->syncContractsForSetting($setting);
        $this->assertSame(0, (int) BuybackSyncStatus::where('setting_id', $setting->id)->value('contracts_seen'));

        $this->assertSame(['buyback.contract.matched', 'buyback.contract.completed'], $this->events->names());
        $this->assertSame('outstanding', $this->events->events[1]['payload']['previous_status']);
        $this->assertSame('2026-11-21 12:30:00', BuybackContract::first()->completed_date->toDateTimeString());
    }

    public function test_a_key_that_matches_nothing_is_kept_and_alerts_once_over_many_runs(): void
    {
        $setting = $this->setting();
        $contractId = $this->contract(['title' => 'Buyback please bb-zzzz9999', 'price' => 5000.0]);

        for ($run = 0; $run < 5; $run++) {
            $this->service()->syncContractsForSetting($setting);
        }

        $this->assertSame(['buyback.contract.unmatched'], $this->events->names());
        $payload = $this->events->events[0]['payload'];
        $this->assertFalse($payload['matched']);
        $this->assertSame('bb-zzzz9999', $payload['attempted_appraisal_key']);
        $this->assertSame(5000.0, $payload['asked_price']);

        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertNull($row->appraisal_id);
        $this->assertSame([BuybackContract::FLAG_UNKNOWN_KEY], $row->flags());
        $this->assertSame(0, BuybackContract::matched()->count());
    }

    public function test_an_expired_contract_announces_its_lapse_once_and_is_never_nudged(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345', createdAt: '2026-11-18 10:00:00');
        $this->contract(['date_issued' => '2026-11-18 11:00:00', 'date_expired' => '2026-11-21 10:00:00']);

        $this->service()->syncContracts();
        $this->service()->syncContracts();

        $this->assertSame(['buyback.contract.matched', 'buyback.contract.expired'], $this->events->names());
        $row = BuybackContract::first();
        $this->assertSame('expired', $row->displayStatus());
        $this->assertNull($row->nudged_at);
        $this->assertSame(0, BuybackContract::active()->count());

        // EVE deleting the expired contract later is not a second ending.
        $this->setStatus((int) $row->contract_id, 'deleted');
        $this->service()->syncContracts();
        $this->assertSame(['buyback.contract.matched', 'buyback.contract.expired'], $this->events->names());
    }

    public function test_a_lapse_inside_the_grace_window_waits(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345');
        $this->contract(['date_expired' => '2026-11-21 11:30:00']);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched'], $this->events->names());
    }

    public function test_a_contract_accepted_after_a_slow_lapse_still_announces_completion(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345');
        $contractId = $this->contract(['date_expired' => '2026-11-21 10:00:00']);

        $this->service()->syncContractsForSetting($setting);
        $this->setStatus($contractId, 'finished', '2026-11-21 09:59:00');
        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched', 'buyback.contract.expired', 'buyback.contract.completed'], $this->events->names());
    }

    public function test_changing_the_target_keeps_following_tracked_contracts(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345');
        $contractId = $this->contract();
        $this->service()->syncContractsForSetting($setting);

        $setting->update(['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => self::OPERATOR]);
        $this->setStatus($contractId, 'finished', '2026-11-21 12:40:00');
        $this->service()->syncContractsForSetting($setting->fresh());

        $this->assertSame(['buyback.contract.matched', 'buyback.contract.completed'], $this->events->names());
        // Made out to the corporation, so still announced and credited as one.
        $completed = $this->events->events[1]['payload'];
        $this->assertSame('my_corp', $completed['target_type']);
        $this->assertSame(self::CORP, $completed['target_corporation_id']);
        $this->assertNull($completed['target_character_id']);
        $this->assertSame('Test Corp', $completed['send_to']);
    }

    public function test_a_contract_to_a_player_stays_theirs_when_the_target_moves_to_the_corp(): void
    {
        DB::table('character_infos')->insert(['character_id' => self::OPERATOR, 'name' => 'Test Operator']);
        $setting = $this->setting(['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => self::OPERATOR]);
        $this->appraisal('bb-abcd2345');
        $this->contract(['assignee_id' => self::OPERATOR, 'date_expired' => '2026-11-22 12:00:00'], ['character_contracts', 'character_id', self::OPERATOR]);
        $this->service()->syncContractsForSetting($setting);

        $setting->update(['target_type' => BuybackSetting::TARGET_MY_CORP, 'character_id' => null]);
        Carbon::setTestNow('2026-11-22 13:30:00');
        $this->service()->syncContractsForSetting($setting->fresh());

        $this->assertSame(['buyback.contract.matched', 'buyback.contract.expired'], $this->events->names());
        foreach ($this->events->events as $event) {
            $this->assertSame('player', $event['payload']['target_type'], $event['name']);
            $this->assertSame(self::OPERATOR, $event['payload']['target_character_id'], $event['name']);
            $this->assertNull($event['payload']['target_corporation_id'], $event['name']);
            $this->assertSame('Test Operator', $event['payload']['send_to'], $event['name']);
        }
    }

    public function test_a_player_target_reads_the_operators_own_feed(): void
    {
        $setting = $this->setting(['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => self::OPERATOR]);
        $this->appraisal('bb-abcd2345');
        $this->contract(['assignee_id' => self::OPERATOR], ['character_contracts', 'character_id', self::OPERATOR]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched'], $this->events->names());
    }

    public function test_contracts_that_are_not_buybacks_are_never_tracked(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345');
        $this->contract(['title' => 'Selling my old Rifter']);
        $this->contract(['assignee_id' => self::OTHER_CORP]);
        $this->contract(['type' => 'courier']);
        // Issued before any appraisal key could still resolve.
        $this->contract(['date_issued' => '2026-04-01 10:00:00', 'date_expired' => '2026-04-15 10:00:00']);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame([], $this->events->names());
        $this->assertSame(0, BuybackContract::count());
    }

    public function test_review_flags_send_flagged_instead_of_matched(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345', 10000000.0, createdAt: '2026-11-17 10:00:00');
        $this->contract(['price' => 12000000.0], quantity: 90);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.flagged'], $this->events->names());
        $flags = $this->events->events[0]['payload']['flags'];
        $this->assertContains(BuybackContract::FLAG_PRICE_MISMATCH, $flags);
        $this->assertContains(BuybackContract::FLAG_STALE_QUOTE, $flags);
        $this->assertContains(BuybackContract::FLAG_ITEM_MISMATCH, $flags);
    }

    public function test_a_second_contract_on_a_used_key_is_flagged_as_reused(): void
    {
        $setting = $this->setting();
        $appraisal = $this->appraisal('bb-abcd2345');
        $first = $this->contract();
        $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched', 'buyback.contract.flagged'], $this->events->names());
        $this->assertSame([BuybackContract::FLAG_KEY_REUSED], $this->events->events[1]['payload']['flags']);
        $this->assertSame(BuybackContract::where('contract_id', $first)->value('id'), (int) $appraisal->fresh()->matched_contract_id);
    }

    public function test_the_nudge_fires_once_with_the_status_it_had(): void
    {
        $this->setting();
        $this->appraisal('bb-abcd2345', createdAt: '2026-11-19 09:00:00');
        $this->contract(['date_issued' => '2026-11-19 10:00:00']);

        $this->service()->syncContracts();
        $this->service()->syncContracts();

        $this->assertSame(['buyback.contract.matched', 'buyback.contract.nudge'], $this->events->names());
        $nudge = $this->events->events[1]['payload'];
        $this->assertSame('outstanding', $nudge['previous_status']);
        $this->assertSame('jita', $nudge['market']);
        $this->assertSame('fuzzwork', $nudge['provider']);
    }

    public function test_every_contract_event_names_who_it_was_sent_to(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345');
        $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $payload = $this->events->events[0]['payload'];
        $this->assertSame('my_corp', $payload['target_type']);
        $this->assertSame(self::CORP, $payload['target_corporation_id']);
        $this->assertNull($payload['target_character_id']);
        $this->assertTrue($payload['matched']);
        $this->assertSame(['my_corp', self::CORP, null, 'Test Corp'], array_values(
            BuybackContract::first()->only(['target_type', 'target_corporation_id', 'target_character_id', 'send_to'])
        ));
        $this->assertArrayNotHasKey('mode', $payload);
        $this->assertArrayNotHasKey('offer_public_id', $payload);
    }

    public function test_a_failing_run_is_recorded_and_a_good_one_clears_it(): void
    {
        $setting = $this->setting();
        $this->service()->syncContractsForSetting($setting);
        $okAt = BuybackSyncStatus::where('setting_id', $setting->id)->first()->last_ok_at;

        Schema::rename('contract_details', 'contract_details_away');
        Carbon::setTestNow('2026-11-21 12:15:00');
        $this->service()->syncContractsForSetting($setting);
        Carbon::setTestNow('2026-11-21 12:30:00');
        $this->service()->syncContractsForSetting($setting);

        $status = BuybackSyncStatus::where('setting_id', $setting->id)->first();
        $this->assertNotNull($status->last_error);
        $this->assertSame(2, $status->failures);
        $this->assertSame($okAt->toDateTimeString(), $status->last_ok_at->toDateTimeString());
        $this->assertSame('2026-11-21 12:30:00', $status->last_run_at->toDateTimeString());
        $this->assertFalse($status->isHealthy());

        Schema::rename('contract_details_away', 'contract_details');
        $this->service()->syncContractsForSetting($setting);

        $status->refresh();
        $this->assertNull($status->last_error);
        $this->assertSame(0, $status->failures);
        $this->assertTrue($status->isHealthy());
    }

    public function test_an_old_last_good_run_reads_as_stale(): void
    {
        $setting = $this->setting();
        $this->service()->syncContractsForSetting($setting);

        Carbon::setTestNow('2026-11-21 13:30:00');

        $this->assertTrue(BuybackSyncStatus::where('setting_id', $setting->id)->first()->isStale());
    }

    public function test_item_pruning_reaches_past_appraisals_already_stripped(): void
    {
        $this->setting(['appraisal_item_retention_days' => 14]);

        // More stripped appraisals past the item window than one pass takes,
        // all older than the one that still has items.
        $rows = [];
        for ($i = 1; $i <= 5001; $i++) {
            $rows[] = ['public_id' => sprintf('bb-old%05d', $i), 'corporation_id' => self::CORP, 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00'];
            if (count($rows) === 250) {
                DB::table('buyback_manager_appraisals')->insert($rows);
                $rows = [];
            }
        }
        DB::table('buyback_manager_appraisals')->insert($rows);
        $withItems = $this->appraisal('bb-keep2345', createdAt: '2026-10-20 10:00:00');
        $fresh = $this->appraisal('bb-new23456');

        (new AppraisalRecordService())->prune();

        $this->assertSame(0, BuybackAppraisalItem::where('appraisal_id', $withItems->id)->count());
        $this->assertSame(1, BuybackAppraisalItem::where('appraisal_id', $fresh->id)->count());
    }

    /** The contract page says how long lines are kept by the window the pruning uses. */
    public function test_the_item_window_is_the_longest_any_programme_sets(): void
    {
        $records = new AppraisalRecordService();
        $this->assertSame(14, $records->itemRetentionDays());

        $this->setting(['appraisal_item_retention_days' => 7]);
        BuybackSetting::create(['corporation_id' => self::OTHER_CORP, 'enabled' => true, 'target_type' => BuybackSetting::TARGET_MY_CORP, 'appraisal_item_retention_days' => 30]);

        $this->assertSame(30, $records->itemRetentionDays());
    }

    public function test_every_sync_cycle_retries_the_webhook_posts_that_did_not_get_through(): void
    {
        $this->service()->syncContracts();
        $this->service()->syncContracts();

        $this->assertSame(2, $this->events->retries);
    }

    private function token(int $characterId, int $corporationId, array $scopes, ?string $deletedAt = null): void
    {
        DB::table('refresh_tokens')->insert([
            'character_id' => $characterId,
            'user_id' => 900 + DB::table('refresh_tokens')->count(),
            'scopes' => json_encode($scopes),
            'deleted_at' => $deletedAt,
        ]);
        DB::table('character_affiliations')->insert(['character_id' => $characterId, 'corporation_id' => $corporationId]);
    }

    /** @return array<int, string> */
    private function alertNames(): array
    {
        return array_column($this->events->alerts, 'name');
    }

    public function test_a_corporation_feed_with_a_member_token_is_quiet(): void
    {
        $this->token(2112000050, self::CORP, ['esi-contracts.read_corporation_contracts.v1']);
        $setting = $this->setting();

        $this->service()->syncContractsForSetting($setting);
        $this->service()->syncContractsForSetting($setting);

        $this->assertSame([], $this->alertNames());
        $this->assertFalse(BuybackSyncStatus::where('setting_id', $setting->id)->first()->feed_failing);
    }

    public function test_a_corporation_with_no_member_holding_the_scope_is_announced_once(): void
    {
        // A member without the scope, and one whose token SeAT removed.
        $this->token(2112000050, self::CORP, ['esi-assets.read_corporation_assets.v1']);
        $this->token(2112000051, self::CORP, ['esi-contracts.read_corporation_contracts.v1'], '2026-11-20 10:00:00');
        $setting = $this->setting();

        $this->service()->syncContractsForSetting($setting);
        Carbon::setTestNow('2026-11-21 12:15:00');
        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.feed.failing'], $this->alertNames());
        $alert = $this->events->alerts[0]['payload'];
        $this->assertSame(self::CORP, $alert['corporation_id']);
        $this->assertSame('Test Corp', $alert['send_to']);
        $this->assertTrue(str_contains($alert['reason'], 'No member of Test Corp'), $alert['reason']);
        $status = BuybackSyncStatus::where('setting_id', $setting->id)->first();
        $this->assertTrue($status->feed_failing);
        $this->assertSame('2026-11-21 12:00:00', $status->feed_since->toDateTimeString());
    }

    public function test_a_player_feed_is_announced_when_the_token_goes_and_again_when_it_is_back(): void
    {
        DB::table('character_infos')->insert(['character_id' => self::OPERATOR, 'name' => 'Test Operator']);
        $setting = $this->setting(['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => self::OPERATOR]);

        $this->service()->syncContractsForSetting($setting);
        $this->assertSame(['buyback.feed.failing'], $this->alertNames());
        $this->assertTrue(str_contains($this->events->alerts[0]['payload']['reason'], 'SeAT holds no token for Test Operator'));

        // Logged in again without the contracts scope: still broken, and not
        // announced a second time.
        $this->token(self::OPERATOR, self::OTHER_CORP, ['esi-wallet.read_character_wallet.v1']);
        $this->service()->syncContractsForSetting($setting);
        $this->assertSame(['buyback.feed.failing'], $this->alertNames());
        $reason = BuybackSyncStatus::where('setting_id', $setting->id)->first()->feed_reason;
        $this->assertTrue(str_contains($reason, 'does not carry esi-contracts.read_character_contracts.v1'), $reason);

        DB::table('refresh_tokens')->where('character_id', self::OPERATOR)->update(['scopes' => json_encode(['esi-contracts.read_character_contracts.v1'])]);
        Carbon::setTestNow('2026-11-21 13:00:00');
        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.feed.failing', 'buyback.feed.recovered'], $this->alertNames());
        $this->assertSame('2026-11-21T12:00:00+00:00', $this->events->alerts[1]['payload']['since']);
        $this->assertNull(BuybackSyncStatus::where('setting_id', $setting->id)->first()->feed_since);
    }

    public function test_three_failed_syncs_in_a_row_announce_the_feed(): void
    {
        $this->token(2112000050, self::CORP, ['esi-contracts.read_corporation_contracts.v1']);
        $setting = $this->setting();

        Schema::rename('contract_details', 'contract_details_away');
        $this->service()->syncContractsForSetting($setting);
        $this->service()->syncContractsForSetting($setting);
        $this->assertSame([], $this->alertNames());
        $this->service()->syncContractsForSetting($setting);
        $this->assertSame(['buyback.feed.failing'], $this->alertNames());
        $this->assertTrue(str_contains($this->events->alerts[0]['payload']['reason'], 'The last 3 contract syncs failed'));

        Schema::rename('contract_details_away', 'contract_details');
        $this->service()->syncContractsForSetting($setting);
        $this->assertSame(['buyback.feed.failing', 'buyback.feed.recovered'], $this->alertNames());
    }

    public function test_an_outside_corporation_typed_by_name_never_announces_its_feed(): void
    {
        $setting = $this->setting();
        $this->service()->syncContractsForSetting($setting);
        $this->assertSame(['buyback.feed.failing'], $this->alertNames());

        // Switched to a corporation SeAT cannot read: there is nothing to
        // recover, so nothing is said.
        $setting->update(['target_type' => BuybackSetting::TARGET_CORP, 'target_corporation_name' => 'Outside Holding']);
        $this->service()->syncContractsForSetting($setting->fresh());
        $this->service()->syncContractsForSetting($setting->fresh());

        $this->assertSame(['buyback.feed.failing'], $this->alertNames());
        $this->assertFalse(BuybackSyncStatus::where('setting_id', $setting->id)->first()->feed_failing);
    }

    /** A quote on so many m3, and a 100 ISK/m3 haul fee where the contract is made. */
    private function haulFeeAtTheContractStation(float $quote = 2000000.0, float $volume = 4000.0): BuybackSetting
    {
        $setting = $this->setting();
        BuybackLocationRule::create(['setting_id' => $setting->id, 'location_type' => 'station', 'location_id' => 60003760, 'haul_fee_per_m3' => 100]);
        $appraisal = $this->appraisal('bb-abcd2345', $quote);
        $appraisal->update(['total_volume' => $volume]);

        return $setting;
    }

    public function test_a_contract_at_a_fee_location_is_held_to_the_quote_less_the_fee(): void
    {
        $setting = $this->haulFeeAtTheContractStation();
        $contractId = $this->contract(['price' => 1600000.0]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched'], $this->events->names());
        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([], $row->flags());
        $this->assertSame(1600000.0, (float) $row->total_value);
        $this->assertSame(400000.0, (float) $row->haul_fee);
        $this->assertSame(0.0, (float) $row->deviation_percent);
        $payload = $this->events->events[0]['payload'];
        $this->assertSame(1600000.0, $payload['total_value']);
        $this->assertSame(400000.0, $payload['haul_fee']);
    }

    public function test_asking_the_full_quote_where_a_fee_comes_off_is_a_price_mismatch(): void
    {
        $setting = $this->haulFeeAtTheContractStation();
        $this->contract(['price' => 2000000.0]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.flagged'], $this->events->names());
        $this->assertContains(BuybackContract::FLAG_PRICE_MISMATCH, $this->events->events[0]['payload']['flags']);
        $this->assertSame(25.0, $this->events->events[0]['payload']['deviation_percent']);
    }

    /** The quote is over a million, but less the 200 ISK fee it is not. */
    public function test_a_quote_under_a_million_after_its_haul_fee_is_not_held_to_the_tolerance(): void
    {
        $setting = $this->haulFeeAtTheContractStation(1000100.0, 2.0);
        $contractId = $this->contract(['price' => 900000.0]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched'], $this->events->names());
        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([], $row->flags());
        $this->assertSame(999900.0, (float) $row->total_value);
        $this->assertSame(200.0, (float) $row->haul_fee);
        $this->assertSame(-9.99, (float) $row->deviation_percent);
    }

    public function test_a_quote_over_a_million_after_its_haul_fee_is_held_to_the_tolerance(): void
    {
        $setting = $this->haulFeeAtTheContractStation(1000300.0, 2.0);
        $contractId = $this->contract(['price' => 900000.0]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.flagged'], $this->events->names());
        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([BuybackContract::FLAG_PRICE_MISMATCH], $row->flags());
        $this->assertSame(1000100.0, (float) $row->total_value);
        $this->assertSame(-10.01, (float) $row->deviation_percent);
    }

    private static function sde(): void
    {
        Schema::create('invTypes', function (Blueprint $table) {
            $table->unsignedInteger('typeID')->primary();
            $table->unsignedInteger('groupID');
            $table->double('volume')->nullable();
        });
        Schema::create('invGroups', function (Blueprint $table) {
            $table->unsignedInteger('groupID')->primary();
            $table->unsignedInteger('categoryID');
        });
        DB::table('invGroups')->insert(['groupID' => 18, 'categoryID' => 4]);
        DB::table('invTypes')->insert(['typeID' => self::TRITANIUM, 'groupID' => 18, 'volume' => 0.01]);
    }

    public function test_a_contract_is_measured_by_what_it_carries(): void
    {
        self::sde();
        $setting = $this->setting();
        // The quote said 2 m3; the contract holds 300 Tritanium, 3 m3.
        $this->appraisal('bb-abcd2345')->update(['total_volume' => 2.0]);
        $contractId = $this->contract([], null, 300);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(3.0, BuybackContract::where('contract_id', $contractId)->first()->total_volume);
    }

    public function test_a_contract_seat_has_not_read_the_items_of_takes_the_quotes_volume(): void
    {
        self::sde();
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345')->update(['total_volume' => 2.0]);
        $contractId = $this->contract();
        DB::table('contract_items')->where('contract_id', $contractId)->delete();

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(2.0, BuybackContract::where('contract_id', $contractId)->first()->total_volume);
    }

    public function test_a_contract_with_no_fee_where_it_was_made_has_none(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345')->update(['total_volume' => 2.0]);
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertNull($row->haul_fee);
        $this->assertSame(1000.0, (float) $row->total_value);
        $this->assertNull($this->events->events[0]['payload']['haul_fee']);
    }

    public function test_a_programme_open_to_any_corporation_never_looks_a_seller_up(): void
    {
        Http::fake();
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345');
        $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched'], $this->events->names());
        Http::assertNothingSent();
    }

    public function test_a_seller_outside_the_programmes_corporation_is_flagged_not_refused(): void
    {
        $setting = $this->setting(['seller_scope' => BuybackSetting::SELLERS_CORPORATION]);
        $this->appraisal('bb-abcd2345');
        $this->appraisal('bb-efgh6789');
        $member = $this->contract(['issuer_corporation_id' => self::CORP]);
        $outsider = $this->contract(['title' => 'bb-efgh6789']);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched', 'buyback.contract.flagged'], $this->events->names());
        $this->assertSame([], BuybackContract::where('contract_id', $member)->first()->flags());
        $this->assertSame([BuybackContract::FLAG_OUTSIDE_SELLER], BuybackContract::where('contract_id', $outsider)->first()->flags());
        $this->assertSame([BuybackContract::FLAG_OUTSIDE_SELLER], $this->events->events[1]['payload']['flags']);
    }

    public function test_an_alliance_is_judged_by_the_corporation_the_contract_was_issued_from(): void
    {
        Http::fake();
        $setting = $this->setting(['seller_scope' => BuybackSetting::SELLERS_ALLIANCE, 'seller_alliance_id' => self::ALLIANCE]);
        DB::table('alliance_members')->insert([
            ['alliance_id' => self::ALLIANCE, 'corporation_id' => self::OTHER_CORP],
            ['alliance_id' => self::OTHER_ALLIANCE, 'corporation_id' => self::THIRD_CORP],
        ]);
        // SeAT has the second seller in the alliance's corporation now, but
        // they sold from the other one, and that is the one that counts.
        DB::table('character_affiliations')->insert(['character_id' => self::SECOND_SELLER, 'corporation_id' => self::OTHER_CORP, 'alliance_id' => self::ALLIANCE]);
        $this->appraisal('bb-abcd2345');
        $this->appraisal('bb-efgh6789');
        $member = $this->contract();
        $outsider = $this->contract(['title' => 'bb-efgh6789', 'issuer_id' => self::SECOND_SELLER, 'issuer_corporation_id' => self::THIRD_CORP]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame([], BuybackContract::where('contract_id', $member)->first()->flags());
        $this->assertSame([BuybackContract::FLAG_OUTSIDE_SELLER], BuybackContract::where('contract_id', $outsider)->first()->flags());
        Http::assertNothingSent();
    }

    public function test_a_seller_seat_cannot_place_is_looked_up_online(): void
    {
        Http::fake([
            'evewho.com/*' => Http::response(['info' => [[
                'character_id' => self::SELLER, 'corporation_id' => self::OTHER_CORP, 'alliance_id' => self::ALLIANCE,
                'faction_id' => 0, 'name' => 'Seller', 'sec_status' => '0.1',
            ]], 'history' => []]),
        ]);
        $setting = $this->setting(['seller_scope' => BuybackSetting::SELLERS_ALLIANCE, 'seller_alliance_id' => self::ALLIANCE]);
        $this->appraisal('bb-abcd2345');
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame([], BuybackContract::where('contract_id', $contractId)->first()->flags());
        Http::assertSentCount(1);
    }

    public function test_a_seller_no_source_can_place_is_flagged_as_unverified(): void
    {
        Http::fake(['*' => Http::response('Not found', 404)]);
        $setting = $this->setting(['seller_scope' => BuybackSetting::SELLERS_ALLIANCE, 'seller_alliance_id' => self::ALLIANCE]);
        $this->appraisal('bb-abcd2345');
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.flagged'], $this->events->names());
        $this->assertSame([BuybackContract::FLAG_SELLER_UNVERIFIED], BuybackContract::where('contract_id', $contractId)->first()->flags());
        Http::assertSentCount(2);
    }

    public function test_a_key_that_matches_nothing_still_says_the_seller_is_outside(): void
    {
        $setting = $this->setting(['seller_scope' => BuybackSetting::SELLERS_CORPORATION]);
        $contractId = $this->contract(['title' => 'bb-zzzz9999']);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.unmatched'], $this->events->names());
        $this->assertSame(
            [BuybackContract::FLAG_UNKNOWN_KEY, BuybackContract::FLAG_OUTSIDE_SELLER],
            BuybackContract::where('contract_id', $contractId)->first()->flags()
        );
    }

    public function test_who_can_sell_reads_in_words(): void
    {
        Schema::create('alliances', function (Blueprint $table) {
            $table->integer('alliance_id')->primary();
            $table->string('name')->nullable();
            $table->string('ticker')->nullable();
        });
        DB::table('alliances')->insert(['alliance_id' => self::ALLIANCE, 'name' => 'Test Alliance', 'ticker' => 'TEST']);

        $this->assertSame('Anyone', $this->setting()->sellerScopeLabel());
        BuybackSetting::query()->delete();
        $this->assertSame('Members of Test Corp', $this->setting(['seller_scope' => BuybackSetting::SELLERS_CORPORATION])->sellerScopeLabel());
        BuybackSetting::query()->delete();
        $this->assertSame('Members of Test Alliance', $this->setting(['seller_scope' => BuybackSetting::SELLERS_ALLIANCE, 'seller_alliance_id' => self::ALLIANCE])->sellerScopeLabel());
        BuybackSetting::query()->delete();
        $this->assertSame('Members of alliance #' . self::OTHER_ALLIANCE, $this->setting(['seller_scope' => BuybackSetting::SELLERS_ALLIANCE, 'seller_alliance_id' => self::OTHER_ALLIANCE])->sellerScopeLabel());
        BuybackSetting::query()->delete();
        $this->assertSame('Anyone', $this->setting(['seller_scope' => 'nonsense'])->sellerScopeLabel());
    }

    public function test_an_alliance_scope_with_no_alliance_checks_nobody(): void
    {
        $setting = $this->setting(['seller_scope' => BuybackSetting::SELLERS_ALLIANCE]);
        $this->appraisal('bb-abcd2345');
        $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame(['buyback.contract.matched'], $this->events->names());
    }

    /**
     * A player on one SeAT account: their main in the programme's
     * corporation, and the seller as an alt in another.
     */
    private function sellerIsAnAltOfAMember(): void
    {
        DB::table('users')->insert(['id' => 5, 'main_character_id' => self::OPERATOR]);
        DB::table('refresh_tokens')->insert([
            ['character_id' => self::OPERATOR, 'user_id' => 5, 'scopes' => '[]'],
            ['character_id' => self::SELLER, 'user_id' => 5, 'scopes' => '[]'],
        ]);
        DB::table('character_affiliations')->insert([
            ['character_id' => self::OPERATOR, 'corporation_id' => self::CORP, 'alliance_id' => null],
            ['character_id' => self::SELLER, 'corporation_id' => self::OTHER_CORP, 'alliance_id' => null],
        ]);
        DB::table('character_infos')->insert(['character_id' => self::OPERATOR, 'name' => 'Main Pilot']);
    }

    private function memberQuote(string $key): BuybackAppraisal
    {
        $appraisal = $this->appraisal($key);
        DB::table('buyback_manager_appraisals')->where('id', $appraisal->id)->update(['rate_tier' => BuybackAppraisal::RATE_MEMBER]);

        return $appraisal->fresh();
    }

    public function test_a_members_only_programme_flags_a_seller_outside_its_group(): void
    {
        Http::fake();
        // "Members only" as the form sends it, read as the members' own group.
        $setting = $this->setting(['seller_scope' => BuybackSetting::SELLERS_MEMBERS, 'member_scope' => BuybackSetting::MEMBERS_ALLIANCE, 'seller_alliance_id' => self::ALLIANCE]);
        DB::table('alliance_members')->insert(['alliance_id' => self::OTHER_ALLIANCE, 'corporation_id' => self::OTHER_CORP]);
        $this->appraisal('bb-abcd2345');
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([BuybackContract::FLAG_OUTSIDE_SELLER], $row->flags());
        $this->assertNull($row->seller_note);
        $this->assertSame(['buyback.contract.flagged'], $this->events->names());
    }

    public function test_a_member_rate_quote_contracted_by_someone_outside_is_flagged(): void
    {
        $setting = $this->setting(['member_bonus' => 5]);
        $this->memberQuote('bb-abcd2345');
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame([BuybackContract::FLAG_MEMBER_RATE_OUTSIDER], BuybackContract::where('contract_id', $contractId)->first()->flags());
        $this->assertSame(['buyback.contract.flagged'], $this->events->names());
        $this->assertSame([BuybackContract::FLAG_MEMBER_RATE_OUTSIDER], $this->events->events[0]['payload']['flags']);
    }

    public function test_a_member_rate_quote_contracted_by_a_member_is_clean(): void
    {
        $setting = $this->setting(['member_bonus' => 5]);
        $this->memberQuote('bb-abcd2345');
        $contractId = $this->contract(['issuer_corporation_id' => self::CORP]);

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame([], BuybackContract::where('contract_id', $contractId)->first()->flags());
        $this->assertSame(['buyback.contract.matched'], $this->events->names());
    }

    public function test_a_public_quote_on_an_open_programme_never_asks_who_the_seller_is(): void
    {
        $setting = $this->setting(['member_bonus' => 5]);
        $this->sellerIsAnAltOfAMember();
        $this->appraisal('bb-abcd2345');
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([], $row->flags());
        $this->assertNull($row->seller_note);
    }

    public function test_a_members_alt_is_flagged_with_whose_alt_it_is(): void
    {
        $setting = $this->setting(['seller_scope' => BuybackSetting::SELLERS_CORPORATION]);
        $this->sellerIsAnAltOfAMember();
        $this->appraisal('bb-abcd2345');
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([BuybackContract::FLAG_MEMBER_ALT], $row->flags());
        $this->assertSame('belongs to Main Pilot, who is in Test Corp', $row->seller_note);
        $this->assertSame(['Seller is an alt of a member: belongs to Main Pilot, who is in Test Corp'], $row->flagLabels());

        // The note travels with the contract to Discord and Manager Core.
        $this->assertSame(['buyback.contract.flagged'], $this->events->names());
        $this->assertSame('belongs to Main Pilot, who is in Test Corp', $this->events->events[0]['payload']['seller_note']);
    }

    public function test_a_members_alt_on_a_member_rate_quote_is_flagged_as_an_alt_only(): void
    {
        $setting = $this->setting(['member_bonus' => 5]);
        $this->sellerIsAnAltOfAMember();
        $this->memberQuote('bb-abcd2345');
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([BuybackContract::FLAG_MEMBER_ALT], $row->flags());
        $this->assertSame('belongs to Main Pilot, who is in Test Corp', $row->seller_note);
    }

    public function test_a_member_rate_quote_from_a_seller_no_source_can_place_is_unverified(): void
    {
        Http::fake(['*' => Http::response('Not found', 404)]);
        $setting = $this->setting(['member_bonus' => 5, 'member_scope' => BuybackSetting::MEMBERS_ALLIANCE, 'seller_alliance_id' => self::ALLIANCE]);
        $this->memberQuote('bb-abcd2345');
        $contractId = $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $this->assertSame([BuybackContract::FLAG_SELLER_UNVERIFIED], BuybackContract::where('contract_id', $contractId)->first()->flags());
    }

    public function test_every_contract_event_carries_the_seller_note_even_when_there_is_none(): void
    {
        $setting = $this->setting();
        $this->appraisal('bb-abcd2345');
        $this->contract();

        $this->service()->syncContractsForSetting($setting);

        $this->assertArrayHasKey('seller_note', $this->events->events[0]['payload']);
        $this->assertNull($this->events->events[0]['payload']['seller_note']);
    }

    public function test_a_key_that_matches_nothing_still_says_whose_alt_the_seller_is(): void
    {
        $setting = $this->setting(['seller_scope' => BuybackSetting::SELLERS_CORPORATION]);
        $this->sellerIsAnAltOfAMember();
        $contractId = $this->contract(['title' => 'bb-zzzz9999']);

        $this->service()->syncContractsForSetting($setting);

        $row = BuybackContract::where('contract_id', $contractId)->first();
        $this->assertSame([BuybackContract::FLAG_UNKNOWN_KEY, BuybackContract::FLAG_MEMBER_ALT], $row->flags());
        $this->assertSame('belongs to Main Pilot, who is in Test Corp', $row->seller_note);
    }
}
