<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommSignature;
use skeeks\cms\helpers\RichTextHtml;

/**
 * The employee's signature of a mailbox ("Мои настройки почты" → «Подписи»)
 * in letters prepared through the API: the same block the letter form puts
 * below the text (mail.js setSignature(): "-- " and the signature), added once.
 * The draft keeps it in its text, so the check, the preview and the letter
 * show the same signature and the check token covers it.
 */
class MailSignatures
{
    /** The signature of the employee for the mailbox, or null when there is none. */
    public static function of(int $userId, int $accountId): ?string
    {
        $html = (string)CmsCommSignature::find()->andWhere(['cms_user_id' => $userId, 'cms_comm_account_id' => $accountId])->select('body_html')->scalar();

        return RichTextHtml::isEmpty($html) ? null : $html;
    }

    /** The block below the text, as the letter form makes it. */
    public static function block(string $signature): string
    {
        return '<div><div>--&nbsp;</div>'.$signature.'</div>';
    }

    /** Is the signature already in the text (compared as text: the editor drops the block's marker). */
    public static function has(string $html, string $signature): bool
    {
        $text = static fn(string $h) => trim((string)preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', RichTextHtml::toText($h))));
        $needle = $text($signature);

        return $needle !== '' && mb_strpos($text($html), $needle) !== false;
    }

    /** The text with the signature below it, once. */
    public static function append(string $html, ?string $signature): string
    {
        return $signature === null || self::has($html, $signature) ? $html : $html.self::block($signature);
    }
}
