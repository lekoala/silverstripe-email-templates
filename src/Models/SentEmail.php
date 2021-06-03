<?php

namespace LeKoala\EmailTemplates\Models;

use LeKoala\Base\Actions\CustomAction;
use LeKoala\EmailTemplates\Admin\EmailTemplatesAdmin;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * Defines a record that stores an email
 *
 * @link https://github.com/nyeholt/silverstripe-mailcapture/blob/master/src/Model/CapturedEmail.php
 * @property string $To
 * @property string $From
 * @property string $Subject
 * @property string $Body
 * @property string $CC
 * @property string $BCC
 * @property string $Results
 * @author lekoala
 */
class SentEmail extends DataObject
{
    private static $table_name = 'SentEmail';

    private static $db = array(
        'To' => 'Varchar(191)',
        'From' => 'Varchar(191)',
        'ReplyTo' => 'Varchar(191)',
        'Subject' => 'Varchar(191)',
        'Body' => 'HTMLText',
        'Headers' => 'Text',
        'CC' => 'Text',
        'BCC' => 'Text',
        'Results' => 'Text',
    );
    private static $summary_fields = array(
        'Created.Nice' => 'Date',
        'To' => 'To',
        'Subject' => 'Subject',
        'IsSuccess' => 'Success',
    );
    private static $default_sort = 'Created DESC';

    /**
     * Gets a list of actions for the ModelAdmin interface
     * @return FieldList
     */
    public function getCMSActions()
    {
        $fields = parent::getCMSActions();

        if (class_exists(CustomAction::class)) {
            $fields->push(
                CustomAction::create('resend', 'Resend')
            );
        }

        return $fields;
    }

    /**
     * Gets a list of form fields for editing the record.
     * These records should never be edited, so a readonly list of fields
     * is forced.
     *
     * @return FieldList
     */
    public function getCMSFields()
    {
        $f = FieldList::create();

        $f->push(ReadonlyField::create('Created'));
        $f->push(ReadonlyField::create('From'));
        $f->push(ReadonlyField::create('To'));
        $f->push(ReadonlyField::create('Subject'));

        if ($this->BCC) {
            $f->push(ReadonlyField::create('BCC'));
        }
        if ($this->CC) {
            $f->push(ReadonlyField::create('CC'));
        }

        $f->push(ReadonlyField::create('Results'));

        $sanitisedModel =  str_replace('\\', '-', SentEmail::class);
        $adminSegment = EmailTemplatesAdmin::config()->url_segment;
        $iframeSrc = Director::baseURL() . '/admin/' . $adminSegment . '/' . $sanitisedModel . '/ViewSentEmail/?id=' . $this->ID;
        $iframe = new LiteralField('iframe', '<iframe src="' . $iframeSrc . '" style="width:800px;background:#fff;border:1px solid #ccc;min-height:500px;vertical-align:top"></iframe>');
        $f->push($iframe);

        $this->extend('updateCMSFields', $f);

        return $f;
    }

    /**
     * @return bool
     */
    public function IsSuccess()
    {
        return $this->Results == 'true';
    }

    /**
     * Gets the BetterEmail object that was used to send this email
     * @return BetterEmail
     */
    public function getEmail()
    {
        $email = Email::create();

        $email->setTo($this->To);
        $email->setCc($this->CC);
        $email->setBCC($this->BCC);
        $email->setSubject($this->Subject);
        $email->setReplyTo($this->ReplyTo);
        $email->setBody($this->Body);

        return $email;
    }

    /**
     * A BetterButtons custom action that allows the email to be resent
     */
    public function resend()
    {
        if ($e = $this->getEmail()) {
            $results = $e->send();

            // Update results
            $this->Results = $results;
            $this->write();

            return 'Sent';
        }

        return 'Could not send email';
    }


    /**
     * Cleanup sent emails based on your config
     *
     * @return void
     */
    public static function cleanup()
    {
        $max = self::config()->max_records;
        if ($max && self::get()->count() > $max) {
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
    }

    /**
     * Defines the view permission
     * @param  Member $member
     * @return boolean
     */
    public function canView($member = null)
    {
        return Permission::check('CMS_ACCESS');
    }

    /**
     * Defines the edit permission
     * @param  Member $member
     * @return boolean
     */
    public function canEdit($member = null)
    {
        return false;
    }

    /**
     * Defines the create permission
     * @param  Member $member
     * @return boolean
     */
    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    /**
     * Defines the delete permission
     * @param  Member $member
     * @return boolean
     */
    public function canDelete($member = null)
    {
        return Permission::check('CMS_ACCESS');
    }
}
