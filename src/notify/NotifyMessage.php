<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\notify;

use yii\helpers\Html;

/**
 * What a notification says outside the CRM: a short heading (site and
 * category), the lines of the entry (the event and those that followed it)
 * and the link to the record in the CRM.
 */
class NotifyMessage
{
    /** "SkeekS CRM · Клиенты и предложения" */
    public string $heading = '';

    /** @var string[] the first is the event, others are what followed it ("+ …") */
    public array $lines = [];

    public string $url = '';

    public bool $urgent = false;

    /** The subject of a letter: the event itself. */
    public function subject(): string
    {
        return ($this->urgent ? 'Срочно: ' : '').($this->lines[0] ?? $this->heading);
    }

    public function text(bool $withUrl = true): string
    {
        $text = $this->heading."\n".implode("\n", $this->lines);

        return $withUrl && $this->url !== '' ? $text."\n".$this->url : $text;
    }

    public function html(): string
    {
        $html = Html::tag('p', Html::encode($this->heading), ['style' => 'color:#777;font-size:13px;margin:0 0 8px']);
        foreach ($this->lines as $i => $line) {
            $html .= Html::tag('p', Html::encode($line), ['style' => $i === 0 ? 'font-size:15px;font-weight:bold;margin:0 0 6px' : 'margin:0 0 4px']);
        }
        if ($this->url !== '') {
            $html .= Html::tag('p', Html::a('Открыть в CRM', $this->url), ['style' => 'margin:12px 0 0']);
        }

        return $html;
    }
}
