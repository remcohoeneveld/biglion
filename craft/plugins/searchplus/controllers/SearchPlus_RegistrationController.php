<?php
namespace Craft;

class SearchPlus_RegistrationController extends BaseController
{

    public function actionEdit()
    {
        $licenseKey = craft()->searchPlus_license->getLicenseKey();

        $this->renderTemplate('searchplus/settings/license', [
            'hasLicenseKey' => ($licenseKey !== null)
        ]);
    }

    public function actionGetLicenseInfo()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        return $this->sendResponse(craft()->searchPlus_license->getLicenseInfo());
    }

    public function actionUpdateLicenseKey()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $licenseKey = craft()->request->getRequiredPost('licenseKey');

        // Are we registering a new license key?
        if ($licenseKey) {
            // Record the license key locally
            try {
                craft()->searchPlus_license->setLicenseKey($licenseKey);
            } catch (InvalidLicenseKeyException $e) {
                $this->returnErrorJson(Craft::t('That license key is invalid.'));
            }

            return $this->sendResponse(craft()->searchPlus_license->registerPlugin($licenseKey));
        } else {
            // Just clear our record of the license key
            craft()->searchPlus_license->wipeLicenseKey();
            return $this->sendResponse();

        }
    }




    public function actionUnregister()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        return $this->sendResponse(craft()->searchPlus_license->unregisterLicenseKey());
    }



    public function actionTransfer()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        return $this->sendResponse(craft()->searchPlus_license->transferLicenseKey());
    }



    private function sendResponse($success = true)
    {
        if($success) {
            $this->returnJson([
                'success'          => true,
                'licenseKey'       => craft()->searchPlus_license->getLicenseKey(),
                'licenseKeyStatus' => craft()->plugins->getPluginLicenseKeyStatus('SearchPlus'),
            ]);
        } else {
            $this->returnErrorJson(craft()->searchPlus_license->error);
        }
    }
}
