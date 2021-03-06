=== Dashboard Site Notes ===
Contributors: BenIrvin
Donate link: http://innerdvations.com/
Tags: dashboard, notes, messages, admin, instruction, manual
Requires at least: 3.0
Tested up to: 3.3.1
Stable tag: 1.3.2

Add notes as admin notices in contextual help tabs, as well as compile them into an instruction manual.

== Description ==

Add notes as admin notices in contextual help tabs, as well as compile them into an instruction manual or placed in a dashboard widget.

This is intended to build instructions into a site for clients, so it is focused on providing abilities only to the highest role of user available on a site, although it can be configured as a general purpose tool to leave temporary notes to any group on your site that has admin access.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to Settings->Site Notes and configure the plugin as needed
1. Start adding site notes.  Choose at least one role and location, or your note won't appear.

== Frequently Asked Questions ==

= Can I put notes on the public part of the site, or in a shortcode? =
I recommend using http://wordpress.org/extend/plugins/wp-announcements/ for public notices.
However, a shortcode is available for notes that are explicitly set to allow it. The shortcode can then used like so: [sitenote id='123']
If you only want to show one note, and none of its child notes, set the depth parameter to 0, for example: [sitenote id='123' depth='0']
You can also change 'sitenote' to something else by adding the following line to your functions.php:
define('DSN_SHORTCODE','your_shortcode_name_here');

= I installed the plugin but I don't see anything! =
If you use multisite, only super-admins can create notes. To also allow normal administrators to manage them, add this to your functions.php:
define('DSN_ADMIN_CONFIG',true);
If you aren't using multisite but still don't see site notes, they might be disabled. Try Settings->Site Notes and make sure the box next to Administrator is checked.  Also, make sure that
note management hasn't been disabled (see below).

= I've finished adding notes and now I want to hide the management of Site Notes. =
Go to Settings->Site Notes and uncheck the box next to Administrator and they will no longer be editable.  To re-enable them, just come back to this page and turn it back on.
You can also add define('DSN_DISABLE_CHANGES',true); to your functions.php to disable all changes completely, but note that if you do that, NOBODY will be able to make any changes whatsoever until you remove that line.
At some point in a future version, it may be possible to choose a specific user who is able to maintain the notes while hiding it from others.

= Why isn't one of my notes showing up in the dashboard/instructions? =
If the note has a parent note, that parent must also be visible or else your note won't show up on the dashboard or in the instruction manual. Roles must also be selected; if you don't choose any roles, your note will not show up for anyone. Finally, make sure that you've checked the checkbox for appearing on the dashboard.

= Why is one of my notes showing up with empty text? =
Make sure that the excerpt field for your note is empty or contains the content you want to include.  If it's still not showing up, trying disabling excerpt, excerpt filters, and/or content filters on the settings page.

= What is planned for future versions? =
Here are features planned for future versions (probably released about every 2-3 months):
* 1.5: add warnings if a note is created that can't be seen anywhere (no role or location selected)
* 1.5: add ability to show site note management pages for only specific user(s) instead of just by role
* 1.5: add ability to put notes in the admin bar
* 1.5: note featured image support
* 1.5: refine capabilities management
* 1.5: add option for displaying an 'edit' link next to each note for users with appropriate permissions
* 1.5: add option for eliminating default wordpress contextual help; might be impossible right now - see wp-admin/includes/screen.php starting at 680.  Maybe at least allow rewriting of the existing text?

= Features that will not be implemented: =
* expirations/time ranges - use dashboard site notes with: http://wordpress.org/extend/plugins/automatic-page-publish-expire/
* make notes outside of admin - use shortcode or use: http://wordpress.org/extend/plugins/wp-announcements/
* note importing/exporting - notes are stored as posts and postmeta fields, use one of the many plugins that allows importing/exporting of posts.

= Known issues? =
* Very little testing has been done in the last few versions with multisite or child/parent notes, so if you're using those, it's possible you could see some strangeness.
* translations are out of date
* content filters aren't applied to notes displayed on attachment pages and probably won't ever be unless people complain.
* Version 1.3.0 had a major flaw that caused increasingly massive slowdowns over time.  If you ever had that version installed (ie, if you updated between 12 March 2012 to 16 March 2012), your site may be running slower than it should, even if you've updated that version to a newer one.  See http://wordpress.org/support/topic/plugin-dashboard-site-notes-slowed-my-site-to-a-crawl?replies=12#post-2690354 for solutions to bring your site back up to full speed.

