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
        $recipientsList = self::listRecipients();
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
        if (!$list) {
            switch ($this->Recipients) {
                case 'ALL_MEMBERS':
                    $list = Member::get();
                    break;
                case 'SELECTED_MEMBERS':
                    $list = Member::get()->filter('ID', $this->getNormalizedRecipientsList());
                    break;
                default:
                    $list = Member::get()->filter('ID', 0);
                    break;
            }
        }
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
    public static function listRecipients()
    {
        $arr = [];
        $arr['ALL_MEMBERS'] = _t('Emailing.ALL_MEMBERS', 'All members');
        $arr['SELECTED_MEMBERS'] = _t('Emailing.SELECTED_MEMBERS', 'Selected members');
        $locales = self::getMembersLocales();
        foreach ($locales as $locale) {
            $arr[$locale . '_MEMBERS'] = _t('Emailing.LOCALE_MEMBERS', '{locale} members', ['locale' => $locale]);
        }
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
            $email->setFrom($this->Sender);
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
     * Returns an array of email with members by locale
     *
     * @return array
     */
    public function getEmailByLocales()
    {
        $membersByLocale = [];
        foreach ($this->getAllRecipients() as $r) {
            if (!isset($membersByLocale[$r->Locale])) {
                $membersByLocale[$r->Locale] = [];
            }
            $membersByLocale[$r->Locale][] = $r;
        }

        $emails = [];
        foreach ($membersByLocale as $locale => $membersList) {
            $email = Email::create();
            if (!$email instanceof BetterEmail) {
                throw new Exception("Make sure you are injecting the BetterEmail class instead of your base Email class");
            }
            if ($this->Sender) {
                $email->setFrom($this->Sender);
            }
            foreach ($membersList as $r) {
                $email->addBCC($r->Email, $r->FirstName . ' ' . $r->Surname);
            }

            // Localize
            $EmailingID = $this->ID;
            FluentHelper::withLocale($locale, function () use ($EmailingID, $email) {
                $Emailing = Emailing::get()->byID($EmailingID);
                $email->setSubject($Emailing->Subject);
                $email->addData('EmailContent', $Emailing->Content);
                $email->addData('Callout', $Emailing->Callout);
            });

            $emails[$locale] = $email;
        }
        return $emails;
    }
}
