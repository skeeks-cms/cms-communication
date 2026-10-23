<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

/**
 * A sendable whose introduction has formatting (bold, links, lists): a
 * letter starts with this HTML instead of the plain getSendText(). Channels
 * without formatting (SMS, later messengers) keep using getSendText(), so
 * return the same text there without tags (RichTextHtml::toText()).
 */
interface SendableHtmlInterface
{
    /** HTML of the introduction; the letter cleans it to its own preset. Empty — use getSendText(). */
    public function getSendHtml(): string;
}
