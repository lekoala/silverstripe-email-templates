<?php

namespace LeKoala\EmailTemplates\Tasks;

use Exception;
use DOMElement;
use DOMDocument;
use SilverStripe\ORM\DB;
use SilverStripe\i18n\i18n;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;
use TractorCow\Fluent\Model\Locale;
use SilverStripe\Core\Manifest\ModuleLoader;
use LeKoala\EmailTemplates\Helpers\FluentHelper;
use LeKoala\EmailTemplates\Models\EmailTemplate;
use TractorCow\Fluent\Extension\FluentExtension;
use LeKoala\EmailTemplates\Helpers\SubsiteHelper;
use SilverStripe\Core\Config\Config;
use SilverStripe\i18n\TextCollection\i18nTextCollector;

/**
 * Import email templates provided from ss files
 *
 * \vendor\silverstripe\framework\templates\SilverStripe\Control\Email
 * \vendor\silverstripe\framework\templates\SilverStripe\Control\Email\ForgotPasswordEmail.ss
 *
 * \app\templates\Email\MySampleEmail.ss
 *
 * Finds all *Email.ss templates and imports them into the CMS
 * @author lekoala
 */
class EmailImportTask extends BuildTask
{
    private static $segment = 'EmailImportTask';

    protected $title = "Email import task";
    protected $description = "Finds all *Email.ss templates and imports them into the CMS, if they don't already exist.";

