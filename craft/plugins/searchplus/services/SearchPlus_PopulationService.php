<?php
namespace Craft;

class SearchPlus_PopulationService extends BaseApplicationComponent
{
    public $errors = null;
    private $currentMapping;
    private $batchSize = 10;
    private $uploadBatchSize = 1000;
    private $tableName = 'searchplus_indexitem';

    public function init()
    {
        $this->tableName = craft()->db->addTablePrefix($this->tableName);
    }

    /**
    * Get the stats about the current state of the queue.
    * Mostly used for debugging queue problems
    *
    * @return array
    */
    public function getQueueStats()
    {
        $return = [];

        $count = SearchPlus_IndexItemRecord::model()->count();
        $return['totalRows'] = $count;

        // Get pending items
        $count = SearchPlus_IndexItemRecord::model()->countByAttributes(['status' => 'pending']);
        $return['pendingItems'] = $count;

        // Get batch counts
        $count = $this->countNumberOfMappingBatchesRequiredForMapping();
        $return['batchCount'] = $count;

        // Get mapped items
        $count = SearchPlus_IndexItemRecord::model()->countByAttributes(['status' => 'mapped']);
        $return['mappedItems'] = $count;


        return $return;
    }


    /**
     * Starts the population process for an index
     * @param $indexId
     * @return bool
     */
    public function startPopulationTaskForIndexId($indexId)
    {

        craft()->searchPlus_log->task('Population Service - starting task for id - '.$indexId);
        $this->currentMapping = craft()->searchPlus_algolia->getIndex($indexId);
        if ($this->currentMapping == false) {
            $this->errors[] = 'Invalid Index';
            craft()->searchPlus_log->task('Population Service - invalid index');
            return false;
        }

        // Valid index. Let's get our item sets
        craft()->searchPlus_log->task('Population Service - setting up items for population');
        $result = $this->setupItemsForPopulation($this->currentMapping);
        if(!$result) {
            $this->errors[] = 'Failed to setup items in population queue';
            craft()->searchPlus_log->task('Population Service - failed to setup items for queue');
            return false;
        }

        craft()->searchPlus_log->task('Population Service - setup of population queue complete');
        return $result;
    }

    public function countNumberOfMappingBatchesRequiredForMapping($mappingId = '')
    {
        $attr = [];
        $attr['status'] = 'pending';
        if($mappingId != '') {
            $attr['mappingId'] = $mappingId;
        }
        $count = SearchPlus_IndexItemRecord::model()->countByAttributes($attr);
        $count = ceil($count / $this->batchSize);
        return $count;
    }


    public function countNumberOfMappingBatchesRequiredForUpload($mappingId)
    {
        $count = SearchPlus_IndexItemRecord::model()->countByAttributes(['status' => 'mapped', 'mappingId' => $mappingId]);
        $count = ceil($count / $this->uploadBatchSize);
        return $count;
    }


    public function workMappedItemsInQueue()
    {
        craft()->searchPlus_log->task('Working mapped items in queue');
        
        $items = $this->getNextMappedBatchFromQueue();
        if(empty($items)) return true;

        $batch = [];
        $ids = [];
        foreach($items as $item) {
            $ids[] = $item->id;
            $batch[$item->mappingId][] = $item->mappedData;
        }


        // Get the indexes required
        foreach($batch as $mappingId => $items) {
            craft()->searchPlus_algolia->uploadBatchForIndexById($mappingId, $items);
        }

        // Mark as populated
        $this->deleteUploadedBatchByIds($ids);

        return true;
    }


    public function workPendingItemsInQueue()
    {
        craft()->searchPlus_log->task('Population Service - working pending items');
        $items = $this->getNextPendingBatchFromQueue();
        craft()->searchPlus_log->task('Population Service - got items to work : '.count($items));
        if(empty($items)) return true;


        $types = [];
        foreach($items as $item) {
            $types[$item->elementType][] = $item;
        }

        craft()->searchPlus_log->task('Population Service - got items of types : '.count($types));

        $updatedCount = 0;
        foreach($types as $type => $items) {
            craft()->searchPlus_log->task('Population Service - working items of type : '.$type);
            $count = $this->getMappedDataForItemsByType($type, $items);
            $updatedCount = $updatedCount + $count;
        }

        return true;
    }

    /**
     * Sets up items for population in the queue based on a mapping id.
     * Calls down to the element service to actually perform the db update
     *
     * @param $mappingId
     * @return mixed
     */
    public function setupItemsForPopulation($mapping)
    {
        // Clear any pending for this mapping id
        $this->clearQueuePendingByMappingId($mapping->id);

        $mappingId = $mapping->id;
        craft()->searchPlus_log->task('Population Service - setup items for population for mapping id : '.$mappingId);
        return craft()->searchPlus_elements->queueItemsForIndexingByOptionSet($mapping->sectionMap['options'], $mappingId);
    }


    /**
     * Clears everything from the map queue
     * @param $mappingId
     */
    public function clearQueueCompletely()
    {
        $sql = "TRUNCATE ".$this->tableName;
        craft()->db->createCommand($sql)->execute();
    }


    /**
     * Clears any pending items by mapping id from the population queue
     * @param $mappingId
     */
    private function clearQueuePendingByMappingId($mappingId)
    {
        $sql = "DELETE FROM ".$this->tableName." WHERE status = 'pending' AND mappingId = '".$mappingId."'";
        craft()->db->createCommand($sql)->execute();
        $sql = "DELETE FROM ".$this->tableName." WHERE status = 'mapped' AND mappingId = '".$mappingId."'";

        craft()->db->createCommand($sql)->execute();
    }

    /**
     * Gets the next set of pending items from the index queue
     * @return array
     */
    private function getNextPendingBatchFromQueue()
    {
        $records = SearchPlus_IndexItemRecord::model()->findAllByAttributes(['status' => 'pending'],['order' => 'id asc', 'limit' => $this->batchSize]);
        return SearchPlus_IndexItemModel::populateModels($records);
    }

    /**
     * Gets the next set of mapped items from the index transfer
     * @return array
     */
    private function getNextMappedBatchFromQueue()
    {
        $records = SearchPlus_IndexItemRecord::model()->findAllByAttributes(['status' => 'mapped'],['order' => 'id asc', 'limit' => $this->uploadBatchSize]);
        return SearchPlus_IndexItemModel::populateModels($records);
    }

    private function deleteUploadedBatchByIds($ids)
    {
        SearchPlus_IndexItemRecord::model()->deleteAllByAttributes(['id' => $ids]);
        return;
    }

    private function getMappedDataForItemsByType($elementType, $items)
    {
        $items = craft()->searchPlus_algolia->mapItemsByType($elementType, $items);

        $updatedCount = 0;
        if($items != false && $items != null) {
            // Ok. Lets Update
            // Turn these models back to records and save to the queue
            foreach($items as $item) {
                $record = SearchPlus_IndexItemRecord::model()->findById($item->id);
                $record->status = $item->status;
                $record->mappedData = $item->mappedData;

                $record->update();
                $updatedCount++;
            }
        }

        return $updatedCount;
    }
}
