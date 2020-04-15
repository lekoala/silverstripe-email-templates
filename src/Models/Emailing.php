<?php

namespace LeKoala\EmailTemplates\Models;

use Exception;
use SilverStripe\ORM\DB;
use SilverStripe\Forms\Tab;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\TextField;
use SilverStripe\Security\Member;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Control\Email\Email;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Security\Permission;
use SilverStripe\SiteConfig\SiteConfig;
use LeKoala\EmailTemplates\Email\BetterEmail;
use LeKoala\EmailTemplates\Helpers\FluentHelper;
use LeKoala\EmailTemplates\Admin\EmailTemplatesAdmin;
use LeKoala\EmailTemplates\Helpers\EmailUtils;
use SilverStripe\Forms\FormAction;

/**
 * Send emails to a group of members
 *
 * @property string $Subject
 * @property string $Recipients
 * @property string $RecipientsList
 * @property string $Sender
 * @property string $Content
 * @property string $Callout
 * @author lekoala
 */
class Emailing extends DataObject
{
    private static $table_name = 'Emailing';

    private static $db = array(
        'Subject' => 'Varchar(255)',
        'Recipients' => 'Varchar(255)',
        'RecipientsList' => 'Text',
        'Sender' => 'Varchar(255)',
        'LastSent' => 'Datetime',
        // Content
        'Content' => 'HTMLText',
        'Callout' => 'HTMLText',
    );
    private static $summary_fields = array(
        'Subject', 'LastSent'
    );
    private static $searchable_fields = array(
        'Subject',
    );
    private static $translate = array(
        'Subject', 'Content', 'Callout'
    );

    public function getTitle()
    {
        return $this->Subject;
    }

    public function getCMSActions()
    {
        $actions = parent::getCMSActions();
        $label = _t('Emailing.SEND', 'Send');
        $sure = _t('Emailing.SURE', 'Are you sure?');
        $onclick = 'return confirm(\'' . $sure . '\');';

        $sanitisedModel =  str_replace('\\', '-', Emailing::class);
        $adminSegment = EmailTemplatesAdmin::config()->url_segment;
        $link = '/admin/' . $adminSegment . '/' . $sanitisedModel . '/SendEmailing/?id=' . $this->ID;
        $btnContent = '<a href="' . $link . '" id="action_doSend" onclick="' . $onclick . '" class="btn action btn-info font-icon-angle-double-right">';
        $btnContent .= '<span class="btn__title">' . $label . '</span></a>';
        $actions->push(new LiteralField('doSend', $btnContent));
        return $actions;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        // Do not allow changing subsite
        $fields->removeByName('SubsiteID');

        // Recipients
        $recipientsList = $this->listRecipients();
        $fields->replaceField('Recipients', $Recipients = new DropdownField('Recipients', null, $recipientsList));
        $Recipients->setDescription(_t('Emailing.EMAIL_COUNT', "Email will be sent to {count} members", ['count' => $this->getAllRecipients()->count()]));

        $fields->dataFieldByName('Callout')->setRows(5);

        if ($this->ID) {
            $fields->addFieldToTab('Root.Preview', $this->previewTab());
        }

        $fields->addFieldsToTab('Root.Settings', new TextField('Sender'));
        $fields->addFieldsToTab('Root.Settings', new ReadonlyField('LastSent'));
        $fields->addFieldsToTab('Root.Settings', $RecipientsList = new TextareaField('RecipientsList'));
        $RecipientsList->setDescription(_t('Emailing.RECIPIENTSLISTHELP', 'A list of IDs or emails on each line or separated by commas. Select "Selected members" to use this list'));

        return $fields;
    }

    /**
     * @return DataList
     */
    public function getAllRecipients()
    {
        $list = null;
        $locales = self::getMembersLocales();
        foreach ($locales as $locale) {
            if ($this->Recipients == $locale . '_MEMBERS') {
                $list = Member::get()->filter('Locale', $locale);
            }
        }
        $recipients = $this->Recipients;
        if (!$list) {
            switch ($recipients) {
                case 'ALL_MEMBERS':
                    $list = Member::get()->exclude('Email', '');
                    break;
                case 'SELECTED_MEMBERS':
                    $IDs =  $this->getNormalizedRecipientsList();
                    if (empty($IDs)) {
                        $IDs = 0;
                    }
                    $list = Member::get()->filter('ID', $IDs);
                    break;
                default:
                    $list = Member::get()->filter('ID', 0);
                    break;
            }
        }
        $this->extend('updateGetAllRecipients', $list, $locales, $recipients);
        return $list;
    }

