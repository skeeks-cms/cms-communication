<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommMessage;
use yii\db\Query;

/**
 * Search over whole conversations. Each letter keeps one normalized line in
 * `cms_comm_message_search`: sender, recipients, subject and the text (taken
 * from HTML when the letter has no plain part), lower case, "ё" as "е". A
 * conversation matches when every word of the query is found in some of its
 * letters; the list then shows the letters of matching conversations.
 *
 * The narrow table keeps the scan cheap: the letter table holds HTML bodies
 * many times larger than the text. The line is written by
 * CmsCommMessage::afterSave() for every writer (receive, send, history).
 */
class MailSearch
{
    public const TABLE = '{{%cms_comm_message_search}}';

    /** Characters of the letter text kept for search. */
    public const TEXT_LIMIT = 20000;

    /** Words of a query; shorter words are ignored (they match nearly everything). */
    public const MAX_TERMS = 5;
    public const MIN_TERM = 2;

    private static ?bool $available = null;

    public static function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace('ё', 'е', $value);

        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }

    /** The searchable line of a letter. */
    public static function text(CmsCommMessage $message): string
    {
        $parts = [(string)$message->from_name, (string)$message->from_address];
        foreach ([$message->getTo(), $message->getCc(), $message->getBcc()] as $list) {
            foreach ($list as $address => $name) {
                $parts[] = (string)$name;
                $parts[] = (string)$address;
            }
        }
        $parts[] = (string)$message->subject;
        $text = (string)$message->body_text;
        if (trim($text) === '' && $message->body_html) {
            $html = preg_replace('~<(style|script|head|title)\b[^>]*>.*?</\1>~is', ' ', (string)$message->body_html);
            $html = preg_replace('~<br\s*/?>|</p>|</div>|</tr>|</li>~i', ' ', (string)$html);
            $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $parts[] = mb_substr($text, 0, self::TEXT_LIMIT);

        return self::normalize(implode(' ', array_filter($parts, static fn($p) => $p !== '')));
    }

    /** Writes the line of a letter (insert or replace). */
    public static function index(CmsCommMessage $message): void
    {
        if (!self::isAvailable()) {
            return;
        }
        \Yii::$app->db->createCommand()->upsert(self::TABLE, [
            'cms_comm_message_id' => (int)$message->id,
            'cms_site_id' => (int)$message->cms_site_id,
            'cms_comm_conversation_id' => (int)$message->cms_comm_conversation_id,
            'search_text' => self::text($message),
        ])->execute();
    }

    /** @return string[] normalized words of a query */
    public static function terms(string $query): array
    {
        $query = self::normalize($query);
        $terms = array_values(array_unique(array_filter(explode(' ', $query), static fn($t) => mb_strlen($t) >= self::MIN_TERM)));
        if (!$terms && $query !== '') {
            $terms = [$query];
        }

        return array_slice($terms, 0, self::MAX_TERMS);
    }

    /** Conversations of the site where some letter contains the word. */
    public static function conversations(string $term, int $siteId): Query
    {
        return (new Query())->select('cms_comm_conversation_id')->from(self::TABLE)
            ->andWhere(['cms_site_id' => $siteId])
            ->andWhere(['like', 'search_text', $term]);
    }

    /** Letters whose line contains the word (to restrict matches to visible letters). */
    public static function messages(string $term, int $siteId): Query
    {
        return (new Query())->select('cms_comm_message_id')->from(self::TABLE)
            ->andWhere(['cms_site_id' => $siteId])
            ->andWhere(['like', 'search_text', $term]);
    }

    private static function isAvailable(): bool
    {
        if (self::$available === null) {
            self::$available = \Yii::$app->db->getTableSchema(self::TABLE, true) !== null;
        }

        return self::$available;
    }
}
