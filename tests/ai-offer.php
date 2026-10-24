<?php
/**
 * An individual offer (purpose=individual_offer): one letter to one person with
 * its own subject and text, a presentation and a screenshot, without the
 * opt-out line and List-Unsubscribe, while every opt-out, complaint, bounce
 * and repetition still blocks it; advertising and campaigns keep the opt-out.
 * Against a local application with cms-mcp and cms-proposal, in a transaction
 * that is rolled back: AI_OFFER_TEST=local php tests/ai-offer.php. Letters are
 * only queued inside the transaction; the MIME letter is built, never sent.
 * Identities: TEST_ADMIN_ID (1), TEST_OAUTH_CLIENT_ID.
 */
if (getenv('AI_OFFER_TEST') !== 'local') {
    throw new RuntimeException('Set AI_OFFER_TEST=local.');
}
define('ROOT_DIR', getenv('TEST_APP_ROOT') ?: '/app');
define('YII_ENV', 'dev');
define('YII_DEBUG', true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';
$config = new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null, [Yiisoft\Config\Modifier\RecursiveMerge::groups('web', 'web-'.ENV, 'params', 'params-web-'.ENV)], 'params-web-'.ENV);
$c = $config->get($config->has('web-'.ENV) ? 'web-'.ENV : 'web');
$c['components']['request']['scriptFile'] = ROOT_DIR.'/frontend/web/index.php';
$c['components']['request']['scriptUrl'] = '/index.php';
$c['components']['request']['hostInfo'] = getenv('TEST_HOST_INFO') ?: 'http://localhost';
$c['components']['user']['enableSession'] = false;
new yii\web\Application($c);
Yii::$app->errorHandler->unregister();

use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\jobs\MailSendJobHandler;
use skeeks\cms\communication\models\CmsCommDraft;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\AiSend;
use skeeks\cms\communication\services\LetterPurpose;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\communication\services\MailDrafts;
use skeeks\cms\communication\services\MailingConsents;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompanyEmail;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadEmail;
use skeeks\cms\models\CmsStorageFile;
use skeeks\cms\models\CmsUser;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;
use skeeks\cms\oauth2\models\CmsOauthClient;

