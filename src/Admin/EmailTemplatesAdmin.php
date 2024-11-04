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
use SilverStripe\Core\Environment;
use SilverStripe\Security\Member;

/**
 * Manage your email templates
 *
 * @author lekoala
 */
class EmailTemplatesAdmin extends ModelAdmin
{

    private static $managed_models = [
        EmailTemplate::class,
        SentEmail::class,
        Emailing::class,
    ];
    private static $url_segment = 'email-templates';
    private static $menu_title = 'Emails';
    private static $menu_icon = 'lekoala/silverstripe-email-templates:images/mail.png';
    private static $allowed_actions = [
        'ImportForm',
        'SearchForm',
        'PreviewEmail',
        'PreviewEmailing',
        'SendEmailing',
        'ViewSentEmail',
        'SendTestEmailTemplate',
    ];

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
        Environment::increaseTimeLimitTo();

        $id = (int) $this->getRequest()->getVar('id');

        /* @var $Emailing Emailing */
        $Emailing = self::getEmailingById($id);
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
            $Emailing->LastSentCount = count($emails);
            $Emailing->write();
            $message = _t('EmailTemplatesAdmin.EMAILING_SENT', 'Emailing sent');
        } else {
            $Emailing->LastError = implode(", ", $messages);
            $Emailing->write();

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

        $EmailTemplate = self::getEmailTemplateById($id);
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

        $Emailing = self::getEmailingById($id);
        $html = $Emailing->renderTemplate();

        Requirements::restore();

        return $html;
    }

    public function SendTestEmailTemplate()
    {
        $id = (int) $this->getRequest()->getVar('id');
        $to = (string) $this->getRequest()->getVar('to');

        if (!$to) {
            die("Please set a ?to=some@email.com");
        }

        $member = Member::get()->filter('Email', $to)->first();

        $emailTemplate = self::getEmailTemplateById($id);

        $email = $emailTemplate->getEmail();

        $emailTemplate->setPreviewData($email);
        if ($member) {
            $email->setToMember($member);
            $email->addData("Member", $member);
        } else {
            $email->setTo($to);
        }
        $result = $email->doSend();

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

        $SentEmail = self::getSentEmailById($id);
        $html = $SentEmail->Body;

        Requirements::restore();

        return $html;
    }

    /**
     * @param int $id
     * @return Emailing
     */
    protected static function getEmailingById($id)
    {
        return Emailing::get()->byID($id);
    }

    /**
     * @param int $id
     * @return EmailTemplate
     */
    protected static function getEmailTemplateById($id)
    {
        return EmailTemplate::get()->byID($id);
    }

    /**
     * @param int $id
     * @return SentEmail
     */
    protected static function getSentEmailById($id)
    {
        return SentEmail::get()->byID($id);
    }
}
