<?php

/**
 * Defines a record that stores an email that was sent via {@link BetterEmail} 
 *
 * @property string $To
 * @property string $From
 * @property string $Subject
 * @property string $Body
 * @property string $CC
 * @property string $BCC
 * @property string $Results
 * @property string $SerializedEmail
 * @author lekoala
 */
class SentEmail extends DataObject
{

    private static $db = array(
        'To' => 'Varchar(191)',
        'From' => 'Varchar(191)',
        'Subject' => 'Varchar(191)',
        'Body' => 'HTMLText',
        'CC' => 'Text',
        'BCC' => 'Text',
        'Results' => 'Text',
    );
    private static $summary_fields = array(
        'Created.Nice' => 'Date',
        'To' => 'To',
        'Subject' => 'Subject'
    );
    private static $default_sort = 'Created DESC';

    /**
     * Defines a list of methods that can be invoked by BetterButtons custom actions
     * @var array
     */
    private static $better_buttons_actions = array(
        'resend'
    );

    /**
     * Gets a list of actions for the ModelAdmin interface
     * @return FieldList
     */
    public function getBetterButtonsActions()
    {
        $fields = parent::getBetterButtonsActions();
        $fields->push(BetterButtonCustomAction::create('resend', 'Resend')
                ->setRedirectType(BetterButtonCustomAction::REFRESH)
        );

        $this->extend('updateBetterButtonsActions', $fields);

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
        $f->push(ReadonlyField::create('To'));
        $f->push(ReadonlyField::create('Subject'));

        if ($this->BCC) {
            $f->push(ReadonlyField::create('BCC'));
        }
        if ($this->CC) {
            $f->push(ReadonlyField::create('CC'));
        }

        $iframeSrc = '/admin/emails/EmailTemplate/ViewSentEmail/?id=' . $this->ID;
        $iframe = new LiteralField('iframe', '<iframe src="' . $iframeSrc . '" style="width:800px;background:#fff;border:1px solid #ccc;min-height:500px;vertical-align:top"></iframe>');
        $f->push($iframe);

        $f->push(ReadonlyField::create('Results'));

        $this->extend('updateCMSFields', $f);

        return $f;
    }

    /**
     * Gets the {@link BetterEmail} object that was used to send this email
     * @return Email
     */
    public function getEmail()
    {
        $email = Email::create();

        $email->setTo($this->To);
        $email->setCc($this->CC);
        $email->setBCC($this->BCC);
        $email->setSubject($this->Subject);
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
     * Defines the view permission
     * @param  Member $member
     * @return boolean
     */
    public function canView($member = null)
    {
        return Permission::check('CMS_ACCESS_CMSMain');
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
    public function canCreate($member = null)
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
        return Permission::check('CMS_ACCESS_CMSMain');
    }
}
