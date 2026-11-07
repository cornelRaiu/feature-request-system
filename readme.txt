=== Feature Request System – Feedback Board, Voting & Roadmap ===
Contributors: cornel.raiu
Tags: feature requests, feature request, customer feedback, user feedback, roadmap
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Collect feature requests, let people vote, show your roadmap, and email everyone who follows a request when its status changes.

== Description ==

Feature Request System adds a feature request board to your WordPress site. People suggest features and vote for the ones they want. You give each request a status, such as Planned, In Progress or Shipped, and everyone who voted for a request gets an email when its status changes.

* **A board** that visitors can search, filter and sort, with one-click voting. Filters apply as they change, without reloading the page.
* **A form** that shows similar requests while someone types, so they vote instead of posting a duplicate. You choose its texts.
* **A roadmap** with a column for each status, such as Planned, In Progress and Shipped.
* **A page for every request**, with comments and your team's official response.
* **A short list and a card**, to show the most wanted requests on your home page and one request in a post, each with a working vote button.
* **Emails that close the loop**: voters follow a request and hear about every status change, in the background and with a link to stop.
* **Tools for moderators**: merge duplicates with their votes and followers, limit how many votes each person gives, move a request on when it reaches a number of votes, export to CSV, and a dashboard widget.
* **Visitors who confirm their email** can vote and suggest features without an account.
* **Blocks and shortcodes** that take their look from your theme, and work without JavaScript and behind a page cache.
* **Privacy built in**: no IP addresses stored, export and erasure through WordPress's privacy tools, and no outside service unless you use Akismet or Cloudflare Turnstile.

= Adding the board =

Add the Feature Request Board block to any page, or use the shortcode:

`[feature_requests board="board-slug"]`

Leave out the board to show the default board. Visitors can search, filter by status and category, and sort by votes, by date, or by Trending, which puts first the requests with the most votes in the last 30 days. Filters apply as soon as they change, and a search shortly after typing stops, without reloading the page; the Back button brings the previous list back. Voting works without reloading the page too. With JavaScript off, the board still works, with a Show requests button.

Each of the four controls can be turned off, in the block's Filters panel or in the shortcode, for example `[feature_requests search="no" category_filter="no"]`. The shortcode takes `search`, `status_filter`, `category_filter` and `sort_filter`. With all four off, the board shows no filters at all. Links with filters in them, such as `?fr-status=planned`, still work.

= Collecting requests =

Add the Feature Request Form block where people should suggest features, or use the shortcode:

`[feature_request_form board="board-slug"]`

While someone types a title, the form lists existing requests with similar titles, so they can vote for one instead of posting a duplicate. The form also works when JavaScript is off.

For a complete page, insert the Feature request page pattern. It has the form, then the board.

The form's texts can be changed in the block's Texts panel: its heading, the title field and the help under it, the details field, the button, and the messages shown when a request waits for approval or is published. Leave a text empty for the default, which is translated. The shortcode takes `heading`, `title_label`, `title_help`, `details_label`, `submit_label`, `pending_message` and `published_message`:

`[feature_request_form heading="Tell us what to build" submit_label="Send idea"]`

The texts are plain text: HTML in them is shown as typed.

By default, only logged-in users can suggest features, and new requests wait for your approval under Feature Requests, marked Pending. Requests from editors and administrators are published straight away. Each board can change who may suggest features and whether requests wait for approval.

Each new request is emailed to the site's administration email address, with a link to review it. Under Feature Requests → Settings, the "Send those emails to" field starts with that address. Change it or add others, or turn the email off.

= Visitors who confirm their email =

A board can let visitors without an account vote and suggest features once they confirm their email address. Choose "Visitors who confirm their email" for who can vote or who can suggest features. A visitor who votes gives their address next to the vote button, and the plugin emails them a link that works for 24 hours. The link's page has a button: pressing it confirms the address, and the vote counts or the request is sent. The browser is then remembered for a year, so the next votes count at once.

Each address gets at most one link every 10 minutes. The answer is the same whether or not an address is known, so the form does not tell anyone who has confirmed. Visitors who confirmed their email follow the requests they vote for or suggest, and every email they get has a link to stop.

= Showing requests on other pages =

The board is on one page. Two more blocks bring requests, and votes, to the rest of your site.

The Feature Request List block is a short list of the requests with the most votes, or of the newest ones, with their vote buttons and a link to the board. It suits a home page, a sidebar or the end of a post. In the block, choose how many requests it shows, their order, one status and one board. It has no search and no filters. As a shortcode:

