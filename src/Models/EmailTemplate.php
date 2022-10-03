<?php

namespace LeKoala\EmailTemplates\Models;

use Exception;
use SilverStripe\Forms\Tab;
use SilverStripe\i18n\i18n;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\ArrayData;
use SilverStripe\Forms\TextField;
use SilverStripe\Security\Member;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Control\Email\Email;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Security\Permission;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Admin\AdminRootController;
use LeKoala\EmailTemplates\Email\BetterEmail;
use LeKoala\EmailTemplates\Helpers\FluentHelper;
use LeKoala\EmailTemplates\Admin\EmailTemplatesAdmin;

/**
 * User defined email templates
 *
 * Content of the template should override default content provided with setHTMLTemplate
 *
 * For example, in the framework we have
 *    $email = Email::create()->setHTMLTemplate('SilverStripe\\Control\\Email\\ForgotPasswordEmail')
 *
 * It means our template code should match this : ForgotPasswordEmail
 *
 * @property string $Subject
 * @property string $DefaultSender
 * @property string $DefaultRecipient
 * @property string $Category
 * @property string $Code
 * @property string $Content
 * @property string $Callout
 * @property boolean $Disabled
 * @author lekoala
 */
class EmailTemplate extends DataObject
{
    private static $table_name = 'EmailTemplate';

    private static $db = array(
        'Subject' => 'Varchar(255)',
        'DefaultSender' => 'Varchar(255)',
        'DefaultRecipient' => 'Varchar(255)',
        'Category' => 'Varchar(255)',
        'Code' => 'Varchar(255)',
        // Content
        'Content' => 'HTMLText',
        'Callout' => 'HTMLText',
        // Configuration
        'Disabled' => 'Boolean',
    );
    private static $summary_fields = array(
        'Subject',
        'Code',
        'Category',
        'Disabled',
    );
    private static $searchable_fields = array(
        'Subject',
        'Code',
        'Category',
        'Disabled',
    );
    private static $indexes = array(
        'Code' => true, // Code is not unique because it can be used by subsites
    );
    private static $translate = array(
        'Subject', 'Content', 'Callout'
    );

    public function getTitle()
    {
        return $this->Subject;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        // Do not allow changing subsite
        $fields->removeByName('SubsiteID');

        $fields->dataFieldByName('Callout')->setRows(5);

        $codeField = $fields->dataFieldByName('Code');
        $codeField->setAttribute('placeholder', _t('EmailTemplate.CODEPLACEHOLDER', 'A unique code that will be used in code to retrieve the template, e.g.: MyEmail'));

        if ($this->Code) {
            $codeField->setReadonly(true);
        }

        // Merge fields helper
        $fields->addFieldToTab('Root.Main', new HeaderField('MergeFieldsHelperTitle', _t('EmailTemplate.AVAILABLEMERGEFIELDSTITLE', 'Available merge fields')));

        $fields->addFieldToTab('Root.Main', new LiteralField('MergeFieldsHelper', $this->mergeFieldsHelper()));

        if ($this->ID) {
            $fields->addFieldToTab('Root.Preview', $this->previewTab());
        }

        // Cleanup UI
        $categories = EmailTemplate::get()->column('Category');
        $fields->addFieldsToTab('Root.Settings', new DropdownField('Category', 'Category', array_combine($categories, $categories)));
        $fields->addFieldsToTab('Root.Settings', new CheckboxField('Disabled'));
        $fields->addFieldsToTab('Root.Settings', new TextField('DefaultSender'));
        $fields->addFieldsToTab('Root.Settings', new TextField('DefaultRecipient'));


        return $fields;
    }

    public function canView($member = null)
    {
        return true;
    }

    public function canEdit($member = null)
    {
        return Permission::check('CMS_ACCESS', 'any', $member);
    }

    public function canCreate($member = null, $context = [])
    {
        // Should be created by developer
        return false;
    }

    public function canDelete($member = null)
    {
        return Permission::check('CMS_ACCESS', 'any', $member);
    }

    /**
     * A map of Name => Class
     *
     * User models are variables with a . that should match an existing DataObject name
     *
     * @return array
     */
    public function getAvailableModels()
    {
        $fields = ['Content', 'Callout'];

        $models = self::config()->default_models;

        // Build a list of non namespaced models
        // They are not likely to clash anyway because of their unique table name
        $dataobjects = ClassInfo::getValidSubClasses(DataObject::class);
        $map = [];
        foreach ($dataobjects as $k => $v) {
            $parts = explode('\\', $v);
            $name = end($parts);
            $map[$name] = $v;
        }

        foreach ($fields as $field) {
            // Match variables with a dot in the call, like $MyModel.SomeMethod
            preg_match_all('/\$([a-zA-Z]+)\./m', $this->$field ?? '', $matches);

            if (!empty($matches) && !empty($matches[1])) {
                // Get unique model names
                $arr = array_unique($matches[1]);

                foreach ($arr as $name) {
                    if (!isset($map[$name])) {
                        continue;
                    }
                    $class = $map[$name];
                    $singl = singleton($class);
                    if ($singl instanceof DataObject) {
                        $models[$name] = $class;
                    }
                }
            }
        }

        return $models;
    }

