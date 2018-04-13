<?php
namespace Craft;

class m161020_000000_searchPlus_updateLicense extends BaseMigration
{
    /**
     * Any migration code in here is wrapped inside of a transaction.
     *
     * @return bool
     */
    public function safeUp()
    {
        // trigger a quick license recheck so we've got the proper state stored
        craft()->searchPlus_license->getLicenseInfo();

        return true;
    }
}
