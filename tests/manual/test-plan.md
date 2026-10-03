# Manual test plan

A list of everything to try by hand before a release: 142 cases in 18 areas. The automated tests cover the same ground from the inside; this plan is how a person checks it from the outside, on a real site.

- **Build to test:** the branch that is checked out in the plugin folder of the test site.
- **Test site:** https://test-wp-plugins.test (WordPress 7.1, PHP 8.4, Twenty Twenty-Five).
- **A page to tick the cases off:** the plan is also published as a page where each case can be marked as passed, failed or blocked, with a note.

## How to use it

1. Do the setup once.
2. Work through the areas in order. Later areas use what earlier ones created.
3. For each case, do the steps as the person named, then compare with what is expected. Mark it as passed, failed or blocked.
4. When a case fails, write down what you saw instead (see "Reporting a problem").

| Priority | Meaning | Cases |
|---|---|---|
| P1 | Must pass before the release. | 80 |
| P2 | Should pass. A failure is fixed before the release unless you decide otherwise. | 54 |
| P3 | Extended checks that need extra setup. Run them if you have the time. | 8 |

## Who does the steps

| Name | Who that is |
|---|---|
| Admin | You, the site administrator. |
| Maya | An editor, `frs-maya`. Editors moderate requests but cannot open Settings. |
| Sam | A subscriber, `frs-sam`. A normal logged-in visitor. |
| Riley | A second subscriber, `frs-riley`. |
| Guest | A visitor who is not logged in. Use a private window. |

Use a separate browser, or a private window, for each person, so that you can switch without logging out.

## Setup

### S1 · Check out the build

Your Herd site runs the plugin from its git folder, so the branch you have checked out is the build you test.

```bash
cd ~/Herd/test-wp-plugins/wp-content/plugins/feature-request-system
git status
composer install && npm install && npm run build
```

Never click Delete for this plugin on the Plugins screen of the Herd site. That deletes the git folder. The uninstall cases (INS-05 and INS-06) run on a second, throwaway site.

### S2 · Create the test users

WP-CLI prints a password for each user. Keep them at hand, or use a plugin such as User Switching.

```bash
cd ~/Herd/test-wp-plugins
wp user create frs-maya frs-maya@example.com --role=editor --display_name="Maya Moderator"
wp user create frs-sam frs-sam@example.com --role=subscriber --display_name="Sam Subscriber"
wp user create frs-riley frs-riley@example.com --role=subscriber --display_name="Riley Reader"
```

### S3 · Catch the emails

The email cases need to see what the site sends. If you have Herd Pro, its Mail tab shows it. Otherwise create this file. It writes every email to a log file instead of sending it. Delete the file when you are done.

File: `wp-content/mu-plugins/frs-mail-log.php`

```php
<?php
// Testing only: writes every email to wp-content/frs-mail.log instead of sending it.
add_filter( 'pre_wp_mail', function ( $sent, $mail ) {
	$headers = is_array( $mail['headers'] ) ? implode( "\n", $mail['headers'] ) : (string) $mail['headers'];
	$entry   = sprintf( "=== %s\nTo: %s\nSubject: %s\n%s\n\n%s\n\n", gmdate( 'c' ), implode( ', ', (array) $mail['to'] ), $mail['subject'], $headers, $mail['message'] );
	file_put_contents( WP_CONTENT_DIR . '/frs-mail.log', $entry, FILE_APPEND );
	return true;
}, 10, 2 );
```

Watch the log in a terminal: `tail -f ~/Herd/test-wp-plugins/wp-content/frs-mail.log`

Emails to followers go out in the background, five minutes after a status change. To send one at once, open Tools → Scheduled Actions, choose Pending, point at the action and click Run.

### S4 · Add sample requests

This creates 30 published requests with 400 votes and four categories. Run it again with `--delete` to remove them.

```bash
cd ~/Herd/test-wp-plugins
wp mwtfrs seed
```

### S5 · Install a classic theme

Your site has block themes only. The theme cases also need a classic theme.

```bash
cd ~/Herd/test-wp-plugins
wp theme install twentytwentyone
```

### S6 · Prepare a second, throwaway site

The install and uninstall cases need a clean WordPress site where the plugin is installed from the zip. Create one the way you usually do in Herd, then build the zip and install it there.

```bash
cd ~/Herd/test-wp-plugins/wp-content/plugins/feature-request-system
bin/build-zip.sh
# On the clean site:
wp plugin install ~/Herd/test-wp-plugins/wp-content/plugins/feature-request-system/dist/feature-request-system.zip --activate
```

## Test cases

### INS · Install, upgrade and uninstall

Run INS-01, 02, 05 and 06 on the second, throwaway site (S6). Run INS-03 and 04 on the Herd site.

#### INS-01 · The plugin installs and activates cleanly

**Priority:** P1 · **As:** Admin · **Note:** Clean site (S6).

Steps:

1. On the clean site, install the zip and activate the plugin.
2. Look at the admin menu and at Tools.

Expected:

- No error, warning or notice appears.
- The menu has Feature Requests, with All Requests, Add Feature Request, Boards, Statuses, Categories and Settings.
- Tools has Scheduled Actions.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### INS-02 · A fresh install has one board, six statuses and safe defaults

**Priority:** P1 · **As:** Admin · **Note:** Clean site (S6).

Steps:

1. Open Feature Requests → Boards.
2. Open Feature Requests → Statuses.
3. Open Feature Requests → Settings.

Expected:

- There is one board, Feature Requests.
- The statuses are Open, Under Review, Planned, In Progress, Shipped and Declined, in that order, each with a colour. Shipped and Declined say Closed in the Voting column.
- In Settings: the email about new requests, following, and "A user who votes for a request also follows it" are ticked. "How we use your data" is empty. Both vote limits are 60. "Delete all requests…" is not ticked.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### INS-03 · Deactivating and activating again keeps everything

**Priority:** P2 · **As:** Admin

Steps:

1. On the Herd site, note the number of requests and the votes of one request.
2. Deactivate the plugin on the Plugins screen. Do not delete it.
3. Activate it again.

Expected:

- The requests, votes, boards, statuses and settings are as before.
- No status or board exists twice.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### INS-04 · The Herd site was upgraded to the current database version

**Priority:** P2 · **As:** Admin

Steps:

1. Run: `wp option get mwtfrs_db_version`
2. Run: `wp db query "SHOW TABLES LIKE '%mwtfrs%'"`

Expected:

- The version is 2.
- The tables `wp_mwtfrs_votes` and `wp_mwtfrs_subscriptions` exist.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### INS-05 · Deleting the plugin keeps the data by default

**Priority:** P1 · **As:** Admin · **Note:** Clean site (S6). "Delete all requests…" is not ticked.

Steps:

1. On the clean site, add a request and vote for it.
2. Deactivate the plugin, then delete it on the Plugins screen.
3. Install the zip again and activate it.

Expected:

- The request and its vote are still there.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### INS-06 · Deleting the plugin removes everything when that setting is ticked

**Priority:** P1 · **As:** Admin · **Note:** Clean site (S6).

Steps:

1. On the clean site, tick "Delete all requests, votes, followers, boards, statuses and settings when the plugin is deleted" in Settings and save.
2. Deactivate the plugin, then delete it on the Plugins screen.
3. Run on the clean site: `wp db query "SHOW TABLES LIKE '%mwtfrs%'"`
4. Run: `wp db query "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'mwtfrs_request'"`
5. Run: `wp db query "SELECT option_name FROM wp_options WHERE option_name LIKE 'mwtfrs%'"`

Expected:

- No `mwtfrs` table is left.
- No request is left, and no option of the plugin.
- The actionscheduler tables are still there. That is intended: other plugins may use them.

