<?php

namespace Craft;

class SearchPlus_AlgoliaMapRecord extends BaseRecord
{
    public function getTableName()
    {
        return 'searchplus_algoliamap';
    }

    public function defineAttributes()
    {
        return [
            'name'         => [AttributeType::String, 'required' => true],
            'handle'       => [AttributeType::String, 'required' => true],
            'indexMap'     => AttributeType::Mixed,
            'sectionMap'   => AttributeType::Mixed,
            'contentMap'   => AttributeType::Mixed,
            'status'       => [AttributeType::Enum, 'values' => ['enabled', 'disabled', 'pending'], 'default' => 'pending'],
            'data'         => AttributeType::Mixed,
            'enabledMulti' => AttributeType::Bool,
        ];
    }

}
