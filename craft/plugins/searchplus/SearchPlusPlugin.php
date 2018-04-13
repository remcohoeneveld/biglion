<?php
namespace Craft;

require_once(__DIR__ . '/etc/sources/SearchPlus_BaseIndexSource.php');
require_once(__DIR__ . '/etc/sources/SearchPlus_EntryIndexSource.php');
require_once(__DIR__ . '/etc/sources/SearchPlus_CategoryIndexSource.php');
require_once(__DIR__ . '/etc/sources/SearchPlus_CommerceProductIndexSource.php');

class SearchPlusPlugin extends BasePlugin
{
    public function init()
    {
        craft()->searchPlus_license->ping();

        if (craft()->request->isCpRequest()) {
            $this->includeCpResources();
            craft()->templates->hook('searchPlus.prepCpTemplate', [$this, 'prepCpTemplate']);
        }

        craft()->on('elements.onBeforeDeleteElements', function (Event $event) {
            $elementsIds = $event->params['elementIds'];
            craft()->searchPlus_algolia->elementsDeletedEvent($elementsIds);
        });

        craft()->on('elements.onPerformAction', function (Event $event) {

            $action = $event->params['action'];
            $criteria = $event->params['criteria'];

            if (is_a($action, 'Craft\SetStatusElementAction')) {
                // The statuses have been updated.
                // Let's remap these entries
                $elementIds = $criteria->ids();
                craft()->searchPlus_algolia->elementsSavedEvent($elementIds);
            }

        });

        craft()->on('elements.onSaveElement', function (Event $event) {
            $element = $event->params['element'];

            if($element->elementType == 'Commerce_Product') {
                // skip this. We'll use the onSaveProduct event instead
            } else {
                craft()->searchPlus_algolia->elementSavedEvent($element);
            }
        });

        craft()->on('commerce_products.onSaveProduct', function (Event $event) {
            $element = $event->params['product'];
            craft()->searchPlus_algolia->elementSavedEvent($element);
        });


        craft()->on('commerce_variants.onOrderVariant', function(Event $event) {
            $variant = $event->params['variant'];

            // Get the parent product
            $product = $variant->getProduct();
            if($product != null) {
                craft()->searchPlus_algolia->elementSavedEvent($product);
            }
        });

    }

    /**
     * Includes front end resources for Control Panel requests.
     */
    private function includeCpResources()
    {
        $templatesService = craft()->templates;
        $templatesService->includeCssResource('searchplus/cp/css/searchplus.css');
        $templatesService->includeJsResource('searchplus/cp/js/searchplus.js');
    }

    function getName()
    {
        return Craft::t('Search Plus');
    }

    function getVersion()
    {
        return '1.5.2';
    }

    public function getSchemaVersion()
    {
        return '1.1.6.0';
    }

    function getDeveloper()
    {
        return 'Top Shelf Craft (Michael Rog)';
    }

    function getDeveloperUrl()
    {
        return 'https://topshelfcraft.com';
    }

    function getSettingsUrl()
    {
        return 'searchplus/settings/algolia';
    }

    public function getDescription()
    {
        return 'Algolia Search for Craft.';
    }

    function getDocumentationUrl()
    {
        return 'https://transition.topshelfcraft.com/software/craft/searchplus';
    }

    function getReleaseFeedUrl()
    {
        return 'https://raw.githubusercontent.com/TopShelfCraft/Release-Feeds/master/SearchPlus.json';
    }

    public function hasCpSection()
    {
        return true;
    }

    public function getCpAlerts($path, $fetch)
    {
        if ($path != 'searchplus/settings/license' && $path != 'searchplus/settings') {
            $licenseKeyStatus = craft()->plugins->getPluginLicenseKeyStatus('SearchPlus');

            if ($licenseKeyStatus == LicenseKeyStatus::Invalid) {
                $message = Craft::t('Your Search Plus license key is invalid.');
            } else if ($licenseKeyStatus == LicenseKeyStatus::Mismatched) {
                $message = Craft::t('Your Search Plus license key is being used on another Craft install.');
            }

            if (isset($message)) {
                $message .= ' ';

                if (craft()->userSession->isAdmin()) {
                    $message .= '<a class="go" href="' . UrlHelper::getUrl('searchplus/settings/license') . '">' . Craft::t('Resolve') . '</a>';
                } else {
                    $message .= Craft::t('Please notify one of your site’s admins.');
                }

                return [$message];
            }
        }

        return null;
    }

