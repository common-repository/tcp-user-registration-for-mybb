=== myBB Integration by TheCartPress ===
Contributors:      tcpteam
Plugin Name:       TCP auto register/login user to myBB when user register/login in wordpress
Plugin URI:        https://www.thecartpress.com
Tags:              tcp, mybb, forum, register, users, auto, user, wordpress, auto login, auto register
Author URI:        https://www.thecartpress.com
Author:            TCP Team
Requires PHP:      5.6
Requires at least: 5.5
Tested up to:      5.8.4
Stable tag:        1.3.0
Version:           1.3.0
License:           GPLv3
License URI:       https://www.gnu.org/licenses/gpl-3.0.html

== Description ==

This plugin is used to register myBB forum user whenever user registered in wordpress. It will also auto login to mybb after user login/register in wordpress.
It will convenient the user to avoid them to register/login twice in wordpress and mybb.

KEY FEATURES

- [FREE] Auto register user in myBB when user registered in wordpress.
- [FREE] Sync first 20 users from wordpress to myBB.
- [PREMIUM] Auto sync all users from wordpress to myBB.
- [PREMIUM] Sync 100 users (include sending email to user) per hour. Cron until all users are added to myBB.

= Plugin doesn't fit your requirement? =

Find out more plugins in [TheCartPress](https://www.thecartpress.com/) or

We have added a welcome page to display all plugins from [TheCartPress](https://www.thecartpress.com/) inside the plugin menu. You can easily preview and choose the plugin that might fit your requirement inside the admin page. All plugins information displayed inside the menu are getting from TheCartPress server.

== Installation ==

Unzip and Upload Folder to the /wp-content/plugins/ directory.
Activate through WordPress plugin dashboard page.

Save Changes.
== Upgrade Notice ==

== Changelog ==

= 1.3.0 =
* Add plugin link to TheCartPress sidebar menu
* Use tcp.php

= 1.2.0 =
* implement custom email subject and content in settings page that will send to users after sync to mybb

= 1.1.0 =
* show failed sync users in log tab

= 1.0.0 =
* First release

== Screenshots ==

== Frequently Asked Questions ==

=Why user not registered to myBB forum=
There are many condition that will lead to this problem.
- There are users that have similar username or email in myBB compare to Wordpress users.
- Mybb db table is in different database with wordpress.
