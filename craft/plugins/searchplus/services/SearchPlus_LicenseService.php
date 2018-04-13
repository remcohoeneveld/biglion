<?php
namespace Craft;

class SearchPlus_LicenseService extends BaseApplicationComponent
{
    const Ping = 'https://transition.topshelfcraft.com/actions/licensor/edition/ping';
    const GetLicenseInfo = 'https://transition.topshelfcraft.com/actions/licensor/edition/getLicenseInfo';
    const RegisterPlugin = 'https://transition.topshelfcraft.com/actions/licensor/edition/registerPlugin';
    const UnregisterPlugin = 'https://transition.topshelfcraft.com/actions/licensor/edition/unregisterPlugin';
    const TransferPlugin = 'https://transition.topshelfcraft.com/actions/licensor/edition/transferPlugin';

    private $plugin;
    private $pingStateKey = 'searchplusPhonedHome';
    private $pingCacheTime = 86400;
    private $pluginHandle = 'SearchPlus';
    private $pluginVersion;
    private $licenseKey;
    private $edition;


    public function init()
    {
        require craft()->path->getPluginsPath() . 'searchplus/etc/SearchPlus_Edition.php';
        $this->plugin = craft()->plugins->getPlugin('searchplus');
        $this->pluginVersion = $this->plugin->getVersion();
        $this->licenseKey = $this->getLicenseKey();
        $this->edition = craft()->searchPlus->settings['edition'];
    }

    public function ping()
    {
        if(craft()->request->isCpRequest()) {
            if (!craft()->cache->get($this->pingStateKey)) {

                craft()->searchPlus_log->debug('Checking license state');
                
                $et = new SearchPlus_Edition(static::Ping, $this->pluginHandle, $this->pluginVersion, $this->licenseKey);
                $etResponse = $et->phoneHome();
                craft()->cache->set($this->pingStateKey, true, $this->pingCacheTime);

                return $this->handleEtResponse($etResponse);
            }
        }
        return null;
    }


    public function getEdition()
    {
        $edition = 0;
        if($this->edition !== null) {
            if($this->edition == 1) {
                $edition = 1;
            }
        }

        return $edition;
        /*
        if(craft()->plugins->getPluginLicenseKeyStatus('SearchPlus') == LicenseKeyStatus::Valid) {
            $edition = 1;
        }
        return $edition;*/
    }

    public function wipeLicenseKey()
    {
        craft()->searchPlus_license->setLicenseKey(null);
        craft()->plugins->setPluginLicenseKeyStatus('SearchPlus', LicenseKeyStatus::Unknown);
        $this->setEdition('0');
    }

    public function getLicenseKey()
    {
        $licenseKey = null;

        $settings = $this->plugin->getSettings();
        if (!isset($settings->licenseKey)) return $licenseKey;
        $licenseKey = $settings->licenseKey;

        return $licenseKey;
    }


    public function setLicenseKey($licenseKey)
    {
        $settings = ['licenseKey' => $licenseKey];

        craft()->searchPlus_log->note('Settings Updated : Licence Key', $settings);

        craft()->plugins->savePluginSettings($this->plugin, $settings);
    }

    private function setEdition($edition)
    {
        $settings = ['edition' => $edition];
        craft()->plugins->savePluginSettings($this->plugin, $settings);

        $this->edition = $edition;
    }


    public function getLicenseInfo()
    {
        $et = new SearchPlus_Edition(static::GetLicenseInfo, $this->pluginHandle, $this->pluginVersion, $this->licenseKey);
        $etResponse = $et->phoneHome(true);

        return $this->handleEtResponse($etResponse);
    }


    /**
     * Creates a new EtModel with provided JSON, and returns it if it's valid.
     *
     * @param array $attributes
     *
     * @return EtModel|null
     */
    public function decodeEtModel($attributes)
    {
        if ($attributes) {
            $attributes = JsonHelper::decode($attributes);

            if (is_array($attributes)) {
                $etModel = new SearchPlus_LicenseModel($attributes);

                // Make sure it's valid. (At a minimum, localBuild and localVersion
                // should be set.)
                if ($etModel->validate()) {
                    return $etModel;
                }
            }
        }
        return null;
    }


    public function unregisterLicenseKey()
    {
        craft()->searchPlus_log->note('License key removed');

        $et = new SearchPlus_Edition(static::UnregisterPlugin, $this->pluginHandle, $this->pluginVersion, $this->licenseKey);
        $etResponse = $et->phoneHome(true);

        craft()->searchPlus_license->setLicenseKey(null);
        $this->setEdition('0');
        craft()->plugins->setPluginLicenseKeyStatus('SearchPlus', LicenseKeyStatus::Unknown);

        return $this->handleEtResponse($etResponse);
    }

    public function transferLicenseKey()
    {
        $et = new SearchPlus_Edition(static::TransferPlugin, $this->pluginHandle, $this->pluginVersion, $this->licenseKey);
        $etResponse = $et->phoneHome(true);

        return $etResponse;
    }

    public function registerPlugin($licenseKey)
    {
        $et = new SearchPlus_Edition(static::RegisterPlugin, $this->pluginHandle, $this->pluginVersion, $licenseKey);
        $etResponse = $et->phoneHome(true);

        // Handle the response
        return $this->handleEtResponse($etResponse);
    }

    /**
     * Returns a response based on the EtService response.
     *
     * @return bool|string The resonse from EtService
     */

    private function handleEtResponse($etResponse)
    {
        if (!empty($etResponse->data['success'])) {
            // Set the local details
            $this->setEdition('1');
            craft()->plugins->setPluginLicenseKeyStatus('SearchPlus',LicenseKeyStatus::Valid);
            return true;
        } else {
            $this->setEdition('0');
            if (!empty($etResponse->errors)) {
                switch ($etResponse->errors[0]) {
                    case 'nonexistent_plugin_license':
                        craft()->searchPlus_log->note('Failed to validate license key status - key is invalid');
                        craft()->plugins->setPluginLicenseKeyStatus('SearchPlus',LicenseKeyStatus::Invalid);
                        break;
                    case 'plugin_license_in_use':
                        craft()->searchPlus_log->note('Failed to validate license key status - key is already in use');
                        craft()->plugins->setPluginLicenseKeyStatus('SearchPlus',LicenseKeyStatus::Mismatched);
                        break;
                    default:
                        craft()->searchPlus_log->note('Failed to validate license key status - encountered an unknown error');
                        craft()->plugins->setPluginLicenseKeyStatus('SearchPlus',LicenseKeyStatus::Unknown);
                }
            } else {
                //$error = Craft::t('An unknown error occurred.');
                return false;
            }

            return true;
        }
    }
}
