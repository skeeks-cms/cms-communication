<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\models\CmsUser;
use yii\db\ActiveRecord;
use yii\helpers\Html;

/**
 * "Подготовлено ИИ": who prepared a draft, a letter, a campaign or a personal
 * campaign letter. The mark is a plain array — by ("ai"), client
 * ("mcp:<OAuth client>"), user_id (the CRM user the AI client worked for),
 * at, edited (an employee changed the text before it left) — stored in the
 * prepared_* columns of drafts, messages and campaigns and in the text_*
 * columns of campaign recipients.
 *
 * Only the server sets it: AI actions (services\AiActions) from the OAuth
 * token of the running tool, sending from the employee's own draft or from
 * the campaign recipient. Forms and tool arguments never carry it, so it
 * cannot be removed or forged by a field.
 */
class AiMark
{
    public const BY_AI = 'ai';

    /** A mark of AI working through an MCP client for a CRM user. */
    public static function make(string $clientName, int $userId): array
    {
        $clientName = trim($clientName);

        return [
            'by' => self::BY_AI,
            'client' => mb_substr('mcp:'.($clientName !== '' ? $clientName : 'клиент'), 0, 190),
            'user_id' => $userId,
            'at' => time(),
            'edited' => false,
        ];
    }

    /** Writes a mark into the prepared_* columns of a draft, a letter or a campaign. */
    public static function apply(ActiveRecord $model, ?array $mark): void
    {
        if (!$mark || !$model->hasAttribute('prepared_by')) {
            return;
        }
        $model->prepared_by = (string)$mark['by'];
        $model->prepared_client = (string)$mark['client'];
        $model->prepared_user_id = $mark['user_id'] ? (int)$mark['user_id'] : null;
        $model->prepared_at = (int)($mark['at'] ?? time());
        if ($model->hasAttribute('prepared_edited')) {
            $model->prepared_edited = (int)!empty($mark['edited']);
        }
    }

    /** The mark of a draft, a letter or a campaign, or null when an employee prepared it. */
    public static function of(?ActiveRecord $model): ?array
    {
        if (!$model || !$model->hasAttribute('prepared_by') || $model->prepared_by !== self::BY_AI) {
            return null;
        }

        return [
            'by' => self::BY_AI,
            'client' => (string)$model->prepared_client,
            'user_id' => $model->prepared_user_id ? (int)$model->prepared_user_id : null,
            'at' => (int)$model->prepared_at,
            'edited' => $model->hasAttribute('prepared_edited') && (bool)$model->prepared_edited,
        ];
    }

    /** The mark of a personal campaign letter written by AI, or null. */
    public static function ofRecipient(?CmsCommCampaignRecipient $recipient): ?array
    {
        if (!$recipient || $recipient->text_by !== CmsCommCampaignRecipient::TEXT_BY_AI || !$recipient->getHasPersonalText()) {
            return null;
        }

        return [
            'by' => self::BY_AI,
            'client' => (string)$recipient->text_client,
            'user_id' => $recipient->text_user_id ? (int)$recipient->text_user_id : null,
            'at' => (int)$recipient->text_at,
            'edited' => (bool)$recipient->text_edited_by,
        ];
    }

    /** Fingerprint of a subject and text, to tell whether an employee changed what AI wrote. */
    public static function hash(string $subject, string $html): string
    {
        $text = preg_replace('/\s+/u', ' ', trim(\skeeks\cms\helpers\RichTextHtml::toText($html)));

        return hash('sha256', trim($subject)."\n".$text);
    }

    /** "Claude Code (skeeks)" of "mcp:Claude Code (skeeks)". */
    public static function clientName(string $client): string
    {
        return strpos($client, 'mcp:') === 0 ? substr($client, 4) : $client;
    }

    /**
     * "Подготовлено ИИ (Claude Code) по запросу Семенов А. · изменено перед отправкой".
     *
     * @param int|null $sentBy the employee who sent the letter, when it left
     */
    public static function text(array $mark, ?int $sentBy = null): string
    {
        $parts = ['Подготовлено ИИ'];
        $client = self::clientName((string)$mark['client']);
        if ($client !== '' && $client !== 'клиент') {
            $parts[0] .= ' ('.$client.')';
        }
        if (!empty($mark['user_id']) && ($user = CmsUser::findOne((int)$mark['user_id']))) {
            $parts[0] .= ' по запросу '.$user->shortDisplayName;
        }
        if (!empty($mark['edited'])) {
            $parts[] = 'текст изменён сотрудником';
        }
        if ($sentBy && ($sender = CmsUser::findOne($sentBy))) {
            $parts[] = 'отправил '.$sender->shortDisplayName;
        }

        return implode(' · ', $parts);
    }

    /** The mark as a small label for lists, feeds and letter pages; the details are in its title. */
    public static function badge(?array $mark, ?int $sentBy = null, string $label = 'ИИ'): string
    {
        if (!$mark) {
            return '';
        }

        return Html::tag('span', Html::encode($label), [
            'class' => 'sx-status sx-status--accent sx-ai-mark',
            'title' => self::text($mark, $sentBy),
        ]);
    }

    /** The details in the API: by, client (the OAuth client name), user_id, at (unix), edited. */
    public static function forApi(?array $mark): ?array
    {
        return $mark ? [
            'by' => 'ai',
            'client' => self::clientName((string)$mark['client']),
            'user_id' => $mark['user_id'],
            'at' => (int)$mark['at'],
            'edited' => (bool)$mark['edited'],
        ] : null;
    }
}
