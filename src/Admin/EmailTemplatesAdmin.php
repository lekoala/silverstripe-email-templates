<?php

namespace LeKoala\EmailTemplates\Admin;

use Exception;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Director;
use SilverStripe\View\Requirements;
use SilverStripe\Control\HTTPResponse;
use LeKoala\EmailTemplates\Models\Emailing;
use LeKoala\EmailTemplates\Models\SentEmail;
use LeKoala\EmailTemplates\Helpers\FluentHelper;
use LeKoala\EmailTemplates\Models\EmailTemplate;

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
        'SendTestEmailTemplate',
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
        $emails = $Emailing->getEmailsByLocales();

        $errors = 0;
        $messages = [];
        foreach ($emails as $locale => $emails) {
            foreach ($emails as $email) {
                $res = null;
                $msg = null;
                // Wrap with withLocale to make sure any environment variable (urls, etc) are properly set when sending
                FluentHelper::withLocale($locale, function () use ($email, &$res, &$msg) {
                    try {
                        $res = $email->send();
                    } catch (Exception $ex) {
                        $res = false;
                        $msg = $ex->getMessage();
                    }
                });
                if (!$res) {
                    $errors++;
                    $messages[] = $msg;
                }
            }
        }

        if ($errors == 0) {
            $Emailing->LastSent = date('Y-m-d H:i:s');
            $Emailing->write();
            $message = _t('EmailTemplatesAdmin.EMAILING_SENT', 'Emailing sent');
        } else {
            $message =  _t('EmailTemplatesAdmin.EMAILING_ERROR', 'There was an error sending email');
            $message .= ": " . implode(", ", $messages);
        }

        if (Director::is_ajax()) {
            $this->getResponse()->addHeader('X-Status', rawurlencode($message));
            if ($errors > 0) {
                // $this->getResponse()->setStatusCode(400);
            }
            return $this->getResponse();
        }

        return $message;
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

    public function SendTestEmailTemplate()
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
