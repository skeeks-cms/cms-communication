<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

/**
 * Link checks of mail services and security systems that open a personal
 * link in a real browser (Outlook Safe Links, Proofpoint, Yandex and Mail.ru
 * antispam and the like): their opening must not look like the client. The
 * page counts a view only by its own signal after it was shown (robots
 * without scripts never send it); this class tells which signals still look
 * like a check:
 * - early: seconds after the message actually left (a person needs longer
 *   to get a letter and press the button);
 * - network: networks of mail services and security scanners
 *   (NETWORKS + `communication.linkCheckNetworks`). Public clouds are not
 *   listed on purpose: people use VPNs there;
 * - browser: an automated browser (`navigator.webdriver`, no languages, a
 *   window of zero size) or a known User-Agent of link previews.
 *
 * A package keeps such an opening in its history with the reason, writes
 * "возможно, проверка ссылок" to the feed, and gives it no status, no
 * notification and no task.
 */
class LinkChecks
{
    public const REASON_EARLY = 'early';
    public const REASON_NETWORK = 'network';
    public const REASON_BROWSER = 'browser';

    /** An opening this soon after the message left is a check. */
    public const EARLY_SECONDS = 30;

    /**
     * Networks of mail services and security systems that check links in
     * letters. Not public clouds: a client behind a VPN is a client.
     */
    public const NETWORKS = [
        // Microsoft Exchange Online Protection (Outlook, Safe Links).
        '40.92.0.0/15', '40.107.0.0/16', '52.100.0.0/14', '104.47.0.0/17',
        // Google mail and its proxy.
        '64.233.160.0/19', '66.102.0.0/20', '66.249.64.0/19', '72.14.192.0/18', '209.85.128.0/17',
        // Yandex mail and robots.
        '5.45.192.0/18', '5.255.192.0/18', '77.88.0.0/18', '87.250.224.0/19', '95.108.128.0/17', '141.8.128.0/18', '213.180.192.0/19',
        // Mail.ru mail.
        '94.100.176.0/20', '217.69.128.0/20', '185.5.136.0/22',
        // Proofpoint, Mimecast, Barracuda.
        '148.163.128.0/19', '67.231.144.0/20', '205.220.160.0/19',
        '205.139.110.0/24', '207.211.30.0/24', '91.220.42.0/24',
        '64.235.144.0/20', '209.222.80.0/21',
    ];

    /**
     * Robots that open links for previews and checks, matched case-insensitively
     * against the User-Agent; an empty User-Agent is a robot too. Kept in the
     * history as robots and never counted (pages of offers, visits by links
     * of letters). Not broadly "yandex" or "bot": the Yandex app and Cubot
     * phones are people.
     */
    public const ROBOT_PATTERNS = [
        // "Cubot" is a phone, not a robot.
        '(?<!cu)bot\b', 'bot/', '_bot', '-bot', 'crawl', 'spider', 'slurp', 'preview', 'scanner', 'checker', 'monitor',
        'telegram', 'whatsapp', 'viber', 'vkshare', 'skypeuripreview', 'facebookexternalhit', 'meta-externalagent',
        'yandex(?!search|browser)', 'mail\.ru', '\bmax(?:bot|messenger)?/',
        'kaspersky', 'drweb', 'safebrowsing', 'safelinks', 'proofpoint', 'mimecast', 'barracuda',
        'curl', 'wget', 'httpie', 'python', 'java/', 'okhttp', 'go-http-client', 'libwww', 'axios', 'node-fetch', 'guzzle', 'php/',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'lighthouse', 'pagespeed',
    ];

    public static function isRobot(?string $userAgent): bool
    {
        $userAgent = trim((string)$userAgent);
        if ($userAgent === '') {
            return true;
        }

        return (bool)preg_match('~'.implode('|', self::ROBOT_PATTERNS).'~i', $userAgent);
    }

    /** User-Agents of link previews that run scripts in a browser. Case-insensitive. */
    public const USER_AGENTS = ['bingpreview', 'msoffice', 'ms-office', 'microsoft office', 'zscaler', 'forcepoint', 'symantec', 'trendmicro', 'sophos'];

    public static function reasonNames(): array
    {
        return [
            self::REASON_EARLY => 'открыто через несколько секунд после отправки',
            self::REASON_NETWORK => 'адрес почтовой службы или защитной системы',
            self::REASON_BROWSER => 'автоматический браузер',
        ];
    }

    public static function reasonName(?string $reason): string
    {
        return (string)(self::reasonNames()[(string)$reason] ?? $reason);
    }

    /**
     * Why the signal of a page looks like a link check, or null for a person.
     *
     * @param int   $sentAt  when the message actually left (or was created)
     * @param array $signals from the page: `webdriver` (1), `languages` (count), `width`, `height` (of the window)
     */
    public static function reason(int $sentAt, string $ip, string $userAgent, array $signals = [], ?int $now = null): ?string
    {
        $now = $now ?? time();
        if ($sentAt > 0 && $now - $sentAt < self::EARLY_SECONDS) {
            return self::REASON_EARLY;
        }
        if ($ip !== '' && self::inNetworks($ip, self::networks())) {
            return self::REASON_NETWORK;
        }
        if (!empty($signals['webdriver'])
            || (array_key_exists('languages', $signals) && (string)$signals['languages'] === '0')
            || (array_key_exists('width', $signals) && (string)$signals['width'] === '0')
            || (array_key_exists('height', $signals) && (string)$signals['height'] === '0')) {
            return self::REASON_BROWSER;
        }
        $userAgent = mb_strtolower($userAgent);
        foreach (self::USER_AGENTS as $needle) {
            if (str_contains($userAgent, $needle)) {
                return self::REASON_BROWSER;
            }
        }

        return null;
    }

    /** The built-in networks and those of the site configuration. */
    public static function networks(): array
    {
        $extra = \Yii::$app->has('communication') ? (array)\Yii::$app->communication->linkCheckNetworks : [];

        return array_merge(self::NETWORKS, $extra);
    }

    /** Is the IPv4 or IPv6 address inside one of the CIDR networks. */
    public static function inNetworks(string $ip, array $networks): bool
    {
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }
        foreach ($networks as $network) {
            [$base, $bits] = array_pad(explode('/', (string)$network, 2), 2, null);
            $subnet = @inet_pton((string)$base);
            if ($subnet === false || strlen($subnet) !== strlen($address)) {
                continue;
            }
            $bits = $bits === null ? strlen($address) * 8 : (int)$bits;
            $bytes = intdiv($bits, 8);
            if (strncmp($address, $subnet, $bytes) !== 0) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($address[$bytes]) & $mask) === (ord($subnet[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