    /**
     * Get an email template by code
     *
     * @param string $code
     * @param bool $alwaysReturn
     * @param string $locale
     * @return EmailTemplate
     */
    public static function getByCode($code, $alwaysReturn = true, $locale = null)
    {
        if ($locale) {
            $template = FluentHelper::withLocale($locale, function () use ($code) {
                return EmailTemplate::get()->filter('Code', $code)->first();
            });
        } else {
            $template = EmailTemplate::get()->filter('Code', $code)->first();
        }
        // Always return a template
        if (!$template && $alwaysReturn) {
            $template = new EmailTemplate();
            $template->Subject = $code;
            $template->Code = $code;
            $template->Content = 'Replace this with your own content and untick disabled';
            $template->Disabled = true;
            $template->write();
        }
        return $template;
    }

    /**
     * A shorthand to get an email by code
     *
     * @param string $code
     * @param string $locale
     * @return BetterEmail
     */
    public static function getEmailByCode($code, $locale = null)
    {
        return self::getByCode($code, true, $locale)->getEmail();
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
    }

    /**
     * Content of the literal field for the merge fields
     *
     * @return string
     */
    protected function mergeFieldsHelper()
    {
        $content = '<strong>Base fields:</strong><br/>';
        $baseFields = array(
            'To', 'Cc', 'Bcc', 'From', 'Subject', 'Body', 'BaseURL', 'Controller'
        );
        foreach ($baseFields as $baseField) {
            $content .= $baseField . ', ';
        }
        $content = trim($content, ', ') . '<br/>';

        $models = $this->getAvailableModels();

        $modelsByClass = array();
        $classes = array();
        foreach ($models as $name => $model) {
            $classes[] = $model;
            if (!isset($modelsByClass[$model])) {
                $modelsByClass[$model] = array();
            }
            $modelsByClass[$model][] = $name;
        }
        $classes = array_unique($classes);

        $locales = array();
        // if (class_exists('Fluent')) {
        //     $locales = Fluent::locales();
        // }

        foreach ($classes as $model) {
            if (!class_exists($model)) {
                continue;
            }
            $props = Config::inst()->get($model, 'db');
            $o = singleton($model);
            $content .= '<strong>' . $model . ' (' . implode(',', $modelsByClass[$model]) . '):</strong><br/>';
            foreach ($props as $fieldName => $fieldType) {
                // Filter out locale fields
                foreach ($locales as $locale) {
                    if (strpos($fieldName, $locale) !== false) {
                        continue;
                    }
                }
                $content .= $fieldName . ', ';
            }

            // We could also show methods but that may be long
            if (self::config()->helper_show_methods) {
                $methods = array_diff($o->allMethodNames(true), $o->allMethodNames());
                foreach ($methods as $method) {
                    if (strpos($method, 'get') === 0) {
                        $content .= $method . ', ';
                    }
                }
            }

            $content = trim($content, ', ') . '<br/>';
        }
        $content .= "<br/><div class='message info'>" . _t('EmailTemplate.ENCLOSEFIELD', 'To escape a field from surrounding text, you can enclose it between brackets, eg: {$Member.FirstName}.') . '</div>';
        return $content;
    }

    /**
     * Provide content for the Preview tab
     *
     * @return Tab
     */
    protected function previewTab()
    {
        $tab = new Tab('Preview');

        // Preview iframe
        $sanitisedModel =  str_replace('\\', '-', EmailTemplate::class);
        $adminSegment = EmailTemplatesAdmin::config()->url_segment;
        $adminBaseSegment = AdminRootController::config()->url_base;
        $iframeSrc = Director::baseURL() . $adminBaseSegment . '/' . $adminSegment . '/' . $sanitisedModel . '/PreviewEmail/?id=' . $this->ID;
        $iframe = new LiteralField('iframe', '<iframe src="' . $iframeSrc . '" style="width:800px;background:#fff;border:1px solid #ccc;min-height:500px;vertical-align:top"></iframe>');
        $tab->push($iframe);

        $env = Environment::getEnv('SS_SEND_ALL_EMAILS_TO');
        if ($env || Director::isDev()) {
            $sendTestLink = Director::baseURL() . $adminBaseSegment . '/' . $adminSegment . '/' . $sanitisedModel . '/SendTestEmailTemplate/?id=' . $this->ID . '&to=' . urlencode($env);
            $sendTest = new LiteralField("send_test", "<hr/><a href='$sendTestLink'>Send test email</a>");
            $tab->push($sendTest);
        }

        return $tab;
    }