    public function run($request)
    {
        $subsiteSupport = SubsiteHelper::usesSubsite();
        $fluentSupport = FluentHelper::usesFluent();

        echo 'Run with ?clear=1 to clear empty database before running the task<br/>';
        echo 'Run with ?overwrite=soft|hard to overwrite templates that exists in the cms. Soft will replace template if not modified by the user, hard will replace template even if modified by user.<br/>';
        echo 'Run with ?templates=xxx,yyy to specify which template should be imported<br/>';
        if ($subsiteSupport) {
            echo 'Run with ?subsite=all|subsiteID to create email templates in all subsites (including main site) or only in the chosen subsite (if a subsite is active, it will be used by default).<br/>';
        }
        if ($fluentSupport) {
            echo 'Run with ?locales=fr,en to choose which locale to import.<br/>';
        }
        echo '<strong>Remember to flush the templates/translations if needed</strong><br/>';
        echo '<hr/>';

        $overwrite = $request->getVar('overwrite');
        $clear = $request->getVar('clear');
        $templatesToImport = $request->getVar('templates');
        $importToSubsite = $request->getVar('subsite');
        $chosenLocales = $request->getVar('locales');

        // Normalize argument
        if ($overwrite && $overwrite != 'soft' && $overwrite != 'hard') {
            $overwrite = 'soft';
        }

        // Select which subsite to import emails to
        $importToSubsite = array();
        if ($subsiteSupport) {
            $subsites = array();
            if ($importToSubsite == 'all') {
                $subsites = SubsiteHelper::listSubsites();
            } elseif (is_numeric($importToSubsite)) {
                $subsites = SubsiteHelper::listSubsites();
                $subsiteTitle = 'Subsite #' . $importToSubsite;
                foreach ($subsites as $subsite) {
                    if ($subsite->ID == $importToSubsite) {
                        $subsiteTitle = $subsite->Title;
                    }
                }
                $subsites = array(
                    $importToSubsite => $subsiteTitle
                );
            }
            if ($subsiteSupport && SubsiteHelper::currentSubsiteID()) {
                DB::alteration_message("Importing to current subsite. Run from main site to import other subsites at once.", "created");
                $subsites = array();
            }
            if (!empty($subsites)) {
                DB::alteration_message("Importing to subsites : " . implode(',', array_values($subsites)), "created");
            }
        }

        if ($templatesToImport) {
            $templatesToImport = explode(',', $templatesToImport);
        }

        // Do we clear our templates?
        if ($clear == 1) {
            DB::alteration_message("Clear all email templates", "created");
            $emailTemplates = EmailTemplate::get();
            foreach ($emailTemplates as $emailTemplate) {
                $emailTemplate->delete();
            }
        }

        $emailTemplateSingl = singleton(EmailTemplate::class);

        $locales = null;
        if ($fluentSupport) {
            if (FluentHelper::isClassTranslated(EmailTemplate::class)) {
                $locales = Locale::get()->column('Locale');

                // We collect only one locale, restrict the list
                if ($chosenLocales) {
                    $arr = explode(',', $chosenLocales);
                    $locales = array();
                    foreach ($arr as $a) {
                        $a = FluentHelper::get_locale_from_lang($a);
                        $locales[] = $a;
                    }
                }
            }
        }

        $defaultLocale = FluentHelper::get_locale();

        $templates = $this->collectTemplates();

        // don't throw errors
        Config::modify()->set(i18n::class, 'missing_default_warning', false);

        foreach ($templates as $filePath) {
            $isOverwritten = false;

            $fileName = basename($filePath, '.ss');

            // Remove base path
            $relativeFilePath = str_replace(Director::baseFolder(), '', $filePath);
            $relativeFilePathParts = explode('/', trim($relativeFilePath, '/'));

            // Group by module
            $moduleName = array_shift($relativeFilePathParts);
            if ($moduleName == 'vendor') {
                $moduleVendor = array_shift($relativeFilePathParts);
                // get module name
                $moduleName = $moduleVendor . '/' . array_shift($relativeFilePathParts);
            }

            // remove /templates part
            array_shift($relativeFilePathParts);
            $templateName = str_replace('.ss', '', implode('/', $relativeFilePathParts));

            $templateTitle = basename($templateName);

            // Create the email code (basically, the template name without "Email" at the end)
            $code = preg_replace('/Email$/', '', $fileName);

            if (!empty($templatesToImport) && !in_array($code, $templatesToImport)) {
                DB::alteration_message("Template with code <b>$code</b> was ignored.", "repaired");
                continue;
            }

            $whereCode = array(
                'Code' => $code
            );
            $emailTemplate = EmailTemplate::get()->filter($whereCode)->first();

            // Check if it has been modified or not
            $templateModified = false;
            if ($emailTemplate) {
                $templateModified = $emailTemplate->Created != $emailTemplate->LastEdited;
            }

            if (!$overwrite && $emailTemplate) {
                DB::alteration_message("Template with code <b>$code</b> already exists. Choose overwrite if you want to import again.", "repaired");
                continue;
            }
            if ($overwrite == 'soft' && $templateModified) {
                DB::alteration_message("Template with code <b>$code</b> has been modified by the user. Choose overwrite=hard to change.", "repaired");
                continue;
            }

            // Create a default title from code
            $title = preg_split('/(?=[A-Z])/', $code);
            $title = implode(' ', $title);

            // Get content of the email
            $content = file_get_contents($filePath);

            // Analyze content to find incompatibilities
            $errors = self::checkContentForErrors($content);
            if (!empty($errors)) {
                echo "<div style='color:red'>Invalid syntax was found in '$relativeFilePath'. Please fix these errors before importing the template<ul>";
                foreach ($errors as $error) {
                    echo '<li>' . $error . '</li>';
                }
                echo '</ul></div>';
                continue;
            }

            // Parse language
            $module = ModuleLoader::getModule($moduleName);
            $collector = new i18nTextCollector;
            $entities = $collector->collectFromTemplate($content, $fileName, $module);

            /*
            array:1 [▼
            "MyEmail.SUBJECT" => "My subject"
            ]
            */

            $translationTable = array();
            foreach ($entities as $entity => $data) {
                if ($locales) {
                    foreach ($locales as $locale) {
                        i18n::set_locale($locale);
                        if (!isset($translationTable[$entity])) {
                            $translationTable[$entity] = array();
                        }
                        $translationTable[$entity][$locale] = i18n::_t($entity, $data);
                    }
                    i18n::set_locale($defaultLocale);
                } else {
                    $translationTable[$entity] = array($defaultLocale => i18n::_t($entity, $data));
                }
            }

            $contentLocale = array();
            // May be null
            if ($locales) {
                foreach ($locales as $locale) {
                    $contentLocale[$locale] = $content;
                }
            }
            if (!isset($contentLocale[$defaultLocale])) {
                $contentLocale[$defaultLocale] = $content;
            }

            // Now we use our translation table to manually replace _t calls into file content
            foreach ($translationTable as $entity => $translationData) {
                $escapedEntity = str_replace('.', '\.', $entity);
                $baseTranslation = null;

                foreach ($translationData as $locale => $translation) {
                    if (!$baseTranslation && $translation) {
                        $baseTranslation = $translation;
                    }
                    if (!$translation) {
                        $translation = $baseTranslation;
                    }
                    // This regex should match old and new style
                    $count = 0;
                    $contentLocale[$locale] = preg_replace("/<%(t | _t\(')" . $escapedEntity . "( |').*?%>/ums", $translation, $contentLocale[$locale], -1, $count);
                    if (!$count) {
                        throw new Exception("Failed to replace $escapedEntity with translation $translation");
                    }
                }
            }

            // Create a template if necassery or mark as overwritten
            if (!$emailTemplate) {
                $emailTemplate = new EmailTemplate;
            } else {
                $isOverwritten = true;
            }

            // Other properties
            $emailTemplate->Code = $code;
            $emailTemplate->Category = $moduleName;
            if (SubsiteHelper::currentSubsiteID() && !$emailTemplate->SubsiteID) {
                $emailTemplate->SubsiteID = SubsiteHelper::currentSubsiteID();
            }
            // Write to main site or current subsite
            $emailTemplate->write();

            // Apply content to email after write to ensure we can localize properly
            $this->assignContent($emailTemplate, $contentLocale[$defaultLocale]);

            if (!empty($locales)) {
                foreach ($locales as $locale) {
                    $this->assignContent($emailTemplate, $contentLocale[$locale], $locale);
                }
            }

            // Reset date to allow tracking user edition (for soft/hard overwrite)
            $this->resetLastEditedDate($emailTemplate->ID);

            // Loop through subsites
            if (!empty($importToSubsite)) {
                SubsiteHelper::disableFilter();
                foreach ($subsites as $subsiteID => $subsiteTitle) {
                    $whereCode['SubsiteID'] = $subsiteID;

                    $subsiteEmailTemplate = EmailTemplate::get()->filter($whereCode)->first();

                    $emailTemplateCopy = $emailTemplate;
                    $emailTemplateCopy->SubsiteID = $subsiteID;
                    if ($subsiteEmailTemplate) {
                        $emailTemplateCopy->ID = $subsiteEmailTemplate->ID;
                    } else {
                        $emailTemplateCopy->ID = 0; // New
                    }
                    $emailTemplateCopy->write();

                    $this->resetLastEditedDate($emailTemplateCopy->ID);
                }
            }

            if ($isOverwritten) {
                DB::alteration_message("Overwrote <b>{$emailTemplate->Code}</b>", "created");
            } else {
                DB::alteration_message("Imported <b>{$emailTemplate->Code}</b>", "created");
            }
        }
    }

