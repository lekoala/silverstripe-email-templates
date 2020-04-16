<?php

namespace LeKoala\EmailTemplates\Email;

use Exception;
use Swift_MimePart;
use BadMethodCallException;
use SilverStripe\i18n\i18n;
use SilverStripe\Control\HTTP;
use SilverStripe\View\SSViewer;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Control\Director;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Control\Email\Email;
use SilverStripe\SiteConfig\SiteConfig;
use LeKoala\EmailTemplates\Models\SentEmail;
use LeKoala\EmailTemplates\Helpers\EmailUtils;
use LeKoala\EmailTemplates\Models\EmailTemplate;
use LeKoala\EmailTemplates\Helpers\SubsiteHelper;

/**
 * An improved and more pleasant base Email class to use on your project
 *
 * This class is fully decoupled from the EmailTemplate class and keep be used
 * independantly
 *
 * Improvements are:
 *
 * - URL safe rewriting
 * - Configurable base template (base system use Email class with setHTMLTemplate to provide content)
 * - Send email according to member locale
 * - Check for subject
 * - Send to member or admin
 * - Persist emails
 * - Parse body (multi part body is supported)
 * - Plaintext takes template into account
 * - Disable emails
 * - Unified send methods that support hooks
 *
 * @author lekoala
 */
class BetterEmail extends Email
{
    const STATE_CANCELLED = 'cancelled';
    const STATE_NOT_SENT = 'not_sent';
    const STATE_SENT = 'sent';
    const STATE_FAILED = 'failed';

    /**
     * @var EmailTemplate
     */
    protected $emailTemplate;

    /**
     * @var string
     */
    protected $locale;

    /**
     * @var Member
     */
    protected $to_member;

    /**
     * @var Member
     */
    protected $from_member;

    /**
     * @var boolean
     */
    protected $disabled = false;

    /**
     * @var SentMail
     */
    protected $sentMail = null;

    /**
     * @var boolean
     */
    protected $sendingCancelled = false;

    /**
     * Email constructor.
     * @param string|array|null $from
     * @param string|array|null $to
     * @param string|null $subject
     * @param string|null $body
     * @param string|array|null $cc
     * @param string|array|null $bcc
     * @param string|null $returnPath
     */
    public function __construct(
        $from = null,
        $to = null,
        $subject = null,
        $body = null,
        $cc = null,
        $bcc = null,
        $returnPath = null
    ) {
        parent::__construct($from, $to, $subject, $body, $cc, $bcc, $returnPath);

        // Use template as a layout
        if ($defaultTemplate = self::config()->template) {
            // Call method because variable is private
            parent::setHTMLTemplate($defaultTemplate);
        }
    }

    /**
     * Persists the email to the database
     *
     * @param bool|array $results
     * @return SentEmail
     */
    protected function persist($results)
    {
        $record = SentEmail::create(array(
            'To' => EmailUtils::format_email_addresses($this->getTo()),
            'From' => EmailUtils::format_email_addresses($this->getFrom()),
            'ReplyTo' => $this->getReplyTo(),
            'Subject' => $this->getSubject(),
            'Body' => $this->getRenderedBody(),
            'Headers' => $this->getSwiftMessage()->getHeaders()->toString(),
            'CC' => EmailUtils::format_email_addresses($this->getCC()),
            'BCC' => EmailUtils::format_email_addresses($this->getBCC()),
            'Results' => json_encode($results),
        ));
        $record->write();

        // TODO: migrate this to a cron task
        SentEmail::cleanup();

        return $record;
    }


    /**
     * Get body of message after rendering
     * Useful for previews
     *
     * @return string
     */
    public function getRenderedBody()
    {
        $this->render();
        return $this->getSwiftMessage()->getBody();
    }

    /**
     * Don't forget that setBody will erase content of html template
     * Prefer to use this instead. Basically you can replace setBody calls with this method
     * URLs are rewritten by render process
     *
     * @param string $body
     * @return $this
     */
    public function addBody($body)
    {
        return $this->addData("EmailContent", $body);
    }

    /**
     * @param array|ViewableData $data The template data to set
     * @return $this
     */
    public function setData($data)
    {
        // Merge data!
        if ($this->emailTemplate) {
            if (is_array($data)) {
                parent::addData($data);
            } elseif ($data instanceof DataObject) {
                parent::addData($data->toMap());
            } else {
                parent::setData($data);
            }
        } else {
            parent::setData($data);
        }
        return $this;
    }

