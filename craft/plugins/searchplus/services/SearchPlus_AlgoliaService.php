<?php
namespace Craft;

//use Mockery\CountValidator\Exception;

class SearchPlus_AlgoliaService extends BaseApplicationComponent
{
    public $enabled = false;
    public $applicationId = null;
    public $searchApiKey = null;
    public $errors = [];
    public $indexes = [];
    public $usingEnvPrefix = false;
    private $settings;
    private $adminApiKey = null;
    private $indexPrefix = null;
    private $configKeys = ['applicationId', 'searchApiKey', 'adminApiKey', 'indexPrefix'];
    private $algoliaConnectionCache = 'algoliaConnectionCached';
    private $batchSize = 10;
    private $apiAnalyticsBase = 'https://analytics.algolia.com/1/';
    private $indexFreeLimit = 3;
    public $itemFreeLimit = 250;
    private $currentMapping;
    private $mappings = [];
    private $supportedElementTypes = [];
    private $indexSources = [];

    public function init()
    {
        $this->fetchSettings();
        $this->enabled = $this->validateConnection();
        $this->initIndexSources();
    }

    private function initIndexSources()
    {
        $supportedElementTypes = [];
        $pluginIndexSources = craft()->plugins->call('registerSearchPlusIndexSources');

        foreach ($pluginIndexSources as $pluginHandle => $pluginIndexSourceArray) {
            foreach ($pluginIndexSourceArray as $indexSource) {
                // Load up the index source class if we can
                $component = Craft::createComponent("Craft\\".$indexSource);
                if ($component)
                {
                    $supportedElementTypes[] = $component->elementType;
                    $this->indexSources[$component->elementType] = $component;
                }
            }
        }

        $this->supportedElementTypes = array_unique($supportedElementTypes);
    }

    private function fetchSettings()
    {
        $this->settings = craft()->searchPlus->settings['algoliaSettings'];
        $plugin = craft()->plugins->getPlugin('searchPlus');
        $plugin->initAutoloader();

        // Assign
        foreach ($this->configKeys as $key) {
            if (isset($this->settings[$key])) {
                $this->$key = $this->settings[$key];
            }
        }
    }

    public function validateConnection($flushSettings = false)
    {
        $flushSettings = true;

        if ($flushSettings) {
            $this->fetchSettings();
        }

        if ($this->applicationId == null || $this->searchApiKey == null || $this->adminApiKey == null) {
            return false;
        }

        if (!craft()->cache->get($this->algoliaConnectionCache) || $flushSettings) {
            $client = $this->client();
            try {
                // Just a blind test
                $indexes = $client->listIndexes();
                $this->indexes = $this->getAllIndexes($indexes['items']);
                craft()->cache->set($this->algoliaConnectionCache, $this->indexes);

            } catch (\AlgoliaSearch\AlgoliaException $e) {
                $this->errors[] = $e->getMessage();

                return false;
            }
        } else {
            $this->indexes = craft()->cache->get($this->algoliaConnectionCache);
        }

        return true;
    }

    private function getAllIndexes($remoteIndexes = [])
    {
        $indexRecords = SearchPlus_AlgoliaMapRecord::model()->findAll();
        $indexes = SearchPlus_AlgoliaMapModel::populateModels($indexRecords);
        $options = [];

        // Check the status of our locals, and unset any remote that we have

        foreach ($indexes as $key => $index) {
            $matched = false;
            // Map it to any remote
            foreach ($remoteIndexes as $remoteKey => $remoteIndex) {

                if (is_null($index->indexMap) || !is_array($index->indexMap)) {
                    continue;
                }


                // check each item in the index map for a match
                foreach ($index->indexMap as $env => $indexName) {
                    if ($indexName == $remoteIndex['name']) {

                        unset($remoteIndexes[$remoteKey]);
                        $matched = true;
                        $indexes[$key]->entries = $remoteIndex['entries'];
                    }
                }

            }

            if (!$matched) {
                // Doesn't exist in remote
                $indexes[$key]->status = 'remote';
            }

            if (isset($index['sectionMap']['options'])) {
                $options[$key] = $index['sectionMap']['options'];
            }
        }


        // Now add any that don't exist as local maps
        foreach ($remoteIndexes as $remoteKey => $remoteIndex) {
            $map = new SearchPlus_AlgoliaMapModel();
            $map->remoteName = $remoteIndex['name'];
            $map->status = 'unmapped';
            $map->indexMap = null;
            $map->sectionMap = null;
            $map->entries = $remoteIndex['entries'];

            $indexes[] = $map;
        }


        // Get an element count for all the maps we have
        $counts = $this->getElementCountsForOptions($options);

        foreach ($indexes as $key => $index) {
            if (isset($counts[$key])) {
                $indexes[$key]->elements = $counts[$key];
                //$indexes[$key]->sectionName = $counts[$key]['name'];
                //$indexes[$key]->sectionHandle = $counts[$key]['handle'];

                // Also set the status
                if ($indexes[$key]->elements == $indexes[$key]->entries) {
                    $indexes[$key]->populationStatus = 'uptodate';
                } else if ($indexes[$key]->entries < 1) {
                    $indexes[$key]->populationStatus = 'empty';
                } else {
                    $indexes[$key]->populationStatus = 'outofsync';
                }
            }
        }


        // if we have a prefix we'll also clean out any invalid items in there
        //$indexes = $this->removeNonPrefixIndexes($indexes);

        return $indexes;
    }

