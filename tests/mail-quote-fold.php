<?php
/**
 * Folding the quoted history of a letter (MailText::foldQuotedHtml(),
 * splitQuotedText()): replies of web mail and mail programs fold, a bare
 * forward and letters that use blockquote for design stay as they are.
 *
 * Usage: php tests/mail-quote-fold.php [vendor/autoload.php]
 */

$autoloadCandidates = array_filter([
    $argv[1] ?? null,
    dirname(__DIR__).'/vendor/autoload.php',
    dirname(__DIR__, 3).'/autoload.php',
    '/app/vendor/autoload.php',
]);
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use skeeks\cms\communication\helpers\MailText;

function foldExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function foldVisible(string $html): string
{
    // The text a reader sees while the quote is folded.
    $html = preg_replace('~<div class="sx-mail-quote">.*$~s', '', $html);

    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))));
}

// Yandex web mail: header lines, the introduction and a blockquote.
$yandex = '<div>test</div><div> </div><div>----------------</div><div>Кому: a@example.com;</div><div>Тема: КП;</div>'
    .'<div>23.10.2026, 22:47, "Семенов Александр" &lt;sem@example.com&gt;:</div><blockquote><p>Здравствуйте!</p></blockquote>';
$folded = MailText::foldQuotedHtml($yandex);
foldExpect($folded !== null, 'a Yandex reply folds');
foldExpect(foldVisible($folded) === 'test', 'only the own text stays visible in a Yandex reply: '.foldVisible($folded));

// Gmail: the quote container.
$gmail = '<div dir="ltr">Спасибо, получили.</div><br><div class="gmail_quote"><div class="gmail_attr">On Mon, Ivan wrote:</div><blockquote class="gmail_quote">old</blockquote></div>';
$folded = MailText::foldQuotedHtml($gmail);
foldExpect($folded !== null && foldVisible($folded) === 'Спасибо, получили.', 'a Gmail reply folds');

// A forward with a note: the forwarded letter folds.
$forward = '<div>Александр, добрый день! Просьба доработать экспорт.</div><div><br><div>---------- Forwarded message ---------<br>From: Alexey</div><div>Текст</div></div>';
$folded = MailText::foldQuotedHtml($forward);
foldExpect($folded !== null && foldVisible($folded) === 'Александр, добрый день! Просьба доработать экспорт.', 'a forwarded letter under a note folds: '.($folded ? foldVisible($folded) : 'null'));

// A bare forward is the letter itself.
foldExpect(MailText::foldQuotedHtml('<div>---------- Forwarded message ---------<br>From: Alexey</div><div>Текст</div>') === null, 'a bare forward stays visible');

// Outlook: everything from the reply header on.
$outlook = '<p>Ok</p><div id="appendonsend"></div><hr><div id="divRplyFwdMsg">From: X</div><div>old text</div>';
$folded = MailText::foldQuotedHtml($outlook);
foldExpect($folded !== null && foldVisible($folded) === 'Ok', 'an Outlook reply folds');

// Design blockquotes of a newsletter stay.
foldExpect(MailText::foldQuotedHtml('<h1>Новинки</h1><blockquote>Цитата недели</blockquote><p>Каталог</p>') === null, 'a design blockquote does not fold');
foldExpect(MailText::foldQuotedHtml('<p>Просто письмо</p>') === null, 'a letter without a quote is unchanged');

// Plain text.
$split = MailText::splitQuotedText("Добрый день!\nСогласны.\n\nПн, 22 окт. Иван <ivan@example.com>:\n> старое\n> письмо");
foldExpect($split !== null && $split[0] === "Добрый день!\nСогласны.", 'plain text: the own text');
foldExpect(strpos($split[1], '> старое') !== false && strpos($split[1], 'Иван') !== false, 'plain text: the quote with its introduction');
foldExpect(MailText::splitQuotedText("> только цитата\n> ещё") === null, 'plain text: a bare quote stays visible');
foldExpect(MailText::splitQuotedText("Просто текст") === null, 'plain text without a quote');

echo "OK mail-quote-fold\n";
