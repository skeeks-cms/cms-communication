<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\helpers;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\helpers\RichTextHtml;

/**
 * The own text of a letter, without the quoted history of the conversation:
 * what a task or lead created from the letter is about.
 */
class MailText
{
    /** A line that introduces a quote: "…, Иван <ivan@example.com>:", "On … wrote:", "Иван пишет:". */
    private const QUOTE_HEAD = '/^(.*<[^<>@\s]+@[^<>\s]+>\s*:|.*\b(wrote|писал[аи]?|написал[аи]?|пишет)\s*:|-{2,}\s*(original message|forwarded message|исходное сообщение|пересланное сообщение|пересылаемое сообщение)\s*-*|_{10,})\s*$/iu';

    /** A header line of a quoted or forwarded letter: "Кому: …", "Subject: …", a line of dashes. */
    private const QUOTE_FIELD = '/^((от|кому|копия|тема|дата|отправлено|from|to|cc|subject|date|sent)\s*:.*|-{8,})$/iu';

    public static function withoutQuotes(CmsCommMessage $message): string
    {
        $text = '';
        if (trim((string)$message->body_html) !== '') {
            $text = RichTextHtml::toText(self::stripQuotedHtml((string)$message->body_html));
        }
        if (trim($text) === '') {
            $text = (string)$message->body_text;
        }

        return self::stripQuotedText($text);
    }

    /**
     * A display name as written in the letter: some senders leave MIME
     * encoded words ("=?UTF-8?B?…?=") that the parser kept as they were.
     */
    public static function name(?string $name): string
    {
        $name = trim((string)$name);
        if ($name !== '' && strpos($name, '=?') !== false) {
            $decoded = @iconv_mime_decode($name, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                $name = trim($decoded);
            }
        }

        return $name;
    }