    public function getIndex($indexId, $force = false)
    {
        if ($force) {
            // Clear our local cache, and refresh
            $this->validateConnection();
        }

        foreach ($this->indexes as $index) {
            if ($index->id == $indexId) {
                return $index;
            }
        }

        // Nope
        return false;
    }

    public function getIndexNameByHandle($handle)
    {
        $index = $this->getIndexByHandle($handle);

        if ($index == false) return '';

        $name = '';
        if (is_array($index->indexMap) and isset($index->indexMap['*'])) {
            $name = $index->indexMap['*'];  // @todo this is a hack to later enable multi indexes
        }

        return $name;
    }

    public function getIndexByHandle($handle)
    {
        foreach ($this->indexes as $index) {
            if ($index->handle == $handle) {
                return $index;
            }
        }

        // Nope
        return false;
    }

    public function getIndexByName($name)
    {
        foreach ($this->indexes as $index) {
            if ($index->name == $name) {
                return $index;
            }
        }

        // Nope
        return false;
    }

    public function getIndexAnalytics($indexId)
    {
        $index = $this->getIndex($indexId);
        if ($index == false) return false;

        try {

            $client = new \Guzzle\Http\Client($this->apiAnalyticsBase);
            $request = $client->get('searches/' . $index['index'] . '/popular');

            $request->addHeader('X-Algolia-API-Key', $this->adminApiKey);
            $request->addHeader('X-Algolia-Application-Id', $this->applicationId);

            $response = $request->send();

            if ($response->isSuccessful()) {
                $json = $response->getBody();
                $json = json_decode($json, true);

                return $json;
            }

        } catch (\Exception $e) {
            return false;
        }

        return false;


    }

    public function getMapByName($indexName)
    {
        $indexRecord = SearchPlus_AlgoliaMapRecord::model()->findByAttributes(['index' => $indexName]);
        if ($indexRecord == null) return false;
        $index = SearchPlus_AlgoliaMapModel::populateModel($indexRecord);

        return $index;
    }

    public function getMap($indexId)
    {
        $indexRecord = SearchPlus_AlgoliaMapRecord::model()->findByAttributes(['id' => $indexId]);
        if ($indexRecord == null) return false;
        $index = SearchPlus_AlgoliaMapModel::populateModel($indexRecord);

        return $index;
    }

    public function elementsSavedEvent($elementIds)
    {
        if (!$this->enabled) {
            return;
        }

        $supportedElementIds = $this->_trimUnsupportedElementTypes($elementIds);

        foreach ($supportedElementIds as $elementId) {
            $element = craft()->elements->getElementById($elementId);

            if (!is_null($element)) {
                $this->elementSavedEvent($element);
            }
        }

        return;
    }

    public function elementSavedEvent($element)
    {
        if (!$this->enabled) {
            return;
        }

        // we'll figure out the element types directly on the database
        // this will save hitting the element service item by item
        $supportedElementIds = $this->_trimUnsupportedElementTypes([$element->id]);
        if(empty($supportedElementIds)) return;

        // Shortlist might be overriding this
        // We'll be kind and check the class
        if(get_class($element) === 'Craft\Shortlist_ItemModel') {
            return;
        }

        // Is this in our supported types?
        if (!in_array($element->elementType, $this->supportedElementTypes)) {
            return;
        }

        craft()->searchPlus_log->info('onSave saved event on '.$element->id.' - ('.$element->elementType.')');

        $this->indexSources[$element->elementType]->onSave($element);

        return;
    }

