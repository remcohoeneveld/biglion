<?php
namespace Craft;

class SearchPlus_EntryIndexSource extends SearchPlus_BaseIndexSource
{

    public $elementType = 'Entry';

    /**
     * Gets the variable array for entry sections, used in the CP edit views
     *
     * @return array
     */
    public function getOptions()
    {
        $return = [
            'name' => $this->elementType,
            'label' => 'Entries',
            'default' => true,
        ];


        $sections = [];
        foreach (craft()->sections->getAllSections() as $section) {
            $sections[] = [
                'label' => $section->name,
                'value' => $section->id
            ];
        }

        $return['options'][] = [
            'name' => 'Sections',
            'handle' => 'sections',
            'instructions' => 'What sections do you want indexed?',
            'options' => $sections
        ];

        return $return;
    }

    /**
     * Gets the section name by ID.
     *
     * @param $sourceId
     *
     * @return mixed|string
     */
    public function getSourceName($sourceId)
    {
        $section = craft()->sections->getSectionById($sourceId);

        if(is_null($section)) return '';
        return $section->name;
    }

    /**
     * Counts the number of entries by section id
     *
     * @param $optionSet
     *
     * @return int
     */
    public function getCountsForOptionSet($optionSet)
    {

        if(isset($optionSet['Entry']['sections'])) {

            $sectionIds = $optionSet['Entry']['sections'];

            if(empty($sectionIds)) return 0;
            $c = 0;

            // Build a query for this
            $inSections = '('.implode(',', $sectionIds).')';
            // $query = craft()->db->createCommand();
            // $query->select('count(*) c, entries.sectionId, sections.name, sections.handle')
            //     ->from('entries entries')
            //     ->join('sections sections', 'sections.id = entries.sectionId')
            //     ->join('elements elements', 'entries.id = elements.id')
            //     ->where('sections.id IN '.$inSections)
            //     ->andWhere('elements.enabled = 1')
            //     ->group('entries.sectionId');

            $sql = "SELECT count(*) c, entries.sectionId, sections.name, sections.handle 
            FROM craft_entries entries
                JOIN craft_sections sections ON sections.id = entries.sectionId 
                JOIN craft_elements elements ON entries.id = elements.id 
            WHERE sections.id IN ".$inSections ."
                AND elements.enabled = 1
                AND (entries.expiryDate > NOW() 
                    OR entries.expiryDate IS NULL) 
                GROUP BY entries.sectionId";

            $res = craft()->db->createCommand($sql)->queryAll();

            // $res = $query->queryAll();

            $c = 0;
            foreach ($res as $row) {
                $c += $row['c'];
            }

            return $c;

        }

    }

    /**
     * Queues the entry items for later population by section id
     * We'll do this direct by an insert-select for maximum SPEEDZ
     *
     * @param $optionSet
     * @param $mappingId
     *
     * @return bool
     */
    public function queueItemsForPopulation($optionSet, $mappingId)
    {

        if(isset($optionSet['Entry']['sections'])) {

            $sectionIds = $optionSet['Entry']['sections'];
            if (empty($sectionIds)) {
                return false;
            }

            $inSections = '('.implode(',', $sectionIds).')';
            $currentTime = DateTimeHelper::currentTimeForDb();
            $sql = "INSERT INTO craft_searchplus_indexitem (elementId, elementType, mappingId, status, dateCreated, dateUpdated)
SELECT entries.id, 'Entry', ".$mappingId.", 'pending', '".$currentTime."', '".$currentTime."'
FROM craft_entries entries
JOIN craft_elements elements ON entries.id = elements.id
WHERE entries.sectionId IN ".$inSections."
AND (entries.expiryDate > NOW() 
    OR entries.expiryDate IS NULL) 
AND elements.enabled = 1";

            craft()->db->createCommand($sql)->execute();
        }

        return true;

    }

    /**
     * Handles the entry on save event.
     *
     * @param EntryModel $entry
     *
     * @return bool|void
     */
    public function onSave(EntryModel $entry)
    {
        $maps = $this->getMapsForSource($entry->sectionId, $this->elementType, 'sections'); // There might be multiple

        if (empty($maps)) {
            return;
        }


        foreach ($maps as $map) {

            // This looks to be valid, so we'll pass it onward for population against it's map
            $temp = $this->mapItem($entry, $map);
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
     * Handles the entry on delete event.
     *
     * @param EntryModel $entry
     *
     * @return bool|void
     */
    public function onDelete(EntryModel $entry)
    {
        $maps = $this->getMapsForSource($entry->sectionId, $this->elementType, 'sections'); // There might be multiple

        if (empty($maps)) {
            return;
        }

        foreach ($maps as $map) {

            // This looks to be valid, so we'll pass downward for removal
            $index = $map->indexMap['*'];  // @todo this is a hack to later enable multi indexes

            //craft()->searchPlus_log->success('element_deleted');//, ['elementId' => $entry->id, 'title' => $entry->title, 'type' => 'Entry'];
            craft()->searchPlus_algolia->deleteObject($index, $entry->id);
        }

        return true;
    }

    /**
     * Map entry specific bits.
     *
     * @param EntryModel $entry
     *
     * @return array
     */
    public function mapSpecifics(EntryModel $entry)
    {
        $attr = [
            'sectionId' => 'int',
            'typeId' => 'int'
        ];
        $base = craft()->searchPlus_algoliaMap->getBaseContentForItem($entry, $attr);
        $content = craft()->searchPlus_algoliaMap->getFieldContentForItem($entry);

        return array_merge($base, $content);
    }

}