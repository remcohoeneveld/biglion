<?php
namespace Craft;

class SearchPlus_BaseCpController extends SearchPlus_BaseController
{
    protected $allowAnonymous = false;

    public function init()
    {
        /*
        if(!craft()->userSession->isAdmin()) {
            craft()->userSession->requirePermission('accessPlugin-searchPlus');
        }*/
    }
}
