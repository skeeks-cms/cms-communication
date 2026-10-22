<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\helpers;

/**
 * Prepares letter HTML for display, keeping its own styles like a web mail
 * client does. The result is shown only inside a sandboxed iframe without
 * allow-scripts and allow-forms; this cleaning is a second layer: scripts,
 * active embeds, forms, event handlers, javascript: URLs, refresh/base tags
 * are removed.
 */
class MailHtml
{
    private const REMOVE_TAGS = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'base', 'link', 'noscript'];

    /**
     * @param bool $allowRemote load external images and backgrounds. They are
     *   blocked by default like in mail clients: they tell the sender the letter
     *   was opened, may trigger tracking/confirmation URLs and can hang loading.
     * @param int|null $blocked number of blocked external resources (out)
     * @param array $cidMap content id (lower case) => URL of the letter's own
     *   images: "cid:" pictures inside the text are shown from them
     */
    public static function sanitize(string $html, bool $allowRemote = false, ?int &$blocked = null, array $cidMap = []): string
    {
        $blocked = 0;
        if (trim($html) === '') {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // The charset meta makes libxml read and write UTF-8 instead of numeric entities.
        $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html, LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (self::REMOVE_TAGS as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }
        foreach (iterator_to_array($dom->getElementsByTagName('meta')) as $meta) {
            if (strcasecmp((string)$meta->getAttribute('http-equiv'), 'refresh') === 0) {
                $meta->parentNode->removeChild($meta);
            }
        }

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//@*') as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', (string)$attribute->nodeValue));
            if (strpos($name, 'on') === 0
                || (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href', 'background', 'srcset'], true)
                    && (strpos($value, 'javascript:') === 0 || strpos($value, 'vbscript:') === 0
                        || (strpos($value, 'data:') === 0 && strpos($value, 'data:image/') !== 0)))) {
                $attribute->ownerElement->removeAttributeNode($attribute);
            }
        }
        // Links open outside the letter.
        foreach ($dom->getElementsByTagName('a') as $link) {
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', 'noopener noreferrer');
        }
        // Images carried inside the letter are its own files, not remote ones.
        foreach ($xpath->query('//*[@src or @background]') as $node) {
            foreach (['src', 'background'] as $attribute) {
                $value = trim((string)$node->getAttribute($attribute));
                if (stripos($value, 'cid:') === 0) {
                    $cid = mb_strtolower(trim(rawurldecode(substr($value, 4)), '<> '));
                    $node->setAttribute($attribute, $cidMap[$cid] ?? 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==');
                }
            }
        }

        if (!$allowRemote) {
            $isRemote = static fn(string $url) => (bool)preg_match('~^\s*(https?:)?//~i', $url);
            foreach ($xpath->query('//*[@src or @srcset or @background]') as $node) {
                foreach (['src', 'srcset', 'background'] as $attribute) {
                    if ($node->hasAttribute($attribute) && $isRemote($node->getAttribute($attribute))) {
                        $node->removeAttribute($attribute);
                        $blocked++;
                        // A transparent pixel keeps the layout without a broken-image icon.
                        if ($attribute === 'src' && strtolower($node->nodeName) === 'img') {
                            $node->setAttribute('src', 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==');
                        }
                    }
                }
            }
            // url(...) in style attributes and style blocks.
            $stripUrls = static function (string $css) use (&$blocked): string {
                return (string)preg_replace_callback('~url\(\s*([\'"]?)\s*(?:https?:)?//[^)]*\)~i', static function () use (&$blocked) {
                    $blocked++;
                    return 'none';
                }, $css);
            };
            foreach ($xpath->query('//@style') as $style) {
                $style->value = $stripUrls((string)$style->value);
            }
            foreach ($dom->getElementsByTagName('style') as $style) {
                $style->textContent = $stripUrls((string)$style->textContent);
            }
        }

        return (string)$dom->saveHTML();
    }

    /**
     * Contents of a received letter to quote or forward: active content and
     * the document head removed, only the body kept.
     */
    public static function quoteBody(string $html): string
    {
        $clean = self::sanitize($html, true);
        if (preg_match('~<body[^>]*>(.*)</body>~is', $clean, $m)) {
            $clean = $m[1];
        }

        $clean = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $clean);

        return trim((string)preg_replace('~<meta\b[^>]*>~i', '', (string)$clean));
    }
}
