<?php

/**
 * An improved and more pleasant base Email class to use on your project
 *
 * This class is fully decoupled from the EmailTemplate class and keep be used
 * independantly
 *
 * Improvements are:
 *
 * - URL safe rewriting
 * - Configurable base template
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

    /**
     *
     * @var string
     */
    protected $original_body;

    /**
     *
     * @var string
     */
    protected $locale;

    /**
     *
     * @var Member
     */
    protected $to_member;

    /**
     *
     * @var Member
     */
    protected $from_member;

    /**
     *
     * @var boolean
     */
    protected $parse_body = false;

    /**
     *
     * @var boolean
     */
    protected $disabled = false;

    public function __construct($from = null, $to = null, $subject = null, $body = null, $bounceHandlerURL = null, $cc = null, $bcc = null)
    {
        parent::__construct($from, $to, $subject, $body, $bounceHandlerURL, $cc, $bcc);

        // Use config template
        if ($defaultTemplate = $this->config()->template) {
            $this->setTemplate($defaultTemplate);
        }
    }

    /**
     * Persists a {@link SentEmail} record to the database
     * @return SentEmail
     */
    protected function persist($results = array())
    {
        $record = SentEmail::create(array(
                'To' => $this->To(),
                'From' => $this->From(),
                'Subject' => $this->Subject(),
                'Body' => $this->Body(),
                'CC' => $this->CC(),
                'BCC' => $this->BCC(),
                'Results' => json_encode($results),
        ));
        $record->write();

        $max = self::config()->max_records;
        if ($max && SentEmail::get()->count() > $max) {
            $method = self::config()->cleanup_method;

            // Delete all records older than cleanup_time (7 days by default)
            if ($method == 'time') {
                $time = self::config()->cleanup_time;
                $date = date('Y-m-d H:i:s', strtotime($time));
                DB::query("DELETE FROM \"SentEmail\" WHERE Created < '$date'");
            }

            // Delete all records that are after half the maximum number of records
            if ($method == 'max') {
                $maxID = SentEmail::get()->max('ID') - ($max / 2);
                DB::query("DELETE FROM \"SentEmail\" WHERE ID < '$maxID'");
            }
        }

        return $record;
    }

    /**
     * Sends an HTML email
     * @param int $messageID
     */
    public function send($messageID = null)
    {
        return $this->doSend($messageID, false);
    }

    /**
     * Sends a plain text email
     * @param int $messageID
     */
    public function sendPlain($messageID = null)
    {
        return $this->doSend($messageID, true);
    }

    /**
     * Send this email
     *
     * @param int $messageID
     * @param boolean $plain
     * @return boolean
     * @throws Exception
     */
    public function doSend($messageID = null, $plain = false)
    {
        if ($this->disabled) {
            return false;
        }

        // Check for Subject
        if (!$this->subject) {
            throw new Exception('You must set a subject');
        }

        // This hook can prevent email from being sent
        $result = $this->extend('onBeforeDoSend', $this);
        if ($result === false) {
            return false;
        }

        $SiteConfig = SiteConfig::current_site_config();

        // Check for Sender and use default if necessary
        $from = $this->From();
        if (!$from) {
            $this->setFrom($SiteConfig->EmailDefaultSender());
        }

        // Check for Recipient and use default if necessary
        $to = $this->To();
        if (!$to) {
            $this->setTo($SiteConfig->EmailDefaultRecipient());
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

        if ($plain) {
            // sendPlain use the body variable
            $this->body = $this->convertHtmlToText($this->body);
            $res = parent::sendPlain($messageID);
        } else {
            // send will use plaintext_body variable as plain text alternative
            $this->plaintext_body = $this->convertHtmlToText($this->body);
            $res = parent::send($messageID);
        }

        if ($restore_locale) {
            i18n::set_locale($restore_locale);
        }

        $this->extend('onAfterDoSend', $this, $res);
        $this->persist($res);

        return $res;
    }

    protected function templateData()
    {
        $data = parent::templateData();

        $data = $data->customise(array(
            'SiteConfig' => SiteConfig::current_site_config(),
            'CurrentSiteConfig' => SiteConfig::current_site_config(),
            'CurrentController' => Controller::has_curr() ? Controller::curr() : new Controller,
        ));

        return $data;
    }

    /**
     * Load all the template variables into the internal variables, including
     * the template into body. Called before send() or debug().
     *
     * $isPlain=true will do nothing and is kept for compatibility reason only.
     * Template data can contain important information and should be properly
     * converted to plaintext by the send method.
     */
    protected function parseVariables($isPlain = false)
    {
        // Turn off source fill comments will rendering the content
        $state = Config::inst()->get('SSViewer', 'source_file_comments');
        Config::inst()->update('SSViewer', 'source_file_comments', false);

        // Avoid clutter in our rendered html
        Requirements::clear();

        if (!$this->parseVariables_done) {
            $this->parseVariables_done = true;

            // Parse $ variables in the base parameters
            $data = $this->templateData();

            // Parse the email title
            if ($this->subject) {
                try {
                    $viewer = SSViewer::fromString($this->subject);
                    $this->subject = $viewer->process($data);
                } catch (Exception $ex) {
                    SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
                }
            }

            // Update templateData for subject
            $data = $this->templateData();

            // Original body is the data intended to be parsed
            $original_body = $this->original_body;

            // Parse the values
            $parsed_body = array();
            foreach ($original_body as $k => $v) {
                try {
                    $viewer = SSViewer::fromString($v);
                    $parsed_body[$k] = $viewer->process($data);
                } catch (Exception $ex) {
                    SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
                    if(Director::isDev()) {
                        $parsed_body[$k] = $ex->getMessage();
                    }
                }
            }

            $data = $data->customise($parsed_body);

            if ($this->ss_template) {
                $template = new SSViewer($this->ss_template);

                if ($template->exists()) {
                    $this->body = $template->process($data);
                }
            } else {
                $this->body = implode("\n\n", array_values($original_body));
            }

            // Rewrite relative URLs
            $this->body = self::rewriteURLs($this->body);
        }

        Config::inst()->update('SSViewer', 'source_file_comments', $state);
        Requirements::restore();

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
     * Is body parsed or not?
     *
     * @return bool
     */
    public function getParseBody()
    {
        return $this->parse_body;
    }

    /**
     * Set if body should be parsed or not
     *
     * @param bool $v
     * @return $this
     */
    public function setParseBody($v = true)
    {
        $this->parse_body = (bool) $v;
        return $this;
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
     * @param type $disabled
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
            $email = self::get_email_from_rfc_email($this->to);
            $member = Member::get()->filter(array('Email' => $email))->first();
            if ($member) {
                $this->setToMember($member);
            }
        }
        return $this->to_member;
    }

    /**
     * @return array
     */
    public function getOriginalBody()
    {
        return $this->original_body;
    }

    /**
     * Set the body. The content of the body will be parsed with SSViewer and templateData will be injected
     * @param string|array $val A string or an array of string
     * @return $this
     */
    public function setBody($val)
    {
        if (!is_array($val)) {
            $val = array(
                'Body' => $val
            );
        }
        if (!isset($val['Body'])) {
            throw new Exception('The array should have an element with Body as key');
        }
        $this->original_body = $val;
        return $this;
    }

    /**
     * Set recipient
     *
     * @param string $val
     * @return Email
     */
    public function setTo($val)
    {
        // Make sure this doesn't conflict with to_member property
        if ($this->to_member && $val !== $this->to_member->Email) {
            $this->to_member = false;
        }
        return parent::setTo($val);
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

        $this->populateTemplate(array('Recipient' => $member));

        return $this->setTo($member->Email);
    }

    /**
     * Get sender as member
     *
     * @return Member
     */
    public function getFromMember()
    {
        if (!$this->from_member && $this->from) {
            $email = self::get_email_from_rfc_email($this->from);
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
     * @param Member $member
     * @return BetterEmail
     */
    public function setFromMember(Member $member)
    {
        $this->from_member = $member;

        $this->populateTemplate(array('Sender' => $member));

        return $this->setFrom($member->Email);
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
        if (class_exists('Subsite') && Subsite::currentSubsiteID()) {
            $subsite = Subsite::currentSubsite();
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
     * Match all words and whitespace, will be terminated by '<'
     *
     * Note: use /u to support utf8 strings
     *
     * @param string $rfc_email_string
     * @return string
     */
    protected static function get_displayname_from_rfc_email($rfc_email_string)
    {
        $name = preg_match('/[\w\s]+/u', $rfc_email_string, $matches);
        $matches[0] = trim($matches[0]);
        return $matches[0];
    }

    /**
     * Extract parts between brackets
     *
     * @param string $rfc_email_string
     * @return string
     */
    protected static function get_email_from_rfc_email($rfc_email_string)
    {
        if (strpos($rfc_email_string, '<') === false) {
            return $rfc_email_string;
        }
        preg_match('/(?:<)(.+)(?:>)$/', $rfc_email_string, $matches);
        if (empty($matches)) {
            return $rfc_email_string;
        }
        return $matches[1];
    }

    /**
     * Convert an html email to a text email while keeping formatting and links
     *
     * @param string $content
     * @return string
     */
    protected static function convertHtmlToText($content)
    {
        // Prevent styles to be included
        $content = preg_replace('/<style.*>([\s\S]*)<\/style>/i', '', $content);
        // Convert html entities to strip them later on
        $content = html_entity_decode($content);
        // Convert new lines for relevant tags
        $content = str_ireplace(['<br />', '<br/>', '<br>', '<table>', '</table>'], "\r\n", $content);
        // Avoid lots of spaces
        $content = preg_replace('/[\r\n]+/', ' ', $content);
        // Replace links to keep them accessible
        $content = preg_replace('/<a[\s\S]*href="(.*?)"[\s\S]*>(.*)<\/a>/i', '$2 ($1)', $content);
        // Remove html tags
        $content = strip_tags($content);
        // Trim content so that it's nice
        $content = trim($content);
        return $content;
    }
}
