<?php
namespace Craft;

class m160829_000000_searchPlus_updateLogTableSchema extends BaseMigration
{
    public function safeUp()
    {
        $searchPlusTable = $this->dbConnection->schema->getTable('{{searchplus_logs}}');

        if ($searchPlusTable->getColumn('requestKey') === null)
        {
            $this->addColumnAfter('searchplus_logs', 'requestKey', array('column' => ColumnType::Varchar), 'level');
        }

        if ($searchPlusTable->getColumn('source') === null)
        {
            $this->addColumnAfter('searchplus_logs', 'source', array('column' => ColumnType::Varchar), 'level');
        }


        if ($searchPlusTable->getColumn('message') != null)
        {
            $this->dropColumn('searchplus_logs', 'message');
        }


        if ($searchPlusTable->getColumn('result') != null)
        {
            $this->dropColumn('searchplus_logs', 'result');
        }


        if ($searchPlusTable->getColumn('related') != null)
        {
            $this->dropColumn('searchplus_logs', 'related');
        }

        return true;
    }
}
