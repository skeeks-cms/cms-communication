<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\helpers;

use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\services\MailingConsents;
use skeeks\cms\communication\services\MailingContacts;

/**
 * «Каналы связи» of a client person (core ClientChannelsWidget::EVENT_CHANNELS):
 * the state of emails and phones for mailings (bounces, complaints, opt-out of
 * advertising) and the person's own chats with the site's messenger bots.
 */
class ClientChannels
{
    /**
     * @param \skeeks\cms\widgets\admin\ClientChannelsEvent $event
     */
    public static function fill($event): void
    {
        $site = \Yii::$app->skeeks->site;
        if (!$site || !$event->user) {
            return;
        }
        $siteId = (int)$site->id;

        //Emails and phones: what mailings know about them.
        foreach ($event->channels as $key => $channel) {
            $kind = $channel['kind'] ?? '';
            if ($kind !== 'email' && $kind !== 'phone') {
                continue;
            }
            $contact = MailingContacts::find($siteId, $kind === 'email' ? CmsCommContact::KIND_EMAIL : CmsCommContact::KIND_PHONE, (string)$channel['value']);
            if (!$contact) {
                continue;
            }
            foreach (MailingConsents::state($contact)['suppressions'] as $reason => $suppression) {
                if ($reason === CmsCommSuppression::REASON_BOUNCE) {
                    $event->channels[$key]['state'] = 'danger';
                    $event->channels[$key]['notes'][] = 'письма не доставляются';
                } elseif ($reason === CmsCommSuppression::REASON_COMPLAINT) {
                    $event->channels[$key]['notes'][] = 'пожаловался на рассылку';
                } elseif ($reason === CmsCommSuppression::REASON_ALL) {
                    $event->channels[$key]['notes'][] = 'отписан от рекламы';
                }
            }
        }

        //Messengers: one row per bot channel of the site, linked or not.
        $channels = [];
        $connectionChannels = [];
        $drivers = Bots::drivers();
        foreach (CmsCommConnection::find()->andWhere(['cms_site_id' => $siteId, 'is_active' => 1, 'driver' => array_keys($drivers)])->all() as $connection) {
            $class = $drivers[$connection->driver];
            $channels[$class::channel()] = $class::title();
            $connectionChannels[(int)$connection->id] = $class::channel();
        }
        if (!$channels) {
            return;
        }
        $identities = CmsCommIdentity::find()
            ->andWhere(['cms_site_id' => $siteId, 'cms_user_id' => (int)$event->user->id, 'is_bot' => 0, 'channel' => array_keys($channels)])
            ->orderBy(['last_seen_at' => SORT_DESC])
            ->indexBy('id')
            ->all();

        //A linked profile may come only from a group; a message reaches the person only
        //through their own private chat with an active bot (as ChatIdentities::privateChats(),
        //a private chat is the person's by their messages in it).
        $privates = [];
        if ($identities) {
            $rows = CmsCommMessage::find()->alias('m')
                ->innerJoin(['c' => CmsCommConversation::tableName()], 'c.id = m.cms_comm_conversation_id')
                ->select(['identity' => 'm.cms_comm_identity_id', 'connection' => 'c.cms_comm_connection_id', 'last' => 'MAX(c.last_message_at)'])
                ->andWhere(['m.cms_comm_identity_id' => array_keys($identities), 'c.chat_type' => CmsCommConversation::CHAT_PRIVATE, 'c.cms_comm_connection_id' => array_keys($connectionChannels)])
                ->groupBy(['m.cms_comm_identity_id', 'c.cms_comm_connection_id'])
                ->asArray()->all();
            foreach ($rows as $row) {
                $channel = $connectionChannels[(int)$row['connection']];
                if (!isset($privates[$channel]) || (int)$row['last'] > (int)$privates[$channel]['last']) {
                    $privates[$channel] = $row;
                }
            }
        }
        $byChannel = [];
        foreach ($identities as $identity) {
            $channel = (string)$identity->channel;
            if (isset($privates[$channel])) {
                $identity = $identities[(int)$privates[$channel]['identity']];
            }
            $byChannel[$channel] = $byChannel[$channel] ?? $identity;
        }

        $priority = 300;
        foreach ($channels as $channel => $title) {
            $identity = $byChannel[$channel] ?? null;
            $private = $privates[$channel] ?? null;
            $row = ['kind' => 'messenger', 'icon' => 'chat', 'label' => $title, 'priority' => $priority++];
            if (!$identity) {
                $event->channels['bot:'.$channel] = $row + ['state' => 'off', 'notes' => ['личный чат с ботом не привязан']];
                continue;
            }
            $notes = [];
            if ($identity->is_blocked) {
                $state = 'danger';
                $notes[] = 'остановил или заблокировал бота';
            } elseif ($private) {
                $state = 'ok';
                $notes[] = 'личный чат привязан';
            } else {
                $state = 'warning';
                $notes[] = 'профиль известен по группе, личного чата с ботом нет — лично написать нельзя';
            }
            if ($private && $private['last']) {
                $notes[] = 'последнее сообщение '.ChatUi::shortTime((int)$private['last']);
            }
            $event->channels['bot:'.$channel] = $row + [
                'value' => trim((string)$identity->name) !== '' ? (string)$identity->name : ($identity->username ? '@'.$identity->username : ''),
                'state' => $state,
                'notes' => $notes,
            ];
        }
    }
}
