<?php
/**
 * Marks of links to our sites in letters (step F) without a database:
 * which links get utm_* and the personal code, manager's marks kept, other
 * sites, mailto:, tel:, anchors and personal pages untouched, links that
 * already carry a code (a resend, a quote) unchanged, the HTML and the
 * plain text of a letter, the robots list and addresses of visits.
 *
 * Usage: php tests/link-marks.php [vendor/autoload.php]
 */

$autoloadCandidates = array_filter([$argv[1] ?? null, dirname(__DIR__, 3).'/autoload.php', '/app/vendor/autoload.php']);
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        require_once dirname($autoload).'/yiisoft/yii2/Yii.php';
        break;
    }
}
Yii::setAlias('@skeeks/cms/communication', dirname(__DIR__).'/src');

use skeeks\cms\communication\components\LinkTrackingSettings;
use skeeks\cms\communication\services\LinkChecks;
use skeeks\cms\communication\services\LinkMarks;
use skeeks\cms\communication\services\LinkVisits;

$checks = 0;
function marksExpect($condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

new \yii\console\Application(['id' => 'cms-communication-link-marks', 'basePath' => sys_get_temp_dir()]);

$domains = ['skeeks.com', 'skeeks-platform.ru'];
$params = ['utm_source' => 'crm', 'utm_medium' => 'email', 'utm_campaign' => 'personal', 'sx' => 'AbCdEfGhIjKlMnOp'];
$tail = 'utm_source=crm&utm_medium=email&utm_campaign=personal&sx=AbCdEfGhIjKlMnOp';

// Settings: domains from the list, scheme, www and paths dropped.
marksExpect(LinkTrackingSettings::parseDomains("https://www.Skeeks.com/about\nskeeks-platform.ru, skeeks.com") === ['skeeks.com', 'skeeks-platform.ru'],
    'Domains are lower-case hosts without scheme, www and path, each once');

// Hosts: our domains and their subdomains only.
marksExpect(LinkMarks::isOurHost('skeeks.com', $domains) && LinkMarks::isOurHost('www.skeeks.com', $domains) && LinkMarks::isOurHost('cms.skeeks.com', $domains),
    'Our domain and subdomains are ours');
marksExpect(!LinkMarks::isOurHost('notskeeks.com', $domains) && !LinkMarks::isOurHost('skeeks.com.evil.ru', $domains) && !LinkMarks::isOurHost('', $domains),
    'A look-alike host is not ours');

// One link: the missing marks are added, the fragment stays at the end.
marksExpect(LinkMarks::addParams('https://skeeks.com/solutions/plitka', $params) === 'https://skeeks.com/solutions/plitka?'.$tail, 'Marks are added to a plain link');
marksExpect(LinkMarks::addParams('https://skeeks.com/a?x=1#price', $params) === 'https://skeeks.com/a?x=1&'.$tail.'#price', 'The fragment stays at the end');
marksExpect(LinkMarks::addParams('https://skeeks.com/a?UTM_Source=vk&utm_campaign=autumn', $params)
    === 'https://skeeks.com/a?UTM_Source=vk&utm_campaign=autumn&utm_medium=email&sx=AbCdEfGhIjKlMnOp', 'Marks of the manager are kept in any case');
marksExpect(LinkMarks::removeParam('https://skeeks.com/a?utm_source=crm&SX=AbCdEfGhIjKlMnOp&b=2#top', 'sx') === 'https://skeeks.com/a?utm_source=crm&b=2#top',
    'The code is removed from an address, the rest stays');
marksExpect(LinkMarks::removeParam('https://skeeks.com/a?sx=AbCdEfGhIjKlMnOp', 'sx') === 'https://skeeks.com/a', 'The last parameter leaves no "?"');

// Which links are marked.
marksExpect(LinkMarks::isMarkable('https://skeeks.com/', $domains) && LinkMarks::isMarkable('http://skeeks-platform.ru/x', $domains), 'Links to our sites are marked');
foreach (['https://google.com/', 'mailto:info@skeeks.com', 'tel:+74950000000', '#top', '/relative', 'ftp://skeeks.com/f',
             'https://skeeks.com/~kp/abcdefghijklmnopqrstuvwx', 'https://skeeks.com/~unsub/abcdefghijklmnop', 'https://skeeks.com/a?sx=ZZZZZZZZZZZZZZZZ'] as $url) {
    marksExpect(!LinkMarks::isMarkable($url, $domains), 'Not marked: '.$url);
}

// HTML of a letter: href only, the shown text stays; other links untouched; entities are kept right.
$html = '<p>Смотрите <a href="https://skeeks.com/solutions/plitka">https://skeeks.com/solutions/plitka</a> и <a href=\'https://google.com/x\'>Google</a>,'
    .' <a href="mailto:info@skeeks.com">почта</a>, <a href="tel:+7495">телефон</a>, <a href="#a">якорь</a>.</p>'
    .'<p>Подпись: <a class="sig" href="https://www.skeeks.com/?a=1&amp;b=2" target="_blank">skeeks.com</a></p>';
$count = 0;
$marked = LinkMarks::markHtml($html, $params, $domains, $count);
marksExpect($count === 2, 'Two links of the letter are ours: in the text and in the signature');
marksExpect(str_contains($marked, '<a href="https://skeeks.com/solutions/plitka?'.str_replace('&', '&amp;', $tail).'">https://skeeks.com/solutions/plitka</a>'),
    'The link in the text gets the marks, its shown text stays: '.$marked);
marksExpect(str_contains($marked, 'href="https://www.skeeks.com/?a=1&amp;b=2&amp;'.str_replace('&', '&amp;', $tail).'" target="_blank"'),
    'The link of the signature keeps its own parameters and attributes');
marksExpect(str_contains($marked, "href='https://google.com/x'") && str_contains($marked, 'href="mailto:info@skeeks.com"')
    && str_contains($marked, 'href="tel:+7495"') && str_contains($marked, 'href="#a"'), 'Other sites, mail, phones and anchors are untouched');
$again = 0;
marksExpect(LinkMarks::markHtml($marked, ['sx' => 'QQQQQQQQQQQQQQQQ'] + $params, $domains, $again) === $marked && $again === 0,
    'A letter marked before (a resend, a quote, a forward) keeps its links and code');

// Plain text: an address followed by a dot or a bracket.
$count = 0;
$text = LinkMarks::markText("Ссылка: https://skeeks.com/works. Ещё (https://skeeks-platform.ru/) и https://ya.ru/x.", $params, $domains, $count);
marksExpect($count === 2 && $text === "Ссылка: https://skeeks.com/works?{$tail}. Ещё (https://skeeks-platform.ru/?{$tail}) и https://ya.ru/x.",
    'Plain text addresses are marked, punctuation after them is not part of them: '.$text);

// The whole letter through the site settings: nothing without our links, nothing when off.
LinkTrackingSettings::reset(1, ['enabled' => true, 'domains' => $domains, 'taskOnVisit' => true]);
$prepared = LinkMarks::prepare(1, '<a href="https://skeeks.com/">Сайт</a>', 'Сайт https://skeeks.com/', 'email', null, null);
marksExpect($prepared && preg_match('~^'.LinkMarks::CODE_PATTERN.'$~', $prepared['code']) && $prepared['utm_campaign'] === 'personal'
    && str_contains($prepared['html'], 'sx='.$prepared['code']) && str_contains($prepared['text'], 'sx='.$prepared['code']), 'A personal letter gets a code and "personal"');
marksExpect(LinkMarks::prepare(1, '<a href="https://ya.ru/">Яндекс</a>', 'https://ya.ru/', 'email') === null, 'A letter without our links gets no code');
marksExpect(LinkMarks::prepare(1, '<a href="https://skeeks.com/">С</a>', '', 'email', 12, 'proposal-5')['utm_campaign'] === 'campaign-12', 'A campaign letter is "campaign-<id>"');
marksExpect(LinkMarks::prepare(1, '<a href="https://skeeks.com/">С</a>', '', 'email', null, 'proposal-5')['utm_campaign'] === 'proposal-5', 'A letter with an offer is the offer mark');
marksExpect(LinkMarks::newCode() !== LinkMarks::newCode(), 'Codes are random');
LinkTrackingSettings::reset(1, ['enabled' => false, 'domains' => $domains, 'taskOnVisit' => true]);
marksExpect(LinkMarks::prepare(1, '<a href="https://skeeks.com/">Сайт</a>', '', 'email') === null, 'Marking off: letters leave as written');
LinkTrackingSettings::reset();

// Robots (shared with the pages of offers) and addresses of visits.
foreach (['curl/8.4.0', '', 'TelegramBot (like TwitterBot)', 'Mozilla/5.0 (compatible; YandexBot/3.0)', 'python-requests/2.31'] as $robot) {
    marksExpect(LinkChecks::isRobot($robot), 'A robot: '.$robot);
}
marksExpect(!LinkChecks::isRobot('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 YaBrowser/24.10 Safari/537.36')
    && !LinkChecks::isRobot('Mozilla/5.0 (Linux; Android 13; Cubot KingKong) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36'), 'People are not robots');
marksExpect(LinkVisits::cleanUrl('https://skeeks.com/a?utm_source=crm&sx=AbCdEfGhIjKlMnOp') === 'https://skeeks.com/a?utm_source=crm'
    && LinkVisits::cleanUrl('javascript:alert(1)') === null, 'A visit keeps the page without the code, only http(s)');
marksExpect(LinkVisits::displayUrl('https://www.skeeks.com/works/?utm_source=crm&utm_medium=email&page=2') === 'skeeks.com/works/?page=2'
    && LinkVisits::displayUrl('https://skeeks.com/') === 'skeeks.com', 'A page is shown without the scheme and marks');

echo "OK link-marks: {$checks} checks\n";
