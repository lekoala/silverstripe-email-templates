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
    );

    public function updateCMSFields(FieldList $fields)
    {
        $EmailFooter = new HtmlEditorField('EmailFooter', _t('EmailTemplateSiteConfigExtension.EmailFooter', 'Email Footer'));
        $EmailFooter->setRows(5);
        $fields->addFieldToTab('Root.Email', $EmailFooter);

        return $fields;
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
