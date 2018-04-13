<?php
namespace Craft;

class SearchPlus_LogRecord extends BaseRecord
{
    public function getTableName()
    {
        return 'searchplus_logs';
    }

    protected function defineAttributes()
    {
        return [
            'level'       => [AttributeType::String],
            'requestKey'  => [AttributeType::String],
            'type'        => [AttributeType::String],
            'source'      => [AttributeType::String],
            'extra'       => [AttributeType::Mixed]
        ];
    }
}


