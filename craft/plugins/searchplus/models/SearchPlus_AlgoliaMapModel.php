<?php
namespace Craft;

class SearchPlus_AlgoliaMapModel extends BaseModel
{

    protected function defineAttributes()
    {
        return array_merge(parent::defineAttributes(), [
            'id'                  => [AttributeType::Number],
            'name'                => [AttributeType::String, 'required' => true],
            'handle'              => [AttributeType::String, 'required' => true],
            'indexMap'            => [AttributeType::Mixed],
            'sectionMap'          => [AttributeType::Mixed],
            'contentMap'          => [AttributeType::Mixed],
            'status'              => [AttributeType::Enum, 'values' => ['enabled', 'disabled', 'pending', 'remote']],
            'remoteName'          => [AttributeType::String],
            'data'                => [AttributeType::Mixed],
            'enabledMulti'        => [AttributeType::Bool],
            'elements'            => [AttributeType::Number],
            'entries'             => [AttributeType::Number],
            'populationStatus'    => [AttributeType::Enum, 'values' => ['uptodate', 'empty', 'outofsync']],
            'sectionMappingWordy' => [AttributeType::String],
        ]);
    }


    public function validate($attributes = null, $clearErrors = true)
    {
        // Must have some elements mapped
        if(is_null($this->sectionMap) || !is_array($this->sectionMap) || empty($this->sectionMap) || !isset($this->sectionMap['elements'])) {
            $this->addError('sectionMap', Craft::t('Section Mapping must have at least some elements'));
        }

        // Must be a unique handle
        $existing = craft()->searchPlus_algolia->getIndexByHandle($this->handle);
        if($existing !== false && $existing->id != $this->id) {
            $this->addError('handle', Craft::t('Handle must be unique'));
        }


        return parent::validate($attributes, false);
    }


    public function getSectionMapDescription()
    {
        if(is_null($this->sectionMap) || !is_array($this->sectionMap) || empty($this->sectionMap) || !isset($this->sectionMap['elements'])) return '';
        $rows = craft()->searchPlus_elements->buildRows($this->sectionMap);

        $return = '';

        foreach($rows as $row) {
            if($row['type'] == 'heading') {
                $temp = '<strong>'.$row['label'].'</strong>';
            }
            elseif($row['type'] == 'row') {
                $temp = '- '.$row['label'];
            }

            $return .= '<li>'. $temp.'</li>';
        }

        $return = '<ul>'.$return.'</ul>';

        $charset = craft()->templates->getTwig()->getCharset();
        return new \Twig_Markup($return, $charset);

    }
}
