<?php
namespace Craft;

class SearchPlus_LogModel extends BaseModel
{

    protected function defineAttributes()
    {
        return [
            'id'          => [AttributeType::Number],
            'level'       => [AttributeType::String],
            'requestKey'  => [AttributeType::String],
            'type'        => [AttributeType::String],
            'source'      => [AttributeType::String],
            'extra'       => [AttributeType::Mixed],
            'dateCreated' => [AttributeType::String]
        ];
    }

}



