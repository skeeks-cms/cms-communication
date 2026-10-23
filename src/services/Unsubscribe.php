<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\models\CmsSite;

/**
 * The opt-out of advertising mailings by the personal link of a contact:
 * `/~unsub/<code>` (code of CmsCommContact, without signing in), with the
 * topic of the letter (`t`) and the letter (`m`) in the address.
 *
 * - The page shows the active topics as checkboxes and "отписаться от всех
 *   рекламных рассылок" (by all channels: every contact of the lead, the
 *   company and the client behind this contact).
 * - RFC 8058: a mail program POSTs `List-Unsubscribe=One-Click` to the same
 *   address — the contact is opted out of the topic of the letter (of all
 *   advertising when the address has no topic) without any page.
 * - A campaign letter has the link where the template has the placeholder
 *   «Отписаться» (PLACEHOLDER) or at the end, and the headers
 *   `List-Unsubscribe` and `List-Unsubscribe-Post` (headers()).
 *
 * Service messages and personal letters are never touched by an opt-out.
 */
class Unsubscribe
{
    /** The link «Отписаться» in a campaign template; becomes the personal link of each recipient. */
    public const PLACEHOLDER = 'https://sx-unsubscribe.invalid/';

    public const ROUTE = 'cmsCommunication/unsubscribe/view';

    /**
     * The personal opt-out link of the contact.
     *
     * @param string|null $baseUrl "https://site.ru"; by default CommunicationComponent::siteUrl(): the address
     *                             of the current web request (a stand links to itself), in jobs
     *                             communication.baseUrl or the main domain of the contact's site
     */
    public static function url(CmsCommContact $contact, ?int $topicId = null, ?int $messageId = null, ?string $baseUrl = null): string
    {
        if ($baseUrl === null) {
            $baseUrl = \Yii::$app->has('communication') ? \Yii::$app->communication->siteUrl((int)$contact->cms_site_id) : '';
        }
        if ($baseUrl === '') {
            $site = CmsSite::findOne((int)$contact->cms_site_id);
            $baseUrl = $site ? (string)$site->url : '';
        }
        $query = array_filter(['t' => $topicId ?: null, 'm' => $messageId ?: null]);

        return rtrim($baseUrl, '/').'/~unsub/'.$contact->code.($query ? '?'.http_build_query($query) : '');
    }