    public function elementsDeletedEvent($elementIds)
    {
        if (!$this->enabled) {
            return;
        }

        // we'll figure out the element types directly on the database
        // this will save hitting the element service item by item
        $supportedElementIds = $this->_trimUnsupportedElementTypes($elementIds);


        foreach ($supportedElementIds as $elementId) {
            $element = craft()->elements->getElementById($elementId);

            if (!is_null($element)) {
                $this->elementDeletedEvent($element);
            }
        }

        return;
    }

    public function elementDeletedEvent($element)
    {
        // Is this in our supported types?
        if (!in_array($element->elementType, $this->supportedElementTypes)) {
            return;
        }

        craft()->searchPlus_log->info('Element deleted event on '.$element->id.' - ('.$element->elementType.')');
        $this->indexSources[$element->elementType]->onDelete($element);
    }

    public function indexAdminAction(SearchPlus_AlgoliaMapModel $index, $type, $extra = [])
    {
        $result = null;

        switch ($type) {
            case 'clear' :
                $result = $this->clearIndex($index);
                break;
            case 'delete' :
                $result = $this->deleteIndex($index);
                break;
            case 'unmap' :
                $result = $this->unmapIndex($index);
                break;
        }


        return $result;
    }

    public function canPopulateUnlimited()
    {
        $edition = craft()->searchPlus_license->getEdition();
        if ($edition > 0) return true;

        return false;
    }

    public function canCreateIndexes()
    {
        $edition = craft()->searchPlus_license->getEdition();

        if ($edition > 0) return true;

        if (count($this->getAllIndexes()) < $this->indexFreeLimit) return true;

        return false;
    }

    private function getElementCountsForOptions($options = [])
    {
        if (empty($options)) return [];

        $return = [];
        foreach ($options as $key => $optionSet) {
            $return[$key] = craft()->searchPlus_elements->getCountsForOptionSet($optionSet);
        }

        return $return;
    }

    public function uploadBatchForIndexById($indexId, $batch)
    {
        craft()->searchPlus_log->task('Uploading batch to algolia', ['indexId' => $indexId, 'batch' => $batch]);

        $mapping = $this->getIndex($indexId);
        $index = $mapping->indexMap['*'];

        return $this->saveObjects($index, $batch);
    }

    public function saveObjects($index, $batch)
    {
        $client = $this->client();

        try {
            $index = $client->initIndex($index);
            $index->saveObjects($batch);


            craft()->searchPlus_log->api('Saved objects to algolia index', ['batch' => $batch]);
            return true;

        } catch (\AlgoliaSearch\AlgoliaException $e) {
            craft()->searchPlus_log->exception('Exception while uploading mapped content to algolia', ['exception' => $e->getMessage(), 'index' => $index]);
            $this->errors[] = $e->getMessage();
        }

        return false;
    }

    public function deleteObject($index, $id)
    {
        $client = $this->client();

        try {
            $index = $client->initIndex($index);
            $index->deleteObject($id);

            craft()->searchPlus_log->api('Deleted object from algolia index', ['id' => $id]);
            return true;

        } catch (\AlgoliaSearch\AlgoliaException $e) {
            craft()->searchPlus_log->exception('Exception while deleting item from algolai', ['exception' => $e->getMessage(), 'id' => $id]);            
            $this->errors[] = $e->getMessage();
        }

        return false;
    }

    public function getIndexPrefix()
    {
        if (!is_null($this->indexPrefix) && $this->indexPrefix != '') {
            $prefix = $this->indexPrefix . '_';
        } else {
            // Use the site env as the prefix
            $prefix = str_replace('.', '_', CRAFT_ENVIRONMENT);
            $this->usingEnvPrefix = true;
        }

        return $prefix;
    }