    /**
     * Sends a HTML email
     *
     * @return bool true if successful or array of failed recipients
     */
    public function send()
    {
        return $this->doSend(false);
    }

    /**
     * Sends a plain text email
     *
     * @return bool true if successful or array of failed recipients
     */
    public function sendPlain()
    {
        return $this->doSend(true);
    }

    /**
     * Send this email
     *
     * @param bool $plain
     * @return bool true if successful or array of failed recipients
     * @throws Exception
     */
    public function doSend($plain = false)
    {
        if ($this->disabled) {
            $this->sendingCancelled = true;
            return false;
        }

        // Check for Subject
        if (!$this->getSubject()) {
            throw new BadMethodCallException('You must set a subject');
        }

        // This hook can prevent email from being sent
        $result = $this->extend('onBeforeDoSend', $this);
        if ($result === false) {
            $this->sendingCancelled = true;
            return false;
        }

        $SiteConfig = SiteConfig::current_site_config();

        // Check for Sender and use default if necessary
        $from = $this->getFrom();
        if (empty($from)) {
            $this->setFrom($SiteConfig->EmailDefaultSender());
        }

        // Check for Recipient and use default if necessary
        $to = $this->getTo();
        if (empty($to)) {
            $this->addTo($SiteConfig->EmailDefaultRecipient());
        }

        // Set language to use for the email
        $restore_locale = null;
        if ($this->locale) {
            $restore_locale = i18n::get_locale();
            i18n::set_locale($this->locale);
        }

        $member = $this->to_member;
        if ($member) {
            // Maybe this member doesn't want to receive emails?
            if ($member->hasMethod('canReceiveEmails') && !$member->canReceiveEmails()) {
                return false;
            }
        }

        // Make sure we have a full render with current locale
        if ($this->emailTemplate) {
            $this->clearBody();
        }

        if ($plain) {
            // sendPlain will trigger our updated generatePlainPartFromBody
            $res = parent::sendPlain();
        } else {
            $res = parent::send();
        }

        if ($restore_locale) {
            i18n::set_locale($restore_locale);
        }

        $this->extend('onAfterDoSend', $this, $res);
        $this->sentMail = $this->persist($res);

        return $res;
    }

    /**
     * Returns one of the STATE_xxxx constant
     *
     * @return string
     */
    public function getSendStatus()
    {
        if ($this->sendingCancelled) {
            return self::STATE_CANCELLED;
        }
        if ($this->sentMail) {
            if ($this->sentMail->IsSuccess()) {
                return self::STATE_SENT;
            }
            return self::STATE_FAILED;
        }
        return self::STATE_NOT_SENT;
    }

    /**
     * Was sending cancelled ?
     *
     * @return bool
     */
    public function getSendingCancelled()
    {
        return $this->sendingCancelled;
    }

    /**
     * The last result from "send" method. Null if not sent yet or sending was cancelled
     *
     * @return SentMail
     */
    public function getSentMail()
    {
        return $this->sentMail;
    }

    /**
     * Automatically adds a plain part to the email generated from the current Body
     *
     * @return $this
     */
    public function generatePlainPartFromBody()
    {
        $plainPart = $this->findPlainPart();
        if ($plainPart) {
            $this->getSwiftMessage()->detach($plainPart);
        }
        unset($plainPart);

        $this->getSwiftMessage()->addPart(
            EmailUtils::convert_html_to_text($this->getBody()),
            'text/plain',
            'utf-8'
        );

        return $this;
    }

    /**
     * @return $this
     */
    public function clearBody()
    {
        $this->getSwiftMessage()->setBody(null);
        return $this;
    }

    /**
     * Set the template to render the email with
     *
     * This method is overidden in order to look for email templates to provide
     * content to
     *
     * @param string $template
     * @return $this
     */
    public function setHTMLTemplate($template)
    {
        if (substr($template, -3) == '.ss') {
            $template = substr($template, 0, -3);
        }

        // Do we have a custom template matching this code?
        $code = self::makeTemplateCode($template);
        $emailTemplate = EmailTemplate::getByCode($code, false);
        if ($emailTemplate) {
            $emailTemplate->applyTemplate($this);
            return $this;
        }

        // If not, keep default behaviour (call method because var is private)
        return parent::setHTMLTemplate($template);
    }

    /**
     * Make a template code
     *
     * @param string $str
     * @return string
     */
    public static function makeTemplateCode($str)
    {
        // If we get a class name
        $parts = explode('\\', $str);
        $str = end($parts);
        $code = preg_replace('/Email$/', '', $str);
        return $code;
    }

