<?php
/**
 * "Привязать чат" / "Привязать собеседника": a lead, company, client (or a
 * deal for a whole chat) chosen with the standard CRM search over the
 * records the employee sees. Opened in a backend window over the chat;
 * chats.js posts it and closes the window.
 *
 * @var $this yii\web\View
 * @var $conversation skeeks\cms\communication\models\CmsCommConversation
 * @var $identity skeeks\cms\communication\models\CmsCommIdentity|null the person (link the person, not the chat)
 * @var $siteId int
 */

use skeeks\cms\communication\assets\ChatAsset;
use skeeks\cms\communication\chat\ChatLinks;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\models\CmsLead;
use skeeks\cms\widgets\AjaxSelectModel;
use yii\helpers\Html;
use yii\helpers\Url;

ChatAsset::register($this);
$this->registerCss('body { background: var(--sx-color-surface, #fff) !important; }');

$user = \Yii::$app->user->identity;
$types = $identity ? [CrmRecords::LEAD, CrmRecords::COMPANY, CrmRecords::CLIENT] : ChatLinks::TYPES;
$selectFor = static function (string $type) use ($user, $siteId) {
    $class = CrmRecords::codeOf($type);

    return AjaxSelectModel::widget([
        'name' => 'id_'.$type,
        'id' => 'sx-chat-link-'.$type,
        'modelClass' => $class,
        'options' => ['data-sx-chat-link-select' => $type],
        'pluginOptions' => ['placeholder' => 'Начните вводить название, имя, телефон или email', 'allowClear' => true, 'width' => '100%'],
        'searchQuery' => static function ($word = '') use ($type, $class, $user, $siteId) {
            $query = CrmRecords::visibleQuery($type, $user);
            if ($type === CrmRecords::LEAD) {
                $query->andWhere([CmsLead::tableName().'.cms_site_id' => $siteId]);
            }
            if ($type === CrmRecords::CLIENT) {
                $query->andWhere([$class::tableName().'.is_worker' => 0]);
            }
            $word = trim((string)$word);
            if ($word !== '') {
                if (method_exists($query, 'search')) {
                    $query->search($word);
                } else {
                    $query->andWhere(['or', ['like', $class::tableName().'.name', $word], [$class::tableName().'.id' => ctype_digit($word) ? (int)$word : 0]]);
                }
            }

            return $query->orderBy([$class::tableName().'.id' => SORT_DESC]);
        },
    ]);
};
$first = $types[0];
?>
<form class="sx-chat-link" data-sx-chat-link-form action="<?= Html::encode(Url::to(['link', 'pk' => (int)$conversation->id])) ?>" method="post">
    <input type="hidden" name="person" value="<?= $identity ? 1 : 0 ?>">
    <p class="sx-chat-link__intro">
        <?php if ($identity): ?>
            Кто такой <b><?= Html::encode($identity->asText()) ?></b> в CRM? Его личные чаты с ботами будут привязаны к выбранной записи.
        <?php else: ?>
            Чат <b><?= Html::encode($conversation->asText()) ?></b> появится в ленте записи<?= $conversation->chat_type === 'private' ? ' — каждое сообщение' : ' — сводкой за день' ?>, сотрудники записи увидят чат.
        <?php endif; ?>
    </p>
    <div class="sx-chat-link__types" role="radiogroup">
        <?php foreach ($types as $type): ?>
            <label class="sx-chat-link__type">
                <input type="radio" name="type" value="<?= Html::encode($type) ?>" <?= $type === $first ? 'checked' : '' ?> data-sx-chat-link-type>
                <span><?= Html::encode(CrmRecords::type($type)['title']) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <?php foreach ($types as $type): ?>
        <div class="sx-chat-link__select" data-sx-chat-link-for="<?= Html::encode($type) ?>" <?= $type === $first ? '' : 'hidden' ?>>
            <?= $selectFor($type) ?>
        </div>
    <?php endforeach; ?>
    <div class="sx-chat__error" data-sx-chat-link-error hidden></div>
    <div class="sx-chat-link__actions">
        <button type="submit" class="btn btn-primary">Привязать</button>
    </div>
</form>