`[feature_request_list count="5" sort="new" status="planned" board="board-slug"]`

Leave them all out for the five requests with the most votes among those still open for voting, from every board.

The Feature Request Card block shows one request with its vote button, in a post or a page that talks about it, such as an announcement or a help article. In the block, find the request by typing a few letters of its title. The shortcode takes the request's ID, which is the number in the address of its edit screen:

`[feature_request id="123"]`

Three patterns put the blocks on a page for you: Feature request form beside the board, in two columns; Most wanted feature requests, a heading above the list; and Suggest a feature, a few words with a button that leads to the board.

= The roadmap =

The Feature Request Roadmap block shows a column for each status on your roadmap, in the order of your statuses, with the most voted requests first. Each column shows 10 requests, and Show more adds 10 at a time. People can vote on the roadmap too. It covers every board, or the one you choose. As a shortcode:

`[feature_roadmap board="board-slug"]`

By default Planned, In Progress and Shipped are on the roadmap. Under Feature Requests → Statuses, tick or untick "Show on roadmap" for each status. The Feature request roadmap pattern puts a heading above the block.

= Managing requests =

Feature Requests in the admin lists every request with its votes, status, board and categories. Sort by votes to see what people want most, and filter by status, board or category. The menu shows how many requests wait for approval.

Change a request's status from its edit screen, from Quick Edit, or for many requests at once with Bulk Edit. The edit screen also shows who voted for the request, how many users follow it and who submitted it.

To merge a duplicate, open it and use the "Merge into…" box. Its votes and followers move to the other request, and its address leads there. You choose who is emailed about it.

"Export to CSV" and "Export voters to CSV" above the list download the requests the list shows, or their votes, as files for Excel, Numbers or LibreOffice.

The Feature Requests widget on the dashboard shows the five requests that have waited longest for approval, and the requests with the most votes this week.

= Boards and statuses =

Under Feature Requests → Boards, each board has its own settings: who can vote, who can suggest features (everyone, visitors who confirm their email, logged-in users, or nobody), whether new requests wait for approval, and the page that shows the board. Each request links back to that page. The board fills the page in by itself the first time it is shown on one.

"Votes each person can give" limits how many requests each person can vote for on the board. Only votes for requests still open for voting count, so a vote comes back when its request is shipped or declined, or when the person takes it back. The board says how many votes each person has left.

"Vote threshold" moves a request to another status when it reaches a number of votes, for example from Under Review to Planned at 50 votes. It moves each request once, so a request you move back stays where you put it. Followers get the usual email about the new status.

A board can also be limited to some of your categories. Its form then offers only those, and so does its category filter. Categories themselves are under Feature Requests → Categories.

Under Feature Requests → Statuses, add, rename and reorder statuses. Each has a colour and a position, and can be closed for voting: requests in a closed status keep their votes, and nobody can add or remove one.

= Request pages and official responses =

Every request has its own page with its vote button, status, categories, description and comments. Comments are WordPress comments, so your discussion settings, comment moderation and your theme's comment design apply.

To answer a request on behalf of your team, write a comment on its page and tick "Post as the official response" before sending it. The newest official response is pinned below the request's description, and each one is labelled in the comments.

= Following requests and emails =

Logged-in users can follow a request with the Follow button on its page. A user who votes for a request follows it too, and so does a user who suggests one. Followers get an email when the request's status changes, and the user who suggested a request gets one when you approve it.

The emails are plain text. They go out in the background, 50 at a time, so saving a request never waits for mail. A status email goes out five minutes after the change: if you pick the wrong status, correct it within that time and nobody hears about it, and several changes in a row become one email.

Every email ends with a link that stops the emails about that request, without logging in. The page behind the link can also stop all of them at once. On their profile, users see the requests they follow, can stop following any of them, and choose whether voting makes them follow.

To change a status without an email, untick "Email followers when the status changes" next to the status, on the edit screen, in Quick Edit or in Bulk Edit. Under Feature Requests → Settings you can turn following off altogether, or only for votes.

Visitors without an account cannot follow and are not emailed, except visitors who confirmed their email on a board that asks for it.

= Spam protection =

The form stops most spam without a captcha:

* A hidden field that people never see and bots tend to fill in.
* Forms sent within 3 seconds of loading the page are refused, as bots send them.
* Each visitor can send 5 requests an hour, and each IP address 10.
* Votes are limited too: 30 an hour for each visitor, and 60 an hour from one IP address. Logged-in users and visitors without an account are counted apart, and you can change both numbers under Feature Requests → Settings.
* If the Akismet plugin is active and connected, it checks every new request. Requests it flags wait for your review instead of being deleted.

