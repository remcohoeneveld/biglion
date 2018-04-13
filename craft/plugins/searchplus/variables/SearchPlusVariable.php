<?php

namespace Craft;

class SearchPlusVariable extends BaseApplicationComponent
{
    public function getPlugin()
    {
        return craft()->plugins->getPlugin('searchplus');
    }

    public function params()
    {
        // Get all the params from the search plus service
        $params = craft()->searchPlus->getParams();

        return $params;
    }

    public function paginate( $base = '' )
    {
        $params = craft()->searchPlus->getParams();

        $qs = $base . '?';

        $data = array();
        foreach($params as $key => $val)
        {
            $data[] = $key.'='.$val;
        }
        $qs .= implode('&', $data);

        return $qs;
    }


    public function facetLink( $facet = array() )
    {
        $params = craft()->searchPlus->getParams();

        $qs = craft()->request->getUrl();
        $qs .= '?';

        $params = array_merge($params, $facet);

        $data = array();
        foreach($params as $key => $val)
        {
            $data[] = $key.'='.$val;
        }
        $qs .= implode('&', $data);

        return $qs;
    }

    public function criteria($extra = array())
    {
        $obj = craft()->searchPlus->getResults($extra, true);

        return $obj;
    }

    public function results($extra = array())
    {
        $obj = craft()->searchPlus->getResults($extra);

        return $obj;
    }

/*
    public function getCpTabs()
    {
        return craft()->searchPlus->getCPTabs();
    }
*/

    public function index($handle = '')
    {
        if($handle == '') return '';

        return craft()->searchPlus_algolia->getIndexNameByHandle($handle);
    }

    public function searchApiKey()
    {
        return craft()->searchPlus_algolia->searchApiKey;
    }
    public function applicationId()
    {
        return craft()->searchPlus_algolia->applicationId;
    }


    public function getAlgoliaSearchApiKey()
    {
        return $this->searchApiKey();
    }

    public function getAlgoliaApplicationId()
    {
        return $this->applicationId();
    }
}