    /**
     * Helper method to render string with data
     *
     * @param string $content
     * @return string
     */
    public function renderWithData($content)
    {
        $viewer = SSViewer::fromString($content);
        $data = $this->getData();
        // SSViewer_DataPresenter requires array
        if (is_object($data)) {
            if (method_exists($data, 'toArray')) {
                $data = $data->toArray();
            } else {
                $data = (array) $data;
            }
        }
        $result = (string) $viewer->process($this, $data);
        $result = self::rewriteURLs($result);
        return $result;
    }

    /**
     * Render the email
     * @param bool $plainOnly Only render the message as plain text
     * @return $this
     */
    public function render($plainOnly = false)
    {
        if ($existingPlainPart = $this->findPlainPart()) {
            $this->getSwiftMessage()->detach($existingPlainPart);
        }
        unset($existingPlainPart);

        // Respect explicitly set body
        $htmlPart = $plainPart = null;

        // Only respect if we don't have an email template
        if ($this->emailTemplate) {
            $htmlPart = $plainOnly ? null : $this->getBody();
            $plainPart = $plainOnly ? $this->getBody() : null;
        }

        // Ensure we can at least render something
        $htmlTemplate = $this->getHTMLTemplate();
        $plainTemplate = $this->getPlainTemplate();
        if (!$htmlTemplate && !$plainTemplate && !$plainPart && !$htmlPart) {
            return $this;
        }

        // Do not interfere with emails styles
        Requirements::clear();

        // Render plain part
        if ($plainTemplate && !$plainPart) {
            $plainPart = $this->renderWith($plainTemplate, $this->getData())->Plain();
            // Do another round of rendering to render our variables inside
            $plainPart = $this->renderWithData($plainPart);
        }

        // Render HTML part, either if sending html email, or a plain part is lacking
        if (!$htmlPart && $htmlTemplate && (!$plainOnly || empty($plainPart))) {
            $htmlPart = $this->renderWith($htmlTemplate, $this->getData());
            // Do another round of rendering to render our variables inside
            $htmlPart = $this->renderWithData($htmlPart);
        }

        // Render subject with data as well
        $subject = $this->renderWithData($this->getSubject());
        // Html entities in email titles is not a good idea
        $subject = html_entity_decode($subject, ENT_QUOTES | ENT_XML1, 'UTF-8');
        parent::setSubject($subject);

        // Plain part fails over to generated from html
        if (!$plainPart && $htmlPart) {
            $plainPart = EmailUtils::convert_html_to_text($htmlPart);
        }

        // Rendering is finished
        Requirements::restore();

        // Fail if no email to send
        if (!$plainPart && !$htmlPart) {
            return $this;
        }

        // Build HTML / Plain components
        if ($htmlPart && !$plainOnly) {
            $this->setBody($htmlPart);
            $this->getSwiftMessage()->setContentType('text/html');
            $this->getSwiftMessage()->setCharset('utf-8');
            if ($plainPart) {
                $this->getSwiftMessage()->addPart($plainPart, 'text/plain', 'utf-8');
            }
        } else {
            if ($plainPart) {
                $this->setBody($plainPart);
            }
            $this->getSwiftMessage()->setContentType('text/plain');
            $this->getSwiftMessage()->setCharset('utf-8');
        }

        return $this;
    }

    /**
     * Get locale set before email is sent
     *
     * @return string
     */
    public function getLocale()
    {
        return $this->locale;
    }

    /**
     *  Set locale to set before email is sent
     *
     * @param string $val
     */
    public function setLocale($val)
    {
        $this->locale = $val;
    }

    /**
     * Is this email disabled ?
     *
     * @return boolean
     */
    public function getDisabled()
    {
        return $this->disabled;
    }

    /**
     * Disable this email (sending will have no effect)
     *
     * @param bool $disabled
     * @return $this
     */
    public function setDisabled($disabled)
    {
        $this->disabled = (bool) $disabled;
        return $this;
    }

    /**
     * Get recipient as member
     *
     * @return Member
     */
    public function getToMember()
    {
        if (!$this->to_member && $this->to) {
            $email = EmailUtils::get_email_from_rfc_email($this->to);
            $member = Member::get()->filter(array('Email' => $email))->first();
            if ($member) {
                $this->setToMember($member);
            }
        }
        return $this->to_member;
    }