    /** Headers of an advertising letter (RFC 2369, RFC 8058). */
    public static function headers(string $url): array
    {
        return [
            'List-Unsubscribe' => '<'.$url.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /**
     * Puts the personal link into a letter: the placeholder links of the
     * template become it; a letter without one gets a short line at the end.
     *
     * @return string[] [html, text]
     */
    public static function apply(string $html, string $text, string $url): array
    {
        $escaped = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $placed = false;
        $html = (string)preg_replace_callback('~(href\s*=\s*["\'])'.preg_quote(self::PLACEHOLDER, '~').'[^"\']*(["\'])~i', static function ($m) use ($escaped, &$placed) {
            $placed = true;
            return $m[1].$escaped.$m[2];
        }, $html);
        if (!$placed) {
            $html .= '<div style="margin-top:28px;padding-top:12px;border-top:1px solid #e5e7eb;color:#8a8f98;font-size:12px;line-height:1.5">'
                .'Вы получили это письмо, потому что ваш адрес есть в нашей базе клиентов. '
                .'<a href="'.$escaped.'" style="color:#8a8f98">Отписаться от рассылки</a></div>';
        }
        $textPlaced = false;
        $text = (string)preg_replace_callback('~'.preg_quote(self::PLACEHOLDER, '~').'\S*~', static function () use ($url, &$textPlaced) {
            $textPlaced = true;
            return $url;
        }, $text);
        if (!$textPlaced) {
            $text = rtrim($text)."\n\n--\nОтписаться от рассылки: ".$url;
        }

        return [$html, $text];
    }

    /** The contact of a personal link, or null. */
    public static function contactByCode(string $code): ?CmsCommContact
    {
        return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $code) ? CmsCommContact::findOne(['code' => $code]) : null;
    }

    /** The topic of the link if it is a topic of the contact's site. */
    public static function topicOf(CmsCommContact $contact, $topicId): ?CmsCommTopic
    {
        $topicId = (int)$topicId;

        return $topicId > 0 ? CmsCommTopic::findOne(['id' => $topicId, 'cms_site_id' => (int)$contact->cms_site_id]) : null;
    }

    /**
     * The letter of the link, only if it really went to this contact: the
     * address cannot attach someone else's letter to an opt-out.
     */
    public static function messageOf(CmsCommContact $contact, $messageId): ?int
    {
        $messageId = (int)$messageId;
        if ($messageId <= 0 || !($message = CmsCommMessage::findOne($messageId)) || (int)$message->cms_site_id !== (int)$contact->cms_site_id) {
            return null;
        }
        foreach (array_keys($message->getTo()) as $recipient) {
            if (CmsCommContact::normalize((string)$contact->kind, (string)$recipient) === (string)$contact->value) {
                return $messageId;
            }
        }

        return null;
    }

    /**
     * One click in the mail program (RFC 8058): out of the topic of the
     * letter, or of all advertising when the link has no topic.
     */
    public static function oneClick(CmsCommContact $contact, ?CmsCommTopic $topic, ?int $messageId): void
    {
        if ($topic) {
            MailingConsents::unsubscribe($contact, (int)$topic->id, CmsCommContactTopic::SOURCE_ONE_CLICK, null, $messageId);
        } else {
            MailingConsents::suppress($contact, CmsCommSuppression::REASON_ALL, CmsCommContactTopic::SOURCE_ONE_CLICK, null, $messageId);
        }
    }

    /**
     * The opt-out page was saved.
     *
     * @param int[] $keep topics the client left checked
     * @param bool  $all  opt out of all advertising mailings
     * @param bool  $allChannels with $all: every contact of the client
     * @return string what to tell the client
     */
    public static function save(CmsCommContact $contact, array $keep, bool $all, bool $allChannels, ?int $messageId): string
    {
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            if ($all) {
                $contacts = $allChannels ? MailingContacts::siblings($contact) : [$contact];
                foreach ($contacts as $one) {
                    MailingConsents::suppress($one, CmsCommSuppression::REASON_ALL, CmsCommContactTopic::SOURCE_LINK, null,
                        (int)$one->id === (int)$contact->id ? $messageId : null,
                        (int)$one->id === (int)$contact->id ? null : 'Отписка по всем каналам со страницы отписки '.$contact->masked());
                }
                $transaction->commit();

                return $allChannels && count($contacts) > 1
                    ? 'Вы отписаны от всех рекламных рассылок по всем каналам: почте и телефону.'
                    : 'Вы отписаны от всех рекламных рассылок на этот '.($contact->kind === CmsCommContact::KIND_PHONE ? 'номер' : 'адрес').'.';
            }

            $keep = array_map('intval', $keep);
            $state = MailingConsents::state($contact);
            // Checking a topic again is the client's own consent and lifts "отписан от всех".
            if ($keep && isset($state['suppressions'][CmsCommSuppression::REASON_ALL])) {
                MailingConsents::unsuppress($contact, CmsCommSuppression::REASON_ALL);
            }
            foreach (CmsCommTopic::forSite((int)$contact->cms_site_id, true) as $topic) {
                $id = (int)$topic->id;
                $row = $state['topics'][$id] ?? null;
                if (in_array($id, $keep, true)) {
                    if ($row && !$row->isSubscribed()) {
                        MailingConsents::subscribe($contact, $id, CmsCommContactTopic::BASIS_SITE, CmsCommContactTopic::SOURCE_LINK, null,
                            'Снова подписался на странице отписки', true);
                    }
                } else {
                    MailingConsents::unsubscribe($contact, $id, CmsCommContactTopic::SOURCE_LINK, null, $messageId);
                }
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $keep ? 'Настройки рассылок сохранены.' : 'Вы отписаны от всех тем рассылок.';
    }
}