Result: ☐ Passed ☐ Failed ☐ Blocked

### BLK · Blocks, pattern and shortcodes

#### BLK-01 · The page pattern puts the form above the board

**Priority:** P1 · **As:** Admin

Steps:

1. Add a new page called Feature requests.
2. In the inserter, open Patterns, choose the Feature requests category and insert "Feature request page".
3. Publish the page and view it.

Expected:

- The editor shows the Feature Request Form, a heading "Browse requests" and the Feature Request Board.
- The page shows the form "Suggest a feature", the heading, and the board with the sample requests.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### BLK-02 · The board block has its settings

**Priority:** P1 · **As:** Admin · **Note:** Set it back to 20 afterwards.

Steps:

1. Edit the page and select the Feature Request Board block.
2. In the sidebar, set "Requests per page" to 5 and update the page.
3. View the page.

Expected:

- The sidebar offers "Board to show" and "Requests per page".
- The board shows 5 requests and page links below them.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### BLK-03 · The shortcodes show the same board and form

**Priority:** P1 · **As:** Admin

Steps:

1. Add a page with a Shortcode block that holds `[feature_request_form]` and another that holds `[feature_requests per_page="5"]`.
2. View the page.

Expected:

- The form and the board appear and work as on the pattern page.
- The board shows 5 requests per page.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### BLK-04 · Block themes get a template for requests

**Priority:** P2 · **As:** Admin · **Note:** Twenty Twenty-Five is active.

Steps:

1. Open Appearance → Editor → Templates.
2. Open "Single Feature Request".

Expected:

- The template exists. It holds the title, the Feature Request Details block, the content and the comments.

Result: ☐ Passed ☐ Failed ☐ Blocked

### BRD · The board

Use the page from BLK-01, with the sample requests (S4).

#### BRD-01 · The board lists open requests, most votes first

**Priority:** P1 · **As:** Guest

Steps:

1. Open the board page in a private window.

Expected:

- Each request shows its votes, its title as a link, the start of its text, its status with a coloured dot, and its category.
- The request with the most votes is first.
- Requests that are Shipped or Declined are not in the list.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### BRD-02 · Sorting by newest

**Priority:** P1 · **As:** Guest

Steps:

1. Set "Sort by" to Newest and press "Show requests".

Expected:

- The newest request is first.
- The address contains `fr-sort=new`.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### BRD-03 · Filtering by status

**Priority:** P1 · **As:** Guest

Steps:

1. Set Status to "All statuses" and press "Show requests".
2. Set Status to Planned and press "Show requests".

Expected:

- With "All statuses", Shipped and Declined requests appear too.
- With Planned, only Planned requests appear.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### BRD-04 · Filtering by category

**Priority:** P1 · **As:** Guest

Steps:

1. Choose a category and press "Show requests".

Expected:

- Only requests of that category appear.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### BRD-05 · Searching

**Priority:** P1 · **As:** Guest

Steps:

1. Type "export" into "Search requests" and press "Show requests".
2. Search for "zzzz".

Expected:

- Only requests with the word in their title or text appear.
- The second search shows "No requests match these filters."

Result: ☐ Passed ☐ Failed ☐ Blocked

#### BRD-06 · Page links keep the filters

**Priority:** P1 · **As:** Guest

Steps:

1. Set Status to "All statuses" and press "Show requests".
2. Go to page 2 with the links below the list.

Expected:

- Page 2 shows the next requests.
- Status still says "All statuses".

Result: ☐ Passed ☐ Failed ☐ Blocked

#### BRD-07 · Requests that are not published stay off the board

**Priority:** P2 · **As:** Admin

Steps:

1. In the admin, set one request to Pending, one to Draft and move one to the Trash.
2. Open the board as a guest, with Status set to "All statuses".

Expected:

- None of the three requests appears, on any page.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### BRD-08 · An empty board says so

**Priority:** P2 · **As:** Admin

Steps:

1. Add a board called Empty under Feature Requests → Boards.
2. Add a page with the Feature Request Board block and choose Empty as the board to show.
3. View the page.

Expected:

- The board shows "No requests yet."

Result: ☐ Passed ☐ Failed ☐ Blocked

#### BRD-09 · Text is shown as text

**Priority:** P2 · **As:** Admin

Steps:

1. In the admin, add and publish a request with the title <b>Bold</b> & "quotes" <script>alert(1)</script>.
2. Open the board.

Expected:

- The title shows as text or without the tags. Nothing is bold, and no alert appears.

Result: ☐ Passed ☐ Failed ☐ Blocked

### VOT · Voting

#### VOT-01 · A logged-in user votes

**Priority:** P1 · **As:** Sam

Steps:

1. Open the board and press the vote button of a request.
2. Reload the page.

Expected:

- The count goes up by one at once and the button turns dark.
- After the reload the button is still dark and the count is the same.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### VOT-02 · Taking a vote back

**Priority:** P1 · **As:** Sam · **Note:** After VOT-01.

Steps:

1. Press the dark vote button again.
2. Reload the page.

Expected:

- The count goes down by one and the button is no longer dark.
- It stays that way after the reload.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### VOT-03 · A vote on the request page shows on the board too

**Priority:** P1 · **As:** Sam

Steps:

1. Open a request and press its vote button.
2. Go back to the board.

Expected:

- On the board, that request has the dark button and the new count.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### VOT-04 · A guest is asked to log in

**Priority:** P1 · **As:** Guest · **Note:** The board has "Who can vote" set to "Logged-in users", the default.

Steps:

1. Open the board in a private window and press a vote button.
2. Follow the "Log in" link and log in as Sam.

Expected:

- The message "Log in to vote on this request." appears next to the request, with a "Log in" link. The count does not change.
- After logging in you are back on the board page.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### VOT-05 · Guests vote where the board allows it

**Priority:** P1 · **As:** Guest · **Note:** Set "Who can vote" back to "Logged-in users" afterwards.

Steps:

1. As Admin, edit the board and set "Who can vote" to "Everyone, including visitors without an account".
2. In a private window, vote for a request and reload.
3. Press the button again.
4. Open a second private window in another browser and vote for the same request.

Expected:

- The guest's vote counts and is still shown after the reload.
- Pressing again takes the vote back. One guest cannot vote twice.
- The other browser counts as another guest: the count goes up by one more.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### VOT-06 · Closed requests cannot be voted on

**Priority:** P1 · **As:** Sam

Steps:

1. As Admin, set a request with votes to Shipped.
2. As Sam, open the board with Status set to "All statuses", and open that request.

Expected:

- The request shows its vote count without a button, on the board and on its page.
- Its votes are still counted.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### VOT-07 · A board can turn voting off

**Priority:** P2 · **As:** Sam · **Note:** Set it back afterwards.

Steps:

1. As Admin, set the board's "Who can vote" to Nobody.
2. As Sam, open the board.

Expected:

- Every request shows its count without a button.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### VOT-08 · One user has one vote, in any browser

**Priority:** P2 · **As:** Sam

Steps:

1. Vote for a request in one browser.
2. Log in as Sam in another browser and open the board.

Expected:

- The second browser shows the dark button. The count went up once only.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### VOT-09 · Too many votes in an hour are refused

**Priority:** P2 · **As:** Sam

Steps:

1. Press one vote button 31 times, waiting for the count each time.

Expected:

- After 30 changes a message appears: "Too many votes in a short time. Try again in … minutes."
- As Admin or Maya there is no such limit.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### VOT-10 · Counts in the admin match the board

**Priority:** P2 · **As:** Admin

Steps:

1. Compare the Votes column under Feature Requests with the board for three requests.
2. Run: `wp mwtfrs recount`

