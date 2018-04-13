<?php
namespace Craft;

class SearchPlus_AlgoliaController extends SearchPlus_BaseCpController
{
    protected $allowAnonymous = false;
    public $variables = [];

    public function __construct()
    {
        $this->variables['connected'] = craft()->searchPlus_algolia->validateConnection();

        if ($this->variables['connected']) {
            // Grab the indexes
            $this->variables['indexes'] = craft()->searchPlus_algolia->indexes;

            foreach ($this->variables['indexes'] as $index) {
                if ($index->status == 'unmapped') {
                    $this->variables['unconnectedIndexes'][] = $index;
                } else {
                    $this->variables['connectedIndexes'][] = $index;
                }
            }
        }
    }

    public function actionIndex(array $variables = [])
    {
        $variables['canCreateIndex'] = craft()->searchPlus_algolia->canCreateIndexes();

        $this->variables = array_merge($variables, $this->variables);

        $this->renderTemplate('searchPlus/algolia/_index', $this->variables);
    }

    public function actionUnconnected(array $variables = [])
    {
        $this->variables = array_merge($variables, $this->variables);
        $this->renderTemplate('searchPlus/algolia/_unconnected', $this->variables);
    }

    public function actionSetup(array $variables = [])
    {
        $variables['settingsEditable'] = true;
        $variables['algolia'] = craft()->searchPlus->settings['algoliaSettings'];

        // See if the settings are coming from a config file?
        if (!is_null(craft()->config->get('algolia', 'searchplus'))) {
            $variables['settingsEditable'] = false;
        }

        // Prefix might actually be falling back
        $variables['prefixEditable'] = false;
        $variables['indexPrefix'] = craft()->searchPlus_algolia->getIndexPrefix();
        if (craft()->searchPlus_algolia->usingEnvPrefix) {
            // Set via fallback
            $variables['prefixEditable'] = true;
        }

        $this->variables = array_merge($variables, $this->variables);
        $this->renderTemplate('searchPlus/algolia/_setup', $this->variables);
    }

    public function actionManageIndex(array $variables = [])
    {
        $variables['index'] = craft()->searchPlus_algolia->getIndex($variables['indexId'], true); // Forced just because
        if ($variables['index'] == false) return $this->redirect('searchplus/algolia');


        // Get some stats
        $stats = craft()->searchPlus_algolia->getIndexAnalytics($variables['indexId']);
        $variables['stats'] = $stats;

        $variables['canPopulateUnlimited'] = craft()->searchPlus_algolia->canPopulateUnlimited();
        $variables['itemPopulateLimit'] = craft()->searchPlus_algolia->itemFreeLimit;

        $this->variables = array_merge($variables, $this->variables);
        $this->renderTemplate('searchPlus/algolia/_manageIndex', $this->variables);
    }

    public function actionManageIndexAdmin(array $variables = [])
    {
        // Is this a post request?
        if (craft()->request->isPostRequest()) {
            $index = craft()->searchPlus_algolia->getIndex(craft()->request->getPost('indexId'));
            $type = craft()->request->getPost('type');
            if ($index == false || !in_array($type, ['unmap', 'clear', 'delete'])) return $this->redirect('searchplus/algolia');

            $repopulate = craft()->request->getPost('repopulate', false);

            // Validate name confirmation
            $confirm = craft()->request->getPost('confirm', '');
            if ($confirm == $index->handle) {
                craft()->searchPlus_algolia->indexAdminAction($index, $type, ['repopulate' => $repopulate]);

                $this->redirectToPostedUrl($index);
            } else {
                craft()->userSession->setError(Craft::t('Action canceled - confirmation didn\'t match'));
                $variables['index'] = craft()->searchPlus_algolia->getIndex(craft()->request->getPost('indexId'));
            }
        }

        if (!isset($variables['index'])) {
            $variables['index'] = craft()->searchPlus_algolia->getIndex($variables['indexId']);
        }
        if ($variables['index'] == false) return $this->redirect('searchplus/algolia');


        $this->variables = array_merge($variables, $this->variables);
        $this->renderTemplate('searchPlus/algolia/_manageIndexAdmin', $this->variables);
    }

