<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

/**
 * A sendable that names its visits for Metrika: links of a letter to our
 * sites (services\LinkMarks) carry `utm_campaign=<this mark>` when the
 * letter goes with the document and is not a campaign letter, the same mark
 * as the personal link of the document itself ("proposal-12").
 */
interface SendableMarksInterface
{
    public function getSendUtmCampaign(): string;
}
