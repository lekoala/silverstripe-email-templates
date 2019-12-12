<?php

namespace LeKoala\EmailTemplates\Test;

use SilverStripe\Dev\SapphireTest;
use LeKoala\EmailTemplates\Helpers\EmailUtils;

/**
 * Test for EmailUtils
 *
 * @group EmailTemplates
 */
class EmailUtilsTest extends SapphireTest
{
    public function testDisplayName()
    {
        $arr = [
            // Standard emails
            "me@test.com" => "me",
            "mobius@test.com" => "mobius",
            "test_with-chars.in.it@test-ds.com.xyz" => "test_with-chars.in.it",
            // Rfc emails
            "Me <me@test.com>" => "Me",
            "Möbius <mobius@test.com>" => "Möbius",
            "John Smith <test_with-chars.in.it@test-ds.com.xyz>" => "John Smith",

        ];

        foreach ($arr as $k => $v) {
            $displayName = EmailUtils::get_displayname_from_rfc_email($k);
            $this->assertEquals($v, $displayName);
        }
    }

    public function testGetEmail()
    {
        $arr = [
            // Standard emails
            "me@test.com" => "me@test.com",
            "mobius@test.com" => "mobius@test.com",
            "test_with-chars.in.it@test-ds.com.xyz" => "test_with-chars.in.it@test-ds.com.xyz",
            // Rfc emails
            "Me <me@test.com>" => "me@test.com",
            "Möbius <mobius@test.com>" => "mobius@test.com",
            "John Smith <test_with-chars.in.it@test-ds.com.xyz>" => "test_with-chars.in.it@test-ds.com.xyz",

        ];

        foreach ($arr as $k => $v) {
            $email = EmailUtils::get_email_from_rfc_email($k);
            $this->assertEquals($v, $email);
        }
    }

    public function testConvertHtmlToText()
    {
        $someHtml = '   Some<br/>Text <a href="http://test.com">Link</a> <strong>End</strong>    ';

        $textResult = "Some\r\nText Link (http://test.com) *End*";

        $process = EmailUtils::convert_html_to_text($someHtml);

        $this->assertEquals($textResult, $process);
    }

    public function testConvertEmails()
    {
        $addresses = [
            'test@dom.com' => "Test man"
        ];

        $result = EmailUtils::format_email_addresses($addresses);
        $this->assertEquals("test@dom.com <Test man>", $result);

        $addresses2 = [
            'test@dom.com' => "Test man",
            'test2@dom.com' => "Test man 2"
        ];

        $result = EmailUtils::format_email_addresses($addresses2);
        $this->assertEquals("test@dom.com <Test man>, test2@dom.com <Test man 2>", $result);
    }
}
