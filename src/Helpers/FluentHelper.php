<?php

namespace LeKoala\EmailTemplates\Helpers;

use SilverStripe\i18n\i18n;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use TractorCow\Fluent\Extension\FluentExtension;

/**
 * Helps providing base functionnalities where including
 * subsite module is optional and yet provide a consistent api
 *
 * TODO: externalize this to a specific module
 */
class FluentHelper
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
     * @var array
     */
    protected static $locale_cache = [];

    /**
     * Do we have the subsite module installed
     * TODO: check if it might be better to use module manifest instead?
     *
     * @return bool
     */
    public static function usesFluent()
    {
        return class_exists(FluentState::class);
    }

    /**
     * @param string $class
     * @return boolean
     */
    public static function isClassTranslated($class)
    {
        $singl = singleton($class);
        return $singl->hasExtension(FluentExtension::class);
    }

    /**
     * Execute the callback in given subsite
     *
     * @param string|Locale $locale
     * @param callable $cb
     * @return mixed callback result
     */
    public static function withLocale($locale, $cb)
    {
        if (!self::usesFluent() || !$locale || !class_exists(FluentState::class)) {
            $cb();
            return;
        }
        $state = FluentState::singleton();
        return $state->withState(function ($state) use ($locale, $cb) {
            if (is_object($locale)) {
                $locale = $locale->Locale;
            }
            $state->setLocale($locale);
            return $cb();
        });
    }

    /**
     * Execute the callback for all locales
     *
     * @param callable $cb
     * @return array an array of callback results
     */
    public static function withLocales($cb)
    {
        if (!self::usesFluent() || !class_exists(Locale::class)) {
            $cb();
            return [];
        }
        $allLocales = Locale::get();
        $results = [];
        foreach ($allLocales as $locale) {
            $results[] = self::withLocale($locale, $cb);
        }
        return $results;
    }

    /**
     * Get a locale from the lang
     *
     * @param string $lang
     * @return string
     */
    public static function get_locale_from_lang($lang)
    {
        // Normalize if needed
        if (strlen($lang) > 2) {
            $lang = self::get_lang($lang);
        }

        // Use fluent data
        if (class_exists(Locale::class)) {
            if (empty(self::$locale_cache)) {
                $fluentLocales = Locale::getLocales();
                foreach ($fluentLocales as $locale) {
                    self::$locale_cache[self::get_lang($locale->Locale)] = $locale->Locale;
                }
            }
            if (isset(self::$locale_cache[$lang])) {
                return self::$locale_cache[$lang];
            }
        }
        // Guess
        $localesData = i18n::getData();
        return $localesData->localeFromLang($lang);
    }

    /**
     * Get the right locale (using fluent data if exists)
     *
     * @return string
     */
    public static function get_locale()
    {
        if (class_exists(FluentState::class)) {
            return FluentState::singleton()->getLocale();
        }
        return i18n::get_locale();
    }

    /**
     * Make sure we get a proper two characters lang
     *
     * @param string|object $lang a string or a fluent locale object
     * @return string a two chars lang
     */
    public static function get_lang($lang = null)
    {
        if (!$lang) {
            $lang = self::get_locale();
        }
        if (is_object($lang)) {
            $lang = $lang->Locale;
        }
        return substr($lang, 0, 2);
    }
}
