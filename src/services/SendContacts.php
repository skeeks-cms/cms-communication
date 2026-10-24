<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\chat\ChatAccess;
use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommConversationLink;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\helpers\PhoneHelper;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\User;
use yii\db\ActiveRecord;

/**
 * Contacts of a client for "Отправить клиенту": emails, phones and bot chats of the
 * lead, its company and client, of a company and its people, of a deal's
 * company and client. Only records the employee sees in the CRM
 * (forManager() through CrmRecords).
 */
class SendContacts
{
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    /** A chat of a bot linked to the record (or the private chat of its person) the employee may write to. */
    public const CHAT = 'chat';

    private User $user;

    /** @var array kind:value => contact */
    private array $found = [];

    /** @var array code:id => true */
    private array $seen = [];

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * @param ActiveRecord[] $records the card the window was opened from first, then the sendable's records
     * @return array[] each: kind, value (email in lower case, phone as digits), display, name, record (title of the CRM record), type (CrmRecords type)
     */
    public function of(array $records): array
    {
        $this->found = [];
        $this->seen = [];
        foreach ($records as $record) {
            $this->collect($record, 0);
        }

        return array_values($this->found);
    }

    /** @return array[] contacts of one kind */
    public static function ofKind(array $contacts, string $kind): array
    {
        return array_values(array_filter($contacts, static fn($c) => $c['kind'] === $kind));
    }

    /** Phone as digits with the country code: "8 (999) 123-45-67" → "79991234567"; null — not a phone. */
    public static function normalizePhone(string $value): ?string
    {
        $digits = PhoneHelper::digits($value);
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        } elseif (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '7'.$digits;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    /** "79991234567" → "+7 999 123-45-67" for Russian numbers, otherwise "+<digits>". */
    public static function formatPhone(string $digits): string
    {
        if (preg_match('/^7(\d{3})(\d{3})(\d{2})(\d{2})$/', $digits, $m)) {
            return '+7 '.$m[1].' '.$m[2].'-'.$m[3].'-'.$m[4];
        }

        return '+'.$digits;
    }

    private function collect(?ActiveRecord $record, int $depth): void
    {
        if (!$record || $depth > 1) {
            return;
        }
        $type = $this->typeOf($record);
        if (!$type) {
            return;
        }
        $key = $type.':'.$record->primaryKey;
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;
        $class = CrmRecords::codeOf($type);
        if (!CrmRecords::isVisible($class, (int)$record->primaryKey, $this->user)) {
            return;
        }
        $title = CrmRecords::name($record);
        $this->chats($class, (int)$record->primaryKey, $title, $type);

        if ($record instanceof CmsLead) {
            foreach ($record->emails as $row) {
                $this->add(self::EMAIL, (string)$row->value, (string)$row->name, $title, $type);
            }
            foreach ($record->phones as $row) {
                $this->add(self::PHONE, (string)$row->value, (string)$row->name, $title, $type);
            }
            $this->collect($record->client, $depth + 1);
            $this->collect($record->company, $depth + 1);
        } elseif ($record instanceof CmsCompany) {
            foreach ($record->emails as $row) {
                $this->add(self::EMAIL, (string)$row->value, (string)$row->name, $title, $type);
            }
            foreach ($record->phones as $row) {
                $this->add(self::PHONE, (string)$row->value, (string)$row->name, $title, $type);
            }
            foreach ($record->users as $user) {
                $this->collect($user, $depth + 1);
            }
        } elseif ($record instanceof CmsDeal) {
            // A deal has no contacts of its own: those of its client and company.
            $this->collect($record->user, $depth);
            $this->collect($record->company, $depth);
        } elseif ($record instanceof User) {
            $name = (string)$record->shortDisplayName;
            $emails = $record->cmsUserEmails;
            foreach ($emails as $row) {
                $this->add(self::EMAIL, (string)$row->value, $name, $title, $type);
            }
            if (!$emails && $record->email) {
                $this->add(self::EMAIL, (string)$record->email, $name, $title, $type);
            }
            $phones = $record->cmsUserPhones;
            foreach ($phones as $row) {
                $this->add(self::PHONE, (string)$row->value, $name, $title, $type);
            }
            if (!$phones && $record->phone) {
                $this->add(self::PHONE, (string)$record->phone, $name, $title, $type);
            }
        }
    }

    /**
     * Chats of a record: chats linked to it as a whole, and for a client the
     * private chats of the person linked to them; only those the employee may
     * write to. Value — the chat id, display — "MAX: name of the chat".
     */
    private function chats(string $class, int $id, string $title, string $type): void
    {
        $ids = CmsCommConversationLink::find()->select('cms_comm_conversation_id')->andWhere(['model_code' => $class, 'model_id' => $id])->column();
        if (is_a($class, User::class, true)) {
            $people = CmsCommIdentity::find()->select('id')->andWhere(['cms_user_id' => $id]);
            $ids = array_merge($ids, CmsCommMessage::find()->select('cms_comm_conversation_id')->distinct()->andWhere(['cms_comm_identity_id' => $people])->column());
        }
        if (!$ids) {
            return;
        }
        $access = new ChatAccess($this->user);
        foreach (CmsCommConversation::find()->andWhere(['id' => array_unique($ids)])->andWhere(['not', ['chat_type' => null]])->all() as $chat) {
            if ($chat->bot_status !== CmsCommConversation::BOT_LEFT && $access->canSend($chat)) {
                $this->add(self::CHAT, (string)$chat->id, '', $title, $type, ChatUi::channelName($chat).': '.$chat->asText());
            }
        }
    }

    private function add(string $kind, string $value, string $name, string $record, string $type, ?string $display = null): void
    {
        if ($kind === self::CHAT) {
            $key = $kind.':'.$value;
            $this->found[$key] = $this->found[$key] ?? ['kind' => $kind, 'value' => $value, 'display' => (string)$display, 'name' => '', 'record' => $record, 'type' => $type];
            return;
        }
        $display = trim($value);
        if ($kind === self::EMAIL) {
            $value = mb_strtolower($display);
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return;
            }
        } else {
            $value = (string)self::normalizePhone($display);
            if ($value === '') {
                return;
            }
            $display = self::formatPhone($value);
        }
        $key = $kind.':'.$value;
        if (!isset($this->found[$key])) {
            $this->found[$key] = ['kind' => $kind, 'value' => $value, 'display' => $display, 'name' => trim($name), 'record' => $record, 'type' => $type];
        }
    }

    private function typeOf(ActiveRecord $record): ?string
    {
        $code = method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record);
        $type = CrmRecords::typeOfCode($code);

        return in_array($type, CrmRecords::LINKABLE, true) ? $type : null;
    }
}