    public function actionSaveSettings(array $variables = [])
    {
        $this->requirePostRequest();

        $pluginClass = 'searchPlus';
        $settings = craft()->request->getPost('algoliaSettings');
        $plugin = craft()->plugins->getPlugin($pluginClass);
        $settings = ['algoliaSettings' => $settings];

        if (craft()->plugins->savePluginSettings($plugin, $settings)) {

            craft()->searchPlus_log->note('Settings updated : Algolia Credentials', $settings);

            $valid = craft()->searchPlus_algolia->validateConnection(true);
            if ($valid) {
                // Ok. Good to go. Onward to Indexes
                craft()->userSession->setNotice(Craft::t('Algolia connection saved.'));
                $this->redirectToPostedUrl();
            }

            craft()->searchPlus_log->error('Connection validation failed with updated credentials');
            // Nope.
        }
        craft()->userSession->setError(Craft::t('Couldn’t validate Algolia settings.'));

        // Send the plugin back to the template
        craft()->urlManager->setRouteVariables([]);
    }

    public function actionEditIndex(array $variables = [])
    {
        $variables['new'] = true;
        $variables['canCreateIndexes'] = craft()->searchPlus_algolia->canCreateIndexes();

        if (isset($variables['indexId'])) {
            $variables['new'] = false;
            $variables['index'] = craft()->searchPlus_algolia->getIndex($variables['indexId'], true);
        }

        $variables['elementOptions'] = craft()->searchPlus_elements->getSupportedOptions();
        foreach (craft()->searchPlus_algoliaMap->getMappingOptions() as $option) {
            $variables['mappingOptions'][] = ['value' => $option['handle'], 'label' => $option['name'] . ' - ' . $option['description']];
        }

        // Is this a post request?
        if (craft()->request->isPostRequest()) {

            $name = craft()->request->getPost('name');
            $handle = craft()->request->getPost('handle');
            $indexId = craft()->request->getPost('indexId');
            $sectionMap = craft()->request->getPost('sectionMap');
            $contentMap = craft()->request->getPost('contentMap');

            if($indexId != '') {
                // Update
                $map =  craft()->searchPlus_algolia->getIndex($indexId);
                $map->name = $name;
                $map->handle = $handle;
                $map->sectionMap = $sectionMap;
                $map->contentMap = $contentMap;
                $map->status = 'pending';

                if (craft()->searchPlus_algoliaMap->saveMap($map)) {
                    $this->redirect('searchplus/algolia/manageIndex/' . $map->id);
                    exit();
                }

            } else {
                $map = new SearchPlus_AlgoliaMapModel();
                $map->name = $name;
                $map->handle = $handle;
                $map->sectionMap = $sectionMap;
                $map->contentMap = $contentMap;
                $map->status = 'pending';

                if($variables['canCreateIndexes'] == true) {
                
                    if (craft()->searchPlus_algoliaMap->saveMap($map)) {
                        $this->redirect('searchplus/algolia/manageIndex/' . $map->id);
                        exit();
                    }
    
                } else {
                    $map->addError('general', 'Sorry, you cannot create any more indexes with your current Search Plus tier. Upgrade to Pro for unlimited indexes');
                }

            }


           

            // Push back with errors
            craft()->urlManager->setRouteVariables([
                'new'       => false,
                'index'     => $map,
                'allErrors' => $map->getErrors()
            ]);

            $this->variables['new'] = true;
            $this->variables['allErrors'] = $map->getErrors();
            $this->variables['index'] = $map;
            $this->variables = array_merge($variables, $this->variables);
            $this->renderTemplate('searchPlus/algolia/_editIndex', $this->variables);


        } else {

            $this->variables = array_merge($variables, $this->variables);
            $this->renderTemplate('searchPlus/algolia/_editIndex', $this->variables);
        }

    }

