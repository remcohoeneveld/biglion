<?php
namespace Craft;

class SearchPlus_SettingsController extends BaseController
{
    private $plugin;

    public function init()
    {
        $this->plugin = craft()->plugins->getPlugin('searchPlus');
    }

    public function actionLicense()
    {
        craft()->searchPlus_license->ping(true);

        $variables = [
            'edition'     => craft()->searchPlus_license->getEdition(),
            'editionName' => craft()->searchPlus_license->getEditionName(),
            'hasLicenseKey' => true];


        //$this->renderTemplate('searchplus/settings/_license', $variables);

        $this->renderTemplate('searchplus/settings/license/index', $variables);
    }


    public function actionLogs()
    {
        $logLevels = ['1' => 'Primary Events & Exceptions only (recommended)', '10' => 'Full logging (for development & debugging)'];
        $logRetention = ['-1' => 'Forever', '1' => '1 Hour', '24' => '24 Hours', '168' => '7 Days', '720' => '30 Days', '2160' => '90 Days'];


        $baseSettings = $this->plugin->getSettings()->logs;
        $enabled = craft()->searchPlus_log->enabled;
        $retention = craft()->searchPlus_log->retention;
        $level = craft()->searchPlus_log->level;

        if (!isset($baseSettings['enabled'])) {
            $baseSettings['enabled'] = $enabled;
        }

        if (!isset($baseSettings['retention'])) {
            $baseSettings['retention'] = $retention;
        }

        if (!isset($baseSettings['levels'])) {
            $baseSettings['levels'] = $level;
        }

        $variables = [
            'logs'             => $baseSettings,
            'logLevels'        => $logLevels,
            'logRetention'     => $logRetention,
            'settingsEditable' => !$this->plugin->isConfigOverridden('logs'),
        ];

        $this->renderTemplate('searchplus/settings/_logs', $variables);
    }

    public function actionSaveLogs()
    {
        $this->saveSettings('logs');
    }

    private function saveSettings($group, $data = [])
    {   
        $this->requirePostRequest();
        $settings = craft()->request->getPost($group);

        $settings = [$group => $settings];


        if (craft()->plugins->savePluginSettings($this->plugin, $settings)) {

            craft()->searchPlus_log->note('Settings updated : '.ucfirst($group), $settings);

            craft()->userSession->setNotice(Craft::t('Settings saved.'));
            $this->redirectToPostedUrl();
        }
        craft()->userSession->setError(Craft::t('Couldn\'t save the settings.'));

        // Send the plugin back to the template
        craft()->urlManager->setRouteVariables([]);
    }



}
