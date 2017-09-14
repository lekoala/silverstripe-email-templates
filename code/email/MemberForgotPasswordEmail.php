<?php

class MemberForgotPasswordEmail extends BetterEmail
{

    public function __construct($from = null, $to = null, $subject = null, $body = null, $bounceHandlerURL = null, $cc = null, $bcc = null)
    {
        $this->subject = _t('MemberForgotPasswordEmail.SUBJECT', "Your password reset link", 'Email subject');

        parent::__construct($from, $to, $subject, $body, $bounceHandlerURL, $cc, $bcc);

        /* @var $template EmailTemplate */
        $template = EmailTemplate::getByCode('member-forgot-password');

        if ($template) {
            $template->applyTemplate($this);
        }
    }
}
