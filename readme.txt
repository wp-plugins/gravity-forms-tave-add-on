=== Gravity Forms Táve add-on ===
Contributors: rowellr
Tags: Gravity Forms, Táve
Requires at least: 3.1
Tested up to: 3.9
Stable tag: 2014.04.18
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html


Connects your WordPress web site to your Táve account for collecting leads using the power of Gravity Forms.

== Description ==
Simple add on for Gravity Forms that will take the form input and put it into Táve.

You will need your Táve Studio ID and Secret Key for the settings, and you will need to have already installed [Gravit Forms](http://www.gravityforms.com) for this add-on to work.

Make a form through Gravity Forms that contains the required fields you want, but they must include a field for "FirstName", "LastName", "Email", "JobType". Those four fields are the required fields for your form to work with Táve.

You will then map your fields from Táve to the fields in Gravity Forms.



A special thank you to pussycatdev for providing the inspiration of this plugin, and to the other developers that have contributed code before I got my hands into this.

== Installation ==
1. **make sure you have Gravity Forms installed first.**
1. Upload the extracted archive to `wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Open the plugin settings page Forms -> Settings -> Táve or the settings link on the Plugins page.
4. Add your Tave Secret Key and Studio ID.
5. Add a "feed" to map the Tave fields to a pre-existing form from the Forms -> Táve menu link.
4. Start generating leads!

== Frequently Asked Questions ==
= I have more than one brand set up in Tave. Will this let me use both? =
Yes, simply make sure you add a hidden field that you map to the brand in tave. The hidden field value must either be a Brand ID (seen in the brand editor URL) or the exact name of a brand.

= Does this work on a multisite installation of WordPress? =
This plugin has *not* been tested on a multi-site installation, so I can't tell you. Let me know if you find out!

= I can't find the settings page so I can enter my API Key and Brand Abbreviation. Where is it? =
You have mostly likely either not installed Gravity Forms, have installed Gravity Forms after the Tave add-on instead of before, or - which has happened, trust me - you have installed Contact Form 7 instead of Gravity Forms. If you've done it correctly, it will be an item in the Gravity Forms admin menu (Forms -> Settings in the WordPress admin menu, then click the Tave link under the heading), or you can find it on the Plugins page in front of the Deactivate and Edit links for the plugin.

= Where can I get more help on getting this thing started? =
Visit my [Usage & Installation Instructions](http://www.rowellphoto.com/gravity-forms-tave/) page for plenty of assistance on getting everything configured to start collecting leads for Táve.

== Screenshots ==

1. These are the two required plugins.
2. This is where your Studio ID and Secret Key are to be entered.
3. What it should look like when you have filled in your details.
4. Mapping your fields from the form to Táve.

== Changelog ==
= 2014.04.18 =
* First release of the plugin.

== Upgrade Notice ==
= 2014.04.18 =
* First release of the plugin.