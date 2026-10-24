<?php
/**
 * Letters prepared by AI clients, part 2: files of drafts (an attachment and a
 * picture inside the text, from cms_storage_file_upload), the purpose of a
 * letter (advertising, personal, service), the test mode for server-listed
 * test recipients, and the check token covering all of them. Against a local
 * application with cms-mcp and cms-proposal, in a transaction that is rolled
 * back: AI_LETTERS_TEST=local php tests/ai-letters.php. Letters are only
 * queued inside the transaction; the MIME letter is built, never sent.
 * Identities: TEST_ADMIN_ID (1), TEST_WORKER_ID (1663), TEST_OAUTH_CLIENT_ID.
 */
if (getenv('AI_LETTERS_TEST') !== 'local') {
    throw new RuntimeException('Set AI_LETTERS_TEST=local.');
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
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

$db = Yii::$app->db;
$tx = $db->beginTransaction();
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    $worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
    $client = CmsOauthClient::findOne((int)(getenv('TEST_OAUTH_CLIENT_ID') ?: 0)) ?: CmsOauthClient::find()->orderBy(['id' => SORT_DESC])->one();
    check($admin && $worker && $client, 'Local identities and an OAuth client exist');
    Yii::$app->user->setIdentity($admin);
    $accountId = (int)(new MailComposeService($admin, $siteId))->defaultAccountId();
    $suffix = bin2hex(random_bytes(3));
    $topic = new CmsCommTopic(['cms_site_id' => $siteId, 'name' => 'Тест писем '.$suffix, 'is_active' => 1]);
    $topic->save(false);
    $presentation = run('cms_proposal_presentation_create', ['name' => 'Тест писем', 'target_url' => 'https://skeeks.com/solutions/plitka-letters-'.$suffix, 'confirm' => true]);
    $offer = 'proposal:'.(int)$presentation['presentation']['id'];

    // A test company of its own: the server list holds it and one exact address.
    $company = new CmsCompany(['name' => 'Тест писем '.$suffix]);
    $company->save(false);
    (new CmsCompanyEmail(['cms_company_id' => (int)$company->id, 'value' => 'test-ai-letters-'.$suffix.'@skeeks.com']))->save(false);
    (new CmsCompanyEmail(['cms_company_id' => (int)$company->id, 'value' => 'test-ai-letters-other-'.$suffix.'@skeeks.com']))->save(false);
    $testAddress = 'test-ai-letters-'.$suffix.'@skeeks.com';
    Yii::$app->communication->aiTestRecipients = ['company:'.$company->id => [$testAddress]];

    // А. A screenshot: an uploaded PNG as a picture in the text and as an attachment; types by content; ownership.
    $pngId = upload($png, 'screen.png');
    $svgId = upload($svg, 'logo.png');
    // No server paths, no local networks, no scripts in the public storage.
    foreach (['/app/composer.json', 'composer.json', 'file:///etc/hostname', 'http://127.0.0.1/', 'http://localhost:8080/', 'http://10.0.0.1/x.png', 'ftp://example.com/x.png'] as $bad) {
        rejects(fn() => run('cms_storage_file_upload', ['source_url' => $bad]), 'source_url', 'The upload refuses '.$bad);
    }
    check(is_file('/app/composer.json'), 'A refused path stays where it was');
    rejects(fn() => run('cms_storage_file_upload', ['base64' => base64_encode('<?php echo 1;'), 'filename' => 'x.php']), '.php', 'A script is not stored');
    $draft = run('cms_comm_draft_create', ['purpose' => 'advertising', 'cms_comm_topic_id' => (int)$topic->id, 'offer' => $offer, 'cms_comm_account_id' => $accountId, 'confirm' => true,
        'items' => [['record_type' => 'company', 'record_id' => (int)$company->id, 'address' => $testAddress, 'subject' => 'Скриншот вашего каталога',
            'body_html' => '<p>Посмотрите на это место:</p><p>{Картинка}</p><p>{Ссылка}</p>',
            'attachments' => [['cms_storage_file_id' => $pngId, 'inline' => true], $pngId]]]]);
    $draftId = (int)($draft['items'][0]['draft_id'] ?? 0);
    check($draftId > 0, 'The draft with files is created: '.json_encode($draft['items'], JSON_UNESCAPED_UNICODE));
    $got = run('cms_comm_draft_get', ['draft_id' => $draftId]);
    check($got['attachments']['count'] === 2 && $got['attachments']['items'][0]['mime'] === 'image/png' && $got['attachments']['items'][0]['size'] === strlen($png)
        && $got['attachments']['items'][0]['name'] === 'screen.png', 'The draft holds the picture and the attachment: '.json_encode($got['attachments'], JSON_UNESCAPED_UNICODE));
    check(count(array_filter($got['attachments']['items'], static fn($a) => $a['inline'])) === 1, 'One picture inside the text, one attachment');
    check(mb_strpos((string)CmsCommDraft::findOne($draftId)->body_html, 'data-sx-upload=') !== false && mb_strpos((string)CmsCommDraft::findOne($draftId)->body_html, '{Картинка}') === false,
        'The picture stands at {Картинка}');
    check($got['prepared']['edited'] === false, 'A picture placed at {Картинка} does not mark the AI text as edited');
    $again = run('cms_comm_draft_attachment_add', ['draft_id' => $draftId, 'cms_storage_file_id' => $pngId]);
    check($again['success'] && $again['attachments']['count'] === 2, 'The same file again adds nothing');
    $bad = run('cms_comm_draft_attachment_add', ['draft_id' => $draftId, 'cms_storage_file_id' => $svgId]);
    check(!$bad['success'] && mb_strpos($bad['message'], 'svg') !== false, 'An SVG named .png is refused by its content: '.json_encode($bad, JSON_UNESCAPED_UNICODE));
    $foreignId = (int)CmsStorageFile::find()->andWhere(['cms_site_id' => $siteId])->andWhere(['!=', 'created_by', (int)$admin->id])->select('id')->scalar();
    if ($foreignId) {
        check(!run('cms_comm_draft_attachment_add', ['draft_id' => $draftId, 'cms_storage_file_id' => $foreignId])['success'], 'A file of another user is refused');
    }
    $c1 = run('cms_comm_draft_check', ['draft_id' => $draftId, 'test_mode' => true]);
    check($c1['ok'] && $c1['attachments']['count'] === 2 && $c1['attachments']['total_size'] === 2 * strlen($png) && $c1['purpose']['opt_out'] === true && $c1['test_mode'] === true,
        'The check lists the files and the purpose: '.json_encode($c1['problems'], JSON_UNESCAPED_UNICODE));
    check(run('cms_comm_draft_check', ['draft_id' => $draftId, 'test_mode' => true])['check_token'] === $c1['check_token'], 'The token is stable over requests (picture tokens do not count)');
    check(run('cms_comm_draft_check', ['draft_id' => $draftId])['check_token'] !== $c1['check_token'], 'The test mode is part of the token');

    // Е. Changes after the check: a file, the text, the address, the offer, the purpose — each needs a new check.
    $png2Id = upload($png2, 'other.png');
    $add = run('cms_comm_draft_attachment_add', ['draft_id' => $draftId, 'cms_storage_file_id' => $png2Id]);
    $send = static fn($token, $key) => run('cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => $token, 'idempotency_key' => $key, 'confirm' => true, 'test_mode' => true]);
    check($send($c1['check_token'], 'letters-'.$suffix.'-x1')['problems'][0]['reason'] === AiSend::REASON_CHANGED, 'An added file needs a new check');
    run('cms_comm_draft_attachment_remove', ['draft_id' => $draftId, 'attachment_id' => (int)$add['attachment_id']]);
    check(CmsStorageFile::findOne($png2Id) !== null, 'Removing from the draft keeps the uploaded file');
    check(run('cms_comm_draft_check', ['draft_id' => $draftId, 'test_mode' => true])['check_token'] === $c1['check_token'], 'Removing it brings the checked content back');
    foreach ([['subject' => 'Другая тема'], ['address' => 'test-ai-letters-other-'.$suffix.'@skeeks.com'], ['offer' => ''], ['purpose' => 'personal', 'cms_comm_topic_id' => null]] as $change) {
        $before = run('cms_comm_draft_check', ['draft_id' => $draftId, 'test_mode' => true]);
        $snapshot = CmsCommDraft::findOne($draftId)->getAttributes();
        $files = CmsCommDraft::findOne($draftId)->getFiles()->count();
        $u = run('cms_comm_draft_update', ['draft_id' => $draftId, 'confirm' => true, 'test_mode' => true] + $change);
        $after = run('cms_comm_draft_check', ['draft_id' => $draftId, 'test_mode' => true]);
        check(($after['check_token'] ?? null) !== $before['check_token'] || !$u['success'], 'A change of '.implode(',', array_keys($change)).' changes the token or is refused');
        check((int)CmsCommDraft::findOne($draftId)->getFiles()->count() === $files, 'Editing keeps the files');
        // Back as it was, for the next change.
        CmsCommDraft::updateAll(array_intersect_key($snapshot, array_flip(['subject', 'body_html', 'to_list', 'sendables', 'purpose', 'cms_comm_topic_id'])), ['id' => $draftId]);
    }
    $updatedAddress = run('cms_comm_draft_update', ['draft_id' => $draftId, 'address' => 'test-ai-letters-other-'.$suffix.'@skeeks.com', 'test_mode' => true]);
    check(!$updatedAddress['success'] && $updatedAddress['reason'] === 'test_mode_not_allowed', 'Г. Test mode for another address of the test company is refused');

    // А, Б. Send in test mode: the PNG goes as MIME parts (inline by cid: and attached); a second letter the same day with the same presentation passes.
    $c2 = run('cms_comm_draft_check', ['draft_id' => $draftId, 'test_mode' => true]);
    check($c2['ok'], 'The restored draft passes: '.json_encode($c2['problems'], JSON_UNESCAPED_UNICODE));
    $first = $send($c2['check_token'], 'letters-'.$suffix.'-1');
    $messageId = (int)($first['message']['id'] ?? 0);
    check($first['state'] === 'queued' && $first['message']['test_mode'] && count($first['message']['attachments']) === 2 && $first['message']['opt_out'], 'Sent in test mode with two files: '.json_encode($first, JSON_UNESCAPED_UNICODE));
    $mime = (new MailSendJobHandler())->buildEmail(CmsCommMessage::findOne($messageId))->toString();
    if (getenv('AI_LETTERS_DEBUG')) {
        preg_match_all('/^Content-[A-Za-z-]+:.*$/mi', $mime, $h);
        echo implode("\n", $h[0]), "\n";
    }
    check(preg_match('/Content-Type: image\/png; name=screen\.png/i', $mime) && preg_match('/Content-Disposition: attachment; name=screen\.png/i', $mime)
        && preg_match('/Content-ID: <[^>]+>/i', $mime) && mb_strpos(preg_replace('/\s+/', '', $mime), base64_encode($png)) !== false, 'The letter carries the PNG itself, not a link');
    $cid = \skeeks\cms\communication\models\CmsCommMessageFile::find()->andWhere(['cms_comm_message_id' => $messageId, 'is_inline' => 1])->select('content_id')->scalar();
    check($cid && mb_strpos((string)CmsCommMessage::findOne($messageId)->body_html, 'src="cid:'.$cid.'"') !== false && mb_strpos($mime, '<'.$cid.'>') !== false,
        'The picture in the text refers to its part by cid:');
    check(mb_strpos($mime, 'List-Unsubscribe') !== false, 'Ж. An advertising letter keeps the opt-out headers');
    check(run('cms_comm_send_status', ['idempotency_key' => 'letters-'.$suffix.'-1'])['repeated'] ?? true, 'The status by key');
    $again1 = $send($c2['check_token'], 'letters-'.$suffix.'-1');
    check($again1['repeated'] && (int)$again1['message']['id'] === $messageId, 'Д. The same key and data give the same letter');

    $second = run('cms_comm_draft_create', ['purpose' => 'advertising', 'cms_comm_topic_id' => (int)$topic->id, 'offer' => $offer, 'cms_comm_account_id' => $accountId, 'confirm' => true, 'test_mode' => true,
        'items' => [['record_type' => 'company', 'record_id' => (int)$company->id, 'address' => $testAddress, 'subject' => 'Второе письмо', 'body_html' => '<p>Ещё</p><p>{Ссылка}</p>']]]);
    $draft2 = (int)($second['items'][0]['draft_id'] ?? 0);
    check($draft2 > 0, 'Б. A second draft the same day in test mode: '.json_encode($second['items'], JSON_UNESCAPED_UNICODE));
    $c3 = run('cms_comm_draft_check', ['draft_id' => $draft2, 'test_mode' => true]);
    check($c3['ok'], 'Б. The second letter with the same presentation passes in test mode');
    $plain = run('cms_comm_draft_check', ['draft_id' => $draft2]);
    check(!$plain['ok'] && array_intersect(['recent_letter', 'recent_offer'], reasons($plain)), 'Without test mode the repetition is refused');
    check($send($c3['check_token'], 'letters-'.$suffix.'-1')['problems'][0]['reason'] === AiSend::REASON_KEY_REUSED, 'Д. The key with another letter is refused');
    $sent2 = run('cms_comm_draft_send', ['draft_id' => $draft2, 'check_token' => $c3['check_token'], 'idempotency_key' => 'letters-'.$suffix.'-2', 'confirm' => true, 'test_mode' => true]);
    check($sent2['state'] === 'queued', 'Б. The second letter is queued');

    // В. A real client: the same repetition is refused, and test mode is not allowed.
    $lead = lead('test-ai-letters-lead-'.$suffix.'@skeeks.com', (int)$admin->id);
    $l1 = run('cms_comm_draft_create', ['purpose' => 'advertising', 'cms_comm_topic_id' => (int)$topic->id, 'offer' => $offer, 'cms_comm_account_id' => $accountId, 'confirm' => true,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead->id, 'subject' => 'Лиду', 'body_html' => '<p>Текст</p>']]]);
    $ld = (int)$l1['items'][0]['draft_id'];
    $lc = run('cms_comm_draft_check', ['draft_id' => $ld]);
    run('cms_comm_draft_send', ['draft_id' => $ld, 'check_token' => $lc['check_token'], 'idempotency_key' => 'letters-'.$suffix.'-lead', 'confirm' => true]);
    $repeat = run('cms_comm_draft_create', ['purpose' => 'advertising', 'cms_comm_topic_id' => (int)$topic->id, 'offer' => $offer, 'cms_comm_account_id' => $accountId,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead->id, 'subject' => 'Ещё', 'body_html' => '<p>Ещё</p>']]]);
    check($repeat['items'][0]['reason'] === 'recent_letter', 'В. A repetition to a client is refused as before');
    $testForClient = run('cms_comm_draft_create', ['purpose' => 'advertising', 'cms_comm_topic_id' => (int)$topic->id, 'cms_comm_account_id' => $accountId, 'test_mode' => true,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead->id, 'subject' => 'Ещё', 'body_html' => '<p>Ещё</p>']]]);
    check($testForClient['items'][0]['reason'] === 'test_mode_not_allowed', 'Г. Test mode for a client outside the list is refused');
    Yii::$app->user->setIdentity($worker);
    rejects(fn() => run('cms_comm_draft_create', ['purpose' => 'advertising', 'cms_comm_topic_id' => (int)$topic->id, 'test_mode' => true,
        'items' => [['record_type' => 'company', 'record_id' => (int)$company->id, 'subject' => 'x', 'body_html' => '<p>x</p>']]]), 'администратор', 'Test mode is for administrators only');
    Yii::$app->user->setIdentity($admin);

    // Ж. Purposes: personal needs a dialog, service needs cooperation; neither gets the opt-out; preferences stay.
    $lead2 = lead('test-ai-letters-dialog-'.$suffix.'@skeeks.com', (int)$admin->id);
    $noDialog = run('cms_comm_draft_create', ['purpose' => 'personal', 'cms_comm_account_id' => $accountId, 'offer' => $offer,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead2->id, 'subject' => 'Ответ', 'body_html' => '<p>Текст</p>']]]);
    check($noDialog['items'][0]['reason'] === LetterPurpose::REASON_NO_DIALOG, 'A personal letter without a dialog is an offer: refused');
    $service = run('cms_comm_draft_create', ['purpose' => 'service', 'cms_comm_account_id' => $accountId,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead2->id, 'subject' => 'Счёт', 'body_html' => '<p>Текст</p>']]]);
    check($service['items'][0]['reason'] === LetterPurpose::REASON_NO_COOPERATION, 'A service letter without cooperation is refused');
    $conversation = new \skeeks\cms\communication\models\CmsCommConversation(['cms_site_id' => $siteId, 'channel' => 'email', 'thread_key' => 'in-'.$suffix,
        'subject' => 'Пришлите презентацию', 'last_message_at' => time() - 3600]);
    $conversation->save(false);
    $in = new CmsCommMessage(['cms_site_id' => $siteId, 'cms_comm_conversation_id' => (int)$conversation->id, 'channel' => 'email', 'direction' => CmsCommMessage::DIRECTION_IN,
        'status' => CmsCommMessage::STATUS_RECEIVED, 'external_id' => 'in-'.$suffix, 'from_address' => 'test-ai-letters-dialog-'.$suffix.'@skeeks.com',
        'subject' => 'Пришлите презентацию', 'is_auto' => 0, 'message_at' => time() - 3600]);
    $in->save(false);
    (new CmsCommLink(['cms_comm_message_id' => (int)$in->id, 'model_code' => CmsLead::class, 'model_id' => (int)$lead2->id, 'link_type' => CmsCommLink::TYPE_AUTO]))->save(false);
    $contact = MailingContacts::get($siteId, 'email', 'test-ai-letters-dialog-'.$suffix.'@skeeks.com');
    MailingConsents::unsubscribe($contact, (int)$topic->id, 'test', (int)$admin->id);
    $p = run('cms_comm_draft_create', ['purpose' => 'personal', 'cms_comm_account_id' => $accountId, 'offer' => $offer, 'confirm' => true,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead2->id, 'subject' => 'Ответ', 'body_html' => '<p>Как просили — презентация.</p><p>{Ссылка}</p>']]]);
    $pd = (int)($p['items'][0]['draft_id'] ?? 0);
    check($pd > 0, 'A personal letter in a dialog (with a presentation) is allowed: '.json_encode($p['items'], JSON_UNESCAPED_UNICODE));
    $pc = run('cms_comm_draft_check', ['draft_id' => $pd]);
    check($pc['ok'] && $pc['purpose']['code'] === 'personal' && $pc['purpose']['opt_out'] === false && $pc['topic'] === null, 'The check shows a personal letter without opt-out');
    $ps = run('cms_comm_draft_send', ['draft_id' => $pd, 'check_token' => $pc['check_token'], 'idempotency_key' => 'letters-'.$suffix.'-p', 'confirm' => true]);
    $pm = CmsCommMessage::findOne((int)$ps['message']['id']);
    check(!$pm->unsubscribe_url && !$pm->cms_comm_topic_id && mb_strpos((string)$pm->body_html, 'Отписаться') === false
        && mb_strpos((new MailSendJobHandler())->buildEmail($pm)->toString(), 'List-Unsubscribe') === false, 'Ж. The personal letter has no opt-out block or headers');
    check($ps['message']['purpose'] === 'personal', 'The journal keeps the purpose');
    $policy = \skeeks\cms\communication\services\MailingPolicy::check($siteId, 'email', 'test-ai-letters-dialog-'.$suffix.'@skeeks.com', (int)$topic->id);
    check(!$policy['allowed'] && $policy['reason'] === 'unsubscribed', 'Ж. The opt-out of the contact is untouched by a personal letter');
    check(in_array('Адрес отписан', array_map(static fn($w) => mb_substr($w, 0, 13), $pc['warnings']), true) || $pc['warnings'] === [] , 'Warnings are a list');
    $deal = new CmsDeal(['name' => 'Тест', 'cms_company_id' => (int)$company->id, 'is_active' => 1, 'start_at' => time(), 'created_at' => time(), 'cms_deal_type_id' => (int)\skeeks\cms\models\CmsDealType::find()->select('id')->scalar(), 'amount' => 0, 'currency_code' => 'RUB', 'description' => '', 'is_periodic' => 0, 'is_auto' => 0, 'period' => '', 'end_at' => null]);
    $deal->save(false);
    $leadOfCompany = lead('test-ai-letters-service-'.$suffix.'@skeeks.com', (int)$admin->id, (int)$company->id);
    $sv = run('cms_comm_draft_create', ['purpose' => 'service', 'cms_comm_account_id' => $accountId,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$leadOfCompany->id, 'subject' => 'По сделке', 'body_html' => '<p>Текст</p>']]]);
    check($sv['items'][0]['status'] === 'ready', 'A service letter to a client with an active deal is allowed');
    rejects(fn() => run('cms_comm_draft_create', ['purpose' => 'personal', 'cms_comm_topic_id' => (int)$topic->id, 'cms_comm_account_id' => $accountId,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead2->id, 'subject' => 'x', 'body_html' => '<p>x</p>']]]), 'нет темы рассылки', 'A personal letter with a topic is refused');
    rejects(fn() => run('cms_comm_draft_create', ['cms_comm_account_id' => $accountId,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead2->id, 'subject' => 'x', 'body_html' => '<p>x</p>']]]), 'purpose', 'Without purpose and topic the call asks for a purpose');

    // The mail screen sends an AI draft under the same rules: a personal draft without a dialog does not validate.
    $lead3 = lead('test-ai-letters-ui-'.$suffix.'@skeeks.com', (int)$admin->id);
    $uiDraft = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$admin->id, 'cms_comm_account_id' => $accountId, 'to_list' => 'test-ai-letters-ui-'.$suffix.'@skeeks.com',
        'subject' => 'x', 'body_html' => '<p>x</p>', 'context_model_code' => CmsLead::class, 'context_model_id' => (int)$lead3->id,
        'prepared_by' => 'ai', 'prepared_client' => 'mcp:test', 'prepared_user_id' => (int)$admin->id, 'prepared_at' => time(), 'purpose' => 'personal']);
    $uiDraft->save(false);
    $form = new ComposeForm(['service' => new MailComposeService($admin, $siteId)]);
    (new MailDrafts($admin, $siteId))->fill($form, $uiDraft);
    (new MailDrafts($admin, $siteId))->applyAiDraft($form);
    check($form->purpose === 'personal' && !$form->validate() && isset($form->getErrors()['purpose']), 'The mail screen refuses the same AI draft');
} finally {
    $tx->rollBack();
}
echo "OK: {$checks} checks\n";
