<?php

namespace LeKoala\EmailTemplates\Extensions;

use Exception;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\Control\Director;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Control\Email\Email;
use SilverStripe\SiteConfig\SiteConfig;
use LeKoala\EmailTemplates\Models\EmailTemplate;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;

/**
 * EmailTemplateSiteConfigExtension
 *
 * @property string $EmailFooter
 * @property string $DefaultFromEmail
 * @property string $ContactEmail
 *
 * @property-read SiteConfig&EmailTemplateSiteConfigExtension $owner
 *
 * @author Kalyptus SPRL <thomas@kalyptus.be>
 */
class EmailTemplateSiteConfigExtension extends DataExtension
{

    private static $db = [
        'EmailFooter' => 'HTMLText',
        'DefaultFromEmail' => 'Varchar(255)',
        'ContactEmail' => 'Varchar(255)',
    ];

    public function updateCMSFields(FieldList $fields)
    {
        // Handle field update ourselves
        if (!SiteConfig::config()->get('email_templates_update_fields')) {
            return $fields;
        }

        // Already defined by another module
        if ($fields->dataFieldByName('EmailFooter')) {
            return $fields;
        }

        $EmailFooter = new HTMLEditorField('EmailFooter', _t('EmailTemplateSiteConfigExtension.EmailFooter', 'Email Footer'));
        $EmailFooter->setRows(3);
        $fields->addFieldToTab('Root.Email', $EmailFooter);

        $fields->addFieldToTab('Root.Email', new TextField('DefaultFromEmail', _t('EmailTemplateSiteConfigExtension.DefaultFromEmail', 'Default Sender')));
        $fields->addFieldToTab('Root.Email', new TextField('ContactEmail', _t('EmailTemplateSiteConfigExtension.ContactEmail', 'Default Recipient')));

        return $fields;
    }

    public function EmailDefaultRecipient()
    {
        if ($this->owner->ContactEmail) {
            return $this->owner->ContactEmail;
        }

        $config = Email::config();

        if ($config->get('default_recipient_email')) {
            return $config->get('default_recipient_email');
        }

        if ($config->get('admin_email')) {
            return $config->get('admin_email');
        }

        if ($config->get('send_all_emails_to')) {
            return $config->get('send_all_emails_to');
        }

        throw new Exception('Could not find the default email recipient');
    }

    public function EmailDefaultSender()
    {
        if ($this->owner->DefaultFromEmail) {
            return $this->owner->DefaultFromEmail;
        }

        $config = Email::config();

        if ($config->get('default_sender_email')) {
            return $config->get('default_sender_email');
        }

        if ($config->get('admin_email')) {
            return $config->get('admin_email');
        }

        if ($config->get('send_all_emails_from')) {
            return $config->get('send_all_emails_from');
        }

        throw new Exception('Could not find the default email sender');
    }

    public function EmailBaseColor()
    {
        $field = EmailTemplate::config()->get('base_color_field');
        if ($field && $this->owner->hasField($field)) {
            return $this->owner->$field;
        }
        return EmailTemplate::config()->get('base_color');
    }

    public function EmailLogoTemplate()
    {
        // Use EmailLogo if defined
        if ($this->owner->EmailLogoID) {
            return $this->owner->EmailLogo();
        }
        // Otherwise, use configurable field
        $field = EmailTemplate::config()->get('logo_field');
        if ($field && $this->owner->hasField($field)) {
            $method = str_replace('ID', '', $field);
            return $this->owner->$method();
        }
    }

    public function EmailTwitterLink()
    {
        if (!EmailTemplate::config()->get('show_twitter')) {
            return;
        }
        $field = EmailTemplate::config()->get('twitter_field');
        if ($field && !$this->owner->hasField($field)) {
            return;
        }
        return 'https://twitter.com/' . $this->owner->$field;
    }

    public function EmailFacebookLink()
    {
        if (!EmailTemplate::config()->get('show_facebook')) {
            return;
        }
        $field = EmailTemplate::config()->get('facebook_field');
        if ($field && !$this->owner->hasField($field)) {
            return;
        }
        return 'https://www.facebook.com/' . $this->owner->$field;
    }

    public function EmailRssLink()
    {
        if (!EmailTemplate::config()->get('show_rss')) {
            return;
        }
        return Director::absoluteURL('rss');
    }
}
