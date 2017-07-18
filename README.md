SilverStripe Email Templates module
==================

This module lets you have user editable email templates through the backoffice.

WARNING : some features are still being actively developped since this module has
been adapted from my mandrill module.

Features
==================

TODO

- Compatible with Fluent and Subsite

Predefined templates
==================

Even if it is really nice to let your users edit the emails being sent, you need
to provide an initial content to them. Creating all the emails manually is cumbersome, and
maybe you already have some existing templates that you want to use.

To help you in this task, you have an ImportEmailTask thats imports all *.ss templates in the /email folder that
end with Email in the name, like /email/myTestEmail.ss.
The content is imported in the "Content" area, except if you specify ids for specific zones, like <div id="SideBar">My side bar content</div>, like so:

    <div id="SideBar">
        My sidebar content
    </div>
    <div id="Content">
        My main content
    </div>

How to use this module
==================

Lets say we want to send an email on form submission, the Silverstripe guide on forms is [here](https://docs.silverstripe.org/en/3.1/developer_guides/forms/introduction/) if you are unsure about forms.

We want a user to input some data and then send an email notifying us that a form was submitted. After handeling our other form requirements like saving to the DB
etc we would then want to send the email.

```php
// Send an email using mandrill
// The recipient, cc and bcc emails can be arrays of email addresses to include.
// The 'Bounce' is the Silverstripe URL for handeling bounced emails
$email = new Email('from@outwebsite.com', 'recipient@email.com', 'Our Subject', 'The body of the email', 'BounceURL', 'AnyCCEmails@email.com', 'AnyBCCEmails@email.com');
// Here we can set a template to use. This could be a custom email template you design or one of the included templates.
$email->setTemplate('BoilerplateEmail');
$email->send();
```

The other option for setting a template for your email is to use the built in template builder. First you define the email template through the 'Emails' tab in the CMS. We can select a base template to use and then define the layout of the email body. We should make a note of the 'code' for the email template once we have created it.

Within the content area we have access to the currently logged in user, the site config options and the basic information passed through such as to, from, subject etc. This is really handy when your client might need to make small changes to the emails sent out.

To create an email using this process within out form we could use the following code.

```php
// Send an email using the templating engine
$email = EmailTemplate::getEmailByCode('template-code');
$email->setToMember('to@email.com');
$email->send();
```

User models
==================

This module expect a simple convention when referencing models inside your templates. Please use the name of the class as the variable.
For instance $Member will match an object of class Member.

You can inject values with whatever name (MyMembe => Member) but it won't be visible inside the admin because
the template doesn't which values are going to be injected.

Sent emails
==================

TODO

Finding a good template
==================

I highly recommend the following [open source email templates from sendwithus](https://www.sendwithus.com/resources/templates)

The one provided with this module is "Oxygen" but you can easily adapt the template
to your needs.

Not happy?
==================

You might be interessted by [Permamail from unclecheese](https://github.com/unclecheese/silverstripe-permamail)

Sending emails?
==================

Check my modules

- [Mandrill](https://github.com/lekoala/silverstripe-mandrill)
- [Sparkpost](https://github.com/lekoala/silverstripe-sparkpost)

Compatibility
==================
Tested with 3.6 and should be compatible from 3.2

Maintainer
==================
LeKoala - thomas@lekoala.be