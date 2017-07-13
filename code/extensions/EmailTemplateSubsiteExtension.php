<?php

/**
 * Add subsites support
 *
 * @author lekoala
 */
class EmailSubsiteExtension extends DataExtension
{

    private static $has_one = array(
        'Subsite' => 'Subsite',
    );

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
    public function augmentSQL(SQLQuery &$query, DataQuery &$dataQuery = null)
    {
        if (Subsite::$disable_subsite_filter) {
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
        if ($query->getDelete()) {
            return;
        }

        // If we match on a subsite, don't filter twice
        $regexp = '/^(.*\.)?("|`)?SubsiteID("|`)?\s?=/';
        foreach ($query->getWhereParameterised($parameters) as $predicate) {
            if (preg_match($regexp, $predicate)) {
                return;
            }
        }

        $subsiteID = (int) Subsite::currentSubsiteID();

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
            $this->owner->SubsiteID = Subsite::currentSubsiteID();
        }
    }

    public function canView($member = null)
    {
        return $this->canEdit($member);
    }

    /**
     * @param Member
     * @return boolean|null
     */
    public function canEdit($member = null)
    {
        return Permission::check('CMS_ACCESS_EmailsAdmin', 'any', $member);
    }

    /**
     * @param Member
     * @return boolean|null
     */
    public function canCreate($member = null)
    {
        return Permission::check('CMS_ACCESS_EmailsAdmin', 'any', $member);
    }

    /**
     * @param Member
     * @return boolean|null
     */
    public function canDelete($member = null)
    {
        return Permission::check('CMS_ACCESS_EmailsAdmin', 'any', $member);
    }

    /**
     * Return a piece of text to keep DataObject cache keys appropriately specific
     */
    public function cacheKeyComponent()
    {
        return 'subsite-' . Subsite::currentSubsiteID();
    }
}
