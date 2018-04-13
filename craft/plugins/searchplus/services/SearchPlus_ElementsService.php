<?php
namespace Craft;

class SearchPlus_ElementsService extends BaseApplicationComponent
{

    private $indexSources = [];

    public function init()
    {

        // Load up the index sources
        $pluginIndexSources = craft()->plugins->call('registerSearchPlusIndexSources');
        foreach ($pluginIndexSources as $pluginHandle => $pluginIndexSourceArray) {
            foreach ($pluginIndexSourceArray as $indexSource) {

                $component = Craft::createComponent("Craft\\".$indexSource);
                if ($component)
                {
                    $this->indexSources[$component->elementType] = $component;
                }

            }
        }

        parent::init();

    }

    /**
     * Gets all the possible options for element mapping
     *
     * @return array
     */
    public function getSupportedOptions()
    {

        $return = [];

        foreach ($this->indexSources as $indexSource) {
            $return[] = $indexSource->getOptions();
        }

        return $return;
    }

    /**
     * Builds the array vars for the table view in the CP index list for a mapping
     *
     * @param $map
     * @return array
     */
    public function buildRows($map)
    {
        $return = [];

        $elements = $map['elements'];
        $options = [];
        if(isset($map['options'])) $options = $map['options'];

        foreach($elements as $element) {
            if (isset($options[$element])) {

                $elementTitle = $element;
                if($element === 'Commerce_Product') {
                    $elementTitle = 'Commerce Products';
                }
                $return[] = ['type' => 'heading', 'label' => $elementTitle];

                foreach ($options[$element] as $key => $sourceIds) {
                    foreach ($sourceIds as $sourceId) {
                        $return[] = ['type' => 'row', 'label' => $this->indexSources[$element]->getSourceName($sourceId)];
                    }
                }
            }
        }

        return $return;
    }


    /**
     * Gets the total count of elements in an option set of elements
     *
     * @param $optionSet
     * @return int
     */
    public function getCountsForOptionSet($optionSet)
    {
        $counts = $this->getGroupedCountsForOptionSet($optionSet);

        $c = 0;
        foreach($counts as $count) {
            $c = $c + $count;
        }

        return $c;
    }


    /**
     * Gets the counts for the elements in an option set, and returns by element type group
     *
     * @param $optionSet
     * @return array
     */
    public function getGroupedCountsForOptionSet($optionSet)
    {
        $counts = [];

        foreach ($this->indexSources as $indexSource) {
            $c = $indexSource->getCountsForOptionSet($optionSet);
            if ($c) {
                $counts[$indexSource->elementType] = $c;
            }
        }

        return $counts;
    }

    /**
     * Queues all the items in an index
     * @param $optionSet
     * @param $mappingId
     * @return bool
     */
    public function queueItemsForIndexingByOptionSet($optionSet, $mappingId)
    {
        $counts = $this->getGroupedCountsForOptionSet($optionSet);
        if (empty($counts)) return true; // Nothing to do

        foreach ($this->indexSources as $indexSource) {
            $indexSource->queueItemsForPopulation($optionSet, $mappingId);
        }

        return true;
    }

}