    /**
     * Returns an instance of an Email with the content of the template
     *
     * @return BetterEmail
     */
    public function getEmail()
    {
        $email = Email::create();
        if (!$email instanceof BetterEmail) {
            throw new Exception("Make sure you are injecting the BetterEmail class instead of your base Email class");
        }

        $this->applyTemplate($email);
        if ($this->Disabled) {
            $email->setDisabled(true);
        }
        return $email;
    }

    /**
     * Returns an instance of an Email with the content tailored to the member
     *
     * @param Member $member
     * @return BetterEmail
     */
    public function getEmailForMember(Member $member)
    {
        $restoreLocale = null;
        if ($member->Locale) {
            $restoreLocale = i18n::get_locale();
            i18n::set_locale($member->Locale);
        }

        $email = $this->getEmail();
        $email->setToMember($member);

        if ($restoreLocale) {
            i18n::set_locale($restoreLocale);
        }

        return $email;
    }

    /**
     * Apply this template to the email
     *
     * @param BetterEmail $email
     */
    public function applyTemplate(&$email)
    {
        $email->setEmailTemplate($this);

        if ($this->Subject) {
            $email->setSubject($this->Subject);
        }

        // Use dbObject to handle shortcodes as well
        $email->setData([
            'EmailContent' => $this->dbObject('Content')->forTemplate(),
            'Callout' => $this->dbObject('Callout')->forTemplate(),
        ]);

        // Email are initialized with admin_email if set, we may want to use our own sender
        if ($this->DefaultSender) {
            $email->setFrom($this->DefaultSender);
        } else {
            $SiteConfig = SiteConfig::current_site_config();
            $email->setFrom($SiteConfig->EmailDefaultSender());
        }
        if ($this->DefaultRecipient) {
            $email->setTo($this->DefaultRecipient);
        }

        $this->extend('updateApplyTemplate', $email);
    }

    /**
     * Get rendered body
     *
     * @param bool $injectFake
     * @return string
     */
    public function renderTemplate($injectFake = false)
    {
        // Disable debug bar in the iframe
        Config::modify()->set('LeKoala\\DebugBar\\DebugBar', 'auto_inject', false);

        $email = $this->getEmail();
        if ($injectFake) {
            $email = $this->setPreviewData($email);
        }

        $html = $email->getRenderedBody();

        return $html;
    }

    /**
     * Inject random data into email for nicer preview
     *
     * @param BetterEmail $email
     * @return BetterEmail
     */
    public function setPreviewData(BetterEmail $email)
    {
        $data = array();

        // Get an array of data like ["Body" => "My content", "Callout" => "The callout..."]
        $emailData = $email->getData();

        // Parse the data for variables
        // For now, simply replace them by their name in curly braces
        foreach ($emailData as $k => $v) {
            if (!$v) {
                continue;
            }

            $matches = null;

            // This match all $Variable or $Member.Firstname kind of vars
            preg_match_all('/\$([a-zA-Z.]*)/', $v, $matches);
            if ($matches && !empty($matches[1])) {
                foreach ($matches[1] as $name) {
                    $name = trim($name, '.');

                    if (strpos($name, '.') !== false) {
                        // It's an object
                        $parts = explode('.', $name);
                        $objectName = array_shift($parts);
                        if (isset($data[$objectName])) {
                            $object = $data[$objectName];
                        } else {
                            $object = new ArrayData(array());
                        }
                        $curr = $object;

                        // May be recursive
                        foreach ($parts as $part) {
                            if (is_string($curr)) {
                                $curr = [];
                                $object->$part = $curr;
                            }
                            $object->$part = '{' . "$objectName.$part" . '}';
                            $prevPart = $part;
                            $curr = $object->$part;
                        }
                        $data[$objectName] = $object;
                    } else {
                        // It's a simple var
                        $data[$name] = '{' . $name . '}';
                    }
                }
            }
        }

        // Inject random data for known classes
        foreach ($this->getAvailableModels() as $name => $class) {
            if (!class_exists($class)) {
                continue;
            }
            if (singleton($class)->hasMethod('getSampleRecord')) {
                $o = $class::getSampleRecord();
            } else {
                $o = $class::get()->sort('RAND()')->first();
            }

            if (!$o) {
                $o = new $class;
            }
            $data[$name] = $o;
        }

        foreach ($data as $name => $value) {
            $email->addData($name, $value);
        }

        return $email;
    }
}
