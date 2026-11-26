<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\ProgrammeMembers;
use BuybackManager\Services\SellerAffiliation;
use BuybackManager\Tests\Sqlite;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Who counts as a programme's member, by SeAT account, against SeAT's own
 * tables in an in-memory database.
 */
class ProgrammeMembersTest extends TestCase
{
    private const CORP = 98000001;
    private const OTHER_CORP = 98000002;
    private const ALLIANCE = 99000001;
    private const OTHER_ALLIANCE = 99000002;

    private const MAIN = 2112000001;
    private const MEMBER_ALT = 2112000002;
    private const OUTSIDE_ALT = 2112000003;
    private const STRANGER = 2112000009;

    private const USER = 5;

    /** @var array<int, ?array{alliance_id: ?int, source: string}> what the stand-in SellerAffiliation answers, by character */
    private array $alliances = [];

    protected function setUp(): void
    {
        Sqlite::migrate();
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
        Schema::create('character_affiliations', function (Blueprint $table) {
            $table->bigInteger('character_id')->primary();
            $table->bigInteger('corporation_id');
            $table->bigInteger('alliance_id')->nullable();
        });
        Schema::create('character_infos', function (Blueprint $table) {
            $table->bigInteger('character_id')->primary();
            $table->string('name');
        });
        Schema::create('corporation_infos', function (Blueprint $table) {
            $table->bigInteger('corporation_id')->primary();
            $table->string('name');
        });
        Schema::create('alliances', function (Blueprint $table) {
            $table->integer('alliance_id')->primary();
            $table->string('name')->nullable();
        });
        DB::table('corporation_infos')->insert(['corporation_id' => self::CORP, 'name' => 'Test Corp']);
        DB::table('alliances')->insert(['alliance_id' => self::ALLIANCE, 'name' => 'Test Alliance']);

        // One player: a main in the programme's corporation, an alt there too,
        // and an alt in another corporation.
        DB::table('users')->insert(['id' => self::USER, 'main_character_id' => self::MAIN]);
        $this->character(self::MAIN, 'Main Character', self::CORP, self::ALLIANCE);
        $this->character(self::MEMBER_ALT, 'Another Member', self::CORP, self::ALLIANCE);
        $this->character(self::OUTSIDE_ALT, 'Outside Alt', self::OTHER_CORP, self::OTHER_ALLIANCE);
    }

    protected function tearDown(): void
    {
        Sqlite::close();
    }

    private function character(int $id, string $name, int $corporationId, ?int $allianceId, int $userId = self::USER, ?string $deletedAt = null): void
    {
        DB::table('refresh_tokens')->insert(['character_id' => $id, 'user_id' => $userId, 'scopes' => '[]', 'deleted_at' => $deletedAt]);
        DB::table('character_affiliations')->insert(['character_id' => $id, 'corporation_id' => $corporationId, 'alliance_id' => $allianceId]);
        DB::table('character_infos')->insert(['character_id' => $id, 'name' => $name]);
    }

    private function setting(array $attributes = []): BuybackSetting
    {
        return BuybackSetting::create($attributes + [
            'corporation_id' => self::CORP,
            'enabled' => true,
            'target_type' => BuybackSetting::TARGET_MY_CORP,
        ]);
    }

    /**
     * Where each seller's alliance comes from is SellerAffiliation's own
     * test; here it answers from $this->alliances.
     */
    private function members(): ProgrammeMembers
    {
        $sellers = new class extends SellerAffiliation {
            public array $answers = [];

            public function allianceOf(int $characterId, int $corporationId): ?array
            {
                return $this->answers[$characterId] ?? null;
            }
        };
        $sellers->answers = $this->alliances;

        return new ProgrammeMembers($sellers);
    }

    public function test_a_seller_from_the_programmes_corporation_is_a_member(): void
    {
        $found = $this->members()->standingOf(self::STRANGER, self::CORP, $this->setting());

        $this->assertSame(['standing' => ProgrammeMembers::MEMBER, 'note' => null], $found);
    }

    public function test_an_alliance_member_is_judged_by_the_corporation_the_contract_came_from(): void
    {
        $setting = $this->setting(['member_scope' => BuybackSetting::MEMBERS_ALLIANCE, 'seller_alliance_id' => self::ALLIANCE]);
        $this->alliances[self::STRANGER] = ['alliance_id' => self::ALLIANCE, 'source' => 'SeAT'];

        $this->assertSame(ProgrammeMembers::MEMBER, $this->members()->standingOf(self::STRANGER, self::OTHER_CORP, $setting)['standing']);
    }

    public function test_an_alt_outside_the_group_says_whose_it_is_naming_the_main(): void
    {
        $found = $this->members()->standingOf(self::OUTSIDE_ALT, self::OTHER_CORP, $this->setting());

        $this->assertSame(ProgrammeMembers::MEMBER_ALT, $found['standing']);
        $this->assertSame('belongs to Main Character, who is in Test Corp', $found['note']);
    }

    public function test_an_alliance_programmes_alt_names_the_alliance(): void
    {
        $setting = $this->setting(['member_scope' => BuybackSetting::MEMBERS_ALLIANCE, 'seller_alliance_id' => self::ALLIANCE]);
        $this->alliances[self::OUTSIDE_ALT] = ['alliance_id' => self::OTHER_ALLIANCE, 'source' => 'SeAT'];

        $found = $this->members()->standingOf(self::OUTSIDE_ALT, self::OTHER_CORP, $setting);

        $this->assertSame(ProgrammeMembers::MEMBER_ALT, $found['standing']);
        $this->assertSame('belongs to Main Character, who is in Test Alliance', $found['note']);
    }

