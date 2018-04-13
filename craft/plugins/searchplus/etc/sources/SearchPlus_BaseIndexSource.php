<?php
namespace Craft;

abstract class SearchPlus_BaseIndexSource
{

    public $elementType = null;

    /**
     * Maps an item (probably a BaseElementModel) against a specific mapping.
     *
     * @param $item
     * @param $mapping
     *
     * @return bool
     */
    public function mapItem($item, $mapping)
    {
        $temp = craft()->searchPlus_algoliaMap->mapItem($item, $mapping->contentMap);

        if ($temp == null || $temp == false || empty($temp)) {
            // nope
            return false;
        } else {
            // Also check validity.
            // We always need an objectId;
            if (!isset($temp['objectID']) || $temp['objectID'] === '' || $temp['objectID'] == null) {
                // Nope.
                // Stop right there mister
                return false;
            }

            return $temp;
        }

        return false;
    }

    /**
     * Returns all the maps for a given sourceId/elementType/sourcesHandle combo.
     *
     * @param $sourceId
     * @param $elementType
     * @param $sourcesHandle
     *
     * @return array
     */
    public function getMapsForSource($sourceId, $elementType, $sourcesHandle)
    {
        $ret = [];

        $possibleMaps = SearchPlus_AlgoliaMapRecord::model()->findAll();
        $possibleMaps = SearchPlus_AlgoliaMapModel::populateModels($possibleMaps);

        foreach($possibleMaps as $map) {
            if(isset($map->sectionMap['options'][$elementType][$sourcesHandle])) {
                if(in_array($sourceId, $map->sectionMap['options'][$elementType][$sourcesHandle])) {
                    $ret[] = $map;
                }
            }
        }

        return $ret;
    }

}