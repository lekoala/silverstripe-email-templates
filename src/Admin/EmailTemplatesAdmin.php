<?php

namespace LeKoala\EmailTemplates\Admin;

use Exception;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\View\Requirements;
use LeKoala\EmailTemplates\Models\SentEmail;
use LeKoala\EmailTemplates\Models\EmailTemplate;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * Manage your email templates
 *
 * @author lekoala
 */
class EmailTemplatesAdmin extends ModelAdmin
{

    private static $managed_models = array(
        EmailTemplate::class,
        SentEmail::class,
    );
    private static $url_segment = 'email-templates';
    private static $menu_title = 'Emails';
    private static $menu_icon = 'lekoala/silverstripe-email-templates:images/mail.png';
    private static $allowed_actions = array(
        'ImportForm',
        'SearchForm',
        'PreviewEmail',
        'ViewSentEmail',
        'SentTestEmail',
    );

    public function subsiteCMSShowInMenu()
    {
        return true;
    }

    public function getList()
    {
        $list = parent::getList();
        return $list;
    }

    /**
     * Called by EmailTemplate::previewTab
     *
     * @return string
     */
    public function PreviewEmail()
    {
        // Prevent CMS styles to interfere with preview
        Requirements::clear();

        $id = (int) $this->getRequest()->getVar('id');

        /* @var $emailTemplate EmailTemplate */
        $emailTemplate = EmailTemplate::get()->byID($id);
        $html = $emailTemplate->renderTemplate(true, true);

        Requirements::restore();

        return $html;
    }

    public function SentTestEmail()
    {
        $id = (int) $this->getRequest()->getVar('id');

        /* @var $emailTemplate EmailTemplate */
        $emailTemplate = EmailTemplate::get()->byID($id);

        $email = $emailTemplate->getEmail();
        $emailTemplate->setPreviewData($email);
        $result = $email->send();

        print_r($result);
        die();
    }

    /**
     * Called by SentEmail
     *
     * @return string
     */
    public function ViewSentEmail()
    {
        // Prevent CMS styles to interfere with preview
        Requirements::clear();

        $id = (int) $this->getRequest()->getVar('id');

        /* @var $SentEmail SentEmail */
        $SentEmail = SentEmail::get()->byID($id);
        $html = $SentEmail->Body;

        Requirements::restore();

        return $html;
    }
}
