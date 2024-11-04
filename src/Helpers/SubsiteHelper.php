<?php

namespace LeKoala\EmailTemplates\Helpers;

use SilverStripe\ORM\DataObject;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\Subsites\State\SubsiteState;

/**
 * Helps providing base functionnalities where including
 * subsite module is optional and yet provide a consistent api
 *
 * TODO: externalize this to a specific module
 */
class SubsiteHelper
{
    /**
     * @var boolean
     */
    protected static $previousState;

    /**
     * @var int
     */
    protected static $previousSubsite;

    /**
     * Return current subsite id (even if module is not installed, which returns 0)
     *
     * @return int
     */
    public static function currentSubsiteID()
    {
        if (self::usesSubsite() && class_exists(SubsiteState::class)) {
            return SubsiteState::singleton()->getSubsiteId();
        }
        return 0;
    }

    /**
     * @return Subsite|null
     */
    public static function currentSubsite()
    {
        $id = self::currentSubsiteID();
        if (self::usesSubsite() && class_exists(Subsite::class)) {
            /** @var Subsite|null */
            return DataObject::get_by_id(Subsite::class, $id);
        }
        return null;
    }

    /**
     * Do we have the subsite module installed
     * TODO: check if it might be better to use module manifest instead?
     *
     * @return bool
     */
    public static function usesSubsite()
    {
        return class_exists(SubsiteState::class) && class_exists(Subsite::class);
    }

    public static function safeAbsoluteURL($absUrl)
    {
        if (!self::usesSubsite() || !class_exists(Subsite::class)) {
            return $absUrl;
        }

        $subsite = SubsiteHelper::currentSubsite();
        if ($subsite->hasMethod('getPrimarySubsiteDomain')) {
            $domain = $subsite->getPrimarySubsiteDomain();
            $link = $subsite->domain();
            $protocol = $domain->getFullProtocol();
        } else {
            $protocol = Director::protocol();
            $link = $subsite->domain();
        }
        $absUrl = preg_replace('/\/\/[^\/]+\//', '//' . $link . '/', $absUrl);
        $absUrl = preg_replace('/http(s)?:\/\//', $protocol, $absUrl);

        return $absUrl;
    }


    /**
     * @return bool
     */
    public static function subsiteFilterDisabled()
    {
        if (!self::usesSubsite() && class_exists(Subsite::class)) {
            return true;
        }
        return Subsite::$disable_subsite_filter;
    }

    /**
     * Enable subsite filter and store previous state
     *
     * @return void
     */
    public static function enableFilter()
    {
        if (!self::usesSubsite() || !class_exists(Subsite::class)) {
            return;
        }
        self::$previousState = Subsite::$disable_subsite_filter;
        Subsite::$disable_subsite_filter = false;
    }

    /**
     * Disable subsite filter and store previous state
     *
     * @return void
     */
    public static function disableFilter()
    {
        if (!self::usesSubsite() || !class_exists(Subsite::class)) {
            return;
        }
        self::$previousState = Subsite::$disable_subsite_filter;
        Subsite::$disable_subsite_filter = true;
    }

    /**
     * Restore subsite filter based on previous set (set when called enableFilter or disableFilter)
     */
    public static function restoreFilter()
    {
        if (!self::usesSubsite() || !class_exists(Subsite::class)) {
            return;
        }
        Subsite::$disable_subsite_filter = self::$previousState;
    }

    /**
     * @return int
     */
    public static function SubsiteIDFromSession()
    {
        $session = Controller::curr()->getRequest()->getSession();
        if ($session) {
            return $session->get('SubsiteID');
        }
        return 0;
    }

    /**
     * Typically call this on PageController::init
     * This is due to InitStateMiddleware not using session in front end and not persisting get var parameters
     *
     * @param HTTPRequest $request
     * @return int
     */
    public static function forceSubsiteFromRequest(HTTPRequest $request)
    {
        $subsiteID = $request->getVar('SubsiteID');
        if ($subsiteID) {
            $request->getSession()->set('ForcedSubsiteID', $subsiteID);
        } else {
            $subsiteID = $request->getSession()->get('ForcedSubsiteID');
        }
        if ($subsiteID) {
            self::changeSubsite($subsiteID, true);
        }
        return $subsiteID;
    }

    /**
     * @param string $ID
     * @param bool $flush
     * @return void
     */
    public static function changeSubsite($ID, $flush = null)
    {
        if (!self::usesSubsite() || !class_exists(Subsite::class) || !class_exists(SubsiteState::class)) {
            return;
        }
        self::$previousSubsite = self::currentSubsiteID();

        // Do this otherwise changeSubsite has no effect if false
        SubsiteState::singleton()->setUseSessions(true);
        Subsite::changeSubsite($ID);
        // This can help avoiding getting static objects like SiteConfig
        if ($flush !== null && $flush) {
            DataObject::reset();
        }
    }

    /**
     * @return void
     */
    public static function restoreSubsite()
    {
        if (!self::usesSubsite() || !class_exists(Subsite::class)) {
            return;
        }
        Subsite::changeSubsite(self::$previousSubsite);
    }

    /**
     * @return array
     */
    public static function listSubsites()
    {
        if (!self::usesSubsite() || !class_exists(Subsite::class)) {
            return [];
        }
        return  Subsite::get()->map();
    }

    /**
     * Execute the callback in given subsite
     *
     * @param int $ID Subsite ID or 0 for main site
     * @param callable $cb
     * @return void
     */
    public static function withSubsite($ID, $cb)
    {
        if (!class_exists(SubsiteState::class)) {
            return;
        }
        $currentID = self::currentSubsiteID();
        SubsiteState::singleton()->setSubsiteId($ID);
        $cb();
        SubsiteState::singleton()->setSubsiteId($currentID);
    }

    /**
     * Execute the callback in all subsites
     *
     * @param callable $cb
     * @param bool $includeMainSite
     * @return void
     */
    public static function withSubsites($cb, $includeMainSite = true)
    {
        if (!self::usesSubsite() || !class_exists(SubsiteState::class) || !class_exists(State::class)) {
            $cb();
            return;
        }

        if ($includeMainSite) {
            SubsiteState::singleton()->setSubsiteId(0);
            $cb(0);
        }

        $currentID = self::currentSubsiteID();
        $subsites = Subsite::get();
        foreach ($subsites as $subsite) {
            // TODO: maybe use changeSubsite instead?
            SubsiteState::singleton()->setSubsiteId($subsite->ID);
            $cb($subsite->ID);
        }
        SubsiteState::singleton()->setSubsiteId($currentID);
    }
}
