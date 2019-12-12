<?php

namespace LeKoala\EmailTemplates\Helpers;

/**
 * Useful tools for emails
 */
class EmailUtils
{
    /**
     * Convert an html email to a text email while keeping formatting and links
     *
     * @param string $content
     * @return string
     */
    public static function convert_html_to_text($content)
    {
        // Prevent double title
        $content = preg_replace('/<title>([\s\S]*)<\/title>/i', '', $content);
        // Prevent styles to be included
        $content = preg_replace('/<style.*>([\s\S]*)<\/style>/i', '', $content);
        // Convert html entities to strip them later on
        $content = html_entity_decode($content);
        // Bold
        $content = str_ireplace(['<strong>', '</strong>', '<b>', '</b>'], "*", $content);
        // Replace links to keep them accessible
        $content = preg_replace('/<a[\s\S]href="(.*?)"[\s\S]*?>(.*?)<\/a>/i', '$2 ($1)', $content);
        // Replace new lines
        $content = str_replace(['<br>', '<br/>', '<br />'], "\r\n", $content);
        // Remove html tags
        $content = strip_tags($content);
        // Avoid lots of spaces
        $content = preg_replace('/^[\s][\s]+(\S)/m', "\n$1", $content);
        // Trim content so that it's nice
        $content = trim($content);
        return $content;
    }

    /**
     * Format an array of addresses into a string
     *
     * @param array $emails
     * @return string
     */
    public static function format_email_addresses($emails)
    {
        if (empty($emails)) {
            return '';
        }
        $arr = [];
        foreach ($emails as $address => $title) {
            $line = "$address";
            if ($title) {
                $line .= " <$title>";
            }
            $arr[] = $line;
        }
        return implode(', ', $arr);
    }

    /**
     * Match all words and whitespace, will be terminated by '<'
     *
     * Note: use /u to support utf8 strings
     *
     * @param string $rfc_email_string
     * @return string
     */
    public static function get_displayname_from_rfc_email($rfc_email_string)
    {
        $name = preg_match('/[\w\s-\.]+/u', $rfc_email_string, $matches);
        $matches[0] = trim($matches[0]);
        return $matches[0];
    }

    /**
     * Extract parts between brackets
     *
     * @param string $rfc_email_string
     * @return string
     */
    public static function get_email_from_rfc_email($rfc_email_string)
    {
        if (strpos($rfc_email_string, '<') === false) {
            return $rfc_email_string;
        }
        $mailAddress = preg_match('/(?:<)(.+)(?:>)$/', $rfc_email_string, $matches);
        if (empty($matches)) {
            return $rfc_email_string;
        }
        return $matches[1];
    }
}