    public static function checkContentForErrors($content)
    {
        $errors = array();
        if (strpos($content, '<% with') !== false) {
            $errors[] = 'Replace "with" blocks by plain calls to the variable';
        }
        if (strpos($content, '<% if') !== false) {
            $errors[] = 'If/else logic is not supported. Please create one template by use case or abstract logic into the model';
        }
        if (strpos($content, '<% loop') !== false) {
            $errors[] = 'Loops are not supported. Please create a helper method on the model to render the loop';
        }
        if (strpos($content, '<% sprintf') !== false) {
            $errors[] = 'You should not use sprintf to escape content, please use plain _t calls';
        }
        return $errors;
    }

    /**
     * Collect email from your project
     *
     * @return array
     */
    protected function collectTemplates()
    {
        $templates = glob(Director::baseFolder() . '/' . project() . '/templates/Email/*Email.ss');

        $framework = self::config()->import_framework;
        if ($framework) {
            $templates = array_merge($templates, glob(Director::baseFolder() . '/vendor/silverstripe/framework/templates/SilverStripe/Control/Email/*Email.ss'));
        }

        $extra = self::config()->extra_paths;
        foreach ($extra as $path) {
            $path = trim($path, '/');
            $templates = array_merge($templates, glob(Director::baseFolder() . '/' . $path . '/*Email.ss'));
        }

        return $templates;
    }

    /**
     * Utility function to reset email templates last edited date
     *
     * @param int $ID
     * @return void
     */
    protected function resetLastEditedDate($ID)
    {
        DB::query("UPDATE `EmailTemplate` SET LastEdited = Created WHERE ID = " . $ID);
    }

    /**
     * Update a template with content
     *
     * @param EmailTemplate $emailTemplate
     * @param string $content The full page content with html
     * @param string $locale
     * @return void
     */
    protected function assignContent(EmailTemplate $emailTemplate, $content, $locale = '')
    {
        FluentHelper::withLocale($locale, function () use ($emailTemplate, $content) {
            // First assign the whole string to Content in case it's not split by zones
            $cleanContent = $this->cleanContent($content);
            $emailTemplate->Content = '';
            $emailTemplate->Content = $cleanContent;

            $dom = new DOMDocument;
            $dom->loadHTML(mb_convert_encoding('<div>' . $content . '</div>', 'HTML-ENTITIES', 'UTF-8'));

            // Look for nodes to assign to proper fields (will overwrite content)
            $fields = array('Content', 'Callout', 'Subject');
            foreach ($fields as $field) {
                $node = $dom->getElementById($field);
                if ($node) {
                    $cleanContent = $this->cleanContent($this->getInnerHtml($node));
                    $emailTemplate->$field = '';
                    $emailTemplate->$field = $cleanContent;
                }
            }

            // Write each time within the given state
            $emailTemplate->write();
        });
    }

    /**
     * Get a clean string
     *
     * @param string $content
     * @return string
     */
    protected function cleanContent($content)
    {
        $content = strip_tags($content, '<p><br><br/><div><img><a><span><ul><li><strong><em><b><i><blockquote><h1><h2><h3><h4><h5><h6>');
        $content = str_replace("’", "'", $content);
        $content = trim($content);
        $content = nl2br($content);
        return $content;
    }

    /**
     * Loop over a node to extract all html
     *
     * @param DOMElement $node
     * @return string
     */
    protected function getInnerHtml(DOMElement $node)
    {
        $innerHTML = '';
        $children = $node->childNodes;
        foreach ($children as $child) {
            $innerHTML .= $child->ownerDocument->saveXML($child);
        }
        return $innerHTML;
    }
}
