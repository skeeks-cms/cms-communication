<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mail;

use ZBateson\MailMimeParser\Message;

/**
 * Parses a raw RFC 822 letter into the fields cms-communication stores.
 */
class MailParser
{
    /**
     * @return array message_id, in_reply_to, references (string[]), from_address,
     *   from_name, to (address => name), cc, recipients (all addresses the letter
     *   was delivered to), subject, date (int|null), text, html, attachments
     *   (name, type, size), files (the same parts with content, content_id and
     *   inline: an image inside the text), is_auto
     */
    public function parse(string $raw): array
    {
        $message = Message::from($raw, false);

        $from = $this->addresses($message, 'From');
        $fromAddress = (string)array_key_first($from);
        $to = $this->addresses($message, 'To');
        $cc = $this->addresses($message, 'Cc');
        $recipients = array_keys($to + $cc
            + $this->addresses($message, 'Delivered-To')
            + $this->addresses($message, 'X-Original-To'));

        $date = null;
        $dateHeader = $message->getHeader('Date');
        if ($dateHeader && method_exists($dateHeader, 'getDateTime') && ($dt = $dateHeader->getDateTime())) {
            $date = $dt->getTimestamp();
        }

        $references = [];
        $referencesHeader = $message->getHeader('References');
        if ($referencesHeader && method_exists($referencesHeader, 'getIds')) {
            $references = array_values(array_filter(array_map([$this, 'normalizeId'], $referencesHeader->getIds())));
        }

        $attachments = [];
        $files = [];
        foreach ($message->getAllAttachmentParts() as $part) {
            $stream = $part->getBinaryContentStream();
            $content = $stream ? $stream->getContents() : '';
            $contentId = trim((string)$part->getContentId(), '<> ');
            $type = strtolower((string)$part->getContentType());
            $name = (string)($part->getFilename() ?: '');
            if ($name === '') {
                $name = $contentId !== '' && strpos($type, 'image/') === 0 ? 'картинка.'.substr($type, 6) : 'без имени';
            }
            $files[] = [
                // The same position is found again when the letter is fetched from the mailbox.
                'part_index' => count($files),
                'name' => $name,
                'type' => $type,
                'size' => strlen($content),
                'content' => $content,
                'content_id' => $contentId,
                // An image the text refers to by cid:, not a file to download.
                'inline' => $contentId !== '' && strpos($type, 'image/') === 0
                    && strtolower((string)$part->getContentDisposition()) !== 'attachment',
            ];
        }
        // An image the text shows (cid:) is part of the text even when the sender marked it as an attachment.
        $html = (string)$message->getHtmlContent();
        foreach ($files as &$file) {
            if (!$file['inline'] && $file['content_id'] !== '' && strpos($file['type'], 'image/') === 0
                && stripos($html, 'cid:'.$file['content_id']) !== false) {
                $file['inline'] = true;
            }
        }
        unset($file);
        // The list of attachments (and the paperclip in the list) leaves out images inside the text.
        foreach ($files as $file) {
            if (!$file['inline']) {
                $attachments[] = ['name' => $file['name'], 'type' => $file['type'], 'size' => $file['size']];
            }
        }

        $autoSubmitted = strtolower(trim((string)$message->getHeaderValue('Auto-Submitted')));
        $precedence = strtolower(trim((string)$message->getHeaderValue('Precedence')));

        return [
            'message_id' => $this->normalizeId((string)$message->getHeaderValue('Message-ID')),
            'in_reply_to' => $this->normalizeId((string)$message->getHeaderValue('In-Reply-To')),
            'references' => $references,
            'from_address' => $fromAddress,
            'from_name' => $from[$fromAddress] ?? '',
            'to' => $to,
            'cc' => $cc,
            'recipients' => $recipients,
            'subject' => trim((string)$message->getHeaderValue('Subject')),
            'date' => $date,
            'text' => (string)$message->getTextContent(),
            'html' => (string)$message->getHtmlContent(),
            'attachments' => $attachments,
            'files' => $files,
            'is_auto' => ($autoSubmitted !== '' && $autoSubmitted !== 'no')
                || in_array($precedence, ['bulk', 'list', 'junk'], true)
                || $message->getHeader('List-Unsubscribe') !== null,
        ];
    }

    /** Message-ID without angle brackets and spaces, at most 190 characters. */
    public function normalizeId(?string $id): string
    {
        $id = trim((string)$id);
        if (preg_match('/<([^>]+)>/', $id, $m)) {
            $id = $m[1];
        }

        return mb_substr(trim($id, " <>\t\r\n"), 0, 190);
    }

    /** @return array lowercased address => name */
    private function addresses(Message $message, string $header): array
    {
        $result = [];
        $h = $message->getHeader($header);
        if (!$h || !method_exists($h, 'getAddresses')) {
            return $result;
        }
        foreach ($h->getAddresses() as $address) {
            $email = mb_strtolower(trim((string)$address->getEmail()));
            if ($email !== '' && strpos($email, '@') !== false) {
                $result[$email] = trim((string)$address->getName());
            }
        }

        return $result;
    }
}
