<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\BackendAction;
use skeeks\cms\backend\BackendController;
use skeeks\cms\base\Component;
use skeeks\cms\communication\components\LinkTrackingSettings;
use skeeks\cms\communication\components\MailFileSettings;
use skeeks\cms\communication\forms\MailFileCleanupForm;
use skeeks\cms\communication\forms\MailFileSaveForm;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\services\MailFileCleanup;
use skeeks\cms\communication\services\MailFileSave;
use skeeks\cms\communication\services\MailFilePolicy;
use skeeks\cms\helpers\RequestResponse;
use skeeks\cms\rbac\CmsManager;
use yii\helpers\ArrayHelper;
use yii\widgets\ActiveForm;

/**
 * "Настройки почты": how letter files are kept for the whole site (a mailbox
 * may override it in its own form) and a one-off cleanup of CRM copies that
 * an administrator starts as a job.
 */
class AdminCmsCommSettingsController extends BackendController
{
    public function init()
    {
        $this->name = 'Настройки почты';
        $this->generateAccessActions = false;
        $this->permissionName = CmsManager::PERMISSION_ROLE_ADMIN_ACCESS;
        $this->defaultAction = 'files';

        parent::init();
    }

    public function actions()
    {
        return [
            'files' => [
                'class' => BackendAction::class,
                'name' => 'Хранение файлов',
                'icon' => 'far fa-hdd',
                'priority' => 10,
                'callback' => [$this, 'files'],
            ],
            'save-files' => [
                'class' => BackendAction::class,
                'name' => 'Сохранить файлы в CRM',
                'icon' => 'fas fa-download',
                'priority' => 15,
                'callback' => [$this, 'saveFiles'],
            ],
            'cleanup' => [
                'class' => BackendAction::class,
                'name' => 'Очистка файлов',
                'icon' => 'fas fa-broom',
                'priority' => 20,
                'callback' => [$this, 'cleanup'],
            ],
            // Step F: marks and the personal code in links to our sites, visits in the feed (LinkTrackingSettings).
            'links' => [
                'class' => BackendAction::class,
                'name' => 'Переходы из писем',
                'icon' => 'fas fa-link',
                'priority' => 30,
                'callback' => [$this, 'links'],
            ],
        ];
    }

    /** Marks of links to our sites and visits by them, at the level the administration edits (component-settings.md). */
    public function links()
    {
        $site = \Yii::$app->skeeks->site;
        $component = new LinkTrackingSettings();
        if ($site->is_default) {
            $component->overridePath = [Component::OVERRIDE_DEFAULT];
        } else {
            $component->overridePath = [Component::OVERRIDE_DEFAULT, Component::OVERRIDE_SITE];
            $component->cmsSite = $site;
        }
        $component->refresh();

        $request = \Yii::$app->request;
        if ($request->isPost && !$request->post(RequestResponse::DYNAMIC_RELOAD_NOT_SUBMIT)) {
            if ($component->load($request->post()) && $component->validate()) {
                $component->domains = implode("\n", LinkTrackingSettings::parseDomains((string)$component->domains));
                if ($site->is_default) {
                    $component->override = Component::OVERRIDE_DEFAULT;
                } else {
                    $component->override = Component::OVERRIDE_SITE;
                    $component->cmsSite = $site;
                }
                $saved = $component->save();
                LinkTrackingSettings::reset();
                \Yii::$app->getSession()->setFlash($saved ? 'success' : 'error', $saved ? 'Сохранено' : 'Не удалось сохранить');
            } else {
                \Yii::$app->getSession()->setFlash('error', 'Не удалось сохранить');
            }
        } elseif ($request->isPost) {
            $component->load($request->post());
        }

        return $this->render('@skeeks/cms/communication/views/settings/links', [
            'component' => $component,
            'settings' => LinkTrackingSettings::forSite((int)$site->id),
            'scriptUrl' => \yii\helpers\Url::to(['/cmsCommunication/visit/script'], true),
        ]);
    }

