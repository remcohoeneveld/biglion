<?php
namespace Craft;

class SearchPlus_CategoryIndexSource extends SearchPlus_BaseIndexSource
{

    public $elementType = 'Category';

    /**
     * Gets the variable array for category groups, used in the CP edit views
     *
     * @return array
     */
    public function getOptions()
    {
        $return = [
            'name' => $this->elementType,
            'label' => 'Categories'
        ];


        $groups = [];
        foreach (craft()->categories->getAllGroups() as $group) {
            $groups[] = [
                'label' => $group->name,
                'value' => $group->id
            ];
        }

        $return['options'][] = [
            'name' => 'Groups',
            'handle' => 'groups',
            'instructions' => 'What groups do you want indexed?',
            'options' => $groups
        ];

        return $return;
    }

    /**
     * Gets the category group name by ID.
     *
     * @param $sourceId
     *
     * @return mixed|string
     */
    public function getSourceName($sourceId)
    {
        $group = craft()->categories->getGroupById($sourceId);

        if(is_null($group)) return '';
        return $group->name;
    }

    /**
     * Counts the number of categories by group id
     *
     * @param $optionSet
     *
     * @return int
     */
    public function getCountsForOptionSet($optionSet)
    {

        if(isset($optionSet['Category']['groups'])) {

            $groupIds = $optionSet['Category']['groups'];

            if(empty($groupIds)) return 0;
            $c = 0;

            // Build a query for this
            $inGroups = '('.implode(',', $groupIds).')';
            $query = craft()->db->createCommand();
            $query->select('count(*) c, categories.groupId, categorygroups.name, categorygroups.handle')
                ->from('categories categories')
                ->join('categorygroups categorygroups', 'categorygroups.id = categories.groupId')
                ->join('elements elements', 'categories.id = elements.id')
                ->where('categorygroups.id IN '.$inGroups)
                ->andWhere('elements.enabled = 1')
                ->group('categories.groupId');

            $res = $query->queryAll();

            $c = 0;
            foreach ($res as $row) {
                $c += $row['c'];
            }

            return $c;

        }

    }

    /**
     * Queues the category items for later population by group id
     * We'll do this direct by an insert-select for maximum SPEEDZ
     *
     * @param $optionSet
     * @param $mappingId
     *
     * @return bool
     */
    public function queueItemsForPopulation($optionSet, $mappingId)
    {

        if(isset($optionSet['Category']['groups'])) {

            $groupIds = $optionSet['Category']['groups'];
            if (empty($groupIds)) {
                return false;
            }

            $inGroups = '('.implode(',', $groupIds).')';
            $currentTime = DateTimeHelper::currentTimeForDb();
            $sql = "INSERT INTO craft_searchplus_indexitem (elementId, elementType, mappingId, status, dateCreated, dateUpdated)
SELECT categories.id, 'Category', ".$mappingId.", 'pending', '".$currentTime."', '".$currentTime."'
FROM craft_categories categories
JOIN craft_elements elements ON categories.id = elements.id
WHERE categories.groupId IN ".$inGroups."
AND elements.enabled = 1";

            craft()->db->createCommand($sql)->execute();
        }

        return true;

    }

    /**
     * Handles the category on save event.
     *
     * @param CategoryModel $category
     *
     * @return bool|void
     */
    public function onSave(CategoryModel $category)
    {

        $maps = $this->getMapsForSource($category->groupId, $this->elementType, 'groups'); // There might be multiple

        if (empty($maps)) {
            return;
        }


        foreach ($maps as $map) {

            // This looks to be valid, so we'll pass it onward for population against it's map
            $temp = $this->mapItem($category, $map);
            if ($temp === false) {
                return;
            }

            $batch = [];
            $batch[] = $temp;

            $index = $map->indexMap['*'];  // @todo this is a hack to later enable multi indexes


            if (!craft()->searchPlus_algolia->canPopulateUnlimited()) {
                // We need to check if this goes too far beyond the limit
                $algoliaIndex = craft()->searchPlus_algolia->getIndexByName($index);
                if ($algoliaIndex !== false) {
                    if ($algoliaIndex->entries > craft()->searchPlus_algolia->itemFreeLimit) {
                        return;
                    }
                }
            }

            //craft()->searchPlus_log->success('element_indexed');//, ['elementId' => $entry->id, 'title' => $entry->title, 'type' => 'Entry'];
            craft()->searchPlus_algolia->saveObjects($index, $batch);
        }

        return true;
    }

    /**
     * Handles the category on delete event.
     *
     * @param CategoryModel $category
     *
     * @return bool|void
     */
    public function onDelete(CategoryModel $category)
    {
        $maps = $this->getMapsForSource($category->groupId, $this->elementType, 'groups'); // There might be multiple

        if (empty($maps)) {
            return;
        }

        foreach ($maps as $map) {

            // This looks to be valid, so we'll pass downward for removal
            $index = $map->indexMap['*'];  // @todo this is a hack to later enable multi indexes

            craft()->searchPlus_algolia->deleteObject($index, $category->id);
        }

        return true;
    }

    /**
     * Map category specific bits.
     *
     * @param CategoryModel $category
     *
     * @return array
     */
    public function mapSpecifics(CategoryModel $category)
    {
        $attr = ['groupId' => 'int'];
        $base = craft()->searchPlus_algoliaMap->getBaseContentForItem($category, $attr);
        $content = craft()->searchPlus_algoliaMap->getFieldContentForItem($category);

        return array_merge($base, $content);
    }

}