    /**
     * Set recipient(s) of the email
     *
     * To send to many, pass an array:
     * array('me@example.com' => 'My Name', 'other@example.com');
     *
     * @param string|array $address The message recipient(s) - if sending to multiple, use an array of address => name
     * @param string|null $name The name of the recipient (if one)
     * @return $this
     */
    public function setTo($address, $name = null)
    {
        // Make sure this doesn't conflict with to_member property
        if ($this->to_member) {
            $this->to_member = null;
        }
        return parent::setTo($address, $name);
    }

    /**
     * @param string $subject The Subject line for the email
     * @return $this
     */
    public function setSubject($subject)
    {
        // Do not allow changing subject if a template is set
        if ($this->emailTemplate && $this->getSubject()) {
            return $this;
        }
        return parent::setSubject($subject);
    }

    /**
     * Send to admin
     *
     * @return Email
     */
    public function setToAdmin()
    {
        return $this->setToMember(Security::findAnAdministrator());
    }

    /**
     * Set a member as a recipient.
     *
     * It will also set the $Recipient variable in the template
     *
     * @param Member $member
     * @param string $locale Locale to use, set to false to keep current locale
     * @return BetterEmail
     */
    public function setToMember(Member $member, $locale = null)
    {
        if ($locale === null) {
            $this->locale = $member->Locale;
        } else {
            $this->locale = $locale;
        }
        $this->to_member = $member;

        $this->addData(array('Recipient' => $member));

        return parent::setTo($member->Email, $member->getTitle());
    }

    /**
     * Get sender as member
     *
     * @return Member
     */
    public function getFromMember()
    {
        if (!$this->from_member && $this->from) {
            $email = EmailUtils::get_email_from_rfc_email($this->from);
            $member = Member::get()->filter(array('Email' => $email))->first();
            if ($member) {
                $this->setFromMember($member);
            }
        }
        return $this->from_member;
    }

    /**
     * Set From Member
     *
     * It will also set the $Sender variable in the template
     *
     * @param Member $member
     * @return BetterEmail
     */
    public function setFromMember(Member $member)
    {
        $this->from_member = $member;

        $this->addData(array('Sender' => $member));

        return $this->setFrom($member->Email, $member->getTitle());
    }

    /**
     * Improved set from that supports Name <my@domain.com> notation
     *
     * @param string|array $address
     * @param string|null $name
     * @return $this
     */
    public function setFrom($address, $name = null)
    {
        if ($name === null && is_string($address)) {
            $name = EmailUtils::get_displayname_from_rfc_email($address);
            $address = EmailUtils::get_email_from_rfc_email($address);
        }
        return parent::setFrom($address, $name);
    }

    /**
     * Bug safe absolute url that support subsites
     *
     * @param string $url
     * @param bool $relativeToSiteBase
     * @return string
     */
    protected static function safeAbsoluteURL($url, $relativeToSiteBase = false)
    {
        if (empty($url)) {
            return Director::baseURL();
        }
        $absUrl = Director::absoluteURL($url, $relativeToSiteBase);

        // If we use subsite, absolute url may not use the proper url
        if (SubsiteHelper::usesSubsite()) {
            $subsite = SubsiteHelper::currentSubsite();
            if ($subsite->hasMethod('getPrimarySubsiteDomain')) {
                $domain = $subsite->getPrimarySubsiteDomain();
                $link = $subsite->domain();
                $protocol = $domain->getFullProtocol();
            } else {
                $protocol = Director::protocol();
                $link = $subsite->domain();
            }
            $absUrl = preg_replace('/\/\/[^\/]+\//', '//' . $link . '/', $absUrl);
            $absUrl = preg_replace('/http(s)?:\/\//', $protocol, $absUrl);
        }

        return $absUrl;
    }

    /**
     * Turn all relative URLs in the content to absolute URLs
     */
    protected static function rewriteURLs($html)
    {
        if (isset($_SERVER['REQUEST_URI'])) {
            $html = str_replace('$CurrentPageURL', $_SERVER['REQUEST_URI'], $html);
        }
        return HTTP::urlRewriter($html, function ($url) {
            //no need to rewrite, if uri has a protocol (determined here by existence of reserved URI character ":")
            if (preg_match('/^\w+:/', $url)) {
                return $url;
            }
            return self::safeAbsoluteURL($url, true);
        });
    }

    /**
     * Get the value of emailTemplate
     * @return EmailTemplate
     */
    public function getEmailTemplate()
    {
        return $this->emailTemplate;
    }

    /**
     * Set the value of emailTemplate
     *
     * @param EmailTemplate $emailTemplate
     * @return $this
     */
    public function setEmailTemplate(EmailTemplate $emailTemplate)
    {
        $this->emailTemplate = $emailTemplate;
        return $this;
    }
}
