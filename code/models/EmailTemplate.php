<?php

/**
 * EmailTemplate
 *
 * @property string $Title
 * @property string $DefaultSender
 * @property string $DefaultRecipient
 * @property string $Category
 * @property string $Code
 * @property string $Content
 * @property string $Callout
 * @property string $SideBar
 * @property boolean $Disabled
 * @author lekoala
 */
class EmailTemplate extends DataObject
{

    private static $db = array(
        'Title' => 'Varchar(255)',
        'DefaultSender' => 'Varchar(255)',
        'DefaultRecipient' => 'Varchar(255)',
        'Category' => 'Varchar(255)',
        'Code' => 'Varchar(255)',
        // Content
        'Content' => 'HTMLText',
        'Callout' => 'HTMLText',
        'SideBar' => 'HTMLText',
        // Configuration
        'Disabled' => 'Boolean',
    );
    private static $summary_fields = array(
        'Title',
        'Code',
        'Category',
        'Disabled',
    );
    private static $searchable_fields = array(
        'Title',
        'Code',
        'Category',
        'Disabled',
    );
    private static $indexes = array(
        'Code' => true, // Code is not unique because it can be used by subsites
    );
    private static $translate = array(
        'Title', 'Content', 'Callout', 'SideBar'
    );

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $config = EmailTemplate::config();

        $objectsSource = array();
        $dataobjects = ClassInfo::subclassesFor('DataObject');
        foreach ($dataobjects as $dataobject) {
            if ($dataobject == 'DataObject') {
                continue;
            }
            $objectsSource[$dataobject] = $dataobject;
        }
        asort($objectsSource);

        // Do not allow changing subsite
        $fields->removeByName('SubsiteID');

        // form-extras integration
        if (class_exists('ComboField')) {
            $categories = EmailTemplate::get()->column('Category');
            $fields->replaceField('Category', new ComboField('Category', 'Category', array_combine($categories, $categories)));
        }

        $fields->dataFieldByName('Callout')->setRows(5);

        $codeField = $fields->dataFieldByName('Code');
        $codeField->setAttribute('placeholder', _t('EmailTemplate.CODEPLACEHOLDER', 'A unique code that will be used in code to retrieve the template, e.g.: my-email'));

        if ($this->Code) {
            $codeField->setReadonly(true);
        }

        // Merge fields helper
        $fields->addFieldToTab('Root.Main', new HeaderField('MergeFieldsHelperTitle', _t('EmailTemplate.AVAILABLEMERGEFIELDSTITLE', 'Available merge fields')));

        $fields->addFieldToTab('Root.Main', new LiteralField('MergeFieldsHelper', $this->mergeFieldsHelper()));

        if ($this->ID) {
            $fields->addFieldToTab('Root.Preview', $this->previewTab());
        }

        $mailer = Email::mailer();
        if (!$mailer->hasMethod('getSendingDisabled')) {
            $fields->insertAfter('Disabled', new LiteralField('DisabledWarning', '<div class="message bad">' . _t('EmailTemplate.DISABLEDWARNING', "Your mailer does not support disabling emails") . '</div>'));
        }

