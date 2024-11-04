<?php

namespace LeKoala\EmailTemplates\Extensions;

use LeKoala\EmailTemplates\Helpers\SubsiteHelper;
use LeKoala\EmailTemplates\Models\Emailing;
use LeKoala\EmailTemplates\Models\EmailTemplate;
use LeKoala\EmailTemplates\Models\SentEmail;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Permission;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Member;

/**
 * Add subsites support
 * 
 * @property-read EmailTemplate|SentEmail|Emailing $owner
 * @author lekoala
 */
class EmailSubsiteExtension extends DataExtension
{

    private static $has_one = [
        'Subsite' => 'Subsite',
    ];

    public function isMainDataObject()
    {
        if ($this->owner->SubsiteID == 0) {
            return true;
        }
        return false;
    }

    /**
     * Update any requests to limit the results to the current site
     */
    public function augmentSQL(SQLSelect $query, DataQuery $dataQuery = null)
    {
        if (SubsiteHelper::subsiteFilterDisabled()) {
            return;
        }
        if ($dataQuery && $dataQuery->getQueryParam('Subsite.filter') === false) {
            return;
        }

        // If you're querying by ID, don't filter
        if ($query->filtersOnID()) {
            return;
        }

        // Don't run on delete queries, since they are always tied to a specific ID.
        // if ($query->getDelete()) {
        //     return;
        // }

        // If we match on a subsite, don't filter twice
        $regexp = '/^(.*\.)?("|`)?SubsiteID("|`)?\s?=/';
        foreach ($query->getWhereParameterised($parameters) as $predicate) {
            if (preg_match($regexp, $predicate)) {
                return;
            }
        }

        $subsiteID = (int) SubsiteHelper::currentSubsiteID();

        $froms = $query->getFrom();
        $froms = array_keys($froms);
        $tableName = array_shift($froms);

        if ($subsiteID) {
            $query->addWhere("\"$tableName\".\"SubsiteID\" IN ($subsiteID)");
        }
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        // Assign to current subsite when created
        if (!$this->owner->ID && !$this->owner->SubsiteID) {
            $this->owner->SubsiteID = SubsiteHelper::currentSubsiteID();
        }
    }

    public function canView($member = null)
    {
        return $this->canEdit($member);
    }

    /**
     * @param Member $member
     * @return boolean|null
     */
    public function canEdit($member = null)
    {
        return Permission::check('CMS_ACCESS', 'any', $member);
    }

    /**
     * @param Member $member
     * @return boolean|null
     */
    public function canCreate($member = null)
    {
        return Permission::check('CMS_ACCESS', 'any', $member);
    }

    /**
     * @param Member $member
     * @return boolean|null
     */
    public function canDelete($member = null)
    {
        return Permission::check('CMS_ACCESS', 'any', $member);
    }

    /**
     * Return a piece of text to keep DataObject cache keys appropriately specific
     */
    public function cacheKeyComponent()
    {
        return 'subsite-' . SubsiteHelper::currentSubsiteID();
    }
}
