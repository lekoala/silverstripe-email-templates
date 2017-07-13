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
        if ($this->owner->PrimaryColor) {
            return $this->owner->PrimaryColor;
        }
        return EmailTemplate::config()->base_color;
    }

    public function EmailLogoTemplate()
    {
        if ($this->owner->LogoID) {
            return $this->owner->Logo();
        }
    }

    public function EmailTwitterLink()
    {
        if (!EmailTemplate::config()->show_twitter) {
            return;
        }
        if (!$this->owner->TwitterAccount) {
            return;
        }
        return 'https://twitter.com/' . $this->owner->TwitterAccount;
    }

    public function EmailFacebookLink()
    {
        if (!EmailTemplate::config()->show_facebook) {
            return;
        }
        if (!$this->owner->FacebookAccount) {
            return;
        }
        return 'https://www.facebook.com/' . $this->owner->FacebookAccount;
    }

    public function EmailRssLink()
    {
        if (!EmailTemplate::config()->show_rss) {
            return;
        }
        return Director::absoluteURL('rss');
    }
}