Expected:

- The numbers match.
- The command says it fixed 0 vote counts.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### VOT-11 · The limits on votes from one address are settings

**Priority:** P2 · **As:** Admin · **Note:** The board lets guests vote, as in VOT-05. Set the limit and the board back afterwards.

Steps:

1. Open Feature Requests → Settings and find "Vote limits".
2. Set "Visitors without an account" to 2 and save.
3. Run three times, with the ID of a published request in place of ID: `curl -sk -o /dev/null -w "%{http_code}\n" -X POST -H "Origin: https://test-wp-plugins.test" "https://test-wp-plugins.test/wp-json/mwtfrs/v1/requests/ID/vote"`
4. As Sam, vote for a request on the board.

Expected:

- The section has two numbers, "Logged-in users" and "Visitors without an account". Both are 60 before you change one.
- The page says "Settings saved." and the field shows 2.
- Each run votes as a new guest from your address. The third run prints 429, or an earlier one if guests voted from your address in the last hour. The runs before it print 200.
- Sam's vote counts: logged-in users at the same address have their own limit.

Result: ☐ Passed ☐ Failed ☐ Blocked

### LST · The list, the card and more patterns

These blocks show requests on pages other than the board's. Use the sample requests (S4) and the page from BLK-01, which is the board's page.

#### LST-01 · A list shows the most wanted requests

**Priority:** P1 · **As:** Admin

Steps:

1. Add a new page called Home.
2. In the inserter, search for "Feature Request List" and insert it.
3. Look at the block's settings in the sidebar.
4. Publish the page and view it.

Expected:

- The inserter offers Feature Request List next to Feature Request Board, each with its own icon.
- The settings are "Requests of" (All boards), "Number of requests" (5), "Order" (Most votes) and "Status" (Open for voting).
- The page shows the five requests with the most votes, each with its vote button, its title and its status.
- The list has no search, no filters and no page links.
- "See all requests" leads to the Feature requests page.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### LST-02 · The settings of the list

**Priority:** P2 · **As:** Admin

Steps:

1. Edit Home. Set "Number of requests" to 3 and "Order" to Newest. Update and view the page.
2. Set "Status" to Planned. Update and view the page.
3. Set "Status" back to "Open for voting" and "Order" back to "Most votes". Update.

Expected:

- The page shows the three newest requests.
- With Planned, it shows only requests that are Planned.
- The editor shows the same requests as the page each time.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### LST-03 · Voting on the list

**Priority:** P1 · **As:** Sam

Steps:

1. Open Home.
2. Press the vote button of a request.
3. Reload the page.
4. Press the button again.

Expected:

- The button looks pressed and the count goes up by one, without the page reloading.
- After the reload the button is still pressed.
- The second press takes the vote back.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### LST-04 · A card shows one request in a post

**Priority:** P1 · **As:** Admin

Steps:

1. Add a new post called "What we are working on" with a line of text.
2. Insert the Feature Request Card block.
3. In its field, type a few letters of a request's title, such as "dark", and choose the request.
4. Publish the post and view it.

Expected:

- The field lists the requests whose titles match as you type.
- The editor then shows the card of that request.
- The post shows the card between the text: the vote button with its count, the title, the first words of the description, the status and the categories.
- The title leads to the request's page.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### LST-05 · A request shown twice stays in step

**Priority:** P1 · **As:** Admin, then Sam

Steps:

1. As Admin: edit Home and add a Feature Request Card below the list, for the first request of the list. Update.
2. As Sam: open Home and press the vote button on the card.
3. Press the vote button of the same request in the list.

Expected:

- The card's button is pressed and its count goes up.
- The same request in the list changes with it, at once: pressed, and the same count.
- Taking the vote back in the list changes the card too.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### LST-06 · A card of a request that visitors cannot see

**Priority:** P2 · **As:** Admin

Steps:

1. Under Feature Requests, use Quick Edit to set the request from LST-04 to "Pending Review".
2. View the post from LST-04.
3. Open the post in the editor.
4. Publish the request again and view the post.

Expected:

- The post shows nothing where the card was, and no error.
- The editor says that the request is not published, and that visitors see nothing there.
- After publishing the request, the card is back.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### LST-07 · The pattern with the form beside the board

**Priority:** P2 · **As:** Admin

Steps:

1. Add a new page called Feedback.
2. In the inserter, open Patterns, choose the Feature requests category and insert "Feature request form beside the board".
3. Publish the page and view it in a wide window.
4. Make the window as narrow as a phone.

Expected:

- In a wide window, the form is in a narrow column on the left, and the heading "Browse requests" and the board are on the right.
- In a narrow window, the form comes first and the board below it.
- Nothing is cut off, and nothing scrolls sideways.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### LST-08 · The patterns "Most wanted" and "Suggest a feature"

**Priority:** P2 · **As:** Admin

Steps:

1. Add a new page. From the Feature requests patterns, insert "Most wanted feature requests" and then "Suggest a feature".
2. Publish the page and view it.
3. Press the button "Suggest a feature".
4. In the editor, open the pattern category "Call to action".

Expected:

- The page shows the heading "Most wanted" above five requests with their vote buttons.
- Below it: the heading "Have an idea?", a line of text and the button.
- The button leads to the Feature requests page.
- "Suggest a feature" is also among the calls to action.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### LST-09 · The shortcodes of the list and the card

**Priority:** P3 · **As:** Admin

Steps:

1. Add a new page. Add a Shortcode block with `[feature_request_list count="3" sort="new"]`.
2. Add a second Shortcode block with `[feature_request id="123"]`, where 123 is the number in the address of a request's edit screen.
3. Publish the page and view it.

Expected:

- The page shows the three newest requests as a list, and the card of that request.
- Their vote buttons work.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### LST-10 · Icons and previews of the blocks

**Priority:** P3 · **As:** Admin

Steps:

1. In the editor, open the inserter and search for "feature request".
2. Move the pointer over Feature Request Board, then over Feature Request List and Feature Request Form.

Expected:

