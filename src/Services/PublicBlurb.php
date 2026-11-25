<?php

namespace BuybackManager\Services;

use Illuminate\Support\HtmlString;

/**
 * The public page's blurb: plain text with the few marks the formatting
 * buttons write, turned into HTML.
 *
 *   **bold**   *italic* or _italic_   [text](https://...)   bare https:// links
 *   at the start of a line:  ## heading   - bullet   1. numbered
 *
 * The text is escaped before any mark is read, and only these tags are ever
 * written, so nothing typed into the field can put markup of its own on a page
 * anyone can open. Line breaks stay where they were typed, a blank line starts
 * a new paragraph, and a line that is not a list item ends the list above it.
 */
final class PublicBlurb
{
    private const HEADING = '/^\s*#{1,3}\s+(.+)$/u';
    private const BULLET = '/^\s*[-*•]\s+(.+)$/u';
    private const NUMBERED = '/^\s*(\d{1,3})[.)]\s+(.+)$/u';

    // Stand-ins for finished links while bold and italic are read, so an
    // address with * or _ in it is never taken apart. From the Private Use
    // Area, and removed from the text first, so no blurb can contain them.
    private const OPEN = "\u{E000}";
    private const CLOSE = "\u{E001}";

    public static function html(?string $text): HtmlString
    {
        $text = str_replace(["\r\n", "\r", self::OPEN, self::CLOSE], ["\n", "\n", '', ''], trim((string) $text));
        if ($text === '') {
            return new HtmlString('');
        }

        $out = [];
        $paragraph = [];
        $list = null;

        $endParagraph = function () use (&$out, &$paragraph): void {
            if ($paragraph !== []) {
                $out[] = '<p>' . implode("<br>\n", $paragraph) . '</p>';
                $paragraph = [];
            }
        };
        $endList = function () use (&$out, &$list): void {
            if ($list !== null) {
                $out[] = '</' . $list . '>';
                $list = null;
            }
        };

        foreach (explode("\n", $text) as $line) {
            if (trim($line) === '') {
                $endParagraph();
                $endList();
            } elseif (preg_match(self::HEADING, $line, $m)) {
                $endParagraph();
                $endList();
                $out[] = '<h3>' . self::inline($m[1]) . '</h3>';
            } elseif (preg_match(self::BULLET, $line, $m) || preg_match(self::NUMBERED, $line, $m)) {
                $endParagraph();
                $kind = count($m) === 3 ? 'ol' : 'ul';
                if ($list !== $kind) {
                    $endList();
                    $start = $kind === 'ol' && (int) $m[1] !== 1 ? ' start="' . (int) $m[1] . '"' : '';
                    $out[] = '<' . $kind . $start . '>';
                    $list = $kind;
                }
                $out[] = '<li>' . self::inline(end($m)) . '</li>';
            } else {
                $endList();
                $paragraph[] = self::inline(trim($line));
            }
        }
        $endParagraph();
        $endList();

        return new HtmlString(implode("\n", $out));
    }

    private static function inline(string $text): string
    {
        $html = htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $links = [];
        $keep = function (string $anchor) use (&$links): string {
            $links[] = $anchor;

            return self::OPEN . (count($links) - 1) . self::CLOSE;
        };

        // Written links first, so their address is not linked a second time.
        $html = preg_replace_callback('~\[([^\]\n]+)\]\((https?://[^\s)]+)\)~iu', fn ($m) => $keep(self::anchor($m[2], self::emphasis($m[1]))), $html);
        $html = preg_replace_callback('~(?<![\w/])https?://[^\s' . self::OPEN . ']+~iu', function ($m) use ($keep) {
            // The text is escaped by now, so a quote or angle bracket after the
            // address is an entity; it ends the address, as does a full stop or
            // bracket closing the sentence.
            $url = rtrim(preg_replace('/&(?:lt|gt|quot|#039);.*$/s', '', $m[0]), '.,;:!?)');

            return $keep(self::anchor($url, $url)) . substr($m[0], strlen($url));
        }, $html);

        return preg_replace_callback('/' . self::OPEN . '(\d+)' . self::CLOSE . '/u', fn ($m) => $links[(int) $m[1]] ?? '', self::emphasis($html));
    }

    /**
     * Marks count only around words: a star or underscore inside a word, or
     * with a space on its inner side, stays as typed.
     */
    private static function emphasis(string $html): string
    {
        $html = preg_replace('/\*\*\*(?=\S)(.+?)(?<=\S)\*\*\*/u', '<strong><em>$1</em></strong>', $html);
        $html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html);
        $html = preg_replace('/(?<![\w*])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![\w*])/u', '<em>$1</em>', $html);

        return preg_replace('/(?<!\w)_(?=[^\s_])(.+?)(?<=[^\s_])_(?!\w)/u', '<em>$1</em>', $html);
    }

    /** Both parts arrive escaped, and the address can only be http or https. */
    private static function anchor(string $url, string $label): string
    {
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer nofollow">' . $label . '</a>';
    }
}