    /**
     * Keeps files of earlier letters for good (the reverse of the cleanup):
     * the administrator chooses which, sees how much it is and starts the job.
     */
    public function saveFiles()
    {
        $siteId = (int)\Yii::$app->skeeks->site->id;
        $form = new MailFileSaveForm();
        $preview = null;
        $run = null;
        $request = \Yii::$app->request;
        if ($request->isPost && $form->load($request->post()) && $form->validate()) {
            $criteria = $form->criteria();
            $preview = MailFileSave::preview($criteria, $siteId);
            if ($request->post('start') && ($preview['fetch'] + $preview['cached']) > 0) {
                $run = \Yii::$app->jobs->push('comm.mail.save-files', $criteria + ['siteId' => $siteId], [
                    'siteId' => $siteId,
                    'title' => 'Сохранение файлов писем в CRM',
                    'triggerType' => 'manual',
                ]);
                if (!$run) {
                    \Yii::$app->getSession()->setFlash('warning', 'Сохранение файлов уже идёт: дождитесь его окончания.');
                }
            }
        }

        return $this->render('@skeeks/cms/communication/views/settings/save-files', [
            'form' => $form,
            'preview' => $preview,
            'run' => $run,
            'accounts' => CmsCommAccount::find()->andWhere(['cms_site_id' => $siteId])->orderBy(['address' => SORT_ASC])->all(),
            'policy' => MailFilePolicy::forAccount(null, $siteId),
        ]);
    }

    /** Site settings at the level the administration edits (component-settings.md). */
    public function files()
    {
        $component = new MailFileSettings();
        if (\Yii::$app->skeeks->site->is_default) {
            $component->overridePath = [Component::OVERRIDE_DEFAULT];
        } else {
            $component->overridePath = [Component::OVERRIDE_DEFAULT, Component::OVERRIDE_SITE];
            $component->cmsSite = \Yii::$app->skeeks->site;
        }
        $component->refresh();

        $request = \Yii::$app->request;
        if ($request->isAjax && $request->isPost && !$request->isPjax) {
            $component->load($request->post());
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return ActiveForm::validateMultiple(ArrayHelper::merge([$component], $component->getConfigFormModels()));
        }
        if ($request->isPost && !$request->post(RequestResponse::DYNAMIC_RELOAD_NOT_SUBMIT)) {
            if ($component->load($request->post()) && $component->validate()) {
                if (\Yii::$app->skeeks->site->is_default) {
                    $component->override = Component::OVERRIDE_DEFAULT;
                } else {
                    $component->override = Component::OVERRIDE_SITE;
                    $component->cmsSite = \Yii::$app->skeeks->site;
                }
                $saved = $component->save();
                MailFilePolicy::reset();
                \Yii::$app->getSession()->setFlash($saved ? 'success' : 'error', $saved ? 'Сохранено' : 'Не удалось сохранить');
            } else {
                \Yii::$app->getSession()->setFlash('error', 'Не удалось сохранить');
            }
        } elseif ($request->isPost) {
            $component->load($request->post());
        }

        return $this->render('@skeeks/cms/communication/views/settings/files', [
            'component' => $component,
            'overrides' => CmsCommAccount::find()->andWhere(['cms_site_id' => (int)\Yii::$app->skeeks->site->id])
                ->andWhere(['not', ['file_settings' => null]])->all(),
        ]);
    }

    /**
     * A one-off cleanup: the administrator chooses what to remove, sees how
     * much it is, and starts it as a job. Letters and the mailbox keep the
     * files; opening a letter fetches them again.
     */
    public function cleanup()
    {
        $siteId = (int)\Yii::$app->skeeks->site->id;
        $form = new MailFileCleanupForm();
        $preview = null;
        $run = null;
        $request = \Yii::$app->request;
        if ($request->isPost && $form->load($request->post()) && $form->validate()) {
            $criteria = MailFileCleanup::criteria($form->attributes);
            $preview = (new MailFileCleanup())->preview($criteria, $siteId);
            if ($request->post('start') && $preview['count'] > 0) {
                $run = \Yii::$app->jobs->push('comm.mail.cleanup-files', $criteria, [
                    'siteId' => $siteId,
                    'title' => 'Очистка файлов писем',
                    'triggerType' => 'manual',
                ]);
                if (!$run) {
                    \Yii::$app->getSession()->setFlash('warning', 'Очистка уже идёт: дождитесь её окончания.');
                }
            }
        }

        return $this->render('@skeeks/cms/communication/views/settings/cleanup', [
            'form' => $form,
            'preview' => $preview,
            'run' => $run,
            'accounts' => CmsCommAccount::find()->andWhere(['cms_site_id' => $siteId])->orderBy(['address' => SORT_ASC])->all(),
        ]);
    }
}
