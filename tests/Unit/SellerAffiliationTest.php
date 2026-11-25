<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Services\SellerAffiliation;
use BuybackManager\Tests\Sqlite;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Where a seller's alliance comes from, with SeAT's tables in an in-memory
 * database and EveWho and zKillboard answering in the shapes they send.
 */
class SellerAffiliationTest extends TestCase
{
    private const SELLER = 2112000001;
    private const CORP = 98000002;
    private const NEW_CORP = 98000009;
    private const ALLIANCE = 99000001;

    protected function setUp(): void
    {
        Sqlite::migrate('000001');
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
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Sqlite::close();
    }

    private static function evewho(int $corporationId, int $allianceId): array
    {
        return ['info' => [[
            'character_id' => self::SELLER, 'corporation_id' => $corporationId, 'alliance_id' => $allianceId,
            'faction_id' => 0, 'name' => 'Seller', 'sec_status' => '0.4',
        ]], 'history' => [['record_id' => 1, 'corporation_id' => $corporationId, 'start_date' => '2020/01/01 00:00', 'end_date' => null]]];
    }

    private static function zkillboard(int $corporationId, int $allianceId): array
    {
        return ['type' => 'characterID', 'id' => self::SELLER, 'shipsLost' => 3, 'info' => [
            'type' => 'characterID', 'id' => self::SELLER, 'name' => 'Seller',
            'corporationID' => $corporationId, 'allianceID' => $allianceId,
            'corporation_id' => $corporationId, 'alliance_id' => $allianceId, 'factionID' => 0,
        ]];
    }

    public function test_seat_answers_first_when_it_has_the_seller_in_the_contracts_corporation(): void
    {
        Http::fake();
        DB::table('character_affiliations')->insert(['character_id' => self::SELLER, 'corporation_id' => self::CORP, 'alliance_id' => self::ALLIANCE]);

        $this->assertSame(['alliance_id' => self::ALLIANCE, 'source' => 'SeAT'], (new SellerAffiliation())->allianceOf(self::SELLER, self::CORP));
        Http::assertNothingSent();
    }

    public function test_seat_saying_no_alliance_is_an_answer(): void
    {
        Http::fake();
        DB::table('character_affiliations')->insert(['character_id' => self::SELLER, 'corporation_id' => self::CORP, 'alliance_id' => null]);

        $this->assertSame(['alliance_id' => null, 'source' => 'SeAT'], (new SellerAffiliation())->allianceOf(self::SELLER, self::CORP));
        Http::assertNothingSent();
    }

    public function test_a_seller_seat_has_in_another_corporation_is_placed_by_the_contracts_corporation(): void
    {
        Http::fake();
        DB::table('character_affiliations')->insert(['character_id' => self::SELLER, 'corporation_id' => self::NEW_CORP, 'alliance_id' => null]);
        DB::table('alliance_members')->insert(['alliance_id' => self::ALLIANCE, 'corporation_id' => self::CORP]);

        $this->assertSame(['alliance_id' => self::ALLIANCE, 'source' => 'SeAT'], (new SellerAffiliation())->allianceOf(self::SELLER, self::CORP));
        Http::assertNothingSent();
    }

    public function test_evewho_answers_when_seat_cannot_and_is_asked_once(): void
    {
        Http::fake(['evewho.com/api/character/' . self::SELLER => Http::response(self::evewho(self::CORP, self::ALLIANCE))]);
        $sellers = new SellerAffiliation();

        $this->assertSame(['alliance_id' => self::ALLIANCE, 'source' => 'EveWho'], $sellers->allianceOf(self::SELLER, self::CORP));
        $this->assertSame(['alliance_id' => self::ALLIANCE, 'source' => 'EveWho'], $sellers->allianceOf(self::SELLER, self::CORP));
        Http::assertSentCount(1);
    }

    public function test_an_alliance_id_of_zero_online_means_no_alliance(): void
    {
        Http::fake(['evewho.com/*' => Http::response(self::evewho(self::CORP, 0))]);

        $this->assertSame(['alliance_id' => null, 'source' => 'EveWho'], (new SellerAffiliation())->allianceOf(self::SELLER, self::CORP));
    }

    public function test_zkillboard_answers_when_evewho_is_down(): void
    {
        Http::fake([
            'evewho.com/*' => Http::response('Bad gateway', 502),
            'zkillboard.com/api/stats/characterID/' . self::SELLER . '/' => Http::response(self::zkillboard(self::CORP, self::ALLIANCE)),
        ]);

        $this->assertSame(['alliance_id' => self::ALLIANCE, 'source' => 'zKillboard'], (new SellerAffiliation())->allianceOf(self::SELLER, self::CORP));
        Http::assertSentCount(2);
    }

    public function test_a_source_with_the_seller_in_another_corporation_is_passed_over(): void
    {
        Http::fake([
            'evewho.com/*' => Http::response(self::evewho(self::NEW_CORP, 99000777)),
            'zkillboard.com/*' => Http::response(self::zkillboard(self::NEW_CORP, 99000777)),
        ]);

        $this->assertNull((new SellerAffiliation())->allianceOf(self::SELLER, self::CORP));
    }

    public function test_nothing_is_remembered_when_no_source_could_tell(): void
    {
        Http::fake([
            'evewho.com/*' => Http::sequence()->push('Bad gateway', 502)->push(self::evewho(self::CORP, self::ALLIANCE)),
            'zkillboard.com/*' => Http::response(['type' => 'characterID', 'id' => self::SELLER, 'info' => null]),
        ]);

        $this->assertNull((new SellerAffiliation())->allianceOf(self::SELLER, self::CORP));
        // The next sync run asks again.
        $this->assertSame(['alliance_id' => self::ALLIANCE, 'source' => 'EveWho'], (new SellerAffiliation())->allianceOf(self::SELLER, self::CORP));
    }

    public function test_a_site_that_is_down_is_not_asked_again_in_the_same_run(): void
    {
        Http::fake([
            'evewho.com/*' => Http::response('Bad gateway', 502),
            'zkillboard.com/*' => Http::response('Too many requests', 429),
        ]);
        $sellers = new SellerAffiliation();

        $this->assertNull($sellers->allianceOf(self::SELLER, self::CORP));
        $this->assertNull($sellers->allianceOf(self::SELLER + 1, self::CORP));
        Http::assertSentCount(2);
    }

    public function test_a_character_a_site_does_not_know_leaves_it_asked_for_the_next(): void
    {
        Http::fake([
            'evewho.com/api/character/' . self::SELLER => Http::response(['info' => [], 'history' => []]),
            'evewho.com/api/character/' . (self::SELLER + 1) => Http::response(self::evewho(self::CORP, self::ALLIANCE)),
            'zkillboard.com/*' => Http::response('Not found', 404),
        ]);
        $sellers = new SellerAffiliation();

        $this->assertNull($sellers->allianceOf(self::SELLER, self::CORP));
        $this->assertSame(['alliance_id' => self::ALLIANCE, 'source' => 'EveWho'], $sellers->allianceOf(self::SELLER + 1, self::CORP));
    }

    public function test_a_contract_without_a_corporation_asks_nobody(): void
    {
        Http::fake();

        $this->assertNull((new SellerAffiliation())->allianceOf(self::SELLER, 0));
        Http::assertNothingSent();
    }
}