    /** Plain text: cut at the first quote introduction after the own text, drop "> " lines. */
    public static function stripQuotedText(string $text): string
    {
        $lines = preg_split('/\R/u', str_replace("\xc2\xa0", ' ', $text));
        $own = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match(self::QUOTE_HEAD, $trimmed)) {
                // A reply written under the quote starts with its introduction: skip it.
                if (!array_filter($own, static fn($l) => trim($l) !== '')) {
                    continue;
                }
                break;
            }
            if (strpos($trimmed, '>') === 0) {
                continue;
            }
            $own[] = rtrim($line);
        }

        return trim((string)preg_replace("/\n{3,}/", "\n\n", implode("\n", $own)));
    }

    /**
     * Plain text split into the own text and the quoted history ("> " lines,
     * "…написал:", a forwarded letter) for "Показать переписку" in the
     * letter page. Null when there is no quote or nothing before it: then
     * the quote is the letter itself (a bare forward) and stays visible.
     *
     * @return string[]|null [own, quote]
     */
    public static function splitQuotedText(string $text): ?array
    {
        $lines = preg_split('/\R/u', $text);
        $hasOwn = false;
        foreach ($lines as $i => $line) {
            $trimmed = trim(str_replace("\xc2\xa0", ' ', $line));
            $isQuote = strpos($trimmed, '>') === 0 || preg_match(self::QUOTE_HEAD, $trimmed);
            if ($isQuote && $hasOwn) {
                return [rtrim(implode("\n", array_slice($lines, 0, $i))), implode("\n", array_slice($lines, $i))];
            }
            if (!$isQuote && $trimmed !== '') {
                $hasOwn = true;
            }
        }

        return null;
    }

    /**
     * HTML of a letter page with its quoted history folded: the quote of
     * web mail and mail programs (blockquote, gmail_quote, Outlook reply
     * header), a forwarded letter ("---------- Forwarded message") and the
     * line that introduces them go into `div.sx-mail-quote` blocks, hidden
     * until the page shows them. Null when there is no quote or no own text
     * before it (a bare forward is the letter itself).
     */
    public static function foldQuotedHtml(string $html): ?string
    {
        if (!class_exists(\DOMDocument::class) || trim($html) === '') {
            return null;
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="sx-mail-root">'.$html.'</div>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('sx-mail-root');
        if (!$root) {
            return null;
        }

        $start = self::firstQuote($root);
        if (!$start) {
            return null;
        }
        // "22.10.2026, 11:54, Иван <ivan@example.com>:" and the header lines of web mail
        // ("----", "Кому:", "Тема:") right before the quote belong to it.
        for ($head = $start->previousSibling; $head; $head = $head->previousSibling) {
            $text = trim(str_replace("\xc2\xa0", ' ', $head->textContent));
            if ($head->nodeName === 'br' || $text === '') {
                continue;
            }
            if (mb_strlen($text) > 300 || !(preg_match(self::QUOTE_HEAD, $text) || preg_match(self::QUOTE_FIELD, $text))) {
                break;
            }
            $start = $head;
        }
        if (!self::hasTextBefore($root, $start)) {
            return null;
        }

        // Folds the start and everything after it, level by level up to the letter root.
        $node = $start;
        $first = true;
        while ($node && $node !== $root) {
            $parent = $node->parentNode;
            $from = $first ? $node : $node->nextSibling;
            if ($from) {
                $fold = $doc->createElement('div');
                $fold->setAttribute('class', 'sx-mail-quote');
                $parent->insertBefore($fold, $from);
                while ($fold->nextSibling) {
                    $fold->appendChild($fold->nextSibling);
                }
            }
            $first = false;
            $node = $parent;
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        return $result;
    }

    /** The first quote in document order: a quote element or the text line of a forwarded letter. */
    private static function firstQuote(\DOMNode $node): ?\DOMNode
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $class = ' '.preg_replace('/\s+/', ' ', $child->getAttribute('class')).' ';
                // A blockquote is a quote when marked as cited or introduced by "…написал:": letters also use it for design.
                if (($child->nodeName === 'blockquote' && (strtolower($child->getAttribute('type')) === 'cite' || self::isIntroduced($child)))
                    || strpos($class, ' gmail_quote ') !== false || strpos($class, ' moz-cite') !== false
                    || in_array($child->getAttribute('id'), ['divRplyFwdMsg', 'appendonsend'], true)) {
                    return $child;
                }
                if ($found = self::firstQuote($child)) {
                    return $found;
                }
            } elseif ($child instanceof \DOMText
                && preg_match('/^\s*-{2,}\s*(original message|forwarded message|исходное сообщение|пересланное сообщение|пересылаемое сообщение)/iu', $child->textContent)) {
                return $child;
            }
        }

        return null;
    }

    /** Whether the line right before the node introduces a quote ("…, Иван <ivan@example.com>:"). */
    private static function isIntroduced(\DOMNode $node): bool
    {
        for ($prev = $node->previousSibling; $prev; $prev = $prev->previousSibling) {
            $text = trim(str_replace("\xc2\xa0", ' ', $prev->textContent));
            if ($prev->nodeName === 'br' || $text === '') {
                continue;
            }

            return mb_strlen($text) < 300 && (bool)preg_match(self::QUOTE_HEAD, $text);
        }

        return false;
    }

    /** Whether the letter has own text (or a picture) before the node. */
    private static function hasTextBefore(\DOMNode $root, \DOMNode $start): bool
    {
        for ($node = $start; $node && $node !== $root; $node = $node->parentNode) {
            for ($prev = $node->previousSibling; $prev; $prev = $prev->previousSibling) {
                if (trim(str_replace("\xc2\xa0", ' ', $prev->textContent)) !== ''
                    || ($prev instanceof \DOMElement && ($prev->nodeName === 'img' || $prev->getElementsByTagName('img')->length))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** HTML: quoted blocks of web mail and mail programs are removed. */
    public static function stripQuotedHtml(string $html): string
    {
        if (!class_exists(\DOMDocument::class)) {
            return (string)preg_replace('~<blockquote\b.*?</blockquote>~is', '', $html);
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="sx-mail-root">'.$html.'</div>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($doc);
        $quotes = $xpath->query('//blockquote | //*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ")]'
            .' | //*[@id="divRplyFwdMsg"] | //*[@id="appendonsend"] | //*[@data-sx-mail-quote]');
        $remove = [];
        foreach ($quotes as $node) {
            $remove[] = $node;
            // Outlook: the reply header and everything after it is the old letter.
            if ($node instanceof \DOMElement && in_array($node->getAttribute('id'), ['divRplyFwdMsg', 'appendonsend'], true)) {
                for ($next = $node->nextSibling; $next; $next = $next->nextSibling) {
                    $remove[] = $next;
                }
            }
        }
        foreach ($remove as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
        $root = $doc->getElementById('sx-mail-root');
        if (!$root) {
            return $html;
        }
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        return $result;
    }
}
