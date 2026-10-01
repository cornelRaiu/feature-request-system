=== Feature Request System ===
Contributors: cornel.raiu
Tags: feature requests, feedback, roadmap, voting, ideas
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Collect feature requests, let users vote, share your roadmap, and tell every voter when you ship.

== Description ==

Feature Request System adds a feature request board to your WordPress site. Users submit ideas and vote for the ones they want. You give each request a status, such as Planned or In Progress, and everyone who voted for a request hears about it when it ships.

This plugin is in development. The first public release will be 1.0.0.

= Adding the board =

Add the Feature Request Board block to any page, or use the shortcode:

`[feature_requests board="board-slug"]`

Leave out the board to show the default board. Visitors can search, filter by status and category, and sort by votes or date. Voting works without reloading the page, and still works when JavaScript is off.

= Collecting requests =

Add the Feature Request Form block where people should suggest features, or use the shortcode:

`[feature_request_form board="board-slug"]`

While someone types a title, the form lists existing requests with similar titles, so they can vote for one instead of posting a duplicate. The form also works when JavaScript is off.

For a complete page, insert the Feature request page pattern. It has the form, then the board.

By default, only logged-in users can suggest features, and new requests wait for your approval under Feature Requests, marked Pending. Requests from editors and administrators are published straight away.

Each new request is emailed to the site admin email address, with a link to review it.

= Request pages and official responses =

Every request has its own page with its vote button, status, categories, description and comments. Comments are WordPress comments, so your discussion settings, comment moderation and your theme's comment design apply.

To answer a request on behalf of your team, write a comment on its page and tick "Post as the official response" before sending it. You can also mark an existing comment from the Comments screen. The newest official response is pinned below the request's description, and each one is labelled in the comments. Editors and administrators can do this, for comments written by editors or administrators.

In block themes, request pages use the Single Feature Request template, which you can change in the Site Editor. It needs WordPress 6.7 or later; on WordPress 6.6 the theme's own template for posts is used.

= Spam protection =

The form stops most spam without a captcha:

* A hidden field that people never see and bots tend to fill in.
* Forms sent within 3 seconds of loading the page are refused, as bots send them.
* Each visitor can send 5 requests an hour, and each IP address 10.
* If the Akismet plugin is active and connected, it checks every new request. Requests it flags wait for your review instead of being deleted.

Developers can add a captcha through the `mwtfrs_verify_captcha` filter, or another spam service through the `mwtfrs_is_spam` filter.

= Themes =

The board, the form and the request page take their colours and spacing from your theme. To change their markup, copy a file from the plugin's `templates` folder to a `feature-request-system` folder in your theme: `board.php`, `submit-form.php`, `vote-button.php`, `request.php` or `official-response.php`.

= Privacy =

Everything is stored in your own WordPress database.

People who suggest a feature are not stored as post authors, so their names never appear in author archives or other public data. The name and email a visitor gives are stored privately with their request and shown only to moderators. The plugin stores no IP addresses; rate limits use short-lived, hashed counters.

= External services =

When the Akismet plugin is active and connected to an Akismet account, this plugin sends each new feature request to Akismet to check whether it is spam. It sends the request's title and details, the name and email a visitor gave, and their IP address, browser user agent and referring page. Nothing is sent when Akismet is not active. Akismet is a service by Automattic: [terms of service](https://akismet.com/tos/), [privacy policy](https://akismet.com/privacy/).

== Frequently Asked Questions ==

= Does it work with my theme? =

The board and the form are built for any theme. Block themes and classic themes are both supported.

= Can visitors without an account suggest features? =

Not by default. Each board has a setting for who can suggest features: anyone, logged-in users, or nobody. Visitors without an account can leave their name and email, and those are never shown publicly.

== Changelog ==

= 0.1.0 =
* Development version. Not yet released.