- Five blocks are listed, each with its own icon: Board (the plugin's logo), List, Form, Details and Card.
- Each of the three shows a preview with the site's own requests.

Result: ☐ Passed ☐ Failed ☐ Blocked

### SUB · Suggesting a feature

#### SUB-01 · A subscriber's request waits for approval

**Priority:** P1 · **As:** Sam

Steps:

1. On the board page, fill in Title and Details, choose a category and press "Send request".
2. As Admin, open Feature Requests.

Expected:

- The form says "Thanks! Your request will appear on the board once it is approved." and empties its fields.
- The request is not on the board.
- In the admin the request is listed as Pending, and the menu shows a count badge.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-02 · Similar requests appear while typing

**Priority:** P1 · **As:** Sam

Steps:

1. Type "Dark mode for reports" into Title and wait a moment.
2. Empty the Title field.

Expected:

- A box "Already requested? Vote for one of these instead:" lists requests with a word in common, each with its votes and status. The titles are links to those requests.
- The box disappears when the title is empty.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-03 · Approving a request puts it on the board

**Priority:** P1 · **As:** Admin

Steps:

1. Open the pending request from SUB-01 and press Publish.
2. Open the board, sorted by Newest.

Expected:

- The request is on the board with the status Open and 0 votes.
- The count badge in the menu went down by one.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-04 · A moderator's request is published at once

**Priority:** P1 · **As:** Maya

Steps:

1. As Maya, send a request from the form.

Expected:

- The form says "Thanks! Your request is on the board." with a link "View your request".
- The request is on the board.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-05 · A board can publish everyone's requests at once

**Priority:** P1 · **As:** Sam · **Note:** Tick the box again afterwards.

Steps:

1. As Admin, edit the board and untick "Hold new requests until a moderator approves them".
2. As Sam, send a request.

Expected:

- The form says "Thanks! Your request is on the board.", and the request is on the board.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-06 · A guest is asked to log in

**Priority:** P1 · **As:** Guest

Steps:

1. Open the board page in a private window.

Expected:

- In place of the form, the page says "Log in to suggest a feature." with a "Log in" button.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-07 · Guests suggest features where the board allows it

**Priority:** P1 · **As:** Guest · **Note:** Set "Who can suggest features" back afterwards.

Steps:

1. As Admin, set the board's "Who can suggest features" to "Everyone, including visitors without an account".
2. In a private window, fill in the form with a name and an email address and send it.
3. As Admin, open the new request's edit screen.
4. Publish it, then open it as a guest and view the page source.

Expected:

- The form has a section "About you (optional)" with the note "Only our team sees this, in case we have a question about your request.", a Name field and an Email field.
- The request waits for approval.
- The Request details box says "Submitted by" with the name and the email address.
- The name and the email address are nowhere on the public page or in its source.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-08 · Wrong input is explained

**Priority:** P1 · **As:** Guest

Steps:

1. With guest suggestions allowed (SUB-07), try to send the form with an empty title.
2. Enter only spaces as the title and send.
3. Enter a title and the email "not-an-email". If the browser stops you, change the field type to text in the developer tools and send.

Expected:

- The browser asks for a title.
- The form says "Add a title for your request."
- The form says "Enter a valid email address, or leave it empty."
- No request is created in any of the three cases.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-09 · The "How we use your data" note

**Priority:** P2 · **As:** Guest

Steps:

1. As Admin, write a note in Settings under "How we use your data", with a link and a word in bold, and save.
2. As a guest, open the form (guest suggestions allowed) and press "How we use your data".
3. As Sam, open the form.

Expected:

- For guests, the note opens under the name and email fields, with the link and the bold word. A "Privacy policy" link follows if the site has a privacy policy page.
- Sam does not see the note.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-10 · The team gets an email about a new request

**Priority:** P1 · **As:** Sam

Steps:

1. As Sam, send a request.
2. Look at the mail log (S3).

Expected:

- An email went to the site's administration address. Its subject is "[site name] New feature request to approve: " and the title.
- It names the board and "Submitted by: Sam Subscriber <frs-sam@example.com>", holds the text, and links to the request's edit screen.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-11 · The new request email can go elsewhere or be turned off

**Priority:** P2 · **As:** Admin · **Note:** Tick the box again and empty the field afterwards.

Steps:

1. In Settings, enter "team@example.com, nonsense" under "Send those emails to" and save.
2. As Sam, send a request and check the mail log.
3. Untick "Email the team when someone suggests a feature", save, and send another request as Sam.

Expected:

- Saving shows "These are not valid email addresses and were left out: nonsense". The field keeps team@example.com.
- The email goes to team@example.com.
- With the box unticked no email is sent.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-12 · Sending too fast or too often

**Priority:** P2 · **As:** Sam

Steps:

1. Reload the board page, fill in the title quickly and press "Send request" within three seconds.
2. Send six requests within an hour.

Expected:

- The first request is still sent: the form waits a moment by itself.
- The sixth request is refused with "You have sent several requests in a short time. Try again later."

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-13 · A board limited to some categories

**Priority:** P2 · **As:** Admin · **Note:** Untick the category afterwards.

Steps:

1. Edit the board, tick one category under Categories and update.
2. Open the board page.

Expected:

- The form's Category list offers only that category.
- The board's Category filter offers only that category.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SUB-14 · Shortcodes and markup in a request do nothing

**Priority:** P2 · **As:** Sam

Steps:

1. Send a request with the details: [gallery] <script>alert(1)</script> <b>bold</b>
2. As Admin, publish it and open its page.

Expected:

- The page shows [gallery] as text and the word "bold" as plain text. No gallery and no alert appear.

Result: ☐ Passed ☐ Failed ☐ Blocked

### REQ · The request page

#### REQ-01 · A request's page shows everything about it

**Priority:** P1 · **As:** Guest

Steps:

1. Open a request from the board.

Expected:

- The page shows the title, a link "← Back to Feature Requests", the vote button, the status, the category, the description and the comments.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### REQ-02 · The back link goes to the board's page

**Priority:** P1 · **As:** Guest

Steps:

1. Press "← Back to Feature Requests".
2. As Admin, edit the board, set "Board page" to "— Not set —" and update. Reload the request.
3. Open the board page once, then reload the request.

Expected:

- The link opens the page that shows the board.
- With no page set, the request has no back link.
- After the visit to the board page, the board knows its page again and the link is back.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### REQ-03 · A request that waits for approval is not public

**Priority:** P2 · **As:** Guest

Steps:

1. As Admin, copy the address of a pending request's preview.
2. Open the request's address in a private window.

Expected:

- The guest gets "Page not found".

Result: ☐ Passed ☐ Failed ☐ Blocked

#### REQ-04 · A request with a password shows nothing before it is unlocked

**Priority:** P2 · **As:** Guest

Steps:

1. As Admin, set the visibility of a request with an official response to Password protected.
2. Open it as a guest.

Expected:

- The page asks for the password. It shows no vote button, no status and no official response.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### REQ-05 · No author line on request pages

**Priority:** P2 · **As:** Guest

Steps:

1. Open a request with Twenty Twenty-Five active.

Expected:

- The page has no "Written by" line and no post categories.

Result: ☐ Passed ☐ Failed ☐ Blocked

### COM · Comments and official responses

#### COM-01 · Visitors comment on a request

**Priority:** P1 · **As:** Sam

Steps:

1. Write a comment on a request and send it.

Expected:

- The comment appears, or waits for moderation, as your Discussion settings say.
- Sam's comment form has no "Post as the official response" box.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### COM-02 · A moderator posts the official response

**Priority:** P1 · **As:** Maya

Steps:

1. As Maya, write a comment on a request, tick "Post as the official response" and send it.

Expected:

- A box "Official response" appears below the description, with the text, Maya's name and the date.
- In the comments, the same comment carries the label "Official response".

Result: ☐ Passed ☐ Failed ☐ Blocked

#### COM-03 · Removing the mark next to Reply, and putting it back

**Priority:** P1 · **As:** Maya

Steps:

1. On the request's page, find Maya's comment and press "Remove official mark", on the same line as Reply.
2. Press "Mark as official response" on that comment.

Expected:

- The box below the description and the label disappear.
- Both are back.
- The box itself has no such link, and comments by Sam have none.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### COM-04 · The mark on the comment's edit screen

**Priority:** P1 · **As:** Maya

Steps:

1. Open Comments in the admin and edit Maya's comment.
2. Untick "Official response" in the Save box and update.

Expected:

- The request's page no longer shows the official response.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### COM-05 · The mark on the Comments screen

**Priority:** P2 · **As:** Maya

Steps:

1. On the Comments screen, point at Maya's comment and press "Mark as official response".

Expected:

- The action changes to "Remove official mark", and the request's page shows the response again.
- Comments by subscribers have no such action.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### COM-06 · The newest official response is the one pinned

**Priority:** P2 · **As:** Maya

Steps:

1. Post a second official response on the same request.

Expected:

- The box below the description shows the newer one.
- Both comments carry the label in the comments.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### COM-07 · Visitors see no moderator links

**Priority:** P1 · **As:** Sam

Steps:

1. As Sam, and as a guest, open the request with the official response.

Expected:

- Neither sees "Mark as official response" or "Remove official mark".

Result: ☐ Passed ☐ Failed ☐ Blocked

### FOL · Following a request

#### FOL-01 · Following with the button

**Priority:** P1 · **As:** Riley

Steps:

1. Open a request you have not voted for. Press Follow.
2. Reload the page.
3. Press "Stop following".

Expected:

- Before: the line reads "Get an email when the status changes." with a Follow button.
- After: it reads "You get an email when the status changes." with a "Stop following" button, also after the reload.
- After stopping, the first line is back.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### FOL-02 · Voting follows, and taking the vote back ends it

**Priority:** P1 · **As:** Riley

Steps:

1. Open another request and press its vote button.
2. Press the vote button again.

Expected:

- After the vote the line changes to "You get an email when the status changes." without a reload.
- After taking the vote back it changes back.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### FOL-03 · Choosing to follow outlasts the vote

**Priority:** P1 · **As:** Riley

Steps:

1. Vote for a request, press "Stop following", then press Follow.
2. Take the vote back.

Expected:

- Riley still follows the request.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### FOL-04 · Guests cannot follow

**Priority:** P1 · **As:** Guest

Steps:

1. Open a request in a private window.

Expected:

- There is no line about emails and no Follow button.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### FOL-05 · Whoever suggests a request follows it

**Priority:** P1 · **As:** Sam

Steps:

1. As Sam, send a request. As Admin, publish it.
2. As Sam, open the request.

Expected:

- The request's page says "You get an email when the status changes."

Result: ☐ Passed ☐ Failed ☐ Blocked

#### FOL-06 · The site can stop voters from following

**Priority:** P2 · **As:** Riley · **Note:** Tick the box again afterwards.

Steps:

1. As Admin, untick "A user who votes for a request also follows it" in Settings and save.
2. As Riley, vote for a request.
3. Press Follow.

Expected:

- The vote does not make Riley follow.
- The Follow button still works.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### FOL-07 · The site can turn following off

**Priority:** P2 · **As:** Riley · **Note:** Tick the box again afterwards.

Steps:

1. As Admin, untick "Let users follow requests, and email the followers of a request when its status changes" and save.
2. As Riley, open a request.
3. As Admin, open a request's edit screen and Quick Edit.

Expected:

- There is no line about emails and no Follow button.
- The Request status box and Quick Edit have no "Email followers…" box.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### FOL-08 · The profile lists followed requests

**Priority:** P1 · **As:** Riley

Steps:

1. Follow two requests. Open Users → Profile.
2. Tick "Stop following" next to one of them and press Update Profile.

Expected:

- A section "Feature requests" has the box "Follow the requests I vote for or suggest", ticked, and lists the followed requests as links, newest first.
- After the update the ticked request is gone from the list, and its page shows the Follow button again.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### FOL-09 · A user can stop following by voting

**Priority:** P1 · **As:** Riley · **Note:** Tick the box again afterwards.

Steps:

1. In the profile, untick "Follow the requests I vote for or suggest" and press Update Profile.
2. Vote for a request.

Expected:

- The vote counts, and Riley does not follow the request.
- The Follow button still works.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### FOL-10 · The edit screen counts followers

**Priority:** P2 · **As:** Admin

Steps:

1. Open the edit screen of a request that Sam and Riley follow.

Expected:

- The Request details box says "2 followers".
- The Request status box has a ticked box "Email 2 followers when the status changes".

Result: ☐ Passed ☐ Failed ☐ Blocked

### EML · Emails to followers

These cases need the mail log (S3). To send waiting emails at once: Tools → Scheduled Actions → Pending → Run.

#### EML-01 · A status change emails the followers

**Priority:** P1 · **As:** Admin

Steps:

1. Make sure Sam follows a request that is Open.
2. On its edit screen, set Request status to Planned and press Update.
3. Open Tools → Scheduled Actions → Pending.
4. Wait five minutes, or press Run on the action. Look at the mail log.

Expected:

- The edit screen says the emails go out 5 minutes after the last change.
- A pending action `mwtfrs_send_status_emails` is listed, in the group `feature-request-system`, due in about five minutes.
- Sam gets one email. Subject: "[site name] Status changed to Planned: " and the title.
- The text greets Sam by name, names the request, says "Status: Planned (was Open)", links to the request, says why Sam gets it, and ends with "Stop following it:" and a link.
- The headers include List-Unsubscribe with the same link.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-02 · Whoever changes the status gets no email about it

**Priority:** P1 · **As:** Admin

Steps:

1. As Admin, follow a request that Sam follows too.
2. Change its status and send the emails (Run).

Expected:

- Sam gets the email. Admin gets none.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-03 · A change corrected in time is never announced

**Priority:** P1 · **As:** Admin

Steps:

1. Change a followed request from Open to Planned.
2. Within five minutes, change it back to Open.
3. Press Run on the waiting action.

Expected:

- No email is sent.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-04 · Changes in a row become one email

**Priority:** P1 · **As:** Admin

Steps:

1. Change a followed request from Open to Under Review, then to Planned, then to In Progress, within five minutes.
2. Press Run on every waiting action.

Expected:

- Each follower gets exactly one email. It says "Status: In Progress (was Open)".

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-05 · Changing a status without an email

**Priority:** P1 · **As:** Admin

Steps:

1. On a followed request's edit screen, untick "Email … followers when the status changes", change the status and press Update.
2. Open Tools → Scheduled Actions → Pending.

Expected:

- The status changed.
- No new action is waiting, and no email is sent.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-06 · The same box in Quick Edit and Bulk Edit

**Priority:** P2 · **As:** Admin

Steps:

1. In the requests list, Quick Edit a followed request: change "Request status", untick "Email followers when the status changes", update.
2. Select two followed requests, choose Bulk actions → Edit, set a status, leave the box ticked, update.

Expected:

- The Quick Edit change schedules no email.
- The Bulk Edit change schedules emails for both requests.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-07 · No email to someone who stopped following

**Priority:** P1 · **As:** Admin

Steps:

1. Change the status of a request that Sam and Riley follow.
2. Before the emails go out, as Riley press "Stop following" on the request.
3. Press Run on the waiting action.

Expected:

- Sam gets the email. Riley gets none.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-08 · The approval email

**Priority:** P1 · **As:** Admin

Steps:

1. As Sam, send a request. As Admin, publish it.
2. Press Run on the waiting action `mwtfrs_send_approval_email`, or wait a minute.

Expected:

- Sam gets an email. Subject: "[site name] Your feature request was approved: " and the title.
- It links to the request, says Sam will get an email when its status changes, and ends with the link to stop following.
- A request that is published at once (SUB-04, SUB-05) sends no approval email. A guest's request sends none.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-09 · The link in the email asks first, then stops the emails

**Priority:** P1 · **As:** Guest

Steps:

1. Copy the "Stop following it:" link from an email to Sam. Open it in a private window.
2. Check as Sam that the request is still followed.
3. Press "Stop following" on the page.
4. Open the same link again.

Expected:

- The page asks "Stop following this request?" and names the request. Nothing has changed yet.
- After the button: "You no longer follow this request". Sam no longer follows it.
- The link now shows "This link no longer works".

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-10 · Stopping all emails at once

**Priority:** P1 · **As:** Guest · **Note:** Tick the box in Sam's profile again afterwards.

Steps:

1. Make Sam follow three requests. Change the status of one and send the email.
2. Open the link from that email in a private window and press "Stop all emails".
3. As Sam, open the profile, then vote for a request.

Expected:

- Before pressing, the page says "You follow 3 requests on this site."
- After: "You no longer follow any request".
- In Sam's profile the list is empty and "Follow the requests I vote for or suggest" is unticked. The new vote does not make Sam follow.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-11 · The email says why you get it

**Priority:** P2 · **As:** Admin

Steps:

1. Send status emails to a user who voted, to one who pressed Follow, and to one who suggested the request.

Expected:

- The line before the link reads "because you voted for this request", "because you follow this request" or "because you suggested this request".

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-12 · Special characters arrive as typed

**Priority:** P2 · **As:** Admin

Steps:

1. Rename a followed request to Tom & Jerry's "night" mode and change its status. Send the email.

Expected:

- The subject and the text show the title exactly as typed, with & and the quotes.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-13 · A theme can change the text of the email

**Priority:** P2 · **As:** Admin · **Note:** Delete the copy afterwards.

Steps:

1. Copy `templates/emails/status-changed.php` from the plugin to `feature-request-system/emails/status-changed.php` in the active theme and change a sentence.
2. Change a followed request's status and send the email.

Expected:

- The email has the changed sentence.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-14 · Many followers are emailed in batches of 50

**Priority:** P3 · **As:** Admin · **Note:** Afterwards: `wp eval 'FeatureRequestSystem\Notifications\SubscriptionRepository::remove_request( REQUEST_ID );'`

Steps:

1. Take the ID of a published request and run: `wp eval 'for ( $i = 1; $i <= 120; $i++ ) { FeatureRequestSystem\Notifications\SubscriptionRepository::add( REQUEST_ID, 900000 + $i, "vote" ); }'` (with the ID in place of REQUEST_ID).
2. Change the request's status and open Tools → Scheduled Actions → Pending.

Expected:

- Three actions wait for that request.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### EML-15 · A mail program's own unsubscribe button

**Priority:** P3 · **As:** Admin · **Note:** Needs a site that really sends mail. Skip it on the Herd site.

Steps:

1. With real email set up through SMTP or a mail service, send a status email to an address you can read in Gmail or Apple Mail.
2. Use the mail program's unsubscribe button.

Expected:

- The mail program offers to unsubscribe, and using it stops the emails about that request.

Result: ☐ Passed ☐ Failed ☐ Blocked

### ADM · Admin screens

#### ADM-01 · The requests list

**Priority:** P1 · **As:** Admin

Steps:

1. Open Feature Requests.
2. Press the Votes column heading twice.

Expected:

- The columns are Title, Votes, Status, Board, Categories, comments and Date. Each status has its coloured dot.
- The list sorts by votes, in both directions.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-02 · Filters on the list

**Priority:** P1 · **As:** Admin

Steps:

1. Choose a status in "All statuses" and press Filter.
2. Choose a category and press Filter.

Expected:

- The list shows only matching requests each time.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-03 · Quick Edit changes the status

**Priority:** P1 · **As:** Admin

Steps:

1. Point at a request and press Quick Edit.
2. Choose another "Request status" and press Update.

Expected:

- Quick Edit opens with the request's current status selected.
- The row shows the new status.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-04 · Bulk Edit changes the status of several requests

**Priority:** P1 · **As:** Admin

Steps:

1. Select three requests, choose Bulk actions → Edit and press Apply.
2. Set "Request status" to Under Review and press Update.
3. Bulk Edit three requests again, leave "— No Change —", and update.

Expected:

- All three requests are Under Review.
- With "— No Change —" their status stays as it is.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-05 · The menu shows how many requests wait

**Priority:** P1 · **As:** Admin

Steps:

1. Make two requests Pending.
2. Publish both.

Expected:

- The Feature Requests menu shows a badge with 2.
- The badge is gone when nothing waits.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-06 · The edit screen

**Priority:** P1 · **As:** Admin

Steps:

1. Open a request that Sam voted for and that a guest voted for (VOT-05).

Expected:

- The request opens in the classic editor.
- The Request details box shows the number of votes, Sam's name as a link, the number of guests, the followers, and who submitted the request.
- The Board box and the Request status box each have one dropdown.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-07 · A request added in the admin

**Priority:** P1 · **As:** Admin

Steps:

1. Press Add Feature Request, enter a title and text, and publish.

Expected:

- The request has the default board and the status Open without choosing them.
- It is on the board with 0 votes.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-08 · The settings page saves

**Priority:** P1 · **As:** Admin · **Note:** Set it back to Open afterwards.

Steps:

1. Open Feature Requests → Settings, change "Status of new requests" to Under Review and save.
2. As Sam, send a request. As Admin, look at its status.

Expected:

- The page says "Settings saved."
- The new request starts as Under Review.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-09 · Boards and their settings

**Priority:** P1 · **As:** Admin

Steps:

1. Under Feature Requests → Boards, add a board called Mobile app.
2. Edit it: the form has Who can vote, Who can suggest features, New requests, Board page and Categories.
3. Try to delete the board Feature Requests.

Expected:

- The new board is listed and can be chosen in the board block and on a request's edit screen.
- The default board has no Delete link.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-10 · Statuses and their settings

**Priority:** P1 · **As:** Admin

Steps:

1. Under Feature Requests → Statuses, add a status Beta with a colour and the position 45.
2. Edit it and tick "Closed for voting".
3. Try to delete the status Open.

Expected:

- Beta is listed between In Progress and Shipped, with its colour. The Voting column says Closed after the edit.
- Requests with the status Beta cannot be voted on.
- The default status has no Delete link.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-11 · Deleting a status moves its requests

**Priority:** P2 · **As:** Admin

Steps:

1. Give a followed request the status Beta. Send or cancel the waiting email.
2. Delete the status Beta.

Expected:

- The request has the default status again.
- No email is scheduled for that.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-12 · What editors and others can do

**Priority:** P1 · **As:** Maya

Steps:

1. As Maya, open the admin.
2. As Sam, open `/wp-admin/edit.php?post_type=mwtfrs_request`

Expected:

- Maya sees Feature Requests with All Requests, Add Feature Request, Boards, Statuses and Categories, but no Settings.
- Maya can publish, edit and trash requests.
- Sam has no Feature Requests menu and is refused on that address.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-13 · Trash and restore

**Priority:** P2 · **As:** Admin

Steps:

1. Move a request with votes to the Trash. Look at the board.
2. Restore it and publish it again.

Expected:

- The trashed request is not on the board.
- After restoring, it is back with its votes.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-14 · Moving a request to another board

**Priority:** P2 · **As:** Admin

Steps:

1. On a request's edit screen, choose Mobile app in the Board box and update.

Expected:

- The request is gone from the Feature Requests board and appears on a page that shows the Mobile app board.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-15 · The Scheduled Actions screen

**Priority:** P2 · **As:** Admin

Steps:

1. Open Tools → Scheduled Actions.

Expected:

- The plugin's actions are listed with the group `feature-request-system`: `mwtfrs_send_status_emails` and `mwtfrs_send_approval_email`.
- Finished actions show a log with when they ran.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ADM-16 · The menu icon takes the admin colours

**Priority:** P3 · **As:** Admin

Steps:

1. Look at Feature Requests in the admin menu, then move the pointer over it.
2. Open Feature Requests.
3. Under Users → Profile, choose another Admin Color Scheme and save.

Expected:

- The menu shows the plugin's logo, a stack of request cards, in the colour of the other menu icons.
- Under the pointer, and on the plugin's own screens, it changes colour as the other icons do.
- In the other colour scheme, the icon has that scheme's colours.

Result: ☐ Passed ☐ Failed ☐ Blocked

### PRV · Privacy

#### PRV-01 · Submitters stay private

**Priority:** P1 · **As:** Guest

Steps:

1. Open the board and a request that Sam suggested. View the page source of both and search for "Sam" and "frs-sam".
2. Open `/wp-json/wp/v2/mwtfrs_request` in the private window and search the output for the same words.

Expected:

- Neither Sam's name nor the email address appears.
- The REST output has no author and no submitter fields.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### PRV-02 · Exporting a person's data

**Priority:** P1 · **As:** Admin

Steps:

1. Open Tools → Export Personal Data. Enter frs-sam@example.com, untick the confirmation email, and send the request.
2. Download the export from the row and open index.html from the zip.

Expected:

- The export has the groups "Feature requests", "Feature request votes" and "Followed feature requests", with Sam's requests, votes and followed requests.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### PRV-03 · Erasing a person's data

**Priority:** P1 · **As:** Admin

Steps:

1. Note the vote count of a request Sam voted for.
2. Open Tools → Erase Personal Data. Enter frs-sam@example.com, untick the confirmation email, send the request, then press "Erase personal data" on the row.
3. Open that request's edit screen, then the board as Sam.

Expected:

- WordPress says personal data was found and erased, and shows the plugin's two notes about requests and votes that were kept.
- The vote count is the same as before. The Request details box no longer names Sam among the voters.
- As Sam, the vote button is not dark any more, and the profile lists no followed requests.
- A request Sam suggested still exists. Its Request details box no longer says who submitted it.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### PRV-04 · Text for the privacy policy

**Priority:** P2 · **As:** Admin

Steps:

1. Open Settings → Privacy, then the Policy Guide tab, and open the section "Feature Request System".

Expected:

- The section offers a text about requests, votes, the cookie `mwtfrs_vid`, following and the counters per IP address.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### PRV-05 · The guest cookie is only set when a guest votes

**Priority:** P2 · **As:** Guest

Steps:

1. With guest voting allowed (VOT-05), open the board in a new private window and look at the cookies in the developer tools.
2. Vote, and look again.

Expected:

- Before the vote there is no cookie from the plugin.
- After the vote there is a cookie `mwtfrs_vid`, HttpOnly, that lasts one year.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### PRV-06 · No IP addresses are stored

**Priority:** P2 · **As:** Admin

Steps:

1. Run: `wp db query "SELECT * FROM wp_mwtfrs_votes ORDER BY id DESC LIMIT 3"`

Expected:

- The rows hold a request ID, a long hash, a user ID or 0, and a date. No IP address.

Result: ☐ Passed ☐ Failed ☐ Blocked

### SEC · Security

An independent review of the code found these weak spots, and they were closed. The cases check that they stay closed. Where a case says ID, use the ID of a published request: it is in the address of its edit screen (post=ID).

#### SEC-01 · A link on the site cannot cast a vote

**Priority:** P1 · **As:** Guest · **Note:** The board lets guests vote, as in VOT-05. Set it back afterwards.

Steps:

1. Note the votes of a published request.
2. Run: `curl -sk -o /dev/null -w "%{http_code}\n" -H "Referer: https://test-wp-plugins.test/" "https://test-wp-plugins.test/wp-json/mwtfrs/v1/requests/ID/vote?_method=POST"`
3. Reload the board.

Expected:

- The command prints 403. This is what a link or an image on a page of the site would send.
- The request has as many votes as before.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SEC-02 · A vote sent from another site is refused

**Priority:** P1 · **As:** Guest · **Note:** The board lets guests vote, as in VOT-05. Set it back afterwards.

Steps:

1. Run: `curl -sk -o /dev/null -w "%{http_code}\n" -X POST -H "Origin: https://evil.example" "https://test-wp-plugins.test/wp-json/mwtfrs/v1/requests/ID/vote"`
2. Run the same command with `-H "Origin: https://test-wp-plugins.test"` instead.
3. Reload the board.

Expected:

- The first command prints 403.
- The second prints 200: that is what the vote button on the site sends.
- The request has one vote more: the second command voted as a new guest.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SEC-03 · A web address in a request stays text

**Priority:** P1 · **As:** Sam

Steps:

1. As Sam, suggest a feature. In the details, write a sentence, an empty line, the address of a video on YouTube on a line of its own, an empty line, and another sentence.
2. As Admin, open the request from the list of requests.
3. Approve it and open its page.

Expected:

- In the editor the address is text, not a video player.
- On the page the address is text, not a video player, and no frame from another site is loaded.
- On the board, the summary shows the address as text too.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SEC-04 · The page behind the link in the email keeps the link to itself

**Priority:** P2 · **As:** Guest · **Note:** Needs an email from EML-01.

Steps:

1. Open the "Stop following it" link from an email, as in EML-09.
2. Press "Keep following and go to the request".
3. On the request page, open the browser console and type: `document.referrer`

Expected:

- The answer is an empty text: "". The request page, and any script on it, did not learn the address with the secret.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SEC-05 · The settings page shows what was typed as text

**Priority:** P2 · **As:** Admin · **Note:** Set the field back afterwards.

Steps:

1. Open Feature Requests → Settings. In "Send those emails to", enter: `team@example.com, <b>bold</b>`
2. Save.

Expected:

- A notice says that `<b>bold</b>` is not a valid email address and was left out. The tags show as typed: the word is not bold.
- The field holds team@example.com only.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### SEC-06 · Deleting a user keeps the vote count

**Priority:** P2 · **As:** Admin

Steps:

1. Run: `wp user create frs-temp frs-temp@example.com --role=subscriber`
2. Log in as that user, vote for a published request, and note its votes and its ID.
3. As Admin, open the request's edit screen and note the followers in the Details box.
4. Run: `wp user delete frs-temp --yes`
5. Reload the board and the edit screen.
6. Run: `wp db query "SELECT user_id FROM wp_mwtfrs_votes WHERE request_id = ID"`

Expected:

- The request has as many votes as before the user was deleted.
- It has one follower fewer.
- No row has the ID of the deleted user. The vote is still there, with 0 as its user.

Result: ☐ Passed ☐ Failed ☐ Blocked

### NJS · Without JavaScript

Turn JavaScript off in the browser's developer tools (in Chrome: Command menu → "Disable JavaScript") and reload.

#### NJS-01 · Voting

**Priority:** P1 · **As:** Sam

Steps:

1. Press a vote button on the board.
2. Press it again.

Expected:

- The page reloads at that request with "Your vote was added." and the count is one higher.
- The second press shows "Your vote was removed."

Result: ☐ Passed ☐ Failed ☐ Blocked

#### NJS-02 · Suggesting a feature

**Priority:** P1 · **As:** Sam

Steps:

1. Fill in the form, wait at least three seconds after the page loaded, and press "Send request".

Expected:

- The page reloads at the form with "Thanks! Your request will appear on the board once it is approved."

Result: ☐ Passed ☐ Failed ☐ Blocked

#### NJS-03 · Following

**Priority:** P1 · **As:** Sam

Steps:

1. On a request, press Follow, then "Stop following".

Expected:

- The page reloads with "You now follow this request.", then with "You no longer follow this request."

Result: ☐ Passed ☐ Failed ☐ Blocked

#### NJS-04 · Filters and page links

**Priority:** P2 · **As:** Guest

Steps:

1. Use the search, the filters and the page links.

Expected:

- All of them work as with JavaScript.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### NJS-05 · Voting on a list and on a card

**Priority:** P2 · **As:** Sam

Steps:

1. Open Home, the page with the list and the card from LST-05.
2. Press the vote button on the card.
3. Press the vote button of another request in the list.

Expected:

- The page reloads at the card, with "Your vote was added." in it and the count one higher.
- After the second press, the page reloads at that request in the list, with the message.

Result: ☐ Passed ☐ Failed ☐ Blocked

### CCH · Page caches

#### CCH-01 · Every guest gets the same page

**Priority:** P1 · **As:** Guest · **Note:** The board has its default settings: guests can neither vote nor suggest. Where guests can suggest, the form carries a time stamp that differs with every page view, which is fine for a cache.

Steps:

1. Run twice and compare: `curl -s https://test-wp-plugins.test/feature-requests/ | md5` (use the address of your board page; add `-k` if curl rejects the certificate).
2. Run: `curl -s ADDRESS | grep -o '"nonce":"[^"]*"'`
3. Run: `curl -s -D - -o /dev/null ADDRESS | grep -i set-cookie`

Expected:

- Both runs give the same result.
- Every nonce in the page is empty: `"nonce":""`
- The site sets no cookie for a guest who only looks.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### CCH-02 · Votes show on a cached page

**Priority:** P2 · **As:** Guest · **Note:** Optional: repeat with a page cache plugin active.

Steps:

1. With guest voting allowed, vote in a private window and reload a few times.

Expected:

- The page first shows the plain button, then turns it dark for the request you voted for.

Result: ☐ Passed ☐ Failed ☐ Blocked

### ACC · Accessibility

The automated checks (axe) pass. These cases cover what a machine cannot check.

#### ACC-01 · Everything works with the keyboard

**Priority:** P1 · **As:** Sam

Steps:

1. On the board page, use only Tab, Shift+Tab, Enter and Space.
2. Go through the form, the filters, a vote button, the page links, and on a request the Follow button and the comment form.

Expected:

- Every control can be reached, in the order it appears.
- The control that has the focus is always clearly marked.
- Enter or Space presses the vote button and the Follow button.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ACC-02 · A screen reader hears what happens

**Priority:** P1 · **As:** Sam

Steps:

1. Turn on VoiceOver (Command+F5) and move to a vote button. Press it.
2. On a request, press Follow.
3. Send the form.

Expected:

- The button is read as its vote count and "Vote for" with the title, as a toggle button, pressed or not.
- After the vote, "Vote added." is read. After following, "You now follow this request."
- The form's message is read when it appears.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ACC-03 · Zoom and narrow screens

**Priority:** P2 · **As:** Guest

Steps:

1. Zoom the browser to 200%.
2. Set the window to 320 pixels wide (device toolbar in the developer tools).

Expected:

- Nothing overlaps or is cut off, and the page does not scroll sideways.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### ACC-04 · Your theme's colours

**Priority:** P2 · **As:** Guest

Steps:

1. Run Lighthouse or the axe browser extension on the board page and on a request, in the theme you will use.

Expected:

- No contrast problem is reported inside the board, the form or the request details.

Result: ☐ Passed ☐ Failed ☐ Blocked

### THM · Themes and compatibility

#### THM-01 · A block theme

**Priority:** P1 · **As:** Sam

Steps:

1. With Twenty Twenty-Five active, look at the board page, the form with similar requests, and a request with an official response and comments.

Expected:

- Everything lines up with the theme's text, uses its fonts and colours, and nothing looks broken.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### THM-02 · A classic theme

**Priority:** P1 · **As:** Sam · **Note:** Activate Twenty Twenty-Five again afterwards.

Steps:

1. Activate Twenty Twenty-One (S5) and look at the same three screens.
2. Vote, follow and send a request.

Expected:

- The pages look right in that theme too. The vote button is clearly dark when pressed.
- On a request, the vote button and status are above the description, once.
- Everything works.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### THM-03 · A dark style

**Priority:** P2 · **As:** Sam · **Note:** Switch back afterwards.

Steps:

1. In Appearance → Editor → Styles, choose a dark style variation of Twenty Twenty-Five and look at the board, the form and a request.

Expected:

- Text, field borders and buttons are readable. A pressed vote button still stands out.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### THM-04 · Another popular theme

**Priority:** P2 · **As:** Sam

Steps:

1. Install and activate a theme you care about, such as Astra, Kadence or GeneratePress, and look at the three screens.

Expected:

- The pages look right, and the vote button, the Follow button and the form fields are easy to recognise.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### THM-05 · Turning the plugin's styles off

**Priority:** P2 · **As:** Admin · **Note:** Remove the line afterwards.

Steps:

1. Add to `wp-content/mu-plugins/frs-mail-log.php`: `add_filter( 'mwtfrs_load_styles', '__return_false' );`
2. Reload the board page.

Expected:

- The board and the form appear without the plugin's styles, and still work.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### THM-06 · A theme can replace a template

**Priority:** P2 · **As:** Admin · **Note:** Delete the copy afterwards.

Steps:

1. Copy `templates/board.php` from the plugin to `feature-request-system/board.php` in the active theme and change the text "Show requests".
2. Reload the board page.

Expected:

- The button shows the changed text.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### THM-07 · On a phone

**Priority:** P2 · **As:** Sam

Steps:

1. Open the board, the form and a request on a phone, or in the developer tools' device toolbar.

Expected:

- The layout fits the screen. Buttons and links are easy to hit.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### THM-08 · The oldest supported WordPress

**Priority:** P3 · **As:** Admin

Steps:

1. On a site with WordPress 6.6, install the zip and repeat VOT-01, SUB-01, FOL-01 and EML-01.

Expected:

- All four work.
- With a block theme, the request page uses the theme's own template for posts, which is expected on 6.6.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### THM-09 · Right-to-left languages

**Priority:** P3 · **As:** Admin · **Note:** Set the language back afterwards.

Steps:

1. Set the site language to Hebrew or Arabic under Settings → General and look at the board and a request.

Expected:

- The layout is mirrored: vote buttons are on the right, and nothing overlaps.

Result: ☐ Passed ☐ Failed ☐ Blocked

### CLI · WP-CLI and large boards

#### CLI-01 · Sample requests can be removed again

**Priority:** P2 · **As:** Admin · **Note:** Run `wp mwtfrs seed` again if you still need sample requests.

Steps:

1. Add a request of your own in the admin.
2. Run: `wp mwtfrs seed --delete`

Expected:

- The command says how many sample requests it deleted.
- Your own requests, and the requests made during testing, are still there.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### CLI-02 · Recounting repairs a wrong count

**Priority:** P2 · **As:** Admin

Steps:

1. Take the ID of a request and run: `wp post meta update ID _mwtfrs_votes 999`
2. Run: `wp mwtfrs recount`

Expected:

- The command says it fixed 1 vote count, and the request shows its real count again.

Result: ☐ Passed ☐ Failed ☐ Blocked

#### CLI-03 · A large board stays fast

**Priority:** P3 · **As:** Admin · **Note:** Afterwards: `wp mwtfrs seed --delete`

Steps:

1. Run: `wp mwtfrs seed --requests=1000 --votes=50000`
2. Open the board page and look at the "Waiting for server response" time in the developer tools' Network tab. Try a search and page 20 with "All statuses".
3. Sort the admin list by votes.

Expected:

- The server answers the board page in well under half a second. On the test machine it took about 70 ms.
- The admin list sorts without a noticeable wait.

Result: ☐ Passed ☐ Failed ☐ Blocked

## Reporting a problem

For each case that fails, note:

- the case ID, and the step where it went wrong;
- what you expected and what happened instead, with a screenshot if it is about looks;
- who you were logged in as, the theme, and the browser;
- anything in the browser console (red lines), and the last lines of `wp-content/debug.log` if `WP_DEBUG_LOG` is on.

## When the plan is done

The build is ready for release when every P1 case has passed, and every failed P2 case is either fixed or accepted by you.

Afterwards, clean up the test site:

```bash
cd ~/Herd/test-wp-plugins
wp mwtfrs seed --delete
rm wp-content/mu-plugins/frs-mail-log.php wp-content/frs-mail.log
wp user delete frs-maya frs-sam frs-riley --yes
```
