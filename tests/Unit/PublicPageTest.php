<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\BuybackPublicService;
use BuybackManager\Services\Pricing\PriceProviderService;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class PublicPageTest extends TestCase
{
    private const CLOSED = 98000001;
    private const OURS = 98000002;
    private const ALSO_ABC = 98000003;

    protected function setUp(): void
    {
        Carbon::setTestNow('2026-11-21 12:00:00');
        Sqlite::migrate('000001', '000004', '000006', '000016', '000017');

        Schema::create('corporation_infos', function (Blueprint $table) {
            $table->bigInteger('corporation_id')->primary();
            $table->string('name');
            $table->string('ticker');
            $table->timestamps();
        });
        Schema::create('character_infos', function (Blueprint $table) {
            $table->bigInteger('character_id')->primary();
            $table->string('name');
        });
        Schema::create('mapDenormalize', function (Blueprint $table) {
            $table->bigInteger('itemID')->primary();
            $table->string('itemName');
        });
        DB::table('mapDenormalize')->insert(['itemID' => 10000043, 'itemName' => 'Domain']);
    }

    protected function tearDown(): void
    {
        Sqlite::close();
        Carbon::setTestNow();
    }

    private function corporation(int $id, string $ticker, string $updatedAt): void
    {
        DB::table('corporation_infos')->insert([
            'corporation_id' => $id,
            'name' => 'Corp ' . $id,
            'ticker' => $ticker,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function programme(int $corporationId, bool $published = true, array $attributes = []): BuybackSetting
    {
        return BuybackSetting::create($attributes + [
            'corporation_id' => $corporationId,
            'enabled' => true,
            'public_page_enabled' => $published,
            'target_type' => BuybackSetting::TARGET_MY_CORP,
            'appraisal_stale_hours' => 48,
        ]);
    }

    private function appraisal(string $createdAt = '2026-11-21 10:00:00'): BuybackAppraisal
    {
        $appraisal = BuybackAppraisal::create([
            'public_id' => 'bb-' . substr(md5($createdAt . mt_rand()), 0, 8),
            'corporation_id' => self::OURS,
            'total_market_value' => 1000,
            'total_buyback_value' => 900,
            'market' => 'Jita',
            'provider' => 'fuzzwork',
            'excluded_json' => [],
        ]);
        DB::table('buyback_manager_appraisals')->where('id', $appraisal->id)->update(['created_at' => $createdAt]);

        return $appraisal->fresh();
    }

    private function claimedBy(BuybackAppraisal $appraisal, array $contract): BuybackAppraisal
    {
        $row = BuybackContract::create($contract + [
            'contract_id' => mt_rand(100000, 999999),
            'corporation_id' => self::OURS,
            'issuer_id' => 90000001,
            'appraisal_id' => $appraisal->id,
            'issued_date' => '2026-11-21 10:30:00',
        ]);
        $appraisal->update(['matched_contract_id' => $row->id, 'matched_at' => now()]);

        return $appraisal->fresh();
    }

    public function testATickerAClosedCorporationKeptFindsTheOneWithAProgramme(): void
    {
        // The closed corporation was refreshed more recently, which used to
        // be enough to win the lookup and 404 the page.
        $this->corporation(self::CLOSED, 'ABC', '2026-11-20 00:00:00');
        $this->corporation(self::OURS, 'ABC', '2026-10-01 00:00:00');
        $this->programme(self::OURS);

        $found = (new BuybackPublicService())->resolveByLink('ABC');

        $this->assertNotNull($found);
        $this->assertSame(self::OURS, (int) $found->corporation_id);
    }

    public function testAPublishedPageWinsOverAnUnpublishedOneWithTheSameTicker(): void
    {
        $this->corporation(self::ALSO_ABC, 'ABC', '2026-11-20 00:00:00');
        $this->corporation(self::OURS, 'ABC', '2026-10-01 00:00:00');
        $this->programme(self::ALSO_ABC, false);
        $this->programme(self::OURS);

        $service = new BuybackPublicService();

        $this->assertSame(self::OURS, (int) $service->resolveByLink('ABC')->corporation_id);
        // The image route looks up without the published gate, and has to
        // land on the same corporation or the page shows the other's images.
        $this->assertSame(self::OURS, (int) $service->resolveByLink('ABC', false)->corporation_id);
    }

    public function testBetweenTwoPublishedPagesTheLastRefreshedCorporationWins(): void
    {
        $this->corporation(self::ALSO_ABC, 'ABC', '2026-10-01 00:00:00');
        $this->corporation(self::OURS, 'ABC', '2026-11-20 00:00:00');
        $this->programme(self::ALSO_ABC);
        $this->programme(self::OURS);

        $this->assertSame(self::OURS, (int) (new BuybackPublicService())->resolveByLink('ABC')->corporation_id);
    }

    public function testAnUnpublishedPageIsFoundOnlyWithoutTheGate(): void
    {
        $this->corporation(self::OURS, 'ABC', '2026-10-01 00:00:00');
        $this->programme(self::OURS, false);

        $service = new BuybackPublicService();

        $this->assertNull($service->resolveByLink('ABC'));
        $this->assertNotNull($service->resolveByLink('ABC', false));
        $this->assertNull($service->resolveByLink('XYZ', false));
        $this->assertNull($service->resolveByLink('  ', false));
    }

    public function testTheLinkIsNamedAfterWhoeverTheContractGoesTo(): void
    {
        $this->corporation(self::OURS, 'MINC', '2026-10-01 00:00:00');
        $this->corporation(self::ALSO_ABC, 'HAUL', '2026-10-01 00:00:00');
        DB::table('character_infos')->insert(['character_id' => 90000001, 'name' => "Kira O'Vex"]);

        $link = fn (array $target) => (new BuybackSetting($target + ['corporation_id' => self::OURS]))->public_slug;

        $this->assertSame('MINC', $link(['target_type' => BuybackSetting::TARGET_MY_CORP]));
        $this->assertSame('MINC', $link([]));
        $this->assertSame('HAUL', $link(['target_type' => BuybackSetting::TARGET_CORP, 'target_corporation_id' => self::ALSO_ABC, 'target_corporation_name' => 'Hauling Co']));
        // A corporation SeAT does not know yet goes by the name typed for it.
        $this->assertSame('hauling-co-ltd', $link(['target_type' => BuybackSetting::TARGET_CORP, 'target_corporation_name' => 'Hauling Co. Ltd']));
        $this->assertSame('kira-ovex', $link(['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => 90000001]));
        // Nothing to name it after yet: the programme's own ticker stands in.
        $this->assertSame('MINC', $link(['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => 90000099]));
        $this->assertSame('MINC', $link(['target_type' => BuybackSetting::TARGET_CORP, 'target_corporation_name' => '  ']));
    }

    public function testTheNameUnderTheLogoIsWhoeverTheContractGoesTo(): void
    {
        $this->corporation(self::OURS, 'MINC', '2026-10-01 00:00:00');
        $this->corporation(self::ALSO_ABC, 'HAUL', '2026-10-01 00:00:00');
        DB::table('character_infos')->insert(['character_id' => 90000001, 'name' => "Kira O'Vex"]);

        $badge = fn (array $target) => (new BuybackSetting($target + ['corporation_id' => self::OURS]))->publicTargetBadge();

        $this->assertSame('MINC', $badge(['target_type' => BuybackSetting::TARGET_MY_CORP]));
        $this->assertSame('MINC', $badge([]));
        $this->assertSame('HAUL', $badge(['target_type' => BuybackSetting::TARGET_CORP, 'target_corporation_id' => self::ALSO_ABC, 'target_corporation_name' => 'Hauling Co']));
        $this->assertSame('Hauling Co. Ltd', $badge(['target_type' => BuybackSetting::TARGET_CORP, 'target_corporation_name' => ' Hauling Co. Ltd ']));
        $this->assertSame("Kira O'Vex", $badge(['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => 90000001]));
        $this->assertSame('MINC', $badge(['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => 90000099]));
        $this->assertSame('MINC', $badge(['target_type' => BuybackSetting::TARGET_CORP, 'target_corporation_name' => '  ']));
    }

    public function testTheLinkFindsTheProgrammeAndTheOldTickerStillDoes(): void
    {
        $this->corporation(self::OURS, 'MINC', '2026-10-01 00:00:00');
        DB::table('character_infos')->insert(['character_id' => 90000001, 'name' => 'Kira Vex']);
        $this->programme(self::OURS, true, ['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => 90000001]);

        $service = new BuybackPublicService();

        $this->assertSame(self::OURS, (int) $service->resolveByLink('kira-vex')->corporation_id);
        $this->assertSame(self::OURS, (int) $service->resolveByLink('Kira-Vex')->corporation_id);
        // Links copied before the name followed the contract carry the ticker.
        $this->assertSame(self::OURS, (int) $service->resolveByLink('MINC')->corporation_id);
        $this->assertSame(self::OURS, (int) $service->resolveByLink('minc')->corporation_id);
        $this->assertNull($service->resolveByLink('kira'));
    }

    public function testANameBeatsAnotherProgrammesOldTicker(): void
    {
        // Ours buys for a player now; theirs sends contracts to our corporation.
        $this->corporation(self::OURS, 'MINC', '2026-11-20 00:00:00');
        $this->corporation(self::ALSO_ABC, 'ABC', '2026-10-01 00:00:00');
        DB::table('character_infos')->insert(['character_id' => 90000001, 'name' => 'Kira Vex']);
        $this->programme(self::OURS, true, ['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => 90000001]);
        $this->programme(self::ALSO_ABC, true, ['target_type' => BuybackSetting::TARGET_CORP, 'target_corporation_id' => self::OURS]);

        $this->assertSame(self::ALSO_ABC, (int) (new BuybackPublicService())->resolveByLink('MINC')->corporation_id);
    }

    public function testProgrammesSharingALinkNameSeeEachOther(): void
    {
        $this->corporation(self::OURS, 'MINC', '2026-11-20 00:00:00');
        $this->corporation(self::ALSO_ABC, 'ABC', '2026-10-01 00:00:00');
        $this->corporation(self::CLOSED, 'XYZ', '2026-10-01 00:00:00');
        DB::table('character_infos')->insert(['character_id' => 90000001, 'name' => 'Kira Vex']);
        $ours = $this->programme(self::OURS, false, ['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => 90000001]);
        $theirs = $this->programme(self::ALSO_ABC, true, ['target_type' => BuybackSetting::TARGET_PLAYER, 'character_id' => 90000001]);
        $alone = $this->programme(self::CLOSED);

        $service = new BuybackPublicService();

        $this->assertSame([self::ALSO_ABC], $service->sharingLink($ours->fresh())->pluck('corporation_id')->map('intval')->all());
        $this->assertSame([self::OURS], $service->sharingLink($theirs->fresh())->pluck('corporation_id')->map('intval')->all());
        $this->assertTrue($service->sharingLink($alone->fresh())->isEmpty());
        // The published one is the page the link opens.
        $this->assertSame(self::ALSO_ABC, (int) $service->resolveByLink('kira-vex', false)->corporation_id);
    }

    public function testThePageIsOpenOnlyWhileItAndTheProgrammeAreBothOn(): void
    {
        $this->assertTrue($this->programme(self::OURS)->publicPageOpen());
        $this->assertFalse($this->programme(self::CLOSED, false)->publicPageOpen());
        // A programme switched off takes no contracts, so its page is closed
        // even with the page itself still ticked.
        $this->assertFalse($this->programme(self::ALSO_ABC, true, ['enabled' => false])->publicPageOpen());
    }

    public function testAClosedPageIsTemporarilyClosedUnlessSetOtherwise(): void
    {
        // Read back from the table, so the column's own default is what is checked.
        $this->assertSame(BuybackSetting::PUBLIC_CLOSED_TEMPORARY, $this->programme(self::OURS, false)->fresh()->publicClosedState());

        foreach (['permanent' => 'permanent', 'hidden' => 'hidden', 'temporary' => 'temporary', 'Permanent' => 'temporary', 'gone' => 'temporary', '' => 'temporary'] as $stored => $read) {
            $this->assertSame($read, (new BuybackSetting(['public_closed_state' => $stored]))->publicClosedState(), "stored '{$stored}'");
        }
        $this->assertSame('temporary', (new BuybackSetting())->publicClosedState());
    }

    public function testTheClosedMessageIsTheCorporationsOwnOrTheDefaultForItsState(): void
    {
        $own = new BuybackSetting(['public_closed_state' => 'permanent', 'public_closed_message' => "  Moved to the new corp.\nSee you there.  "]);
        $this->assertSame("Moved to the new corp.\nSee you there.", $own->publicClosedMessage());

        $blank = new BuybackSetting(['public_closed_state' => 'permanent', 'public_closed_message' => " \n "]);
        $this->assertSame(BuybackSetting::defaultClosedMessage('permanent'), $blank->publicClosedMessage());
        $this->assertSame(BuybackSetting::defaultClosedMessage('temporary'), (new BuybackSetting())->publicClosedMessage());
        $this->assertNotSame(BuybackSetting::defaultClosedMessage('temporary'), BuybackSetting::defaultClosedMessage('permanent'));
    }

    public function testAClosedMessageAtTheFormsLimitSurvivesTheTable(): void
    {
        $message = str_repeat('Back after the war. ', 50);
        $this->programme(self::OURS, false, ['public_closed_state' => 'permanent', 'public_closed_message' => $message]);

        $stored = BuybackSetting::where('corporation_id', self::OURS)->first();

        $this->assertSame('permanent', $stored->publicClosedState());
        $this->assertSame(trim($message), $stored->publicClosedMessage());
    }

    public function testAQuoteHoldsForTheProgrammesStaleWindow(): void
    {
        $setting = $this->programme(self::OURS, true, ['appraisal_stale_hours' => 24]);
        $service = new BuybackPublicService();

        $fresh = $service->validUntil($this->appraisal('2026-11-21 10:00:00'), $setting);
        $old = $service->validUntil($this->appraisal('2026-11-20 11:00:00'), $setting);

        $this->assertSame('2026-11-22 10:00:00', $fresh->format('Y-m-d H:i:s'));
        $this->assertFalse($fresh->isPast());
        $this->assertTrue($old->isPast());
    }

    public function testAKeyNoContractHasUsedHasNoStatus(): void
    {
        $this->assertNull((new BuybackPublicService())->keyStatus($this->appraisal()));
    }

    public function testTheKeyStatusFollowsTheContract(): void
    {
        $service = new BuybackPublicService();
        $state = fn (array $contract) => $service->keyStatus($this->claimedBy($this->appraisal(), $contract))['state'];

        $this->assertSame('received', $state(['status' => 'outstanding']));
        $this->assertSame('paid', $state(['status' => 'finished', 'completed_date' => '2026-11-21 11:00:00']));
        // EVE leaves a lapsed contract outstanding; the sync marks it.
        $this->assertSame('expired', $state(['status' => 'outstanding', 'expired_at' => '2026-11-21 11:00:00']));
        $this->assertSame('cancelled', $state(['status' => 'cancelled']));
        $this->assertSame('declined', $state(['status' => 'rejected']));
        $this->assertSame('closed', $state(['status' => 'reversed']));
    }

    public function testAPaidKeySaysWhenAndNeverWho(): void
    {
        $status = (new BuybackPublicService())->keyStatus($this->claimedBy($this->appraisal(), [
            'status' => 'finished',
            'completed_date' => '2026-11-21 11:00:00',
        ]));

        $this->assertSame('ok', $status['tone']);
        $this->assertStringContainsString('21 Nov 2026, 11:00 EVE', $status['text']);
        $this->assertStringNotContainsString('90000001', $status['text']);
    }

    public function testAClaimWhoseContractIsGoneStillSaysTheKeyIsUsed(): void
    {
        $appraisal = $this->appraisal();
        $appraisal->update(['matched_contract_id' => 424242, 'matched_at' => now()]);

        $this->assertSame('used', (new BuybackPublicService())->keyStatus($appraisal->fresh())['state']);
    }

    public function testThePageNamesTheProviderAndMarketTheQuotesUse(): void
    {
        $prices = new PriceProviderService();

        $this->assertSame('Fuzzwork at Jita', $prices->currentSourceLabel(new BuybackSetting(['price_provider' => 'fuzzwork', 'price_source' => 'jita'])));
        $this->assertSame('Fuzzwork at Domain', $prices->currentSourceLabel(new BuybackSetting(['price_provider' => 'fuzzwork', 'price_source' => 'region', 'region_id' => 10000043])));
        $this->assertSame('Janice at Amarr', $prices->currentSourceLabel(new BuybackSetting(['price_provider' => 'janice', 'janice_market' => 'amarr'])));
        $this->assertSame('Janice at Jita', $prices->sourceLabel('janice', 'Jita'));
        $this->assertSame('Fuzzwork', $prices->sourceLabel('fuzzwork', null));
        $this->assertNull($prices->sourceLabel(null, 'Jita'));
    }
}