        return $fields;
    }

    /**
     * Base models always available in the controller
     *
     * @return array
     */
    public function getBaseModels()
    {
        return array(
            'CurrentMember' => 'Member',
            'SiteConfig' => 'SiteConfig'
        );
    }

    /**
     * A map of Name => Class
     *
     * User models are variables with a . that should match an existing DataObject name
     *
     * @return array
     */
    public function getUserModels()
    {
        $fields = ['Content', 'Callout', 'SideBar'];

        $models = [];
        foreach ($fields as $field) {
            preg_match_all('/\$([a-zA-Z]+)\./m', $this->$field, $matches);

            if (!empty($matches) && !empty($matches[1])) {
                $arr = array_unique($matches[1]);

                foreach ($arr as $name) {
                    if (!class_exists($name)) {
                        continue;
                    }
                    $singl = singleton($name);
                    if ($singl instanceof DataObject) {
                        $models[$name] = $name;
                    }
                }
            }
        }

        return $models;
    }

    /**
     * An map of Name => Class
     *
     * @return array
     */
    public function getAvailableModels()
    {
        $userModels = $this->getUserModels();
        $baseModels = $this->getBaseModels();
        return array_merge($baseModels, $userModels);
    }

    /**
     * Get an email template by code
     *
     * @param string $code
     * @return EmailTemplate
     */
    public static function getByCode($code)
    {
        $template = EmailTemplate::get()->filter('Code', $code)->first();
        // If subsite, fallback to main site email if not defined
        if (!$template && class_exists('Subsite') && Subsite::currentSubsiteID()) {
            $template = EmailTemplate::get()
                ->filter('Code', $code)
                ->alterDataQuery(function (DataQuery $dq) {
                    $dq->setQueryParam('Subsite.filter', false);
                })
                ->first();
        }
        // Always return a template
        if (!$template) {
            $template = new EmailTemplate();
            $template->Title = $code;
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
     * @return BetterEmail
     */
    public static function getEmailByCode($code)
    {
        return self::getByCode($code)->getEmail();
    }

    public function onBeforeWrite()
    {
        if ($this->Code) {
            $filter = new URLSegmentFilter;
            $this->Code = $filter->filter($this->Code);
        }

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
        if (class_exists('Fluent')) {
            $locales = Fluent::locales();
        }

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
        $content .= "<div class='message info'>" . _t('EmailTemplate.ENCLOSEFIELD', 'To escape a field from surrounding text, you can enclose it between brackets, eg: {$Member.FirstName}.') . '</div>';
        return $content;
    }

    /**
     * Provide content for the Preview tab
     *
     * @return \Tab
     */
    protected function previewTab()
    {
        $tab = new Tab('Preview');

        // Preview iframe
        $iframeSrc = '/admin/emails/EmailTemplate/PreviewEmail/?id=' . $this->ID;
        $iframe = new LiteralField('iframe', '<iframe src="' . $iframeSrc . '" style="width:800px;background:#fff;border:1px solid #ccc;min-height:500px;vertical-align:top"></iframe>');
        $tab->push($iframe);

        if (class_exists('CmsInlineFormAction')) {
            // Test emails
            $compo = new FieldGroup(
                $recipient = new TextField('SendTestEmail', ''),
                $action = new CmsInlineFormAction('doSendTestEmail', 'Send')
            );
            $recipient->setAttribute('placeholder', 'my@email.test');
            $recipient->setValue(Email::config()->admin_email);
            $tab->push(new HiddenField('EmailTemplateID', '', $this->ID));
            $tab->push(new HeaderField('SendTestEmailHeader', 'Send test email'));
            $tab->push($compo);
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
        /* @var $email BetterEmail */
        $email = BetterEmail::create();

        $this->applyTemplate($email);

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
     *
     * @param Email $email
     */
    public function applyTemplate(&$email)
    {

        if ($this->Title) {
            $email->setSubject($this->Title);
        }

        $email->setBody([
            'Body' => $this->Content,
            'Callout' => $this->Callout,
            'SideBar' => $this->SideBar,
        ]);

        if ($this->DefaultSender) {
            $email->setFrom($this->DefaultSender);
        }
        if ($this->DefaultRecipient) {
            $email->setTo($this->DefaultRecipient);
        }

        // This should be supported by your email transport if you want it to work
        if ($this->Disabled) {
            $email->addCustomHeader('X-SendingDisabled', true);
        }

        $this->extend('updateApplyTemplate', $email);
    }

    /**
     * Get rendered body
     *
     * @param bool $parse Should we parse variables or not?
     * @return string
     */
    public function renderTemplate($parse = false, $injectFake = false)
    {
        // Disable debug bar in the iframe
        Config::inst()->update('DebugBar', 'auto_inject', false);

        $email = $this->getEmail();
        if ($injectFake) {
            $email = $this->setPreviewData($email);
        }

        $debug = $email->debug();

        // Actual email content is after the first </p>
        $paragraphPosition = strpos($debug, '</p>');
        $html = substr($debug, $paragraphPosition + 4);

        return (string)$html;
    }

    /**
     * Inject random data into email for nicer preview
     *
     * @param Email $email
     * @return Email
     */
    public function setPreviewData(BetterEmail $email)
    {
        $data = array();

        $body = $email->getOriginalBody();

        // Parse the body for variables
        foreach ($body as $k => $v) {
            $matches = null;
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

        return $email->populateTemplate($data);
    }
}
