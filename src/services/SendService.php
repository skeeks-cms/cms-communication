<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\send\SendableInterface;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\send\SendFile;
use skeeks\cms\communication\send\SendRequest;
use skeeks\cms\models\CmsStorageFile;
use skeeks\cms\models\User;
use yii\db\ActiveRecord;

/**
 * "Отправить клиенту": finds a sendable of a package for the employee,
 * offers the client's contacts and the channels, and sends through the
 * chosen channel. The same operation serves the window, "Прикрепить из…"
 * in the letter form (through MailComposeService) and future API, bot and AI
 * callers.
 */
class SendService
{
    /** Host of the link placeholder in the letter editor: reserved, never resolves. */
    public const PLACEHOLDER_HOST = 'sx-send.invalid';

    private User $user;
    private int $siteId;

    public function __construct(User $user, ?int $siteId = null)
    {
        $this->user = $user;
        $this->siteId = $siteId ?? (int)\Yii::$app->skeeks->site->id;
    }

    /** "proposal:12" → ["proposal", 12], or null. */
    public static function parseRef(string $ref): ?array
    {
        return preg_match('/^([a-z][a-z0-9_-]*):(\d+)$/', trim($ref), $m) ? [$m[1], (int)$m[2]] : null;
    }

    /** The sendable when its package is installed and the employee may send it. */
    public function find(string $type, int $id): ?SendableInterface
    {
        $source = \Yii::$app->communication->getSendableSource($type);

        return $source && $id > 0 ? $source->find($id, $this->user) : null;
    }

    public function findRef(string $ref): ?SendableInterface
    {
        $parsed = self::parseRef($ref);

        return $parsed ? $this->find($parsed[0], $parsed[1]) : null;
    }

    /**
     * A request from form values: sendable (type:id), channel, recipient,
     * text, subject, account, record (CrmRecords type:id).
     *
     * @throws SendException when the sendable is unknown or hidden
     */
    public function request(string $ref, array $data = []): SendRequest
    {
        $parsed = self::parseRef($ref);
        $sendable = $parsed ? $this->find($parsed[0], $parsed[1]) : null;
        if (!$sendable) {
            throw new SendException(['sendable' => 'Нечего отправлять: документ не найден или у вас нет к нему доступа.']);
        }
        $request = new SendRequest($parsed[0], $parsed[1], $sendable, $this->user, $this->siteId);
        $request->channel = trim((string)($data['channel'] ?? ''));
        $request->recipient = trim((string)($data['recipient'] ?? ''));
        $request->text = (string)($data['text'] ?? '');
        $request->subject = trim((string)($data['subject'] ?? ''));
        $request->accountId = !empty($data['account']) ? (int)$data['account'] : null;
        $request->record = $this->recordRef((string)($data['record'] ?? ''));
        $request->userIp = \Yii::$app->request instanceof \yii\web\Request ? (string)\Yii::$app->request->userIP : '';

        return $request;
    }

    /** A CRM record by "lead:5" the employee sees, or null. */
    public function recordRef(string $ref): ?ActiveRecord
    {
        $parsed = self::parseRef($ref);

        return $parsed && in_array($parsed[0], CrmRecords::LINKABLE, true) ? CrmRecords::find($parsed[0], $parsed[1], $this->user) : null;
    }

    /** "lead:5" of a CRM record. */
    public static function recordRefOf(?ActiveRecord $record): string
    {
        if (!$record) {
            return '';
        }
        $type = CrmRecords::typeOfCode(method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record));

