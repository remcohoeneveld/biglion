<?php
namespace Craft;

class SearchPlus_IndexItemRecord extends BaseRecord
{
    public function getTableName()
    {
        return 'searchplus_indexitem';
    }

    protected function defineAttributes()
    {
        return [
            'mappingId' => [AttributeType::Number],
            'elementId' => [AttributeType::Number],
            'mappedData' => [AttributeType::Mixed],
            'status' => [AttributeType::String],
            'elementType' => [AttributeType::String],
        ];
    }
}