    public function mapItemsByType($elementType, $items)
    {
        $itemIds = [];
        $itemsById = [];
        foreach ($items as $item) {
            $itemIds[] = $item->elementId;
            $itemsById[$item->elementId] = $item;

            if (!isset($this->mappings[$item->mappingId])) {
                $this->mappings[$item->mappingId] = $this->getMap($item->mappingId);
            }
        }

        $criteria = craft()->elements->getCriteria($elementType);
        $criteria->id = $itemIds;

        $elements = $criteria->find();
        if (empty($elements) || $elements == null) return null;


        foreach ($elements as $key => $element) {

            $currentMapping = $this->mappings[$itemsById[$element->id]['mappingId']];

            $temp = $this->mapItem($element, $currentMapping);
            if ($temp != false) {
                $itemsById[$element->id]->mappedData = $temp;
                $itemsById[$element->id]->status = 'mapped';
            } else {
                $itemsById[$element->id]->mappedData = null;
                $itemsById[$element->id]->status = 'error';
            }
        }

        return $itemsById;
    }

    private function mapItem($item, $mapping)
    {
        $temp = craft()->searchPlus_algoliaMap->mapItem($item, $mapping->contentMap);

        if ($temp == null || $temp == false || empty($temp)) {
            // nope
            craft()->searchPlus_log->note('Mapped item returned with empty map. Skipping', ['map' => $temp]);
            return false;
        } else {
            // Also check validity.
            // We always need an objectId;
            if (!isset($temp['objectID']) || $temp['objectID'] == '' || $temp['objectID'] == null) {
                // Nope.
                // Stop right there mister
                craft()->searchPlus_log->error('Caught mapped item with no object id, preventing return', ['map' => $temp]);
                return false;
            }
 
            craft()->searchPlus_log->success('('.$temp['objectID'].') - item mapped', ['map' => $temp]);
            return $temp;
        }

        craft()->searchPlus_log->error('Failed to map item.', ['map' => $temp]);
        return false;
    }

    private function client()
    {
        $client = new \AlgoliaSearch\Client($this->applicationId, $this->adminApiKey);

        return $client;
    }

    private function clearIndex(SearchPlus_AlgoliaMapModel $map)
    {
        $index = $map->indexMap['*'];
        $index = $this->client()->initIndex($index);
        $index->clearIndex();

        craft()->searchPlus_log->success('Algolia index cleared - '.$map->name, ['map' => $map]);

        return true;
    }

    private function deleteIndex(SearchPlus_AlgoliaMapModel $map)
    {
        $index = $map->indexMap['*'];
        $this->client()->deleteIndex($index);

        craft()->searchPlus_log->success('Algolia index deleted - '.$map->name, ['map' => $map]);

        return $this->unmapIndex($map);
    }

    private function unmapIndex(SearchPlus_AlgoliaMapModel $map)
    {
        $record = SearchPlus_AlgoliaMapRecord::model()->findById($map->id);

        craft()->searchPlus_log->success('Index unampped - '.$map->name, ['map' => $map]);
        return $record->deleteByPk($map->id);
    }

    private function _trimUnsupportedElementTypes($elementIds)
    {
        $elementsTable = craft()->db->addTablePrefix('elements');

        $sql = "SELECT id FROM ".$elementsTable." WHERE id IN ";
        $sql .= "(" . implode(',',$elementIds) .") ";
        $sql .= " AND type IN ";
        $sql .= "('" . implode('\',\'',$this->supportedElementTypes) ."') ";
        $res = craft()->db->createCommand($sql)->queryAll();

        $results = [];
        foreach($res as $row) {
            $results[] = $row['id'];
        }

        return $results;
    }


    public function indexPopulateAction(SearchPlus_AlgoliaMapModel $index, $type)
    {
        $result = null;

        switch ($type) {
            case 'clear' :
                $result = $this->clearQueue();
                break;
            case 'collect':
                $result = $this->collectItems($index);
                break;

            case 'map':
                $result = $this->mapItems();
                break;

            case 'transfer':
                $result = $this->transferItems();
                break;


        }


        return $result;
    }

    private function transferItems()
    {
        return craft()->searchPlus_population->workMappedItemsInQueue();
    }


    private function mapItems()
    {
        return craft()->searchPlus_population->workPendingItemsInQueue();
    }


    private function collectItems($index)
    {
        return craft()->searchPlus_population->setupItemsForPopulation($index);
    }

    private function clearQueue()
    {
        return craft()->searchPlus_population->clearQueueCompletely();
    }

}
