<?php
declare(strict_types=1);

// Pure synthetic content; no filesystem, database, host, or network access.
require dirname(__DIR__) . '/app/Services/ArticleText.php';
use App\Services\ArticleText;

function assertArticleText(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
// Oracle copied from the previous PublicSiteController renderer, for regression.
function originalLegacyRenderer(string $text): string
{
    $text = (string)preg_replace('~<br\s*/?>~i', "\n", $text);
    $text = (string)preg_replace('~</(?:p|div|h[1-6]|li)>~i', "\n\n", $text);
    $text = strip_tags($text);
    $lines = preg_split('/\R/u', $text) ?: [];
    $result = ''; $paragraph = [];
    $escape = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $flush = static function () use (&$result, &$paragraph, $escape): void {
        if ($paragraph) { $result .= '<p>' . nl2br($escape(implode("\n", $paragraph))) . '</p>'; $paragraph = []; }
    };
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') { $flush(); continue; }
        if (str_starts_with($trimmed, '### ')) { $flush(); $result .= '<h3>' . $escape(substr($trimmed, 4)) . '</h3>'; }
        elseif (str_starts_with($trimmed, '## ')) { $flush(); $result .= '<h2>' . $escape(substr($trimmed, 3)) . '</h2>'; }
        else $paragraph[] = $line;
    }
    $flush();
    return $result;
}
foreach (['', "سلام\r\n## تیتر\n\n### زیرتیتر", '<p>متن &amp; <b>قدیمی</b></p><div>ادامه<br>آخر</div>', "a <script>alert(1)</script> b", "bad \x80 bytes"] as $sample) {
    assertArticleText(ArticleText::render($sample) === originalLegacyRenderer($sample), 'Legacy output changed.');
}
assertArticleText(ArticleText::hasVisibleText('&nbsp;'), 'Legacy publication validation must remain unchanged.');
$legacy = "مقدمه قدیمی\n\n## تیتر آزمایشی\nمتن <script>alert(42)</script>";
assertArticleText(ArticleText::normalizeInput($legacy, '') === $legacy, 'Legacy content must not be rewritten.');
$legacyRendered = ArticleText::render($legacy);
assertArticleText(str_contains($legacyRendered, '<h2>تیتر آزمایشی</h2>'), 'Legacy heading must keep its old rendering.');
assertArticleText(!str_contains($legacyRendered, '<script>'), 'Legacy HTML must remain escaped.');
assertArticleText(ArticleText::hasVisibleText($legacy), 'Visible legacy text was lost.');
assertArticleText(!ArticleText::hasVisibleText(''), 'Empty text must not count as content.');
foreach (['javascript:alert(1)', 'data:text/html,evil', '//evil.example/', '/\\evil.example/', "https://good.example/\nLocation: evil", 'https://user:pass@evil.example/'] as $url) {
    assertArticleText(!ArticleText::validHref($url), 'Unsafe URL accepted: ' . $url);
}
foreach (['https://example.test/a?x=1&y=2', 'http://example.test/', '/city/rasht/'] as $url) {
    assertArticleText(ArticleText::validHref($url), 'Valid link rejected: ' . $url);
}
$marked = ArticleText::RICH_MARKER . '<p>متن <strong>پررنگ</strong></p>';
assertArticleText(ArticleText::normalizeInput($marked, $marked) === $marked, 'Unchanged content must remain byte-for-byte intact.');
assertArticleText(!ArticleText::hasVisibleText(ArticleText::RICH_MARKER . '<p><br>&nbsp;</p>'), 'Empty rich text must not count as content, with or without DOM.');

if (!ArticleText::richAvailable()) {
    assertArticleText(!str_contains(ArticleText::render($marked), '<strong>'), 'Fallback must not emit raw markup.');
    try { ArticleText::normalizeInput($marked, ''); throw new RuntimeException('Missing DOM accepted a new rich edit.'); }
    catch (InvalidArgumentException $e) { /* expected, legacy edits still work */ }
    echo "PASS: legacy-safe fallback without the PHP DOM extension.\n";
    exit;
}

$rich = ArticleText::RICH_MARKER . '<h2 style="text-align: center">تیتر فارسی</h2>'
    . '<p onclick="alert(1)">سلام <strong>جهان</strong> <em>آزمون</em> <u>زیرخط</u> '
    . '<a href="https://example.test/a?x=1&amp;y=2" onclick="alert(2)">پیوند امن</a>'
    . '<a href="&#x6a;avascript:alert(3)">پیوند خطرناک</a>'
    . '<img src=x onerror="alert(4)"><svg onload="alert(5)"></svg><script>alert(6)</script></p>'
    . '<ul><li><p>گزینه یک</p></li><li><p>گزینه دو</p></li></ul>'
    . '<p style="text-align:left;background:url(https://evil.example)">متن امن</p>';
$stored = ArticleText::normalizeInput($rich, '');
assertArticleText(ArticleText::isRich($stored), 'Rich marker missing.');
$rendered = ArticleText::render($stored);
foreach (['<h2 style="text-align: center">تیتر فارسی</h2>', '<strong>جهان</strong>', '<em>آزمون</em>', '<u>زیرخط</u>', '<ul>', '<li>', 'href="https://example.test/a?x=1&amp;y=2"'] as $expected) {
    assertArticleText(str_contains($rendered, $expected), 'Safe rich markup missing: ' . $expected);
}
foreach (['<script', '<svg', '<img', 'onclick', 'onerror', 'javascript:', 'background:', 'alert(6)', 'https://evil.example'] as $forbidden) {
    assertArticleText(!str_contains(strtolower($stored . $rendered), strtolower($forbidden)), 'Unsafe content survived: ' . $forbidden);
}
assertArticleText(str_contains($rendered, 'پیوند خطرناک'), 'Unsafe link should retain its text.');
assertArticleText(ArticleText::hasVisibleText($stored), 'Rich visible text was lost.');
assertArticleText(!ArticleText::hasVisibleText(ArticleText::RICH_MARKER . '<p><br>&nbsp;</p>'), 'Empty rich text must not count as content.');
foreach (['<p>اول</p></div><p>دوم</p>', '<p>اول</p></body><p>دوم</p>', '</html>اول<p>دوم</p>'] as $malformed) {
    $repaired = ArticleText::normalizeInput(ArticleText::RICH_MARKER . $malformed, '');
    assertArticleText(str_contains($repaired, 'اول') && str_contains($repaired, 'دوم'), 'Unmatched closing tags silently truncated content.');
}

$nested = ArticleText::RICH_MARKER . str_repeat('<blockquote>', 70) . 'x' . str_repeat('</blockquote>', 70);
try { ArticleText::normalizeInput($nested, ''); throw new RuntimeException('Overly deep rich text was accepted.'); }
catch (InvalidArgumentException $e) { /* expected */ }
assertArticleText(!str_contains(ArticleText::render($nested), '<blockquote>'), 'Parser failure must fall back to escaped text.');
echo "PASS: legacy preservation, safe rich tags and links, sanitization, and fail-closed limits.\n";