    public function actionStartPopulationTask()
    {
        $this->requireAdmin();
        $this->requirePostRequest();
        $indexId = craft()->request->getPost('indexId');

        craft()->searchPlus_log->info('Created new index population task for index id - '.$indexId);
        
        $task = craft()->tasks->createTask('SearchPlus_Population', Craft::t('Populating Index..'), ['mappingId' => $indexId]);

        if(craft()->request->isAjaxRequest()) {
            $return = ['success' => true, 'taskId' => $task->id];
            $this->returnJson($return);
        } else {
            $this->redirectToPostedUrl();
        }
    }

    public function actionViewEdition(array $variables = [])
    {
        $this->requireAdmin();


        $this->variables = array_merge($variables, $this->variables);
        $this->renderTemplate('searchPlus/settings/_edition', $this->variables);
    }

    public function actionViewMappings(array $variables = [])
    {
        $this->requireAdmin();

        $variables['mappingOptions'] = craft()->searchPlus_algoliaMap->getMappingOptions();

        $this->variables = array_merge($variables, $this->variables);
        $this->renderTemplate('searchPlus/algolia/_mappings', $this->variables);
    }

    public function actionTestMapping() 
    {
        $this->requireAdmin();

        $type = craft()->request->getPost('type');
        $handle = craft()->request->getPost('handle');
        
        $elementId = '';
        $elementIds = craft()->request->getPost('elementId');
        if(is_array($elementIds) && count($elementIds) > 0) {
            $elementId = $elementIds[0];
        }

        $url = UrlHelper::getCpUrl('searchplus/settings/mappings/test', ['elementId' => $elementId, 'type' => $type, 'handle' => $handle]);
        $this->redirect($url);
    }

    public function actionViewMappingOutput(array $variables = [])
    {
        $this->requireAdmin();

        $handle = craft()->request->getQuery('handle');
        $id = craft()->request->getQuery('elementId');
        $type =  craft()->request->getQuery('type', 'Entry');


        if ($handle == '') $this->redirect('searchplus/settings/mappings');

        $item = craft()->searchPlus_algoliaMap->getTestItem($id, $type);

        $variables['map'] = craft()->searchPlus_algoliaMap->getMappingFromHandle($handle);
        $variables['example'] = craft()->searchPlus_algoliaMap->testOutput($handle, $item);
        $variables['exampleJson'] = json_encode($variables['example']);
        $variables['elementType'] = craft()->elements->getElementType($type);
        $variables['elementClass'] = $type;
        $variables['testItem'] = $item;


        if (!craft()->config->get('devMode')) {
            craft()->templates->getTwig()->addExtension(new \Twig_Extension_Debug());
        }

        $this->variables = array_merge($variables, $this->variables);
        $this->renderTemplate('searchPlus/algolia/_mappingTest', $this->variables);
    }

    public function actionPopulate(array $variables = [])
    {
        // Is this a post request?
        if (craft()->request->isPostRequest()) {
            $index = craft()->searchPlus_algolia->getIndex(craft()->request->getPost('indexId'));
            $type = craft()->request->getPost('type');
            if ($index == false || !in_array($type, ['clear', 'collect', 'map', 'transfer'])) return $this->redirect('searchplus/indexes');
            craft()->searchPlus_algolia->indexPopulateAction($index, $type);

            craft()->userSession->setNotice(Craft::t('Populate Action - '.$type.' completed'));
            $this->redirectToPostedUrl($index);
        }

        $variables['index'] = craft()->searchPlus_algolia->getIndex($variables['indexId'], true);

        // Get the various states of the queue so we can show some debug
        $variables['queueStats'] = craft()->searchPlus_population->getQueueStats();

        $this->variables = array_merge($variables, $this->variables);
        $this->renderTemplate('searchplus/algolia/_populate', $this->variables);
    }

}


