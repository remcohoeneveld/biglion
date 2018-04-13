<?php

namespace Craft;

class SearchPlusService extends BaseApplicationComponent
{
    public $errors = array();
    private $protected = array('p', 'action');
    private $specialCriteria = array('section', 'order', 'limit', 'status', 'search', 'relatedTo');
    public $params = array();
    public $expandedParams = array();
    public $settings = array();

    public function init()
    {
        $plugin = $this->getPlugin();

        if (!$plugin) {
            throw new Exception('Couldn’t find the Search Plus plugin!');
        }

        $this->settings = $plugin->getSettings();
        $algoliaSettings = $this->settings->algoliaSettings;
        if(!is_array($algoliaSettings)) {
            $algoliaSettings = [];
        }

        // Set the defaults by config if they're available
        $overrideSettings = craft()->config->get('algolia', 'searchplus');
        if (is_array($overrideSettings)) {
            foreach ($overrideSettings as $key => $val) {
                $algoliaSettings[$key] = $val;
            }
        }

        $this->settings->algoliaSettings = $algoliaSettings;
    }

    public function getPlugin()
    {
        return craft()->plugins->getPlugin('searchplus');
    }


    public function getCPTabs()
    {
        $tabs = array();

        if (isset($this->settings['enabledAlgolia']) && $this->settings['enabledAlgolia'] == true) {
            $tabs['algolia'] = array('label' => Craft::t('Algolia'), 'url' => UrlHelper::getUrl('searchplus/algolia'));
        }

        if (isset($this->settings['enabledLog']) && $this->settings['enabledLog'] == true) {
            $tabs['logs'] = array('label' => Craft::t('Logs'), 'url' => UrlHelper::getUrl('searchplus/logs'));
        }


        return $tabs;
    }


    public function getResults($extraParams = array(), $returnCriteria = false)
    {
        if (empty($this->params)) $this->_getParams();
        $this->_expandParams();

        $data['criteria'] = $extraParams;


        $criteria = craft()->elements->getCriteria(ElementType::Entry);
        $possibleHandles = $criteria->getSupportedFieldHandles();
        $appliedHandles = array();


        foreach ($data['criteria'] as $key => $val) {
            if (in_array($key, $possibleHandles) || in_array($key, $this->specialCriteria)) {
                $criteria->$key = $val;
                $appliedHandles[$key] = $val;
            }
        }

        foreach ($this->expandedParams as $set) {
            if (in_array($set['key'], $possibleHandles) || in_array($set['key'], $this->specialCriteria)) {
                $criteria->$set['key'] = $set['value'];
                $appliedHandles[$set['key']] = $set['value'];
            }
        }

        // Pass over to the log service
        craft()->searchPlus_log->log($appliedHandles);

        // We can directly return the criteria here, and let the
        // dev perform additional work. Useful for pagination
        if ($returnCriteria) return $criteria;

        // Otherwise, return the actual result set
        return $criteria->find();
    }

    public function getParams()
    {
        if (empty($this->params)) $this->_getParams();

        return $this->params;
    }


    private function _getParams()
    {
        // First get the posted vars
        $data = craft()->request->getPost();
        $data = array_merge(craft()->request->getQuery(), $data);

        // Unset protected values
        foreach ($this->protected as $key) {
            unset($data[$key]);
        }

        $this->params = $data;

        return;
    }

    private function _expandParams()
    {
        if (empty($this->params)) return;

        foreach ($this->params as $key => $val) {
            //$key = strtolower($key);
            if ($val == '') continue;

            $special = false;

            // handle to / from / range
            if (strpos($key, '-to')) {
                $special = true;
                $this->_expandParamTo($key, $val);
            }
            if (strpos($key, '-from')) {
                $special = true;
                $this->_expandParamFrom($key, $val);
            }

            // handle piped lists
            if (strpos($key, '-set')) {
                $special = true;
                $this->_expandParamSet($key, $val);
            }


            // handle piped lists
            if (strpos($key, '-tag')) {
                $special = true;
                $this->_expandParamTag($key, $val);
            }


            if (!$special) {
                $this->_expandParamGeneral($key, $val);
            }
        }

        return;
    }


    private function _expandParamTag($key, $val)
    {
        $this->expandedParams[] = array('key' => 'relatedTo', 'value' => $val);

        return;
    }

    private function _expandParamGeneral($key, $val)
    {
        $this->expandedParams[] = array('key' => $key, 'value' => $val);

        return;
    }

    private function _expandParamSet($key, $val)
    {
        $this->expandedParams[] = array('key' => substr($key, 0, strpos($key, '-set')), 'value' => implode(', ', explode('|', $val)));

        return;
    }

    private function _expandParamTo($key, $val)
    {
        $this->expandedParams[] = array('key' => substr($key, 0, strpos($key, '-to')), 'value' => '<= ' . $val);

        return;
    }

    private function _expandParamFrom($key, $val)
    {
        $this->expandedParams[] = array('key' => substr($key, 0, strpos($key, '-from')), 'value' => '>= ' . $val);

        return;
    }

}
