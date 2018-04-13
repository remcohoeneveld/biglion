<?php
namespace Craft;

class SearchPlus_LogController extends SearchPlus_BaseController
{
    public function init()
    {
        if (!craft()->config->get('devMode'))
        {
            craft()->templates->getTwig()->addExtension(new \Twig_Extension_Debug());
        }
    }

    public function actionAll(array $variables = [])
    {
        $page = craft()->request->getQuery('p');
        if($page == '') { $page = 1; }
        $variables['threaded'] = craft()->searchPlus_log->getAllThreaded($page);
        $variables['totalPages'] = craft()->searchPlus_log->getTotalThreadedPagesCount();

        $variables['currentPage'] = $page;
        $variables['nextPage'] = false;
        $variables['prevPage'] = false;

        if($page > 1) {
            $variables['prevPage'] = $page-1;
        }

        if($page < $variables['totalPages']) {
            $variables['nextPage'] = $page+1;
        }

        $this->renderTemplate('searchplus/log/_index', $variables);
    }


    public function actionView(array $variables = [])
    {
        if(!isset($variables['logId'])) {
            $this->redirect('searchplus/logs');
        }

        $log = craft()->searchPlus_log->getLogById($variables['logId']);

        if($log == null) {
            $this->redirect('searchplus/logs');
        }

        $relatedLogs = craft()->searchPlus_log->getLogsByRequestKey($log->requestKey);

        $variables['log'] = $log;
        $variables['relatedLogs'] = $relatedLogs;

        $this->renderTemplate('searchplus/log/_view', $variables);
    }



    public function actionDeleteLog()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();
        craft()->userSession->requireAdmin();

        $id = craft()->request->getRequiredPost('id');
        $return = craft()->searchPlus_log->deleteById($id);

        return $this->returnJson(['success' => $return]);
    }


    public function actionClearAll()
    {
        $this->requirePostRequest();
        craft()->userSession->requireAdmin();

        craft()->searchPlus_log->deleteAll();

        craft()->userSession->setNotice(Craft::t('SearchPlus logs cleared'));
        return $this->redirect('searchplus/logs');
    }


    public function actionClearByRequest()
    {
        $this->requirePostRequest();
        craft()->userSession->requireAdmin();

        $key = craft()->request->getRequiredPost('requestKey');
        craft()->searchPlus_log->deleteByRequestKey($key);

        return $this->redirect('searchplus/logs');
    }



}
