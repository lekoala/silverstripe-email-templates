<?php

class MemberChangePasswordEmail extends BetterEmail
{

    public function __construct($from = null, $to = null, $subject = null, $body = null, $bounceHandlerURL = null, $cc = null, $bcc = null)
    {
        $this->subject = _t('MemberChangePasswordEmail.SUBJECT', "Your password has been changed", 'Email subject');

        parent::__construct($from, $to, $subject, $body, $bounceHandlerURL, $cc, $bcc);

        /* @var $template EmailTemplate */
        $template = EmailTemplate::getByCode('member-change-password');

        if ($template) {
            $template->applyTemplate($this);
        }
    }
}