= What filters are available? =
* dsn_options (array of options after retrieving it)
* dsn_save_options  (array of options to be saved from options page submission)
* dsn_has_admin (boolean for if current user can administrate dashboard site notes)
* dsn_get_notes (array of notes retrieved after querying for current page notes)
* dsn_admin_notice_content (string of single admin notice. second argument available is post)
* dsn_admin_notice_html (string of single admin notice including html. second argument available is post)
* dsn_admin_notices (string of entire admin notice including html)
* dsn_sanitize_content (string of content about to be sanitized (sanitized at priority 99))
* dsn_content (string of content [after sanitization if necessary])
* dsn_sanitize_title (string of note title about to be sanitized (sanitized at priority 99))
* dsn_title (string of title [after sanitization if necessary])
* dsn_contextual_help_title (string of contextual help title [after sanitization if necessary])
* dsn_widget_content (string of widget content text)
* dsn_contextual_help_content (string of contextual help content text)
* dsn_contextual_help_notice (string of contextual help content including html)
* dsn_shortcode_notice (string of note in shortcode including html)
* dsn_author_name (string of author name (the "- " is prepended at priority 99))


== Screenshots ==
1. Creating a site note in v1.2
1. The dashboard widget that appears after notes are created there.
1. A note as it appears on the list/search page.
1. Generated instruction manual from notes
1. The options page in v1.2

== Changelog ==
= 1.4.0 = 
* feature: can add notes to pretty much any admin location now
* feature: author names can be added to notes
* feature: note options 'hide title in widget', and 'hide title in shortcode'
* feature: added a bunch of hooks and filters (see FAQ) so developers can interact with plugin
* feature: notes are now sanitized so they're hopefully safe for non-admins to use
* bugfix: meta fields no longer cleared after a quick edit or bulk edit
* bugfix: clean up the database mess made in 1.3.0

= 1.3.2 = 
* bugfix: fixed plugin upgrade script that caused site to grind to a halt

= 1.3 =
* feature: ability to add notes to the contextual help tab
* feature: shortcode 'sitenote' or can be renamed with define('DSN_SHORTCODE','yourshortcode');
* feature: 'attachment' type now works on WP Media pages
* feature: 'revision' type now works on WP revision page
* feature: restructured so that plugin memory/cpu footprint on non-admin pages now extremely tiny
* change: 'exclude from instructions' has been replaced with a new instruction manual location. existing notes updated on upgrade.
* change: since contextual help tab is now a possible place to put notes, 'admin notice' has been added as a location instead of being assumed by default. existing notes updated on upgrade.
* change: reorganized the add/edit site notes layout again since concepts keep changing. Sorry!
* change: added some styles to attempt to standardize margin/padding notes when mixing plain text and html notes wrapped with paragraph tags
* bugfix: notes now correctly display on basic 'post' content type
* bugfix: on plugin upgrade, any useless meta fields that were accidentally created in some older versions are deleted

= 1.2 = 
* added Italian (Italiano) and Dutch (Nederlands) translations
* fixed some localization bugs
* added jquery dependency to enqueue_script and moved it to admin_enqueue_scripts

= 1.1 =
* added constant for allowing normal admins to edit config on multisite
* added constant for disabling all changes by anyone and hiding all our admin pages
* css changes to make everything prettier and hopefully more usable
* added config option for choosing which roles can create notes
* added note option for hiding the title
* added config option for enabling application of content filters on notes
* added config option for enabling use of excerpts
* added config option for title of dashboard widget and instruction manual
* added config option for grouping notes
* added options page
* notes now sorted by 'menu_order' ascending, then by 'post_title' ascending

= 1.0.1 =
* fixed bug that was adding extra meta fields to wrong content types

= 1.0 =
* added instruction manual

= 0.9.2.1 =
* fixed fatal error bug and display bug
* fixed incorrect screenshots

= 0.9.2 =
* added translation support and English .pot file
* added donate links
* hierarchy now takes effect on notes on dashboard
* if excerpt is available, use that in notices/dashboard, with full text appearing only in manual
* major optimization by changing note querying method and removing other stupidity
* stored 'everywhere' checkboxes server-side so they work without javascript enabled
* added GPLv3 license document
* improved auto ("everywhere") checkbox functionality

= 0.9.1 =
* notes can be set to display only for certain user roles

= 0.9 =
* added post type notes by action
* added dashboard notes

== Upgrade Notice ==
= 1.1 =
Added a bunch of configuration options, permissions options, a bit of css, and some minor bug fixes.
MULTISITE USERS: this will add permission for normal admins (not just super-admins) to create site notes unless you go to Site Notes Config and disable the administrator permission.

== Other Notes ==
= Acknowledgements =
Thanks to Peter Luit for the Dutch translation and beta testing.