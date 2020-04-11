<?php

namespace LeKoala\EmailTemplates\Admin;

use Exception;
use LeKoala\EmailTemplates\Helpers\FluentHelper;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\View\Requirements;
use LeKoala\EmailTemplates\Models\Emailing;
use LeKoala\EmailTemplates\Models\SentEmail;
use LeKoala\EmailTemplates\Models\EmailTemplate;
use SilverStripe\Control\Director;

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
        Emailing::class,
    );
    private static $url_segment = 'email-templates';
    private static $menu_title = 'Emails';
    private static $menu_icon = 'lekoala/silverstripe-email-templates:images/mail.png';
    private static $allowed_actions = array(
        'ImportForm',
        'SearchForm',
        'PreviewEmail',
        'PreviewEmailing',
        'SendEmailing',
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
     * Called by EmailTemplate
     *
     * @return string
     */
    public function SendEmailing()
    {
        $id = (int) $this->getRequest()->getVar('id');

        /* @var $Emailing Emailing */
        $Emailing = Emailing::get()->byID($id);
        $emails = $Emailing->getEmailByLocales();

        $errors = 0;
        foreach ($emails as $locale => $email) {
            // Wrap with withLocale to make sure any environment variable (urls, etc) are properly set when sending
            $res = null;
            FluentHelper::withLocale($locale, function () use ($email, &$res) {
                try {
                    $res = $email->send();
                } catch (Exception $ex) {
                    return $ex->getMessage();
                }
                return $res;
            });
            if (!$res) {
                $errors++;
            }
        }

        $message =  _t('EmailTemplatesAdmin.EMAILING_ERROR', 'There was an error sending email');

        if ($errors == 0) {
            $Emailing->LastSent = date('Y-m-d H:i:s');
            $Emailing->write();

            $message = _t('EmailTemplatesAdmin.EMAILING_SENT', 'Emailing sent');
        }

        if (Director::is_ajax()) {
            return $message;
        }

        return $this->redirectBack();
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

        /* @var $EmailTemplate EmailTemplate */
        $EmailTemplate = EmailTemplate::get()->byID($id);
        $html = $EmailTemplate->renderTemplate(true);

        Requirements::restore();

        return $html;
    }

    /**
     * Called by Emailing::previewTab
     *
     * @return string
     */
    public function PreviewEmailing()
    {
        // Prevent CMS styles to interfere with preview
        Requirements::clear();

        $id = (int) $this->getRequest()->getVar('id');

        /* @var $Emailing Emailing */
        $Emailing = Emailing::get()->byID($id);
        $html = $Emailing->renderTemplate(true);

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
