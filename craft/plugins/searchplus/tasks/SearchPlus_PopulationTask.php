<?php
namespace Craft;

class SearchPlus_PopulationTask extends BaseTask
{
    protected function defineSettings()
    {
        return ['mappingId' => AttributeType::Number];
    }

    public function getDescription()
    {
        return Craft::t('Populating Search Indexes');
    }

    public function getTotalSteps()
    {
        $mappingId = $this->getSettings()->mappingId;        
        craft()->searchPlus_population->startPopulationTaskForIndexId($mappingId);
        $count = craft()->searchPlus_population->countNumberOfMappingBatchesRequiredForMapping($mappingId);
        craft()->searchPlus_log->task('Getting total required steps for population mapping - '.$count);
        return $count;
    }

    public function runStep($step)
    {
        craft()->searchPlus_log->task('Population Task - running step '.$step);
        $mappingId = $this->getSettings()->mappingId;
        craft()->searchPlus_log->task('Population Task - working queue items');
        craft()->searchPlus_population->workPendingItemsInQueue();

        // Any left to do?
        if(craft()->searchPlus_population->countNumberOfMappingBatchesRequiredForMapping($mappingId) < 1) {
            craft()->searchPlus_log->task('Population Task - finished queue, setting up trasnfer task');
            craft()->tasks->createTask('SearchPlus_PopulationTransfer', Craft::t('Uploading Mapped data to Algolia'), ['mappingId' => $mappingId]);
        } else {          
            craft()->searchPlus_log->task('Population Task - items remain in queue, continuing');
        }

        return true;
    }

}