    /**
     * List of ids
     *
     * @return array
     */
    public function getNormalizedRecipientsList()
    {
        $list = $this->RecipientsList;

        $perLine = explode("\n", $list);

        $arr = [];
        foreach ($perLine as $line) {
            $items = explode(',', $line);
            foreach ($items as $item) {
                // Prevent whitespaces from messing up our queries
                $item = trim($item);

                if (!$item) {
                    continue;
                }
                if (is_numeric($item)) {
                    $arr[] = $item;
                } elseif (strpos($item, '@') !== false) {
                    $arr[] = DB::prepared_query("SELECT ID FROM Member WHERE Email = ?", [$item])->value();
                } else {
                    throw new Exception("Unprocessable item $item");
                }
            }
        }
        return $arr;
    }

    /**
     * @return array
     */
    public static function getMembersLocales()
    {
        return DB::query("SELECT DISTINCT Locale FROM Member")->column();
    }

    /**
     * @return array
     */
    public function listRecipients()
    {
        $arr = [];
        $arr['ALL_MEMBERS'] = _t('Emailing.ALL_MEMBERS', 'All members');
        $arr['SELECTED_MEMBERS'] = _t('Emailing.SELECTED_MEMBERS', 'Selected members');
        $locales = self::getMembersLocales();
        foreach ($locales as $locale) {
            $arr[$locale . '_MEMBERS'] = _t('Emailing.LOCALE_MEMBERS', '{locale} members', ['locale' => $locale]);
        }
        $this->extend("updateListRecipients", $arr, $locales);
        return $arr;
    }

    /**
     * Provide content for the Preview tab
     *
     * @return Tab
     */
    protected function previewTab()
    {
        $tab = new Tab('Preview');

        // Preview iframe
        $sanitisedModel =  str_replace('\\', '-', Emailing::class);
        $adminSegment = EmailTemplatesAdmin::config()->url_segment;
        $iframeSrc = '/admin/' . $adminSegment . '/' . $sanitisedModel . '/PreviewEmailing/?id=' . $this->ID;
        $iframe = new LiteralField('iframe', '<iframe src="' . $iframeSrc . '" style="width:800px;background:#fff;border:1px solid #ccc;min-height:500px;vertical-align:top"></iframe>');
        $tab->push($iframe);

        // Merge var helper
        $vars = $this->collectMergeVars();
        $syntax = self::config()->mail_merge_syntax;
        if (empty($vars)) {
            $varsHelperContent = "You can use $syntax notation to use mail merge variable for the recipients";
        } else {
            $varsHelperContent = "The following mail merge variables are used : " . implode(", ", $vars);
        }
        $varsHelper = new LiteralField("varsHelpers", '<div><br/><br/>' . $varsHelperContent . '</div>');
        $tab->push($varsHelper);

        return $tab;
    }

    public function canView($member = null)
    {
        return true;
    }

    public function canEdit($member = null)
    {
        return Permission::check('CMS_ACCESS', 'any', $member);
    }

    public function canCreate($member = null, $context = [])
    {
        return Permission::check('CMS_ACCESS', 'any', $member);
    }

    public function canDelete($member = null)
    {
        return Permission::check('CMS_ACCESS', 'any', $member);
    }

    /**
     * Get rendered body
     *
     * @return string
     */
    public function renderTemplate()
    {
        // Disable debug bar in the iframe
        Config::modify()->set('LeKoala\\DebugBar\\DebugBar', 'auto_inject', false);

        $email = $this->getEmail();
        $html = $email->getRenderedBody();
        return $html;
    }

    /**
     * Collect all merge vars
     *
     * @return array
     */
    public function collectMergeVars()
    {
        $fields = ['Subject', 'Content', 'Callout'];

        $syntax = self::config()->mail_merge_syntax;

        $regex = $syntax;
        $regex = preg_quote($regex);
        $regex = str_replace("MERGETAG", "([\w\.]+)", $regex);

        $allMatches = [];
        foreach ($fields as $field) {
            $content = $this->$field;
            $matches = [];
            preg_match_all('/' . $regex . '/', $content, $matches);
            if (!empty($matches[1])) {
                $allMatches = array_merge($allMatches, $matches[1]);
            }
        }

        return $allMatches;
    }


