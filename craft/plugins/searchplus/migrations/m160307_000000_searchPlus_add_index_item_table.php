<?php
namespace Craft;

class m160307_000000_searchPlus_add_index_item_table extends BaseMigration
{
    public function safeUp()
    {
        // Create the indexitem table
        craft()->db->createCommand()->createTable('searchplus_indexitem', array(
            'mappingId'   => array('maxLength' => 11, 'decimals' => 0, 'unsigned' => false, 'length' => 10, 'column' => 'integer'),
            'elementId'   => array('maxLength' => 11, 'decimals' => 0, 'unsigned' => false, 'length' => 10, 'column' => 'integer'),
            'mappedData'  => array('column' => 'text'),
            'status'      => array(),
            'elementType' => array(),
        ), null, true);

    }
}
