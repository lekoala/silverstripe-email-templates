<?php

namespace LeKoala\Mailgun\Test;

use LeKoala\EmailTemplates\Email\BetterEmail;
use LeKoala\EmailTemplates\Models\EmailTemplate;
use SilverStripe\Security\Member;
use LeKoala\Mailgun\MailgunHelper;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\Email\Mailer;
use Mailgun\Model\Domain\IndexResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Email\SwiftMailer;
use LeKoala\EmailTemplates\Tasks\EmailImportTask;

/**
 * Test for EmailTemplates
 *
 * @group EmailTemplates
 */
class EmailTemplatesTest extends SapphireTest
{
    protected static $fixture_file = 'EmailTemplatesTest.yml';

    protected function setUp()
    {
        parent::setUp();
    }

    protected function tearDown()
    {
        parent::tearDown();
    }

    /**
     * @return Member
     */
    public static function getTestMember()
    {
        return Member::get()->filter('Email', 'default@test.com')->first();
    }

    public function testCheckContentForErrors()
    {
        $withErrors = 'Some string here <% if $Test %>with a test<% end_if %>';

        $this->assertNotEmpty(EmailImportTask::checkContentForErrors($withErrors));

        $withoutErrors = 'This template <b>is fine</b>';
        $this->assertEmpty(EmailImportTask::checkContentForErrors($withoutErrors));
    }

    public function testDataIsApplied()
    {
        $tpl = EmailTemplate::getByCode('Default');

        $email = $tpl->getEmail();

        $this->assertEquals("Default Subject", $email->getSubject());
    }

    public function testMakeCode()
    {
        $path1 = 'SilverStripe\Control\Email\ChangePasswordEmail';
        $this->assertEquals('ChangePassword', BetterEmail::makeTemplateCode($path1));

        $path2 = 'MyEmail';
        $this->assertEquals('My', BetterEmail::makeTemplateCode($path2));
    }

    public function testHasDefaultTemplate()
    {
        $tpl = BetterEmail::config()->template;

        $em = new BetterEmail();
        $this->assertEquals($em->getHTMLTemplate(), $tpl);
    }

    public function testChangePasswordEmailIsChanged()
    {
        $member = self::getTestMember();

        $tpl = BetterEmail::config()->template;

        $email = Email::create();
        $this->assertInstanceOf(BetterEmail::class, $email);

        $changePasswordEmail = $email
            ->setHTMLTemplate('SilverStripe\\Control\\Email\\ChangePasswordEmail')
            ->setData($member)
            ->setTo($member->Email)
            ->setSubject("Your password has been changed");

        // Make sure we got the replacement provided by the system
        // ChangePasswordEmail does not have a common layout with our email templates otherwise
        $this->assertEquals($tpl, $email->getHTMLTemplate());

        // Make sure subject is not changed by further stuff
        $this->assertEquals("Hey, your password has been changed", $changePasswordEmail->getSubject());
    }
}