    /**
     * Returns an instance of an Email with the content of the emailing
     *
     * @return BetterEmail
     */
    public function getEmail()
    {
        $email = Email::create();
        if (!$email instanceof BetterEmail) {
            throw new Exception("Make sure you are injecting the BetterEmail class instead of your base Email class");
        }
        if ($this->Sender) {
            $senderEmail = EmailUtils::get_email_from_rfc_email($this->Sender);
            $senderName = EmailUtils::get_displayname_from_rfc_email($this->Sender);
            $email->setFrom($senderEmail, $senderName);
        }
        foreach ($this->getAllRecipients() as $r) {
            $email->addBCC($r->Email, $r->FirstName . ' ' . $r->Surname);
        }
        $email->setSubject($this->Subject);
        $email->addData('EmailContent', $this->Content);
        $email->addData('Callout', $this->Callout);
        return $email;
    }

    /**
     * Various email providers use various types of mail merge headers
     * By default, we use mandrill that is expected to work for other platforms through compat layer
     *
     * X-Mailgun-Recipient-Variables: {"bob@example.com": {"first":"Bob", "id":1}, "alice@example.com": {"first":"Alice", "id": 2}}
     * Template syntax: %recipient.first%
     * @link https://documentation.mailgun.com/en/latest/user_manual.html#batch-sending
     *
     * X-MC-MergeVars [{"rcpt":"recipient.email@example.com","vars":[{"name":"merge2","content":"merge2 content"}]}]
     * Template syntax: *|MERGETAG|*
     * @link https://mandrill.zendesk.com/hc/en-us/articles/205582117-How-to-Use-SMTP-Headers-to-Customize-Your-Messages
     *
     * @link https://developers.sparkpost.com/api/smtp/#header-using-the-x-msys-api-custom-header
     *
     * @return string
     */
    public function getMergeVarsHeader()
    {
        return self::config()->mail_merge_header;
    }

    /**
     * Returns an array of emails with members by locale, grouped by a given number of recipients
     * Some apis prevent sending too many emails at the same time
     *
     * @return array
     */
    public function getEmailsByLocales()
    {
        $batchCount = self::config()->batch_count ?? 1000;
        $sendBcc = self::config()->send_bcc;

        $membersByLocale = [];
        foreach ($this->getAllRecipients() as $r) {
            if (!isset($membersByLocale[$r->Locale])) {
                $membersByLocale[$r->Locale] = [];
            }
            $membersByLocale[$r->Locale][] = $r;
        }

        $mergeVars = $this->collectMergeVars();
        $mergeVarHeader = $this->getMergeVarsHeader();

        $emails = [];
        foreach ($membersByLocale as $locale => $membersList) {
            $emails[$locale] = [];
            $chunks = array_chunk($membersList, $batchCount);
            foreach ($chunks as $chunk) {
                $email = Email::create();
                if (!$email instanceof BetterEmail) {
                    throw new Exception("Make sure you are injecting the BetterEmail class instead of your base Email class");
                }
                if ($this->Sender) {
                    $senderEmail = EmailUtils::get_email_from_rfc_email($this->Sender);
                    $senderName = EmailUtils::get_displayname_from_rfc_email($this->Sender);
                    $email->setFrom($senderEmail, $senderName);
                }
                $mergeVarsData = [];
                foreach ($chunk as $r) {
                    if ($sendBcc) {
                        $email->addBCC($r->Email, $r->FirstName . ' ' . $r->Surname);
                    } else {
                        $email->addTo($r->Email, $r->FirstName . ' ' . $r->Surname);
                    }
                    if (!empty($mergeVars)) {
                        $vars = [];
                        foreach ($mergeVars as $mergeVar) {
                            $v = null;
                            if ($r->hasMethod($mergeVar)) {
                                $v = $r->$mergeVar();
                            } else {
                                $v = $r->$mergeVar;
                            }
                            $vars[$mergeVar] = $v;
                        }
                        $mergeVarsData[] = [
                            'rcpt' => $r->Email,
                            'vars' => $vars
                        ];
                    }
                }
                // Merge vars
                if (!empty($mergeVars)) {
                    $email->getSwiftMessage()->getHeaders()->addTextHeader($mergeVarHeader, json_encode($mergeVarsData));
                }
                // Localize
                $EmailingID = $this->ID;
                FluentHelper::withLocale($locale, function () use ($EmailingID, $email) {
                    $Emailing = Emailing::get()->byID($EmailingID);
                    $email->setSubject($Emailing->Subject);
                    $email->addData('EmailContent', $Emailing->Content);
                    $email->addData('Callout', $Emailing->Callout);
                });
                $emails[$locale][] = $email;
            }
        }
        return $emails;
    }
}