if (!Yii::$app->has('cmsMcp') || !Yii::$app->communication->getSendableSource('proposal')) {
    echo "SKIP: skeeks/cms-mcp or skeeks/cms-proposal is not installed\n";
    return;
}
$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function rejects(callable $callback, string $needle, $message) {
    try { $callback(); } catch (Throwable $e) {
        if ($needle !== '' && mb_strpos($e->getMessage(), $needle) === false) { throw new RuntimeException($message.': '.$e->getMessage()); }
        ++$GLOBALS['checks']; return;
    }
    throw new RuntimeException($message);
}
function run(string $tool, array $arguments) {
    global $client;
    $token = new CmsOauthAccessToken(['client_id' => (int)$client->id, 'scopes' => json_encode(array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']))]);
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, $token, ['transport' => 'test']);
}
function lead(string $email, int $executor, ?int $companyId = null): CmsLead {
    $lead = new CmsLead(['name' => 'Тест писем '.$email, 'executor_id' => $executor, 'cms_site_id' => (int)Yii::$app->skeeks->site->id, 'cms_company_id' => $companyId]);
    $lead->save(false);
    (new CmsLeadEmail(['cms_lead_id' => (int)$lead->id, 'value' => $email]))->save(false);
    return $lead;
}
function upload(string $bytes, string $name): int {
    $r = run('cms_storage_file_upload', ['base64' => base64_encode($bytes), 'filename' => $name]);
    return (int)$r['id'];
}
function reasons(array $result): array { return array_column($result['problems'] ?? [], 'reason'); }

// A tiny real PNG (a red pixel) and others for the type checks.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
$png2 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

use skeeks\cms\communication\models\CmsCommApiSend;
use skeeks\cms\communication\services\AiActions;
use skeeks\cms\communication\services\MailingPolicy;
use skeeks\cms\communication\services\Unsubscribe;

$db = Yii::$app->db;
$tx = $db->beginTransaction();
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    $client = CmsOauthClient::findOne((int)(getenv('TEST_OAUTH_CLIENT_ID') ?: 0)) ?: CmsOauthClient::find()->orderBy(['id' => SORT_DESC])->one();
    check($admin && $client, 'Local identities and an OAuth client exist');
    Yii::$app->user->setIdentity($admin);
    $accountId = (int)(new MailComposeService($admin, $siteId))->defaultAccountId();
    $suffix = bin2hex(random_bytes(3));
    $topic = new CmsCommTopic(['cms_site_id' => $siteId, 'name' => 'Тест предложений '.$suffix, 'is_active' => 1]);
    $topic->save(false);
    $presentation = run('cms_proposal_presentation_create', ['name' => 'Тест предложений', 'target_url' => 'https://skeeks.com/solutions/offer-'.$suffix, 'confirm' => true]);
    $offer = 'proposal:'.(int)$presentation['presentation']['id'];
    $address = static fn(string $name) => 'test-ai-offer-'.$name.'-'.$suffix.'@skeeks.com';
    $item = static fn(CmsLead $lead, string $subject = 'Карточка товара на вашем сайте') => ['record_type' => 'lead', 'record_id' => (int)$lead->id,
        'subject' => $subject, 'body_html' => '<p>Здравствуйте! Посмотрели карточку товара: вот что можно улучшить.</p><p>{Картинка}</p><p>{Ссылка}</p><p>{Отписаться}</p>'];
    $offerArgs = ['purpose' => 'individual_offer', 'cms_comm_topic_id' => (int)$topic->id, 'offer' => $offer, 'cms_comm_account_id' => $accountId];

    // 1. An individual offer: the screenshot inline and attached, the presentation button; no opt-out line or headers.
    $pngId = upload($png, 'screen.png');
    $lead = lead($address('main'), (int)$admin->id);
    $d = run('cms_comm_draft_create', $offerArgs + ['confirm' => true, 'items' => [$item($lead) + ['attachments' => [['cms_storage_file_id' => $pngId, 'inline' => true], $pngId]]]]);
    $draftId = (int)($d['items'][0]['draft_id'] ?? 0);
    check($draftId > 0 && $d['items'][0]['purpose'] === 'individual_offer', 'The individual offer draft is created: '.json_encode($d['items'], JSON_UNESCAPED_UNICODE));
    $got = run('cms_comm_draft_get', ['draft_id' => $draftId]);
    check($got['purpose'] === 'individual_offer' && $got['unsubscribe'] === ['line' => false, 'headers' => false] && (int)$got['topic']['id'] === (int)$topic->id
        && mb_strpos($got['topic']['role'], 'только проверка') === 0 && $got['attachments']['count'] === 2 && $got['offers'] === [$offer]
        && mb_strpos($got['body_html'], 'Посмотрели карточку') !== false && mb_strpos($got['body_html'], Unsubscribe::PLACEHOLDER) === false,
        'draft_get: the purpose, no opt-out, the topic kept for checks, the files, the offer and the full text: '.json_encode(array_diff_key($got, ['body_html' => 1]), JSON_UNESCAPED_UNICODE));
    $c1 = run('cms_comm_draft_check', ['draft_id' => $draftId]);
    check($c1['ok'] && $c1['purpose']['code'] === 'individual_offer' && $c1['purpose']['opt_out'] === false && $c1['unsubscribe'] === ['line' => false, 'headers' => false]
        && $c1['recipient']['address'] === $address('main') && $c1['sender']['cms_comm_account_id'] === $accountId && $c1['preview']['subject'] === 'Карточка товара на вашем сайте'
        && count($c1['offers']) === 1 && $c1['attachments']['count'] === 2 && mb_strpos($c1['preview']['note'], 'строки «Отписаться» и заголовков отписки не будет') !== false
        && mb_strpos($c1['preview']['html'], '#offer-preview') !== false, 'draft_check shows everything the person approves: '.json_encode($c1['problems'] ?? $c1, JSON_UNESCAPED_UNICODE));

    // 2. Changing the purpose after the check invalidates the token.
    run('cms_comm_draft_update', ['draft_id' => $draftId, 'purpose' => 'advertising', 'confirm' => true]);
    $adv = run('cms_comm_draft_check', ['draft_id' => $draftId]);
    check($adv['ok'] && $adv['check_token'] !== $c1['check_token'] && $adv['unsubscribe'] === ['line' => true, 'headers' => true], 'The purpose and the opt-out are part of the token');
    $stale = run('cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => $c1['check_token'], 'idempotency_key' => 'offer-'.$suffix.'-stale', 'confirm' => true]);
    check(($stale['problems'][0]['reason'] ?? '') === AiSend::REASON_CHANGED, 'A send with the token of the other purpose is refused: changed_after_check');
    run('cms_comm_draft_update', ['draft_id' => $draftId, 'purpose' => 'individual_offer', 'confirm' => true]);
    $c2 = run('cms_comm_draft_check', ['draft_id' => $draftId]);
    check($c2['ok'] && $c2['check_token'] === $c1['check_token'], 'Back to individual_offer: the same token');
    $noConfirm = run('cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => $c2['check_token'], 'idempotency_key' => 'offer-'.$suffix.'-1']);
    check(empty($noConfirm['message']), 'Without confirm nothing is sent');

    // 3. Send: the personal link of the presentation, the screenshot as MIME parts, no opt-out anywhere; the key repeats the same letter.
    $sent = run('cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => $c2['check_token'], 'idempotency_key' => 'offer-'.$suffix.'-1', 'confirm' => true]);
    $messageId = (int)($sent['message']['id'] ?? 0);
    check($sent['state'] === 'queued' && $sent['message']['purpose'] === 'individual_offer' && $sent['message']['opt_out'] === false && count($sent['message']['attachments']) === 2,
        'The offer is queued: '.json_encode($sent, JSON_UNESCAPED_UNICODE));
    $message = CmsCommMessage::findOne($messageId);
    $mime = (new MailSendJobHandler())->buildEmail($message)->toString();
    check(!$message->unsubscribe_url && (int)$message->cms_comm_topic_id === (int)$topic->id, 'The letter keeps its topic inside, without an opt-out link');
    check(mb_strpos((string)$message->body_html, '/~kp/') !== false && mb_strpos((string)$message->body_html, 'offer-'.$suffix) === false, 'The button is the personal link of the presentation, not the page itself');
    check(mb_strpos((string)$message->body_html, 'Отписаться') === false && mb_strpos((string)$message->body_text, 'Отписаться') === false
        && stripos($mime, 'List-Unsubscribe') === false && strpos($mime, '/~unsub/') === false,
        'No opt-out line, link or List-Unsubscribe / List-Unsubscribe-Post headers in the letter');
    check(preg_match('/Content-ID: <[^>]+>/i', $mime) && preg_match('/Content-Disposition: attachment; name=screen\.png/i', $mime)
        && mb_strpos(preg_replace('/\s+/', '', $mime), base64_encode($png)) !== false, 'The screenshot goes inline by cid: and attached');
    check((string)CmsCommApiSend::find()->andWhere(['cms_comm_message_id' => $messageId])->select('purpose')->scalar() === 'individual_offer', 'The journal keeps the purpose');
    check($message->prepared_by === 'ai' && (int)$message->sent_by_user_id === (int)$admin->id, 'Who prepared it (AI) and who sent it are kept');
    $again = run('cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => $c2['check_token'], 'idempotency_key' => 'offer-'.$suffix.'-1', 'confirm' => true]);
    check($again['repeated'] && (int)$again['message']['id'] === $messageId
        && (int)CmsCommMessage::find()->andWhere(['subject' => 'Карточка товара на вашем сайте'])->andWhere(['>=', 'id', $messageId])->count() === 1,
        'The same idempotency_key gives the same letter, no second one');
    $status = run('cms_comm_send_status', ['idempotency_key' => 'offer-'.$suffix.'-1']);
    check($status['message']['opt_out'] === false && $status['message']['purpose'] === 'individual_offer', 'The status tells the purpose and no opt-out');

    // 4. Repetition: a second offer to the same client within recentDays is refused.
    $rep = run('cms_comm_draft_create', $offerArgs + ['items' => [$item($lead, 'Ещё')]]);
    check($rep['items'][0]['status'] === 'refused' && in_array($rep['items'][0]['reason'], [AiActions::REASON_RECENT_LETTER, AiActions::REASON_RECENT_OFFER], true),
        'A repeated offer is refused: '.json_encode($rep['items'][0], JSON_UNESCAPED_UNICODE));

    // 5. Opt-outs still block: the stop list, a complaint, a bounce, the opt-out of the topic.
    foreach (['all' => MailingPolicy::REASON_UNSUBSCRIBED_ALL, 'complaint' => MailingPolicy::REASON_COMPLAINT, 'bounce' => MailingPolicy::REASON_BOUNCE, 'topic' => 'topic'] as $name => $reason) {
        $blocked = lead($address($name), (int)$admin->id);
        $contact = MailingContacts::get($siteId, 'email', $address($name));
        if ($reason === 'topic') {
            MailingConsents::unsubscribe($contact, (int)$topic->id, 'test', (int)$admin->id);
        } else {
            MailingConsents::suppress($contact, $reason, 'test', (int)$admin->id);
        }
        $r = run('cms_comm_draft_create', $offerArgs + ['items' => [$item($blocked)]]);
        check($r['items'][0]['status'] === 'refused', 'Blocked by '.$name.': '.json_encode($r['items'][0], JSON_UNESCAPED_UNICODE));
        check(MailingPolicy::check($siteId, 'email', $address($name), (int)$topic->id)['allowed'] === false, 'The '.$name.' block of the contact stays');
    }
    // A block that came after the draft: the check refuses it.
    $late = lead($address('late'), (int)$admin->id);
    $ld = run('cms_comm_draft_create', $offerArgs + ['confirm' => true, 'items' => [$item($late)]]);
    MailingConsents::suppress(MailingContacts::get($siteId, 'email', $address('late')), MailingPolicy::REASON_COMPLAINT, 'test', (int)$admin->id);
    $lc = run('cms_comm_draft_check', ['draft_id' => (int)$ld['items'][0]['draft_id']]);
    check(!$lc['ok'] && !isset($lc['check_token']), 'A complaint after the draft blocks the send: '.json_encode($lc['problems'], JSON_UNESCAPED_UNICODE));

    // 6. What an individual offer needs: an explicit choice, a topic, an offer.
    $plain = lead($address('plain'), (int)$admin->id);
    $noOffer = run('cms_comm_draft_create', ['purpose' => 'individual_offer', 'cms_comm_topic_id' => (int)$topic->id, 'cms_comm_account_id' => $accountId, 'items' => [$item($plain)]]);
    check($noOffer['items'][0]['status'] === 'refused' && $noOffer['items'][0]['reason'] === LetterPurpose::REASON_PURPOSE, 'Without a presentation or КП it is refused');
    rejects(fn() => run('cms_comm_draft_create', ['purpose' => 'individual_offer', 'offer' => $offer, 'cms_comm_account_id' => $accountId, 'items' => [$item($plain)]]), 'тему', 'Without a topic it is refused');
    $auto = run('cms_comm_draft_create', ['cms_comm_topic_id' => (int)$topic->id, 'offer' => $offer, 'cms_comm_account_id' => $accountId, 'items' => [$item($plain)]]);
    check($auto['items'][0]['purpose'] === 'advertising', 'One recipient, a name and a presentation never choose individual_offer: without purpose it is advertising');
    $old = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$admin->id, 'cms_comm_account_id' => $accountId, 'to_list' => $address('old'), 'subject' => 'x', 'body_html' => '<p>x</p>',
        'context_model_code' => CmsLead::class, 'context_model_id' => (int)$plain->id, 'cms_comm_topic_id' => (int)$topic->id,
        'prepared_by' => 'ai', 'prepared_client' => 'mcp:test', 'prepared_user_id' => (int)$admin->id, 'prepared_at' => time()]);
    $old->save(false);
    check(run('cms_comm_draft_get', ['draft_id' => (int)$old->id])['purpose'] === 'advertising', 'An existing draft without a purpose stays advertising');

    // 7. Advertising keeps the opt-out line and headers.
    $advLead = lead($address('adv'), (int)$admin->id);
    $ad = run('cms_comm_draft_create', ['purpose' => 'advertising'] + $offerArgs + ['confirm' => true, 'items' => [$item($advLead)]]);
    $adId = (int)$ad['items'][0]['draft_id'];
    $ac = run('cms_comm_draft_check', ['draft_id' => $adId]);
    $as = run('cms_comm_draft_send', ['draft_id' => $adId, 'check_token' => $ac['check_token'], 'idempotency_key' => 'offer-'.$suffix.'-adv', 'confirm' => true]);
    $am = CmsCommMessage::findOne((int)$as['message']['id']);
    $amime = (new MailSendJobHandler())->buildEmail($am)->toString();
    check($am->unsubscribe_url && mb_strpos((string)$am->body_html, 'Отписаться') !== false && strpos($amime, 'List-Unsubscribe:') !== false && strpos($amime, 'List-Unsubscribe-Post:') !== false,
        'Advertising keeps the opt-out line and the List-Unsubscribe headers');

    // 8. Campaigns: no individual offer, by the tool or by the letter form.
    rejects(fn() => run('cms_comm_campaign_create', ['name' => 'Тест '.$suffix, 'cms_comm_topic_id' => (int)$topic->id, 'purpose' => 'individual_offer']), 'individual_offer', 'A campaign with individual_offer is refused');
    rejects(fn() => run('cms_comm_campaign_recipient_add', ['cms_comm_campaign_id' => 1, 'purpose' => 'individual_offer', 'items' => [$item($plain)]]), 'individual_offer', 'Campaign recipients with individual_offer are refused');
    $form = new ComposeForm(['service' => new MailComposeService($admin, $siteId)]);
    $form->cms_comm_account_id = $accountId;
    $form->to = $address('camp');
    $form->subject = 'x';
    $form->body_html = '<p>x</p>';
    $form->cms_comm_topic_id = (int)$topic->id;
    $form->cms_comm_campaign_id = 1;
    $form->purpose = LetterPurpose::INDIVIDUAL_OFFER;
    check($form->withOptOut() && !$form->validate(['purpose']) && mb_strpos((string)$form->getFirstError('purpose'), 'кампанией') !== false,
        'A campaign letter always carries the opt-out; individual_offer is an error there');

    // 9. Personal and service keep their rules.
    $p = run('cms_comm_draft_create', ['purpose' => 'personal', 'cms_comm_account_id' => $accountId, 'items' => [$item($plain)]]);
    check($p['items'][0]['status'] === 'refused' && $p['items'][0]['reason'] === LetterPurpose::REASON_NO_DIALOG, 'Personal without a dialog is refused as before');
    $s = run('cms_comm_draft_create', ['purpose' => 'service', 'cms_comm_account_id' => $accountId, 'items' => [$item($plain)]]);
    check($s['items'][0]['status'] === 'refused' && $s['items'][0]['reason'] === LetterPurpose::REASON_NO_COOPERATION, 'Service without cooperation is refused as before');
    rejects(fn() => run('cms_comm_draft_create', ['purpose' => 'personal', 'cms_comm_topic_id' => (int)$topic->id, 'cms_comm_account_id' => $accountId, 'items' => [$item($plain)]]), 'нет темы', 'Personal with a topic is refused as before');

    // 10. The signature of the mailbox from the CRM: once, below the text and the pictures, the same in the check and the letter.
    \skeeks\cms\communication\models\CmsCommSignature::deleteAll(['cms_user_id' => (int)$admin->id, 'cms_comm_account_id' => $accountId]);
    $noSig = lead($address('nosig'), (int)$admin->id);
    $ns = run('cms_comm_draft_create', $offerArgs + ['confirm' => true, 'items' => [$item($noSig)]]);
    $nsc = run('cms_comm_draft_check', ['draft_id' => (int)$ns['items'][0]['draft_id']]);
    check($nsc['signature'] === null && preg_grep('/нет подписи/u', $nsc['warnings']), 'Without a signature in the CRM: none is invented, the check warns');
    (new \skeeks\cms\communication\models\CmsCommSignature(['cms_user_id' => (int)$admin->id, 'cms_comm_account_id' => $accountId,
        'body_html' => '<p>С уважением, Тестовый Менеджер<br>SkeekS, +7 000 000-00-00</p>']))->save(false);
    $accounts = run('cms_comm_account_list', []);
    $listed = array_values(array_filter($accounts['items'], static fn($a) => $a['id'] === $accountId))[0] ?? null;
    check($listed && mb_strpos((string)$listed['signature_html'], 'Тестовый Менеджер') !== false, 'cms_comm_account_list returns the signature of the mailbox');
    $sigLead = lead($address('sig'), (int)$admin->id);
    $png3Id = upload($png2, 'shot.png');
    $sd = run('cms_comm_draft_create', $offerArgs + ['confirm' => true, 'items' => [['record_type' => 'lead', 'record_id' => (int)$sigLead->id, 'subject' => 'С подписью',
        'body_html' => '<p>Текст без места для картинки.</p><p>{Ссылка}</p>', 'attachments' => [['cms_storage_file_id' => $png3Id, 'inline' => true]]]]]);
    $sdId = (int)$sd['items'][0]['draft_id'];
    check(mb_strpos((string)$sd['items'][0]['signature'], 'Тестовый Менеджер') !== false, 'The draft reports the signature it got');
    $body = (string)CmsCommDraft::findOne($sdId)->body_html;
    check(mb_substr_count($body, 'Тестовый Менеджер') === 1 && mb_strpos($body, 'data-sx-upload') < mb_strpos($body, 'Тестовый Менеджер'), 'The signature is added once, the picture stays above it');
    run('cms_comm_draft_update', ['draft_id' => $sdId, 'body_html' => '<p>Новый текст.</p><p>{Ссылка}</p>', 'confirm' => true]);
    $body = (string)CmsCommDraft::findOne($sdId)->body_html;
    check(mb_substr_count($body, 'Тестовый Менеджер') === 1 && mb_strpos($body, 'Новый текст') !== false, 'A new text gets the signature once again');
    run('cms_comm_draft_update', ['draft_id' => $sdId, 'body_html' => $body, 'confirm' => true]);
    check(mb_substr_count((string)CmsCommDraft::findOne($sdId)->body_html, 'Тестовый Менеджер') === 1, 'A text that has the signature does not get a second one');
    $sc = run('cms_comm_draft_check', ['draft_id' => $sdId]);
    check($sc['ok'] && $sc['signature']['in_letter'] === true && mb_strpos($sc['preview']['html'], 'Тестовый Менеджер') !== false && mb_strpos($sc['preview']['text'], 'Тестовый Менеджер') !== false
        && run('cms_comm_draft_get', ['draft_id' => $sdId])['signature']['in_letter'] === true, 'draft_check and draft_get show the signature in the preview: '.json_encode($sc['problems'], JSON_UNESCAPED_UNICODE));
    $ss = run('cms_comm_draft_send', ['draft_id' => $sdId, 'check_token' => $sc['check_token'], 'idempotency_key' => 'offer-'.$suffix.'-sig', 'confirm' => true]);
    $sm = CmsCommMessage::findOne((int)$ss['message']['id']);
    check(mb_substr_count((string)$sm->body_html, 'Тестовый Менеджер') === 1 && mb_substr_count((string)$sm->body_text, 'Тестовый Менеджер') === 1, 'The letter has the same signature, once');

    // 11. "Настройки почты" → «Работа ИИ через API»: the CRM value wins, then communication.aiLimits, then the defaults.
    $config = Yii::$app->communication->aiLimits;
    Yii::$app->communication->aiLimits = ['perCall' => 7, 'recentDays' => 12];
    \skeeks\cms\communication\components\AiApiSettings::reset();
    $sources = AiActions::limitSources(AiActions::LIMITS + LetterPurpose::DAYS);
    check($sources['perCall'] === ['value' => 7, 'source' => 'config'] && $sources['sendPerDay'] === ['value' => 20, 'source' => 'default']
        && $sources['personalDays'] === ['value' => 90, 'source' => 'default'], 'Without CRM values: the configuration, then the defaults');
    $stored = new \skeeks\cms\communication\components\AiApiSettings();
    $stored->override = \skeeks\cms\base\Component::OVERRIDE_DEFAULT;
    $stored->overridePath = [\skeeks\cms\base\Component::OVERRIDE_DEFAULT];
    $stored->refresh();
    $stored->setAttributes(['sendEnabled' => 0, 'sendPerDay' => '3', 'perCall' => '', 'recentDays' => '0', 'personalDays' => '45'], false);
    check($stored->validate() && $stored->save(), 'The settings are saved by the component settings of the site: '.json_encode($stored->getErrors(), JSON_UNESCAPED_UNICODE));
    \skeeks\cms\communication\components\AiApiSettings::reset();
    $read = \skeeks\cms\communication\components\AiApiSettings::forSite($siteId);
    $limits = AiActions::limits();
    check($read['sendEnabled'] === false && $limits['sendPerDay'] === 3 && $limits['perCall'] === 7 && $limits['recentDays'] === 0 && LetterPurpose::days()['personalDays'] === 45,
        'Set in the CRM wins; an empty field keeps the configuration; 0 is a value: '.json_encode($read + ['limits_now' => $limits], JSON_UNESCAPED_UNICODE));
    $bad = new \skeeks\cms\communication\components\AiApiSettings();
    $bad->setAttributes(['perDay' => '-1', 'personalDays' => '0'], false);
    check(!$bad->validate() && $bad->hasErrors('perDay') && $bad->hasErrors('personalDays'), 'Negative limits and zero days of correspondence are refused');
    // The switch off: the check and the send say why and where it is turned on; preparing still works.
    $offLead = lead($address('off'), (int)$admin->id);
    $off = run('cms_comm_draft_create', $offerArgs + ['confirm' => true, 'items' => [$item($offLead)]]);
    check($off['items'][0]['status'] === 'created', 'With sending off, drafts are still prepared');
    $offCheck = run('cms_comm_draft_check', ['draft_id' => (int)$off['items'][0]['draft_id']]);
    check(!$offCheck['ok'] && in_array(AiSend::REASON_SEND_DISABLED, array_column($offCheck['problems'], 'reason'), true)
        && mb_strpos(json_encode($offCheck['problems'], JSON_UNESCAPED_UNICODE), 'Работа ИИ через API') !== false, 'The check refuses: sending through the API is off, and tells where to turn it on');
    $offSend = run('cms_comm_draft_send', ['draft_id' => (int)$off['items'][0]['draft_id'], 'check_token' => str_repeat('a', 64), 'idempotency_key' => 'offer-'.$suffix.'-off', 'confirm' => true]);
    check(in_array(AiSend::REASON_SEND_DISABLED, array_column($offSend['problems'] ?? [], 'reason'), true), 'The send refuses the same way');
    // Zero letters a day: the day limit refuses.
    \skeeks\cms\communication\components\AiApiSettings::reset($siteId, ['sendEnabled' => true, 'limits' => ['sendPerDay' => 0]]);
    $zero = run('cms_comm_draft_check', ['draft_id' => (int)$off['items'][0]['draft_id']]);
    check(in_array(AiSend::REASON_SEND_LIMIT, array_column($zero['problems'], 'reason'), true), 'sendPerDay = 0: nothing leaves through the API');
    Yii::$app->communication->aiLimits = $config;
    \skeeks\cms\communication\components\AiApiSettings::reset();
    // Who changes them: the right of mail management (also mailboxes and their access), administrators by default.
    $right = \skeeks\cms\communication\controllers\AdminCmsCommSettingsController::PERMISSION;
    $am = Yii::$app->authManager;
    check($am->getPermission($right) && $am->hasChild($am->getRole(\skeeks\cms\rbac\CmsManager::ROLE_ADMIN), $am->getPermission($right)),
        'The right of mail management exists and administrators have it (rbac/init)');
    foreach ([\skeeks\cms\communication\controllers\AdminCmsCommSettingsController::class, \skeeks\cms\communication\controllers\AdminCmsCommAccountController::class,
        \skeeks\cms\communication\controllers\AdminCmsCommAccountAccessController::class] as $class) {
        $controller = new $class('test', Yii::$app->getModule('cmsCommunication'));
        check($controller->permissionName === $right, $class.' opens by the right of mail management');
    }
} finally {
    $tx->rollBack();
    \skeeks\cms\communication\components\AiApiSettings::reset();
}
echo "OK: {$checks} checks\n";
