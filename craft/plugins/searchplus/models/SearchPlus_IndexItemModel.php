<?php
namespace Craft;

class SearchPlus_IndexItemModel extends BaseModel
{
    protected function defineAttributes()
    {
        return [
            'id'          => [AttributeType::Number],
            'mappingId'   => [AttributeType::Number],
            'elementId'   => [AttributeType::Number],
            'mappedData'  => [AttributeType::Mixed],
            'status'      => [AttributeType::String],
            'elementType' => [AttributeType::String],
            'dateCreated' => [AttributeType::DateTime]
        ];
    }
}



