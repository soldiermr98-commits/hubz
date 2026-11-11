<?php
declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The marker opts only explicitly edited content into the restricted rich format.
 * Unmarked text keeps its historical rendering. Older code strips the marker and
 * tags, so a rollback still shows text rather than an opaque serialized document.
 */
final class ArticleText
{
    public const RICH_MARKER = '<!--hhx-rich-v1-->';
    private const MAX_NODES = 20000;
    private const MAX_DEPTH = 64;

    public static function isRich(string $text): bool
    {
        return str_starts_with($text, self::RICH_MARKER);
    }

    public static function richAvailable(): bool
    {
        return class_exists(DOMDocument::class);
    }

    /** Sanitize only changed rich text; merely editing another field must preserve old content. */
    public static function normalizeInput(string $text, string $previous): string
    {
        if (!self::isRich($text) || $text === $previous) return $text;
        if (!self::richAvailable()) {
            throw new InvalidArgumentException('ویرایش قالب‌بندی‌شده به افزونه DOM در PHP وب نیاز دارد؛ متن قبلی تغییر نکرد.');
        }
        try {
            return self::RICH_MARKER . self::sanitize(substr($text, strlen(self::RICH_MARKER)));
        } catch (Throwable $e) {
            throw new InvalidArgumentException('ساختار متن مقاله قابل پردازش نیست؛ متن را بررسی کنید و دوباره ذخیره کنید.', 0, $e);
        }
    }

    public static function render(string $text): string
    {
        if (self::isRich($text) && self::richAvailable()) {
            try {
                return self::sanitize(substr($text, strlen(self::RICH_MARKER)));
            } catch (Throwable $e) {
                // Fail closed: never emit raw markup, even when the parser fails.
            }
        }
        return self::renderLegacy($text);
    }

    /** Keep the old publication check for legacy text; empty rich markup is not content. */
    public static function hasVisibleText(string $text): bool
    {
        if (!self::isRich($text)) return trim(strip_tags($text)) !== '';
        // A server without DOM may still publish an unchanged, previously stored
        // rich article. Never count the format marker or invisible entities.
        $rendered = self::richAvailable() ? self::render($text) : substr($text, strlen(self::RICH_MARKER));
        $plain = html_entity_decode(strip_tags($rendered), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match('/[^\s\x{00A0}\x{200B}]/u', $plain) === 1;
    }

    /** Relative same-site paths or ordinary HTTP(S) links; never active/script schemes. */
    public static function validHref(string $href): bool
    {
        if ($href === '' || strlen($href) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $href)) return false;
        if ($href[0] === '/') return !str_starts_with($href, '//');
        $parts = parse_url($href);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) return false;
        if (!in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) return false;
        return filter_var($href, FILTER_VALIDATE_URL) !== false;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Existing plain/legacy HTML and Markdown-style heading behavior is unchanged. */
    private static function renderLegacy(string $text): string
    {
        $text = (string)preg_replace('~<br\s*/?>~i', "\n", $text);
        $text = (string)preg_replace('~</(?:p|div|h[1-6]|li)>~i', "\n\n", $text);
        $text = strip_tags($text);
        $lines = preg_split('/\R/u', $text) ?: [];
        $result = ''; $paragraph = [];
        // Match the previous public renderer exactly, including invalid UTF-8 behavior.
        $legacyEscape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $flush = static function () use (&$result, &$paragraph, $legacyEscape): void {
            if ($paragraph) { $result .= '<p>' . nl2br($legacyEscape(implode("\n", $paragraph))) . '</p>'; $paragraph = []; }
        };
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') { $flush(); continue; }
            if (str_starts_with($trimmed, '### ')) { $flush(); $result .= '<h3>' . $legacyEscape(substr($trimmed, 4)) . '</h3>'; }
            elseif (str_starts_with($trimmed, '## ')) { $flush(); $result .= '<h2>' . $legacyEscape(substr($trimmed, 3)) . '</h2>'; }
            else $paragraph[] = $line;
        }
        $flush();
        return $result;
    }

    /** Parse in a network-disabled document and reconstruct only known tags and attributes. */
    private static function sanitize(string $html): string
    {
        if (strlen($html) > 1000000) throw new RuntimeException('Rich text too large.');
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            // Parse the entire document rather than wrapping it in a user-closable
            // element. Otherwise an unmatched </div> or </body> could silently
            // discard everything after it when the author saves the HTML mode.
            $ok = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$ok) throw new RuntimeException('Could not parse rich text.');
            $budget = self::MAX_NODES;
            return self::children($doc, $budget, 0);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function children(DOMNode $node, int &$budget, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) throw new RuntimeException('Rich text nesting is too deep.');
        $result = '';
        foreach ($node->childNodes as $child) {
            if (--$budget < 0) throw new RuntimeException('Rich text has too many nodes.');
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $result .= self::escape((string)$child->nodeValue);
                continue;
            }
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['head','script','style','iframe','object','embed','svg','math','template','form','input','button','textarea','select','meta','link','base'], true)) continue;
            $inner = self::children($child, $budget, $depth + 1);
            $aliases = ['b'=>'strong', 'i'=>'em', 'del'=>'s'];
            $tag = $aliases[$tag] ?? $tag;
            if (!in_array($tag, ['p','h2','h3','h4','strong','em','u','s','blockquote','ul','ol','li','br','a'], true)) {
                $result .= $inner;
                continue;
            }
            if ($tag === 'br') { $result .= '<br>'; continue; }
            if ($tag === 'a') {
                $href = trim($child->getAttribute('href'));
                $result .= self::validHref($href)
                    ? '<a href="' . self::escape($href) . '" rel="noopener noreferrer">' . $inner . '</a>'
                    : $inner;
                continue;
            }
            $attributes = '';
            if (in_array($tag, ['p','h2','h3','h4'], true)) {
                $style = trim($child->getAttribute('style'));
                if (preg_match('/^text-align\s*:\s*(left|right|center|justify)\s*;?$/iD', $style, $match)) {
                    $attributes = ' style="text-align: ' . strtolower($match[1]) . '"';
                }
            } elseif ($tag === 'ol') {
                $start = $child->getAttribute('start');
                if (preg_match('/^[1-9][0-9]{0,4}$/D', $start) && (int)$start !== 1) $attributes = ' start="' . $start . '"';
            }
            $result .= '<' . $tag . $attributes . '>' . $inner . '</' . $tag . '>';
        }
        return $result;
    }
}