        return $type ? $type.':'.$record->primaryKey : '';
    }

    /**
     * Records the send belongs to: the card it is sent from, then the
     * sendable's own, only those the employee sees.
     *
     * @return ActiveRecord[]
     */
    public function records(SendableInterface $sendable, ?ActiveRecord $record = null): array
    {
        $result = [];
        foreach (array_merge($record ? [$record] : [], $sendable->getSendRecords()) as $item) {
            if (!$item instanceof ActiveRecord) {
                continue;
            }
            $code = method_exists($item, 'getSkeeksModelCode') ? (string)$item->getSkeeksModelCode() : get_class($item);
            $key = $code.':'.$item->primaryKey;
            if (!isset($result[$key]) && CrmRecords::isVisible($code, (int)$item->primaryKey, $this->user)) {
                $result[$key] = $item;
            }
        }

        return array_values($result);
    }

    /** @return array[] contacts of the client (SendContacts::of()) */
    public function contacts(SendableInterface $sendable, ?ActiveRecord $record = null): array
    {
        return (new SendContacts($this->user))->of($this->records($sendable, $record));
    }

    /**
     * Sends through the channel of the request.
     *
     * @throws SendException
     */
    public function send(SendRequest $request): CmsCommMessage
    {
        $channel = \Yii::$app->communication->getChannel($request->channel);
        if (!$channel) {
            throw new SendException(['channel' => 'Выберите, как отправить.']);
        }
        if ($reason = $channel->unavailableReason($this->user, $this->siteId)) {
            throw new SendException(['channel' => $reason]);
        }
        // From a company or a person card: «Свои сделки» and "edit" send only within their deal (core CrmAccess).
        $record = $request->record;
        $access = \skeeks\cms\crm\CrmAccess::class;
        if (($record instanceof \skeeks\cms\models\CmsCompany || ($record instanceof \skeeks\cms\models\CmsUser && !$record->is_worker))
            && method_exists($access, 'canActOnClient') && !$access::instance()->canActOnClient($this->user, $record)) {
            throw new SendException(['record' => $access::OWN_LEAD_REQUIRED]);
        }
        // From a deal, or a document of a deal: who works on it, a closed one only for who acts on its client.
        if ($error = $this->leadsError($request->sendable, $record)) {
            throw new SendException(['record' => $error]);
        }

        return $channel->send($request);
    }

    /**
     * The first refusal of a new action from the deals of a send — the card
     * it is sent from and the sendable's own records (LeadActions), null when
     * allowed.
     */
    public function leadsError(SendableInterface $sendable, ?ActiveRecord $record = null): ?string
    {
        foreach (array_merge($record ? [$record] : [], $sendable->getSendRecords()) as $item) {
            if ($item instanceof \skeeks\cms\models\CmsLead && ($error = LeadActions::error($this->user, $item))) {
                return $error;
            }
        }

        return null;
    }

    /** Puts the send into the feeds of its CRM records. The caller owns the transaction. */
    public function link(CmsCommMessage $message, SendRequest $request): void
    {
        (new MessageLinker())->link($message, $this->records($request->sendable, $request->record), CmsCommLink::TYPE_CONTEXT, (int)$this->user->id);
    }

    /* The personal link inside a letter */

    /** URL standing for the personal link in the letter editor until the letter is sent. */
    public static function placeholder(string $type, int $id): string
    {
        return 'https://'.self::PLACEHOLDER_HOST.'/'.$type.'/'.$id;
    }

    /** The link as the editor shows it: the employee may move it in the text. */
    public static function editorLink(string $type, int $id, string $label): string
    {
        return '<div><a href="'.htmlspecialchars(self::placeholder($type, $id), ENT_QUOTES, 'UTF-8').'">'
            .htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a></div>';
    }

    /** A button that mail programs draw alike: a table cell with an inline-styled link. */
    public static function buttonHtml(string $url, string $label): string
    {
        $e = static fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:16px 0;border-collapse:separate">'
            .'<tr><td style="border-radius:6px;background:#2563eb">'
            .'<a href="'.$e($url).'" target="_blank" style="display:inline-block;padding:12px 24px;border-radius:6px;'
            .'font-family:Arial,sans-serif;font-size:15px;font-weight:bold;line-height:1.2;color:#ffffff;text-decoration:none">'
            .$e($label).'</a></td></tr></table>';
    }

    /**
     * The placeholder link of a sendable in the letter becomes its button
     * with the personal URL; a letter without it gets the button at the end.
     *
     * @return array{0: string, 1: string} [html, text]
     */
    public static function applyLink(string $html, string $text, string $type, int $id, string $url, string $label): array
    {
        $placeholder = preg_quote(self::placeholder($type, $id), '~');
        $found = false;
        // The block the editor put it into goes with it: a button is a block of its own.
        $html = (string)preg_replace_callback('~(?:<(div|p)\b[^>]*>\s*)?<a\b[^>]*href="'.$placeholder.'/?"[^>]*>(.*?)</a>(?(1)\s*</\1>)~is',
            static function ($m) use (&$found, $url, $label) {
                $found = true;
                $own = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                return self::buttonHtml($url, $own !== '' ? $own : $label);
            }, $html);
        $text = (string)preg_replace('~'.$placeholder.'/?~', $url, $text);
        if (!$found) {
            $html .= self::buttonHtml($url, $label);
            $text = rtrim($text)."\n\n".$label.': '.$url;
        }

        return [$html, $text];
    }

    /** A file of a sendable in the storage, ready to be attached. */
    public static function storeFile(SendFile $file, int $siteId): CmsStorageFile
    {
        if ($file->storageFile) {
            return $file->storageFile;
        }
        $bytes = $file->path && is_readable($file->path) ? file_get_contents($file->path) : false;
        if ($bytes === false) {
            throw new \RuntimeException('Файл «'.$file->name.'» не найден.');
        }

        return MailFiles::storeBytes($bytes, $file->name, $file->type, $siteId);
    }
}