For a check against bots that people barely notice, the plugin supports Cloudflare Turnstile. Add a widget for your site in your Cloudflare account, and enter its site key and secret key under Feature Requests → Settings → Spam check. Visitors without an account then pass the check before they send a request or ask for a link to confirm their email; tick "Check logged-in users too" to check everyone. While Turnstile is on, these forms need JavaScript. The secret key is never shown again once it is saved.

Developers can add another captcha through the `mwtfrs_verify_captcha` filter, or another spam service through the `mwtfrs_is_spam` filter.

= Accessibility =

The board, the list, the card, the form and the request page aim for WCAG 2.2 level AA. Everything works with a keyboard and without JavaScript, changes are announced to screen readers, and the automated checks of axe pass in block themes and classic themes. Colours come from your theme, so the contrast of your theme's own text and background still matters.

= Privacy =

Everything is stored in your own WordPress database, and the plugin stores no IP addresses. People who suggest a feature are not post authors, and the names and email addresses that visitors give are shown only to moderators. The plugin works with WordPress's tools to export and erase personal data, and offers a text for your privacy policy. Under Feature Requests → Settings you can write a "How we use your data" note that visitors see on the form. The FAQ says what is stored, and which cookies the plugin sets.

= External services =

When the Akismet plugin is active and connected to an Akismet account, this plugin sends each new feature request to Akismet to check whether it is spam. It sends the request's title and details, the name and email a visitor gave, and their IP address, browser user agent and referring page. Nothing is sent when Akismet is not active. Akismet is a service by Automattic: [terms of service](https://akismet.com/tos/), [privacy policy](https://akismet.com/privacy/).

