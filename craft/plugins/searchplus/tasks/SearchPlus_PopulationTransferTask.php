<?php
namespace Craft;

class SearchPlus_PopulationTransferTask extends BaseTask
{
    protected function defineSettings()
    {
        return ['mappingId' => AttributeType::Number];
    }

    public function getDescription()
    {
        return Craft::t('Uploading data to Algolia');
    }

    public function getTotalSteps()
    {
        $mappingId = $this->getSettings()->mappingId;
        return craft()->searchPlus_population->countNumberOfMappingBatchesRequiredForUpload($mappingId);
    }

    public function runStep($step)
    {
        craft()->searchPlus_population->workMappedItemsInQueue();

        return true;
    }

}