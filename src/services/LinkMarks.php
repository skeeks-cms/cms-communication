<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\components\LinkTrackingSettings;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTrack;
use yii\db\ActiveRecord;

/**
 * Marks of links to our sites in a letter that leaves the CRM (step F).
 * MailComposeService::queue(), the one point every letter passes (personal,
 * reply, forward, "Отправить клиенту", campaign, AI draft), adds to each
 * link to our domains (LinkTrackingSettings) the missing `utm_source=crm`,
 * `utm_medium=<channel>`, `utm_campaign=<campaign-N | proposal-N | personal>`
 * and the personal code of the recipient `sx=<code>` (CmsCommTrack). The
 * text the employee wrote is marked before the quote or the forwarded
 * letter is added, so earlier letters keep their links and codes.
 *
 * Never touched: marks already in the link (any case), links that already
 * carry a code, links to other sites, `mailto:`, `tel:`, anchors, personal
 * pages of our sites (`/~kp/…`, `/~unsub/…`: paths starting with `/~`).
 * There is no redirect: the link leads straight to the page. The saved
 * copy of the letter keeps what left; the editor and drafts keep the
 * original links.
 */
class LinkMarks
{
    public const CODE_PARAM = 'sx';
    public const UTM_SOURCE = 'crm';
    public const UTM_PERSONAL = 'personal';
    /** The code in a link: 16 url-safe characters (12 random bytes). */
    public const CODE_PATTERN = '[A-Za-z0-9_-]{16}';

    /** "campaign-12" for a letter of a mailing campaign, the offer's own mark, otherwise "personal". */
    public static function utmCampaign(?int $campaignId, ?string $offerCampaign = null): string
    {
        if ($campaignId) {
            return 'campaign-'.$campaignId;
        }

        return $offerCampaign !== null && $offerCampaign !== '' ? $offerCampaign : self::UTM_PERSONAL;
    }

