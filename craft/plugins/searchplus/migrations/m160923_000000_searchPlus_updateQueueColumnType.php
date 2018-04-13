<?php
namespace Craft;

class m160923_000000_searchPlus_updateQueueColumnType extends BaseMigration
{
    public function safeUp()
    {
        $this->alterColumn('searchplus_indexitem', 'mappedData', array('column' => ColumnType::MediumText));

        return true;
    }
}
