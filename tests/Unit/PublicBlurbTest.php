<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Services\PublicBlurb;
use PHPUnit\Framework\TestCase;

class PublicBlurbTest extends TestCase
{
    private static function html(?string $text): string
    {
        return PublicBlurb::html($text)->toHtml();
    }

    public function testABlurbTypedWithoutMarksKeepsItsLinesAndItsDashedLinesBecomeAList(): void
    {
        $blurb = "Turn your mining efforts into ISK! The MINC Moon Ore Buyback Programme provides members with a convenient way to sell their mined moon ore while supporting our corporation's expanding industrial operations.\r\n"
            . "\r\n"
            . "Buyback Guidelines\r\n"
            . "- Accepted items: Raw Moon Ore only.\r\n"
            . "- Not accepted: Moon Goo, reprocessed Moon Ore and Moon Minerals.\r\n"
            . "- Contract locations: Any MINC-owned station, including our Detorid Athanors.\r\n"
            . "- Contract recipient: Woodcruor Atelier (Corporation).\r\n"
            . "- Pricing: Automatically calculated using Jita Buy Refined Value.\r\n"
            . "- Processing time: Allow up to 7 days for contract acceptance.\r\n"
            . "- Contracts exceeding 10 billion ISK: Contact a Director before submission.\r\n"
            . "Looking to sell Moon Goo or Moon Minerals?\r\n"
            . "\r\n"
            . "These items fall outside this buyback programme. Please contact a Director directly to discuss a custom arrangement.\r\n"
            . "\r\n"
            . "For questions, comments or concerns regarding the programme, visit #bb-general-discussion on Discord.\r\n";

        $this->assertSame(
            "<p>Turn your mining efforts into ISK! The MINC Moon Ore Buyback Programme provides members with a convenient way to sell their mined moon ore while supporting our corporation&#039;s expanding industrial operations.</p>\n"
            . "<p>Buyback Guidelines</p>\n"
            . "<ul>\n"
            . "<li>Accepted items: Raw Moon Ore only.</li>\n"
            . "<li>Not accepted: Moon Goo, reprocessed Moon Ore and Moon Minerals.</li>\n"
            . "<li>Contract locations: Any MINC-owned station, including our Detorid Athanors.</li>\n"
            . "<li>Contract recipient: Woodcruor Atelier (Corporation).</li>\n"
            . "<li>Pricing: Automatically calculated using Jita Buy Refined Value.</li>\n"
            . "<li>Processing time: Allow up to 7 days for contract acceptance.</li>\n"
            . "<li>Contracts exceeding 10 billion ISK: Contact a Director before submission.</li>\n"
            . "</ul>\n"
            . "<p>Looking to sell Moon Goo or Moon Minerals?</p>\n"
            . "<p>These items fall outside this buyback programme. Please contact a Director directly to discuss a custom arrangement.</p>\n"
            . "<p>For questions, comments or concerns regarding the programme, visit #bb-general-discussion on Discord.</p>",
            self::html($blurb)
        );
    }

    public function testSingleLineBreaksStayAndABlankLineStartsAParagraph(): void
    {
        $this->assertSame("<p>One<br>\nTwo</p>\n<p>Three</p>", self::html("One\nTwo\n\n\n  Three  "));
    }

    public function testNothingWrittenGivesNothing(): void
    {
        $this->assertSame('', self::html(null));
        $this->assertSame('', self::html(" \n\n "));
    }

    public function testBoldAndItalic(): void
    {
        $this->assertSame(
            '<p><strong>Paid</strong> within <em>seven</em> days, <em>always</em>, <strong><em>no</em></strong> exceptions</p>',
            self::html('**Paid** within *seven* days, _always_, ***no*** exceptions')
        );
    }

    public function testMarksInsideWordsAndLoneStarsAreLeftAlone(): void
    {
        $this->assertSame(
            '<p>raw_moon_ore at 5*3 and a * on its own, ** too</p>',
            self::html('raw_moon_ore at 5*3 and a * on its own, ** too')
        );
        $this->assertSame('<p>** not bold **</p>', self::html('** not bold **'));
    }

    public function testHeadingsAreHashesFollowedByASpace(): void
    {
        $this->assertSame(
            "<h3>Buyback Guidelines</h3>\n<p>#bb-general-discussion</p>\n<h3>Also <strong>this</strong></h3>",
            self::html("## Buyback Guidelines\n#bb-general-discussion\n### Also **this**")
        );
    }

    public function testListsOfBothKindsAndTheNumberTheyStartFrom(): void
    {
        $this->assertSame(
            "<ul>\n<li>one</li>\n<li>two</li>\n</ul>\n<ol>\n<li>first</li>\n<li>second</li>\n</ol>\n<p>after</p>\n<ol start=\"3\">\n<li>third</li>\n</ol>",
            self::html("- one\n* two\n1. first\n2) second\nafter\n3. third")
        );
    }

    public function testAHeadingOrBlankLineEndsAList(): void
    {
        $this->assertSame(
            "<ul>\n<li>a</li>\n</ul>\n<h3>Next</h3>\n<ul>\n<li>b</li>\n</ul>\n<ul>\n<li>c</li>\n</ul>",
            self::html("- a\n## Next\n- b\n\n- c")
        );
    }

    public function testWrittenAndBareLinksOpenInANewTabAndOnlyForHttp(): void
    {
        $anchor = fn (string $url, string $label) => '<a href="' . $url . '" target="_blank" rel="noopener noreferrer nofollow">' . $label . '</a>';

        $this->assertSame(
            '<p>Join ' . $anchor('https://discord.gg/abc_def', 'our <em>Discord</em>') . ' or see ' . $anchor('https://example.com/a?b=1&amp;c=2', 'https://example.com/a?b=1&amp;c=2') . '.</p>',
            self::html('Join [our *Discord*](https://discord.gg/abc_def) or see https://example.com/a?b=1&c=2.')
        );
        $this->assertSame(
            '<p>(' . $anchor('https://example.com/x', 'https://example.com/x') . ') &quot;' . $anchor('http://example.com', 'http://example.com') . '&quot;</p>',
            self::html('(https://example.com/x) "http://example.com"')
        );
        $this->assertSame(
            '<p><strong>' . $anchor('https://example.com', 'Bold link') . '</strong></p>',
            self::html('**[Bold link](https://example.com)**')
        );
    }

    public function testOnlyTheMarksTurnIntoMarkup(): void
    {
        $this->assertSame(
            '<p>&lt;script&gt;alert(1)&lt;/script&gt; &lt;b onclick=&quot;x&quot;&gt;hi&lt;/b&gt;</p>',
            self::html('<script>alert(1)</script> <b onclick="x">hi</b>')
        );
        $this->assertSame('<p>[click](javascript:alert(1))</p>', self::html('[click](javascript:alert(1))'));
        $this->assertSame('<p>[x](data:text/html,hi)</p>', self::html('[x](data:text/html,hi)'));

        // A quote in a written address can't close the attribute.
        $html = self::html('[x](https://example.com/"onmouseover="alert(1))');
        $this->assertStringNotContainsString('"onmouseover', $html);
        $this->assertStringNotContainsString('<script', self::html('**<script>**'));
        $this->assertSame('<p><strong>&lt;i&gt;</strong></p>', self::html('**<i>**'));
    }

    public function testTheLinkStandInsCannotBeTyped(): void
    {
        $this->assertSame('<p>a0b</p>', self::html("a\u{E000}0\u{E001}b"));
    }
}
