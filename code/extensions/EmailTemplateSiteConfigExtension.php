<?php

/**
 * EmailTemplateSiteConfigExtension
 *
 * @author Kalyptus SPRL <thomas@kalyptus.be>
 */
class EmailTemplateSiteConfigExtension extends DataExtension
{

    private static $db = array(
        'EmailFooter' => 'HTMLText',
        'DefaultFromEmail' => 'Varchar(255)',
        'ContactEmail' => 'Varchar(255)',
    );

    public function updateCMSFields(FieldList $fields)
    {
        // Disabled - don't show fields
        if (defined('EMAIL_TEMPLATES_UPDATE_FIELDS') && !EMAIL_TEMPLATES_UPDATE_FIELDS) {
            return $fields;
        }
        if (!SiteConfig::config()->email_templates_update_fields) {
            return $fields;
        }

        // Already defined by another module
        if ($fields->dataFieldByName('EmailFooter')) {
            return $fields;
        }

        $EmailFooter = new HtmlEditorField('EmailFooter', _t('EmailTemplateSiteConfigExtension.EmailFooter', 'Email Footer'));
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

        if ($config->default_recipient_email) {
            return $config->default_recipient_email;
        }

        if ($config->admin_email) {
            return $config->admin_email;
        }

        if ($config->send_all_emails_to) {
            return $config->send_all_emails_to;
        }

        throw new Exception('Could not find the default email recipient');
    }

    public function EmailDefaultSender()
    {
        if ($this->owner->DefaultFromEmail) {
            return $this->owner->DefaultFromEmail;
        }

        $config = Email::config();

        if ($config->default_sender_email) {
            return $config->default_sender_email;
        }

        if ($config->admin_email) {
            return $config->admin_email;
        }

        if ($config->send_all_emails_from) {
            return $config->send_all_emails_from;
        }

        throw new Exception('Could not find the default email sender');
    }

    public function EmailBaseColor()
    {
        $field = EmailTemplate::config()->base_color_field;
        if ($field && $this->owner->$field) {
            return $this->owner->$field;
        }
        return EmailTemplate::config()->base_color;
    }

    public function EmailLogoTemplate()
    {
        // Use EmailLogo if defined
        if ($this->owner->EmailLogoID) {
            return $this->owner->EmailLogo();
        }
        // Otherwise, use configurable field
        $field = EmailTemplate::config()->base_color_field;
        if ($field && $this->owner->$field) {
            $method = str_replace('ID', '', $field);
            return $this->owner->$method();
        }
    }

    public function EmailTwitterLink()
    {
        if (!EmailTemplate::config()->show_twitter) {
            return;
        }
        $field = EmailTemplate::config()->twitter_field;
        if ($field && !$this->owner->$field) {
            return;
        }
        return 'https://twitter.com/' . $this->owner->$field;
    }

    public function EmailFacebookLink()
    {
        if (!EmailTemplate::config()->show_facebook) {
            return;
        }
        $field = EmailTemplate::config()->facebook_field;
        if ($field && !$this->owner->$field) {
            return;
        }
        return 'https://www.facebook.com/' . $this->owner->$field;
    }

    public function EmailRssLink()
    {
        if (!EmailTemplate::config()->show_rss) {
            return;
        }
        return Director::absoluteURL('rss');
    }
}