    public static function newCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    }

    /**
     * Adds the parameters the address does not have yet (names compared in
     * any case); the fragment stays at the end. Shared with the transition of
     * presentations (skeeks/cms-proposal PresentationLinks).
     */
    public static function addParams(string $url, array $params): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $existing = [];
        foreach (explode('&', (string)parse_url($url, PHP_URL_QUERY)) as $pair) {
            if ($pair !== '') {
                $existing[strtolower(rawurldecode(explode('=', $pair, 2)[0]))] = true;
            }
        }
        $add = [];
        foreach ($params as $key => $value) {
            if ((string)$value !== '' && !isset($existing[strtolower((string)$key)])) {
                $add[$key] = (string)$value;
            }
        }
        if ($add) {
            $url .= (str_contains($url, '?') ? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&') : '?').http_build_query($add, '', '&', PHP_QUERY_RFC3986);
        }

        return $url.$fragment;
    }

    /** Removes one parameter (any case) from the address, keeping the rest and the fragment. */
    public static function removeParam(string $url, string $name): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        if (($q = strpos($url, '?')) === false) {
            return $url.$fragment;
        }
        $pairs = array_filter(explode('&', substr($url, $q + 1)), static function ($pair) use ($name) {
            return $pair !== '' && strtolower(rawurldecode(explode('=', $pair, 2)[0])) !== strtolower($name);
        });

        return substr($url, 0, $q).($pairs ? '?'.implode('&', $pairs) : '').$fragment;
    }

    /** Is the host one of our domains or their subdomains. */
    public static function isOurHost(string $host, array $domains): bool
    {
        $host = rtrim(mb_strtolower(trim($host)), '.');
        if ($host === '') {
            return false;
        }
        foreach ($domains as $domain) {
            $domain = (string)$domain;
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }

    /** A link that gets marks: http(s) to our domain, not a personal page, not marked with a code yet. */
    public static function isMarkable(string $url, array $domains): bool
    {
        if (!preg_match('~^https?://~i', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !self::isOurHost((string)$parts['host'], $domains)) {
            return false;
        }
        if (str_starts_with((string)($parts['path'] ?? ''), '/~')) {
            return false;
        }

        return !self::hasCode($url);
    }

    /** Does the address already carry a personal code (`sx=`, any case). */
    public static function hasCode(string $url): bool
    {
        $query = (string)parse_url(explode('#', $url, 2)[0], PHP_URL_QUERY);
        foreach (explode('&', $query) as $pair) {
            if ($pair !== '' && strtolower(rawurldecode(explode('=', $pair, 2)[0])) === self::CODE_PARAM) {
                return true;
            }
        }

        return false;
    }

    /**
     * Marks the links of the HTML (`href` of `<a>` and `<area>`); the shown
     * text of a link stays as the employee wrote it.
     */
    public static function markHtml(string $html, array $params, array $domains, int &$count = 0): string
    {
        if ($html === '' || stripos($html, 'href') === false) {
            return $html;
        }

        return (string)preg_replace_callback('~<(?:a|area)\b[^>]*>~i', static function ($tag) use ($params, $domains, &$count) {
            return (string)preg_replace_callback('~(\bhref\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i', static function ($m) use ($params, $domains, &$count) {
                $raw = $m[2] !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
                $url = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!self::isMarkable($url, $domains)) {
                    return $m[0];
                }
                $count++;

                return $m[1].'"'.htmlspecialchars(self::addParams($url, $params), ENT_QUOTES, 'UTF-8').'"';
            }, $tag[0]);
        }, $html);
    }

    /** Marks the addresses in the plain text of the letter; a dot or a bracket after an address is not part of it. */
    public static function markText(string $text, array $params, array $domains, int &$count = 0): string
    {
        if ($text === '' || stripos($text, 'http') === false) {
            return $text;
        }

        return (string)preg_replace_callback('~https?://[^\s<>"\'\[\]]+~iu', static function ($m) use ($params, $domains, &$count) {
            $url = $m[0];
            $tail = '';
            while ($url !== '' && preg_match('~[.,;:!?)»]$~u', $url)) {
                $tail = mb_substr($url, -1).$tail;
                $url = mb_substr($url, 0, -1);
            }
            if (!self::isMarkable($url, $domains)) {
                return $m[0];
            }
            $count++;

            return self::addParams($url, $params).$tail;
        }, $text);
    }

    /**
     * Marks the text of a letter before it is queued. Nothing when marking is
     * off for the site or the letter has no links to our sites.
     *
     * @return array|null html, text, code, utm_campaign, domains
     */
    public static function prepare(int $siteId, string $html, string $text, string $channel, ?int $campaignId = null, ?string $offerCampaign = null): ?array
    {
        $settings = LinkTrackingSettings::forSite($siteId);
        if (!$settings['enabled'] || !$settings['domains']) {
            return null;
        }
        $code = self::newCode();
        $campaign = self::utmCampaign($campaignId, $offerCampaign);
        $params = [
            'utm_source' => self::UTM_SOURCE,
            'utm_medium' => $channel,
            'utm_campaign' => $campaign,
            self::CODE_PARAM => $code,
        ];
        $count = 0;
        $html = self::markHtml($html, $params, $settings['domains'], $count);
        $text = self::markText($text, $params, $settings['domains'], $count);
        if (!$count) {
            return null;
        }

        return ['html' => $html, 'text' => $text, 'code' => $code, 'utm_campaign' => $campaign, 'domains' => $settings['domains']];
    }

    /** The code of a marked letter, written with the letter in its transaction. */
    public static function store(array $prepared, CmsCommMessage $message, ?ActiveRecord $record, ?string $recipient, ?string $offerRef = null): CmsCommTrack
    {
        $track = new CmsCommTrack([
            'cms_site_id' => (int)$message->cms_site_id,
            'code' => (string)$prepared['code'],
            'cms_comm_message_id' => (int)$message->id,
            'channel' => (string)$message->channel,
            'recipient' => $recipient !== null && $recipient !== '' ? mb_substr($recipient, 0, 255) : null,
            'model_code' => $record ? get_class($record) : null,
            'model_id' => $record ? (int)$record->primaryKey : null,
            'cms_comm_campaign_id' => $message->hasAttribute('cms_comm_campaign_id') && $message->cms_comm_campaign_id ? (int)$message->cms_comm_campaign_id : null,
            'offer_ref' => $offerRef !== null ? mb_substr($offerRef, 0, 64) : null,
            'utm_campaign' => (string)$prepared['utm_campaign'],
        ]);
        if (!$track->save(false)) {
            throw new \RuntimeException('Не удалось сохранить код ссылок письма.');
        }

        return $track;
    }
}
