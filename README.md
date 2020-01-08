SilverStripe Email Templates module
==================

[![Build Status](https://travis-ci.org/lekoala/silverstripe-email-templates.svg?branch=master)](https://travis-ci.org/lekoala/silverstripe-email-templates/)
[![scrutinizer](https://scrutinizer-ci.com/g/lekoala/silverstripe-email-templates/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/lekoala/silverstripe-email-templates/)
[![Code coverage](https://codecov.io/gh/lekoala/silverstripe-email-templates/branch/master/graph/badge.svg)](https://codecov.io/gh/lekoala/silverstripe-email-templates)

This module lets you have user editable email templates through the admin interface.

Features
==================

- Define app level email templates
- Predefine content in ss templates for ease of use
- Override framework or other module emails
- Store sent emails for tracking
- Compatible with Fluent module
- Compatible Subsite module (TODO)

Predefined templates
==================

Even if it is really nice to let your users edit the emails being sent, you need
to provide an initial content to them. Creating all the emails manually is cumbersome, and
maybe you already have some existing templates that you want to use.

To help you in this task, you have an ImportEmailTask thats imports all *.ss templates in the /email folder that
end with Email in the name, like /Email/MyTestEmail.ss.
The content is imported in the "Content" area. You can also define divs with ids matching the name of the field:

    <div id="Subject">
        <%t MyTestEmail.SUBJECT "My translated title" %>
    </div>
    <div id="Callout">
        <%t MyTestEmail.CALLOUT "My translated Callout" %>
    </div>
    <div id="Content">
        <%t MyTestEmail.CONTENT "My translated content" %>
    </div>

I recommend that you store the email title alongside the content of the email, it makes things much easier to follow.

Also keep in mind that your content is escaped by default. So in your template you might need to do this:

    Hello $Member.Firstname,

    Here are your infos:
    $Member.SummaryTable.RAW

This will allow to render html content provided by the Member::SummaryTable method.

Available config flags:
- import_framework: should we import base framework templates
- extra_paths: extra path to look for *Email.ss templates

How it works
==================

This module will inject a BetterEmail class that will be used instead of the default Email class provided by the framework.

We do that instead of a custom mailer in order to not interfere with any custom mailer you may have (like mailgun, sparkpost, etc).

This custom class is in charge of:
- recording sent emails
- overriding templates set by the system: setHTMLTemplate will match any email template matching it's code
- Provide extension hooks (onBeforeDoSend,onAfterDoSend)
- Provide member specific helpers (adjust email to user Locale, check if user want to receive transactionnal emails with canReceiveEmails)
- Improved plain text rendering

How to use this module
==================

This module assume that you want all your transactionnal emails to look the same. This is why there is a default template:

    LeKoala\EmailTemplates\Email\BetterEmail:
      template: 'DefaultEmailTemplate'

This template is used to render all emails. Because we use the custom BetterEmail class, all usages of Email::setHTMLTemplate are intercepted and
our email templates are used with the default template if available.

So this means that:

```php
$email = Email::create('from@outwebsite.com', 'recipient@email.com');
// Will look for a template with code Welcome
$email->setHTMLTemplate('Welcome');
$email->send();
```

Is the same as:

```php
$email = EmailTemplate::getEmailByCode('Welcome');
$email->setToMember(Member::currentUser());
$email->send();
```

This nice trick is required in order for you to have consistent "Change password" and "Forgot password" emails since these are provided by the framework
with no easy way to override them. It also means you can let the user add his own copy without editing translation files.

SiteConfig extension
==================

This module provides a SiteConfig extension to allow you to customize your emails. Most of the time, your emails have some kind of common footer and it would be a waste to define it in each template.

This is why we have the following fields:
- EmailFooter: the common footer
- DefaultFromEmail: the default email used to send emails
- ContactEmail: the default recipient for all emails

Please note that you can define per email sender and recipient (like order@mywebsite.com and messages@mywebsite.com).

The extension also provide some common methods to use in your email templates (like a custom logo for the footer, theme colors, etc.).

User models
==================

This module expect a simple convention when referencing models inside your templates. Please use the name of the class as the variable.
For instance $Member will match an object of class Member.

You can inject values with whatever name (MyMember => Member) but it won't be visible inside the admin because
the template doesn't which values are going to be injected.

Sent emails
==================

In the admin you can review sent emails

Sent emails table is periodically cleaned up. You can configure the following

    LeKoala\EmailTemplates\Models\SentEmail:
      max_records: 1000
      # possible values : 'time' or 'max'
      cleanup_method: 'max'
      cleanup_time: '-7 days'

Finding a good template
==================

I highly recommend the following [open source email templates from dyspatch](https://www.dyspatch.io/resources/templates/oxygen/)

The one provided with this module is "Oxygen" but you can easily adapt the template to your needs.

Sending emails?
==================

Check my modules

- [Mandrill](https://github.com/lekoala/silverstripe-mandrill)
- [Sparkpost](https://github.com/lekoala/silverstripe-sparkpost)
- [Mailgun](https://github.com/lekoala/silverstripe-mailgun)

Compatibility
==================
Tested with 4.4 but should work on any 4.x

For 3.x, use branch 1

Maintainer
==================
LeKoala - thomas@lekoala.be