When you save a site key and a secret key for Cloudflare Turnstile under Feature Requests → Settings → Spam check, pages with the form, and boards where visitors confirm their email, load Cloudflare's script from `https://challenges.cloudflare.com/turnstile/v0/api.js` for visitors without an account (and for logged-in users if you tick "Check logged-in users too"). While the page is open, Cloudflare receives the visitor's IP address and details about their browser and device, to tell people from bots. When the form is sent, your site sends the widget's answer and your secret key to `https://challenges.cloudflare.com/turnstile/v0/siteverify`, without the visitor's IP address. Nothing loads from Cloudflare while either key is empty. Turnstile is a service by Cloudflare: [terms](https://www.cloudflare.com/website-terms/), [privacy policy](https://www.cloudflare.com/privacypolicy/), [Turnstile privacy addendum](https://www.cloudflare.com/turnstile-privacy-policy/).

== Frequently Asked Questions ==

= Does it work with my theme? =

The board and the form are built for any theme. Block themes and classic themes are both supported.

= Can visitors without an account suggest features? =

Not by default. Each board has a setting for who can suggest features: everyone, visitors who confirm their email, logged-in users, or nobody. Change it under Feature Requests → Boards. Visitors without an account leave their name and email, and those are never shown publicly. With "Visitors who confirm their email", their request is sent only once they confirm the address.

= I changed a status. Why did the followers get no email yet? =

Status emails go out five minutes after the last change, so that a status picked by mistake can be corrected first. They are sent in the background by WordPress's scheduled tasks, which run when someone visits the site. On a site with few visitors that can take a little longer.

The user who changes the status gets no email about their own change.

= What is the Scheduled Actions screen under Tools? =

It belongs to Action Scheduler, the library this plugin uses to send emails in the background. WooCommerce and many other plugins use it too. The screen lists the emails that wait to be sent and those that were, under the group feature-request-system.

= Do mail programs show their own unsubscribe button? =

Each email carries the headers that mail programs such as Gmail use for that button, and using it stops the emails about that request. Mail programs rely on those headers only for mail that is sent through SMTP or a mail service and signed by your domain. With PHP's built-in mail function, which WordPress uses unless you set up something else, the button may not appear. The link at the end of the email works in every case.

= Does it work on a multisite network? =

Yes. Each site of the network has its own boards, requests, votes and settings. Deleting the plugin removes the data of every site that has "Delete all requests…" ticked.

On a network, "logged-in users" means the users of the whole network, as it does for comments in WordPress. A user who is removed from a site no longer follows requests there.

= Why does a link to a video stay a link? =

WordPress turns a web address on a line of its own into a video player or a preview. In feature requests the plugin turns that off, because the text comes from your visitors: your server would fetch whatever address they typed, and everyone who opens the request would load content from that site. A moderator who wants a video in a request can wrap its address in WordPress's embed shortcode: `[embed]https://example.com/video[/embed]`. Visitors cannot use shortcodes.

= My site is behind a proxy or a CDN. Do the rate limits still work? =

The limits for each IP address use the address your server sees. Most hosts pass on the visitor's address. If your server sees the address of the proxy instead, all visitors count as one, and a busy board can reach the limit for everyone. In that case, tell the plugin which header holds the visitor's address, from your theme or a small plugin. For Cloudflare:

`add_filter( 'mwtfrs_client_ip', function ( $ip ) { return isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $ip; } );`

Only do this when every request reaches your server through that proxy, because anyone can send such a header. The vote limits themselves are under Feature Requests → Settings, and every limit can be changed with the `mwtfrs_rate_limit` filter.

= People in our office get "Too many votes". What can I do? =

They share one IP address, and by default 60 votes an hour can come from the logged-in users at one address. Raise "Logged-in users" under Feature Requests → Settings → Vote limits. Each user can still vote only once for a request, and 30 times an hour.

= Can I try it with sample requests? =

With WP-CLI, `wp mwtfrs seed` creates 30 sample requests with votes, and `wp mwtfrs seed --delete` removes them again. `wp mwtfrs seed --requests=1000 --votes=50000` creates a large board to test with. `wp mwtfrs recount` recounts every request's votes.

= Can only customers, members or other groups vote? =

Yes, with two filters for developers. `mwtfrs_can_vote` decides who may vote on a request, and `mwtfrs_can_submit` who may suggest features on a board. Each gets the board's own answer and returns true to let someone in or false to keep them out:

`add_filter( 'mwtfrs_can_vote', function ( $allowed, $request_id, $user_id ) { return $allowed && $user_id && user_can( $user_id, 'read_private_posts' ); }, 10, 3 );`

The filters apply to the vote buttons and the form that pages show, and to every vote and request sent, with or without JavaScript. They cannot open a request that is not published, or one in a status closed for voting.

Mind your page cache. Page caches serve one copy of a page to every visitor who is not logged in. A filter whose answer depends on the visitor, such as their membership, a purchase or a cookie, makes pages differ from one visitor to another. Such a filter should tell apart only logged-in users, whose pages caches do not serve, or the pages with the blocks must be left out of the cache. A filter that depends only on the request or the board is safe.

= How does merging work? =

Open the duplicate and use the "Merge into…" box on its edit screen: find the other request by its title, and merge. Its votes move to the other request, counted once for a person who voted for both, and so do its followers. Its address leads to the other request from then on, and its comments stay with it. Only a published request on the same board can take a merge, and a merge cannot be undone. Who hears about it is set under Feature Requests → Settings → Email about a merge: everyone who followed the merged request, only the person who suggested it, or nobody. Each board can choose for itself. The person who merges is never emailed.

= Can I undo a merge? =

Not in 1.1. Votes and followers have moved to the other request by then, and people may have been emailed. Check the request you merge into before you press Merge.

= What is in the CSV exports? =

"Export to CSV" above the list downloads the requests the list shows, with its filters, search and order. "Export voters to CSV" downloads one row for each of their votes. The names and email addresses of the people who suggested and voted are included only for editors and administrators. The files open in Excel, Numbers and LibreOffice. With WP-CLI: `wp mwtfrs export > requests.csv`, or `wp mwtfrs export --voters`, with `--board`, `--status`, `--category`, `--post_status` and `--search` to filter.

= How do I change official responses? =

Editors and administrators can change the mark on any comment written by an editor or administrator, in three places:

* On the request's page, next to the comment's Reply link: "Mark as official response", or "Remove official mark" once it is marked.
* On the comment's edit screen, with the "Official response" checkbox in the Save box.
* On the Comments screen, among the actions that appear when you point at a comment.

In block themes, request pages use the Single Feature Request template, which you can change in the Site Editor. It needs WordPress 6.7 or later; on WordPress 6.6 the theme's own template for posts is used.

= Can I change the markup or the emails? =

The board, the form and the request page take their colours and spacing from your theme. To change their markup, copy a file from the plugin's `templates` folder to a `feature-request-system` folder in your theme: `board.php`, `roadmap.php`, `request-list.php`, `request-card.php`, `request-item.php`, `vote-button.php`, `guest-email-form.php`, `submit-form.php`, `request.php` or `official-response.php`.

The text of the emails can be changed the same way: copy `emails/status-changed.php`, `emails/request-approved.php`, `emails/request-merged.php` or `emails/confirm-email.php` to `feature-request-system/emails` in your theme.

`board.php` changed in 1.1 for live filtering. A copy made from 1.0 still works, with a Show requests button, until you update it. A copy of `submit-form.php` needs the new Turnstile block from the plugin's file, or the check fails while Turnstile is on.

To style the blocks entirely yourself, turn the plugin's stylesheets off from your theme or a small plugin:

`add_filter( 'mwtfrs_load_styles', '__return_false' );`

= What does the plugin store about people? =

Everything is stored in your own WordPress database.

Under Feature Requests → Settings you can write a "How we use your data" note. Visitors without an account can open it on the form, under the name and email fields, followed by a link to your privacy policy page. The settings page suggests a text to start from.

People who suggest a feature are not stored as post authors, so their names never appear in author archives or other public data. The name and email a visitor gives are stored privately with their request and shown only to moderators. The plugin stores no IP addresses; rate limits use short-lived, hashed counters.

The description of a request is shown as plain text. A web address in it stays text, and is not turned into an embedded video or page from another site.

On boards for visitors who confirm their email, the plugin stores the address and name a visitor confirmed, in a table of its own, and sets a cookie named `mwtfrs_guest` for one year, which only the server can read. Addresses that are never confirmed are deleted once their link expires. These addresses are shown only to moderators, and in their CSV exports.

For each logged-in user, the plugin stores which requests they follow, so it can email them. Those emails go to the address of the user's account, through your site's own mail setup. Who follows a request is never shown publicly; moderators see how many users follow it.

The plugin works with WordPress's privacy tools. Tools → Export Personal Data includes the requests a person suggested, their votes and the requests they follow, for users and for visitors who confirmed their email. Tools → Erase Personal Data removes the record of who suggested a request, stops their emails, and cuts the tie between the person and their votes; the votes themselves stay, so counts stay correct. Under Settings → Privacy, the policy guide offers a text about the plugin for your privacy policy.

When a user is deleted, they stop following every request, and their votes stay without the tie to the account, so counts stay correct. WordPress's own export file (Tools → Export) is a copy of your content for moving it to another site: it includes the name and email that visitors gave with their requests.

A visitor who votes without an account gets a cookie named `mwtfrs_vid`, which holds a random number for one year so the site remembers their votes. It is only set on boards where you allow voting without an account, and only when such a visitor votes.

= How do I delete the plugin's data? =

Deleting the plugin keeps your requests and votes, in case you install it again. To remove everything, tick "Delete all requests, votes, followers, boards, statuses and settings when the plugin is deleted" under Feature Requests → Settings before you delete it. The tables of Action Scheduler stay, because other plugins may use them.

== Screenshots ==

1. The board: search, filters and one-click voting.
2. The form lists similar requests while someone types a title.
3. A request's page: its status, the Follow button, your official response and the comments.
4. Requests in the admin, sorted by votes, each with its status.
5. Every board has its own settings: who can vote and suggest, approval, its page and its categories.
6. The settings page.
7. The roadmap: a column for each status, the most voted requests first.

== Changelog ==

= 1.1.0 =
* New: the Feature Request Roadmap block, its shortcode and a pattern. Choose which statuses are on the roadmap under Feature Requests → Statuses.
* New: the board's filters apply as they change, without reloading the page. Each of its four controls can be turned off.
* New: Trending, an order for the board and the list by the votes of the last 30 days.
* New: merge duplicate requests, with their votes and followers. Choose who is emailed about it, for the site and for each board.
* New: boards can let visitors vote and suggest features once they confirm their email.
* New: a limit on the votes each person can give on a board, and a threshold that moves a request to another status at a number of votes.
* New: Cloudflare Turnstile as a spam check for the form.
* New: change the form's heading, labels, button and messages.
* New: export requests and voters to CSV, from the requests list or with `wp mwtfrs export`.
* New: a dashboard widget with the requests waiting for approval and this week's top requests.
* New: the `mwtfrs_can_vote` and `mwtfrs_can_submit` filters, to decide who may vote and suggest features.
* Changed: the "Send those emails to" field starts with the site's administration email.
* Changed: the board's template, `board.php`, for live filtering. Copies in themes made from 1.0 keep working without it.

= 1.0.0 =
* First release.
