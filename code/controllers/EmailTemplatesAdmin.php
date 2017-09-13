<?php

/**
 * EmailTemplatesAdmin
 *
 * @author lekoala
 */
class EmailTemplatesAdmin extends ModelAdmin
{

    private static $managed_models = array(
        'EmailTemplate',
        'SentEmail',
    );
    private static $url_segment = 'emails';
    private static $menu_title = 'Emails';
    private static $menu_icon = 'email-templates/images/mail.png';
    private static $allowed_actions = array(
        'ImportForm',
        'SearchForm',
        'PreviewEmail',
        'ViewSentEmail',
        'doSendTestEmail',
    );

    public function subsiteCMSShowInMenu()
    {
        return true;
    }

    public function getSearchContext()
    {
        $context = parent::getSearchContext();

        $categories = EmailTemplate::get()->column('Category');
        $context->getFields()->replaceField('q[Category]', $dd = new DropdownField('q[Category]', 'Category', ArrayLib::valuekey($categories)));
        $dd->setEmptyString('');

        return $context;
    }

    public function getList()
    {
        $list = parent::getList();

        return $list;
    }

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

    public function doSendTestEmail()
    {
        $request = $this->getRequest();

        $id = (int) $request->requestVar('EmailTemplateID');
        if (!$id) {
            throw new Exception('Please define EmailTemplateID parameter');
        }

        $EmailTemplate = EmailTemplate::get()->byID($id);
        if (!$EmailTemplate) {
            throw new Exception("Template is not found");
        }
        $SendTestEmail = $request->requestVar('SendTestEmail');

        if (!$SendTestEmail) {
            throw new Exception('Please define SendTestEmail parameter');
        }

        $email = $EmailTemplate->getEmail();
        $email->setTo($SendTestEmail);

        $res = $email->send();

        if ($res) {
            return 'Test email sent to ' . $SendTestEmail;
        }
        return 'Failed to send test to ' . $SendTestEmail;
    }
}