    public function getSettingsHtml()
    {
        return craft()->templates->render('searchplus/_settings', [
            'settings' => $this->getSettings()
        ]);
    }

    public function onBeforeInstall()
    {
        if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 50400) {
            Craft::log('SearchPlus requires PHP 5.4+ in order to run.', LogLevel::Error);

            return false;
        }
    }


    public function registerCpRoutes()
    {
        return [
            'searchplus'                                                => ['action' => 'searchPlus/algolia/index'],
            'searchplus/unconnected'                                    => ['action' => 'searchPlus/algolia/unconnected'],
            'searchplus/logs'                                           => ['action' => 'searchPlus/log/all'],
            'searchplus/logs/(?P<logId>\d+)'                            => ['action' => 'searchPlus/log/view'],
            'searchplus/algolia'                                        => ['action' => 'searchPlus/algolia/index'],
            'searchplus/algolia/editIndex'                              => ['action' => 'searchPlus/algolia/editIndex'],
            'searchplus/algolia/editIndex/(?P<indexId>\d+)'             => ['action' => 'searchPlus/algolia/editIndex'],
            'searchplus/algolia/manageIndex/(?P<indexId>\d+)'           => ['action' => 'searchPlus/algolia/manageIndex'],
            'searchplus/algolia/manageIndex/(?P<indexId>\d+)/admin'     => ['action' => 'searchPlus/algolia/manageIndexAdmin'],
            'searchplus/algolia/manageIndex/(?P<indexId>\d+)/populate'  => ['action' => 'searchPlus/algolia/populate'],

            'searchplus/settings'               => ['action' => 'searchPlus/registration/edit'],
            'searchplus/settings/algolia'       => ['action' => 'searchPlus/algolia/setup'],
            'searchplus/settings/mappings'      => ['action' => 'searchPlus/algolia/viewMappings'],
            'searchplus/settings/mappings/test' => ['action' => 'searchPlus/algolia/viewMappingOutput'],
            'searchplus/settings/logs'          => ['action' => 'searchPlus/settings/logs'],
            'searchplus/settings/license'       => ['action' => 'searchPlus/registration/edit'],
        ];
    }


    public function initAutoloader()
    {
        require(__DIR__ . '/vendor/autoload.php');
    }


    public function prepCpTemplate(&$context)
    {
        $context['subnav']['searchPlus'] = ['label' => Craft::t('Indexes'), 'url' => 'searchplus'];

        if (craft()->searchPlus_log->getLogEnabledStatus()) {
            $context['subnav']['logs'] = ['label' => Craft::t('Logs'), 'url' => 'searchplus/logs'];
        }

        if (craft()->userSession->isAdmin()) {
            $context['subnav']['settings'] = ['label' => Craft::t('Settings'), 'url' => 'searchplus/settings'];
        }
    }

    protected function defineSettings()
    {
        return [
            'algoliaSettings' => [AttributeType::Mixed, 'default' => true],
            'licenseKey'      => [AttributeType::String],
            'edition'         => [AttributeType::Mixed],
            'logs'            => [AttributeType::Mixed]
        ];
    }


    public function getSettings()
    {
        $settings = parent::getSettings();

        $base = $this->defineSettings();
        foreach ($base as $key => $row) {
            $override = craft()->config->get($key, 'searchplus');
            if (!is_null($override)) {
                $settings->$key = $override;
            }
        }

        return $settings;
    }


    public function isConfigOverridden($group)
    {
        $state = false;

        $override = craft()->config->get($group, 'searchplus');
        if (!is_null($override)) {
            $state = true;
        }

        return $state;
    }


    public function registerSearchPlusIndexSources()
    {
        $r = ['SearchPlus_EntryIndexSource','SearchPlus_CategoryIndexSource'];

        if (!is_null(craft()->plugins->getPlugin('commerce'))) {
            $r[] = 'SearchPlus_CommerceProductIndexSource';
        }

        return $r;
    }


}
