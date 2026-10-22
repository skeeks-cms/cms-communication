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