    public function test_when_the_main_is_not_a_member_the_alt_names_a_character_who_is(): void
    {
        DB::table('character_affiliations')->where('character_id', self::MAIN)->update(['corporation_id' => self::OTHER_CORP]);

        $found = $this->members()->standingOf(self::OUTSIDE_ALT, self::OTHER_CORP, $this->setting());

        $this->assertSame('belongs to Another Member, who is in Test Corp', $found['note']);
    }

    public function test_a_seller_with_no_member_on_their_account_is_an_outsider(): void
    {
        $this->character(self::STRANGER, 'Stranger', self::OTHER_CORP, null, 6);

        $found = $this->members()->standingOf(self::STRANGER, self::OTHER_CORP, $this->setting());

        $this->assertSame(['standing' => ProgrammeMembers::OUTSIDER, 'note' => null], $found);
    }

    public function test_a_seller_seat_does_not_know_is_an_outsider_of_a_corporation_programme(): void
    {
        $found = $this->members()->standingOf(self::STRANGER, self::OTHER_CORP, $this->setting());

        $this->assertSame(['standing' => ProgrammeMembers::OUTSIDER, 'note' => null], $found);
    }

    public function test_a_member_on_a_deleted_login_makes_nobody_an_alt(): void
    {
        DB::table('refresh_tokens')->whereIn('character_id', [self::MAIN, self::MEMBER_ALT])->update(['deleted_at' => '2026-11-20 10:00:00']);

        $found = $this->members()->standingOf(self::OUTSIDE_ALT, self::OTHER_CORP, $this->setting());

        $this->assertSame(ProgrammeMembers::OUTSIDER, $found['standing']);
    }

    public function test_a_seller_no_source_can_place_is_unknown_to_an_alliance_programme(): void
    {
        $setting = $this->setting(['member_scope' => BuybackSetting::MEMBERS_ALLIANCE, 'seller_alliance_id' => self::ALLIANCE]);

        $this->assertSame(['standing' => ProgrammeMembers::UNKNOWN, 'note' => null], $this->members()->standingOf(self::STRANGER, self::OTHER_CORP, $setting));
        // A corporation in no alliance is placed: it is outside.
        $this->alliances[self::STRANGER] = ['alliance_id' => null, 'source' => 'SeAT'];
        $this->assertSame(ProgrammeMembers::OUTSIDER, $this->members()->standingOf(self::STRANGER, self::OTHER_CORP, $setting)['standing']);
    }

    public function test_a_contract_with_no_issuing_corporation_is_unknown(): void
    {
        $this->assertSame(ProgrammeMembers::UNKNOWN, $this->members()->standingOf(self::STRANGER, 0, $this->setting())['standing']);
    }

    public function test_an_alliance_with_none_saved_falls_back_to_the_corporation(): void
    {
        $setting = $this->setting(['member_scope' => BuybackSetting::MEMBERS_ALLIANCE]);

        $this->assertSame(BuybackSetting::MEMBERS_CORPORATION, $setting->memberScope());
        $this->assertSame(ProgrammeMembers::MEMBER, $this->members()->standingOf(self::STRANGER, self::CORP, $setting)['standing']);
    }

    public function test_the_accounts_member_characters_come_main_first(): void
    {
        DB::table('users')->where('id', self::USER)->update(['main_character_id' => self::MEMBER_ALT]);

        $this->assertSame(
            [self::MEMBER_ALT => 'Another Member', self::MAIN => 'Main Character'],
            $this->members()->memberCharacters(self::USER, $this->setting())
        );
    }

    public function test_an_account_is_a_member_through_any_one_character(): void
    {
        DB::table('character_affiliations')->whereIn('character_id', [self::MAIN, self::MEMBER_ALT])->update(['corporation_id' => self::OTHER_CORP]);
        $members = $this->members();
        $setting = $this->setting();

        $this->assertFalse($members->isMember(self::USER, $setting));
        $this->assertFalse($members->isMember(null, $setting));

        DB::table('character_affiliations')->where('character_id', self::OUTSIDE_ALT)->update(['corporation_id' => self::CORP]);
        $this->assertTrue($members->isMember(self::USER, $setting));
    }

    public function test_alliance_membership_reads_each_characters_alliance(): void
    {
        $setting = $this->setting(['member_scope' => BuybackSetting::MEMBERS_ALLIANCE, 'seller_alliance_id' => self::OTHER_ALLIANCE]);

        $this->assertSame([self::OUTSIDE_ALT => 'Outside Alt'], $this->members()->memberCharacters(self::USER, $setting));
    }

    public function test_without_seats_tables_nobody_is_a_member(): void
    {
        Schema::drop('character_affiliations');

        $this->assertSame([], $this->members()->memberCharacters(self::USER, $this->setting()));
    }

    public function test_the_longest_note_eve_names_allow_fits_its_column(): void
    {
        // EVE caps a character name at 37 characters and a corporation or
        // alliance name at 50.
        DB::table('character_infos')->where('character_id', self::MAIN)->update(['name' => str_repeat('c', 37)]);
        DB::table('corporation_infos')->where('corporation_id', self::CORP)->update(['name' => str_repeat('n', 50)]);
        $setting = $this->setting();

        $note = $this->members()->standingOf(self::OUTSIDE_ALT, self::OTHER_CORP, $setting)['note'];

        $this->assertSame(110, mb_strlen($note));
        $this->assertLessThanOrEqual(255, mb_strlen($note));

        // And a name no EVE rule allows is cut to the column, not refused.
        DB::table('corporation_infos')->where('corporation_id', self::CORP)->update(['name' => str_repeat('ü', 300)]);
        $note = $this->members()->standingOf(self::OUTSIDE_ALT, self::OTHER_CORP, $setting->fresh())['note'];
        $this->assertSame(255, mb_strlen($note));
    }
}
