# Feature Request System: Rebuild Plan

Status: approved 2026-09-30 with the recommended option for every decision (D1–D6) · M0 to M8 done · Slug: `feature-request-system` (reserved on WordPress.org, SVN exists, trunk empty)

Previous attempts are kept for reference only:
- `../feature-request-system-0/` (v0, the original 2020-era plugin)
- `../feature-request-system-0.5/` (v0.5, the unfinished "v2" rewrite)

Nothing is copied over wholesale. Individual ideas are reused where they were right.

---

## 1. Goals

1. **Ship 1.0.0 to WordPress.org** under the reserved slug: a feature request board that works end to end, is secure and is pleasant to use.
2. **Stay small and WordPress-native.** Target about 3–5k lines of PHP for 1.0, not 15k.
3. **Stand out from the competitors** on *closing the loop* with voters and on being *block-native*, then on *customer-aware voting* (WooCommerce/EDD) in a later release.
4. **Leave room for a Pro add-on** later without restructuring the free plugin.

Non-goals for 1.0: analytics dashboards, AI features, SaaS sync, a React admin.

---

## 2. Competitive landscape

Data from the WordPress.org plugins API on 2026-09-30. Ratings are converted from percent to stars.

### Five direct competitors (free, self-hosted, on WordPress.org)

| Plugin | Active installs | Rating | Last update | Free-version highlights | Gaps / paywalled |
|---|---|---|---|---|---|
| **[IdeaPush](https://wordpress.org/plugins/ideapush/)** (Northern Beaches Websites, 2018) | 700+ | 4.5★ (31) | 2026-05 | Boards via shortcode; guest submit and vote; up/down votes; daily vote and idea limits; vote threshold changes status automatically; honeypot and maths captcha; rich notifications (admin, author, voters) with templates; tags and attachments | Duplicate suggestions are Pro; dated UI; shortcode only |
| **[Simple Feature Requests](https://wordpress.org/plugins/simple-feature-requests/)** (2017) | 100+ | 4.0★ (10) | 2026-06 | Submission, voting, native WP comments, search before submitting, Latest/Top/My Requests filters, fixed statuses | Roadmap, categories, notifications, vote limits, bulk status changes and image uploads are **all Pro** |
| **[Changeloger](https://wordpress.org/plugins/changeloger/)** (Spider Themes, 2024) | 300+ | 5.0★ (5) | 2026-09 | Changelog / release notes plus a Kanban roadmap plus a feature request board with upvotes; blocks and shortcode | Changelog-first; the request board is secondary |
| **[WP Roadmap](https://wordpress.org/plugins/wp-roadmap/)** (Iqonic Design, 2020) | 100+ | 3.9★ (7) | 2025-12 | Boards, Kanban status board, up/down votes, Elementor widget | Admin-driven; weak on user submission and moderation; Elementor-centric |
| **[FeatureQuest](https://wordpress.org/plugins/featurequest/)** (Invader Digital, 2026) | <10 | n/a | 2026-08 | One shortcode plus a block; unlimited boards; account **or verified-email** participation; IP guard; Turnstile/reCAPTCHA/hCaptcha; trending sort; live duplicate detection; moderation queue; users can edit their own requests; requester told on completion | New and unproven; notifies only the requester, not the voters |

### Also in the space

- **Assist Roadmap** (2026-09): multiple roadmaps, works without JavaScript, feedback → roadmap conversion.
- **Pufferbay** (2026-07): changelog, CSV import/export, privacy exporter.
- **RIACO Feedback** (2025-12): Kanban roadmap, votes keyed on a hash of IP + user agent.
- **SimpleBoards** (2026-05): pitches SEO value of feedback on your own domain.
- **Boomerang** (2023): Canny-like UI, per-board options.
- **RoadMapWP**, **Product Feature Request** (ThemeHigh, WooCommerce product-level).

SaaS embeds (Sleekplan, ProductLift, Usero) are not direct competitors, but they set the UX bar.

### What this tells us

- **The market is small and fragmented.** The leader has about 700 installs, and the whole category is under about 2k. Revenue here comes from a Pro add-on sold to plugin, theme and SaaS makers, not from install counts.
- **New entrants are arriving fast.** Five appeared in the last 12 months. A "me too" plugin won't be noticed; we need a clear angle and very high polish.
- **The basics are expected:** submission, voting, statuses, moderation, duplicate detection, a roadmap view, spam protection and a block.
- **Openings nobody covers well:**
  1. **Closing the loop.** Notify *every voter and follower* when a request changes status or ships, with a proper subscription and unsubscribe model. Most competitors notify only the author, or put this behind Pro.
  2. **Customer-aware voting.** "This request is wanted by 14 paying customers." Show WooCommerce/EDD (later Freemius) customer data on requests, run customer-only boards, and optionally weight votes. This is Canny's most valuable feature and no free WordPress.org plugin has it.
  3. **Merging duplicates while keeping votes.** Almost nobody offers it for free.
  4. **Block-native and cache-friendly.** Use the Interactivity API, `theme.json` styling, no jQuery, work without JavaScript, and stay correct behind full-page caching.
  5. **Easy switching.** Importers from IdeaPush and Simple Feature Requests.

---

## 3. Positioning

> **"The feature request board that tells your users when you ship."**
> Collect requests, let users vote, show your roadmap, and automatically notify everyone who asked once it's done. Block-native, private by design, and free.

Differentiators, in delivery order:
1. Close the loop (1.0).
2. Block-native and fast (1.0).
3. Merge with votes kept, plus a roadmap view (1.1).
4. Importers (1.2).
5. Customer-aware voting (1.3).

---

## 4. Feature list

✅ = in that release. Everything up to 1.3 is planned as **free**. The Pro candidates are listed separately.

### 1.0.0: MVP (WordPress.org launch)

**Boards and requests**
- ✅ Multiple **boards** (for example, one per product), each with its own settings: who can submit, who can vote, whether moderation is on, and whether guests are allowed.
- ✅ **Requests**: title, description (plain text with line breaks), category, and who submitted it (kept private).
- ✅ **Statuses**: Open, Under Review, Planned, In Progress, Shipped, Declined. Each has a colour, an order, an "is closed" flag (closed requests stop accepting votes) and a "show on roadmap" flag. Admins can add, rename and reorder them.
- ✅ **Categories** per site, optionally limited per board.
- ✅ **Moderation**: new requests are held as `pending` by default, with approve and reject in the admin.

**Voting**
- ✅ Upvote and un-vote in one click. See decision D3 for downvotes.
- ✅ One vote per user, enforced by the database.
- ✅ Guest voting (off by default, enabled per board), identified by a random first-party cookie token and rate-limited by IP hash.
- ✅ Vote count stored on the request, so boards can sort by votes cheaply.
- ✅ Per-user "voted" state loaded after page render, so boards stay correct behind page caching.

**Front end**
- ✅ **Board block** and `[feature_requests board="…"]` shortcode: list, search, filter by status and category, sort (Top, New), pagination.
- ✅ **Submit form**, inline on the board or as its own block, with a **"similar requests" list shown as you type** to cut duplicates.
- ✅ **Single request page**: description, status badge, vote button, voter count, comments, and a highlighted "Official response".
- ✅ Works without JavaScript: voting and submitting fall back to plain form posts.
- ✅ Styling follows `theme.json` (colours, fonts, spacing). The stylesheet is small and can be turned off.
- ✅ Templates can be overridden by the theme (`yourtheme/feature-request-system/…`).

**Comments**
- ✅ Native WordPress comments on requests, which brings moderation, Akismet and threaded replies for free.
- ✅ A moderator's reply can be marked as the **official response**. The newest one is pinned below the description, and each is labelled in the comments.

**Notifications ("close the loop")**
- ✅ Logged-in voters follow the requests they vote for (the site can turn that off, and so can each user). Users can also follow a request without voting, and follow the requests they suggest.
- ✅ Emails when a request's **status changes** (to its followers), on a **new request** (to admins or a list), and on **approval** (to the user who suggested it).
- ✅ Status emails wait five minutes, so a status picked by mistake can be corrected before anyone hears of it, and changes in a row become one email. A moderator can also change a status without an email.
- ✅ One-click **unsubscribe** links that work without logging in, and a list of followed requests on each user's profile.
- ✅ Batched sending in the background, so a status change doesn't send 2,000 emails in one request.
- Visitors without an account are not emailed in 1.0. They can follow once their address is verified, in 1.1.

**Anti-abuse**
- ✅ Honeypot field, a minimum time before a form can be submitted, and rate limits per user or IP hash for votes and submissions.
- ✅ Akismet check on submissions when Akismet is active.
- ✅ Hook for a captcha provider (the Turnstile adapter ships in 1.1).

**Admin**
- ✅ Requests list with columns for votes (sortable), status, board and category, plus filters for board, status and category.
- ✅ Status can be changed from Quick Edit and Bulk Edit.
- ✅ A count badge on the menu for pending requests.
- ✅ Settings page with global defaults, a board settings screen, and a status manager.
- ✅ Each board knows the **page that shows it**, so a request's page can link back to its board.
- ✅ Voters list on each request (users, and a guest count).

**Platform**
- ✅ WordPress privacy **exporter and eraser**, plus suggested privacy policy text.
- ✅ A **"How we use your data" note** on the submit form for visitors without an account. The site owner writes it in settings.
- ✅ `uninstall.php` that removes data only if the admin opted in.
- ✅ Database version tracking with an upgrade routine.
- ✅ Fully translatable; accessibility target is WCAG 2.2 AA for the front end.

### 1.1: Roadmap and admin power

- ✅ **Roadmap block**: Kanban columns by status for statuses flagged "show on roadmap". Each column pages on its own.
- ✅ **Merge requests**: votes and subscribers move to the target (duplicates removed), the source redirects to the target, and subscribers of the source are notified.
- ✅ **Trending** sort, based on votes in the last 30 days (recalculated hourly).
- ✅ **Cloudflare Turnstile** adapter.
- ✅ Per-user vote limits per board, IdeaPush-style.
- ✅ Vote threshold automatically changes a request's status.
- ✅ **CSV export** of requests and voters.
- ✅ Dashboard widget showing the pending queue and top requests this week.
- ✅ **Email-verified guests** (magic link), so guests can vote and submit with less abuse.

### 1.2: Changelog and switching

- ✅ **Changelog / Releases**: a release post type that links to shipped requests. Publishing a release notifies the subscribers of every linked request ("🎉 shipped in 2.3"). Comes with a Changelog block.
- ✅ **Importers** for IdeaPush and Simple Feature Requests (ideas, statuses, votes and comments where possible).
- ✅ Outgoing **webhooks** for request created, status changed and request shipped.

### 1.3: Customer-aware voting

- ✅ WooCommerce and Easy Digital Downloads integration:
  - Customer badge on voters.
  - "N customers / $X lifetime value" on each request (admin only).
  - Customer-only boards.
  - Optional vote weighting by product ownership.
- ✅ Sort in the admin by customer demand.

### Pro candidates (later, separate plugin, not planned now)

- Freemius / Paddle / Stripe customer data.
- Slack, Discord, GitHub, Linear and Jira sync.
- In-app feedback widget for SaaS and plugin dashboards, with SSO.
- Private boards per membership level.
- AI tools: duplicate clustering and summarising.
- Custom fields and white-labelled emails.

### Explicitly out of scope

- Analytics dashboards.
- A React admin.
- Storing raw IP addresses.
- Remote assets (fonts, CDNs).
- Tracking or telemetry.
- Elementor or other page builder widgets (the shortcode covers them).

---

## 5. Architecture

### 5.1 Principles (lessons from v0 and v0.5)

1. **Every identifier comes from one constant.** Post type, taxonomies, meta keys, REST namespace, option names, table names and hook names live in `src/Plugin.php` or small `*::NAME` constants. String literals for these are banned; code review and a grep check in CI enforce it.
2. **Build in vertical slices, each with an integration test.** A feature isn't done until a test drives it through the real entry point (REST, form post or render) down to the database. There is no building one layer at a time.
3. **No code without a caller.** No speculative helpers, unused tables, empty Loader classes or wrapper classes around one-line core functions.
4. **Trust boundaries are explicit.**
   - Every REST route has a real `permission_callback`.
   - Public responses are built from allow-listed fields and never include emails, IPs or user agents.
   - Client IP comes from `REMOTE_ADDR`, with a `mwtfrs_client_ip` filter for sites behind a trusted proxy. An IPv6 address counts as its /64 network, and an IPv4 address written as IPv6 as that IPv4 address (M8).
5. **Stored counts are always initialised.** Every request has `_mwtfrs_votes = 0` from the moment it's created (v0 lost posts from vote-sorted lists because this meta was missing).
6. **Use core wherever it fits.** Comments, post statuses, taxonomies, capabilities, the Settings API, privacy tools, list tables and the Interactivity API.
7. **One coding standard, checked in CI:** WPCS 3, PHPStan level 6, ESLint and Stylelint from `@wordpress/scripts`.

### 5.2 Naming

| Thing | Value |
|---|---|
| Slug and text domain | `feature-request-system` |
| Main file | `feature-request-system.php` |
| PHP namespace | `FeatureRequestSystem\` |
| Prefix (options, meta, hooks, tables, handles, CSS) | `mwtfrs` / `_mwtfrs_` / `mwtfrs-`. **Decision D1.** |
| Post type | `mwtfrs_request` |
| Taxonomies | `mwtfrs_board`, `mwtfrs_status`, `mwtfrs_category` |
| REST namespace | `mwtfrs/v1` |
| Blocks | `mwtfrs/board`, `mwtfrs/submit`, `mwtfrs/request` (1.0); `mwtfrs/roadmap` (1.1); `mwtfrs/changelog` (1.2) |
| Shortcodes | `[feature_requests]`, `[feature_request_form]`, `[feature_roadmap]` |

### 5.3 Data model

**Post type `mwtfrs_request`**
- `public`, `has_archive => false`, and a rewrite slug `feature-requests`.
- `show_in_rest => true`, so the core REST API and the Site Editor see the post type.
- Edited in the classic editor (the block editor is turned off for this post type), where board and status are single-choice dropdowns.
- `capability_type => ['mwtfrs_request', 'mwtfrs_requests']`, `map_meta_cap => true`.
- Post status: `pending` means awaiting moderation, `publish` means visible. Rejected requests go to `trash`.
- Meta:
  - `_mwtfrs_votes` (int, always set)
  - `_mwtfrs_trending` (int, 1.1)
  - `_mwtfrs_merged_into` (int, 1.1)
  - `_mwtfrs_submitter_id` (logged-in submitter), `_mwtfrs_submitter_name` and `_mwtfrs_submitter_email` (a guest's details): private, never exposed
  - `_mwtfrs_flagged_as_spam` (set when a spam check held the request for review)
  - `_mwtfrs_status_notice` (the status announcement that waits to be emailed, or was emailed last; M7)
- Comment meta: `_mwtfrs_official` marks a comment as an official response (M5).
- User meta: `mwtfrs_auto_follow` holds `0` for a user who turned off following the requests they vote for or suggest (M7).
- No `author` support: front-end submitters are not WordPress post authors (`post_author` is 0), so their names stay out of author archives, oEmbed data and the public users endpoint. Built in M4.

**Taxonomies**
- `mwtfrs_board`: not hierarchical, one per request. Term meta holds the board's settings as one array: who can vote, who can submit (each: everyone, logged-in users or nobody), moderation, the board's page, and the categories its requests can have (M8; none chosen means every category).
- `mwtfrs_status`: exactly one per request. Term meta: `color`, `order`, `is_closed`, `on_roadmap`. Default statuses are seeded on activation. **Status lives only in this taxonomy, never in meta as well** (v0.5 kept both).
- `mwtfrs_category`: hierarchical and optional.

**Custom tables** (created with `dbDelta`, version tracked in the `mwtfrs_db_version` option)

```sql
{prefix}mwtfrs_votes
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  request_id   BIGINT UNSIGNED NOT NULL
  voter_key    CHAR(64)        NOT NULL   -- sha256('u:'.user_id) or sha256('g:'.cookie_token)
  user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0
  vote         TINYINT         NOT NULL DEFAULT 1   -- +1 now; -1 reserved (D3)
  weight       SMALLINT        NOT NULL DEFAULT 1   -- 1.3 customer weighting
  created_at   DATETIME        NOT NULL
  UNIQUE KEY request_voter (request_id, voter_key)
  KEY user_id (user_id)
  KEY request_created (request_id, created_at)      -- trending

{prefix}mwtfrs_subscriptions                       -- built in M7, database version 2
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  request_id   BIGINT UNSIGNED NOT NULL
  user_id      BIGINT UNSIGNED NOT NULL
  token        CHAR(32)        NOT NULL   -- secret of the unsubscribe link
  source       VARCHAR(20)     NOT NULL   -- vote | follow | author
  created_at   DATETIME        NOT NULL
  UNIQUE KEY request_user (request_id, user_id)
  KEY token (token)
  KEY user_id (user_id)
```

The subscriptions table holds only what 1.0 uses: one row per user per request they follow. No email address is stored, because a user's emails go to the address of their account, which can change. When guests can follow (1.1), the table gets `email` and `confirmed_at` columns, and its unique key becomes `(request_id, user_id, email)`.

No IP addresses are stored. Rate limit counters are keyed on an HMAC of the address with WordPress's salt, and on the hour. They are rows in the options table named as transients are, or entries in the object cache when the site has a persistent one, and they expire on their own.

**Vote count sync.** `VoteService::cast()` / `retract()`:
1. `INSERT IGNORE` or `DELETE` the vote row.
2. `SELECT COUNT(*)` for that request.
3. `update_post_meta('_mwtfrs_votes', $count)`.

This is idempotent and self-correcting, and there's no read-increment-write race (v0 had one). A WP-CLI command `wp mwtfrs recount` rebuilds all counts.

**Options.** `mwtfrs_settings` holds one array with a typed schema, sanitised via `register_setting` with a `sanitize_callback` and defaults. Also `mwtfrs_db_version`. The plugin keeps no secret of its own: hashes and tokens use WordPress's salts.

**Following (M7).** `Subscriptions` holds the rules:
- Only logged-in users follow, and only published requests without a password. A user can always stop, also when the request is no longer public.
- A vote makes its voter follow (source `vote`), and taking the vote back ends that. Voting again for a request already voted for is not a new vote, so it doesn't follow again after the user stopped.
- Choosing to follow (source `follow`) replaces the weaker reason, so taking back a vote later doesn't end it. A user who suggests a request follows it (source `author`).
- Nobody comes to follow while the site has the emails turned off. A user who turned off following by itself only follows by choosing to.
- Subscriptions go when their request or their user is deleted. Trashing a request keeps them.
- A deleted user's votes stay, so counts stay correct, but lose the user ID and get a random voter key (M8). Some databases give the ID of a deleted user to the next new user, who must not inherit votes.
- On a network of sites, "logged-in" means any user of the network, as for WordPress comments. A user who is removed from one site stops following there and keeps their votes. A user who is deleted from the network is handled on every site they belonged to; a subscription whose user no longer exists is deleted when its next email would go out (M8).
- `VoteService` announces votes with two actions, `mwtfrs_vote_cast` and `mwtfrs_vote_retracted`, which is how following hears of them.

### 5.4 Identity and anti-abuse

- **Logged-in user:** `voter_key = sha256('u:'.ID)`.
- **Guest:** a random 128-bit token in the `mwtfrs_vid` cookie (HttpOnly, SameSite=Lax, Secure on HTTPS, one-year lifetime). `voter_key = sha256('g:'.token)`. Clearing cookies allows another vote. **This is documented as best-effort**; boards that need hard guarantees require login, or verified email from 1.1.
- **Rate limits** (filterable):
  - Votes: 30 per hour per voter and 60 per hour per IP hash. Retracting counts as an attempt too.
  - Submissions: 5 per hour per voter and 10 per hour per IP hash.
  - Moderators (anyone who can edit others' requests) are exempt.
  - Counters are fixed one-hour windows (`mwtfrs_rate_limit` filter to change a limit, `mwtfrs_client_ip` for sites behind a trusted proxy). The votes table's unique key, not the counters, is what prevents duplicate votes.
  - An attempt is counted first, in one statement (`INSERT … ON DUPLICATE KEY UPDATE`, or `wp_cache_incr` with a persistent object cache), and judged after, so requests that arrive together can't all pass on the same count (M8). Measured with 200 simultaneous guest votes against 8 PHP workers: 60 were accepted, the limit; with a count that is read and then written, 124 were.
  - The visitor's own counter is raised first. Once it is over its limit, further attempts don't use up what others at the same address may still do.
  - Behind a proxy that hides the visitors' addresses, all visitors share one address counter until the site names the right header through `mwtfrs_client_ip`. The readme explains this.
- **Frozen votes:** while a request's status is closed, or its board has voting turned off, votes can neither be cast nor retracted.
- **Submissions** (built in M4):
  - A honeypot field, and a minimum 3 seconds between rendering the form and submitting it, checked with an HMAC-signed timestamp. The timestamp has no maximum age, so a form served from a page cache keeps working. With JavaScript, the form waits out the rest of the 3 seconds itself, so quick people are never refused.
  - Refusals don't say which check failed.
  - The `mwtfrs_verify_captcha` filter lets a captcha plugin refuse a submission, and the `mwtfrs_is_spam` filter lets a spam service flag one. The Akismet adapter uses the second when Akismet is active and has a key. A flagged request is held as `pending` with `_mwtfrs_flagged_as_spam`, never deleted, because spam checks sometimes get it wrong.
  - Text is saved as plain text: tags are stripped, and square brackets are stored as entities so a shortcode in a visitor's text displays instead of running.
  - A web address in a request stays text (M8). WordPress would embed an address that stands alone on a line: the server would fetch what a visitor typed, and the page would load a player from that site. `PlainDescription` turns that off for requests, on the page, in the classic editor's preview and in its fetch-ahead. A moderator who wants a video uses `[embed]`. A request written with blocks, on a site that turns the block editor on for requests, is left alone.
  - A guest's email address may be 254 characters long, the longest a mail server takes.
- **CSRF:**
  - REST uses the core `wp_rest` nonce for logged-in users. There is no second custom nonce (v0.5 broke voting by adding one).
  - Guest REST writes need the `Origin` header of this site: scheme, host and port are compared, and origins added through WordPress's `allowed_http_origins` filter count too.
  - The `Referer` is not accepted on the REST routes (M8). WordPress lets a GET act as a POST with `?_method=POST`, and a GET has a Referer but no Origin, so a link or an image on any page of the site would otherwise make its visitors vote or submit. A guest's no-JavaScript form post may still fall back on the Referer: it only reads a real POST body.
  - No-JavaScript form posts use `wp_nonce_field`.

### 5.5 REST API (`mwtfrs/v1`)

| Method and route | Who | Purpose |
|---|---|---|
| `POST /boards/{board}/requests` | per board setting | Submit a request. Returns `{id, status, url, message}`; `status` is `pending` or `publish`. Built in M4. |
| `GET /boards/{board}/similar?q=` | public | Top 5 published requests whose titles share a meaningful word with `q`, most shared words first, then most votes. Built in M4. |
| `POST /requests/{id}/vote` | per board setting | Cast a vote. Returns `{votes, voted: true}`, and for logged-in users `{following}`, because a vote can make them follow. Built in M2. |
| `DELETE /requests/{id}/vote` | voter | Retract a vote. Returns `{votes, voted: false}`, and for logged-in users `{following}`. Built in M2. |
| `POST /me/state` | anyone | Body `{ids: [...]}` → which of these the current visitor has voted on. Used to hydrate cached pages. |
| `POST` / `DELETE /requests/{id}/subscription` | logged-in users | Follow a request, or stop. Returns `{following}`. Built in M7. |
| `POST /requests/{id}/merge` (1.1) | `edit_others_mwtfrs_requests` | Merge into a target request. |

Every route declares its `args` with a type, and returns only the fields listed above.

Routes for reading requests were planned and left out, because nothing calls them: the board is rendered on the server. Requests can be read through WordPress's own REST API (`/wp/v2/mwtfrs_request`), which shows published requests with their title, text and terms. It never returns who submitted a request, and votes and followers aren't part of it.

Vote errors: 401 `mwtfrs_login_required`; 403 `mwtfrs_voting_closed`, `mwtfrs_voting_disabled` or `mwtfrs_invalid_origin`; 404 `mwtfrs_request_not_found` (also for pending, private and password-protected requests); 429 `mwtfrs_rate_limited` with a `Retry-After` header.

Follow errors: 401 `mwtfrs_follow_login_required`; 403 `mwtfrs_following_disabled` (the site doesn't email followers); 404 `mwtfrs_request_not_found`.

The unsubscribe link is not a REST route. It is the home URL with a `mwtfrs_unsubscribe` parameter (see 5.8), because sites often close the REST API and `wp-admin` to visitors who aren't logged in. Whether a user follows a request isn't part of `/me/state` either: only logged-in users follow, their pages aren't cached, so the request's page says it directly.

Submission errors: 400 `mwtfrs_submission_invalid` (with `data.reason`: `title`, `title-long`, `details-long`, `category`, `name-long` or `email`) or `mwtfrs_submission_rejected` (spam checks); 401 `mwtfrs_submit_login_required`; 403 `mwtfrs_submissions_closed` or `mwtfrs_invalid_origin`; 404 `mwtfrs_board_not_found`; 429 `mwtfrs_submit_rate_limited` with a `Retry-After` header.

WordPress search needs every word to match, which would miss most duplicates ("Add dark mode" against "Dark mode"). The similar endpoint instead matches any word of three or more letters that isn't a common word (a translatable list), and ranks titles by how many words they share.

### 5.6 Front end

- **Blocks** are built with `@wordpress/scripts`. Each has a `block.json` with `viewScriptModule` for the **Interactivity API** (requires WordPress 6.5 or later). They are server-rendered in PHP via `render.php` and hydrated with `data-wp-*` directives.
- **Shortcodes** render the block itself (`[feature_requests board="slug"]`), so both share markup, assets and behaviour.
- **Progressive enhancement:**
  - The vote button is a `<form method="post">` pointing at an `admin-post.php` handler. Without JavaScript, submitting it toggles the vote and returns to the board with a notice ("Your vote was added"). The Interactivity API takes over when JavaScript is available.
  - The submit form works the same way: without JavaScript it posts to `admin-post.php` and returns to the form with an `fr-form` notice. Its `required` and `maxlength` attributes catch most mistakes before a post.
  - Search and filters are GET query arguments (`fr-search`, `fr-status`, `fr-category`, `fr-sort`, `fr-page`).
- **Default view:** the board shows requests that are open for voting. Closed statuses (Shipped, Declined) appear under "All statuses" or their own filter.
- **Cache safety:** the HTML never contains per-visitor state, and guests get no nonces. After load, a single `POST /me/state` call marks the buttons the visitor has already voted on. Logged-in users get the REST nonce in the page, which caches do not serve to them; an expired nonce is refreshed through `admin-ajax.php?action=rest-nonce` and the request retried once.
- **One vote script.** The board and the request page share the Interactivity API store `mwtfrs/votes`, one vote button template and the stylesheet partials for it.
- **Follow button (M7).** Logged-in users get it on a request's page, under the status: a line that says whether they get emails, and a button that says what it does ("Follow" or "Stop following"). The request block adds its action to the vote store, so a vote that makes the user follow flips the button at once. It is a form like the vote button, so it works without JavaScript and returns with a notice. Guests don't get it, and their HTML stays the same for everyone.
- **Styling:** CSS custom properties that fall back to `theme.json` presets and then to fixed colours. About 4 KB per block. Headings and wrappers use selectors that hold up against block themes' global styles, which load after block styles. The vote button's rules outrank theme `button` styles so pressed and unpressed always look different. A container query tightens the layout when the board is narrow. No icon fonts; inline SVG only.
- **Single request page (M5).** The theme shows the title, the description and the comments. The plugin adds the vote button, status and categories above the description, and the official response below it.
  - The details are a block, `mwtfrs/request` ("Feature Request Details").
  - **Block themes on WordPress 6.7 or later** get a registered block template, `single-mwtfrs_request`, that holds the title, that block, the content and the comments. They need it because their template for posts prints an author and post categories, which requests don't have. Site owners can edit it in the Site Editor, and a theme can ship its own.
  - **Classic themes, and block themes on WordPress 6.6** (which can't take plugin templates): the details are added to the request's content through `the_content`, only inside the main loop of the request's own page. That keeps them out of excerpts, feeds, the REST API and password-protected requests. On 6.6 the theme's post template still prints its empty author line.
  - If the template in use already holds the block, the details aren't added a second time.
- **Comments and official responses (M5).** Requests use WordPress comments, so moderation, Akismet, threading and the theme's comment design all apply.
  - A moderator can post a comment as the official response with a checkbox on the comment form. For existing comments, moderators can change the mark in three places:
    - On the request's page, with a "Mark as official response" or "Remove official mark" link on the comment's Reply line. Classic themes and the Comments block both build that line through the `comment_reply_link` filter. A comment without a Reply link (threaded comments off, comments closed, or the deepest level) gets the link below its text instead.
    - On the comment's edit screen, with an "Official response" checkbox in the Save box. It is saved only when that screen is saved, so Quick Edit, the REST API and other code leave the mark alone.
    - On the Comments screen, among the comment's actions.
  - The pinned response itself has no controls. Other visitors never get the links, so their HTML stays the same for everyone.
  - Only comments written by moderators can be marked, so the mark always means "from the team". Any moderator can remove it, even after the comment's author left the team.
  - The checkbox carries a nonce. WordPress accepts comments from logged-in users without one, so without it another site could make a moderator post an "official" comment.
  - The mark is comment meta, so it goes when the comment goes. A request can collect several official responses; the newest approved one is pinned below the description and all are labelled in the thread.
- **Turning the styles off (M8).** A site that styles the blocks itself returns false from the `mwtfrs_load_styles` filter, and the blocks then load no stylesheet.
- **Accessibility (M8).** The target is WCAG 2.2 level AA. The end-to-end tests run axe on the board, the form, the request page, the unsubscribe pages and the plugin's parts of the admin screens, in a block theme and a classic theme, and also check that nothing overflows at a width of 320 pixels and that controls are at least 24 pixels in size.
  - Faint text is the text colour at 85%, which keeps 4.5:1 against the page in any theme whose own text has 7:1. It was 70%, which fell just short in Twenty Twenty-One.
  - The edges of text fields and of the Follow button are the text colour at 60%, for the 3:1 that WCAG asks of a control's boundary. Decorative lines stay at 20%.
  - Fields keep to the width of the form in themes that give fields a margin.
  - The board's search form has a name for screen readers.
- **Templates** can be overridden from `yourtheme/feature-request-system/`: `board.php` (since M3), `submit-form.php` (since M4), `vote-button.php`, `request.php` and `official-response.php` (since M5), and the emails `emails/status-changed.php` and `emails/request-approved.php` (since M7).
- **Pattern:** "Feature request page" puts the form, a heading and the board on one page (M4).

### 5.7 Admin

Built in M6 with classic PHP screens: the posts list table, the Settings API and the term screens.

- **Requests list.** A sortable Votes column, then Status (with its colour), Board and Categories. Filters for status, board and category; a filter only shows when there is something to choose.
- **Status in Quick Edit and Bulk Edit.** A dropdown labelled "Request status", because WordPress already has a field called Status there for Published, Pending and so on. Bulk Edit starts at "No Change". A small script selects the request's current status when Quick Edit opens.
- **Pending badge.** The Feature Requests menu shows moderators how many requests wait for approval, the way WordPress shows pending comments.
- **Edit screen.** A Request details box shows the vote count, the users who voted (the 20 most recent by name), how many guests voted, who submitted the request, and whether a spam check flagged it. A submitter's name and email appear here and in the new request email, nowhere else. The board and the status each have a box with one dropdown, titled "Board" and "Request status". Since M7 the details box also counts the request's followers, and the status box has the switch that emails them (see below).
- **One status hook.** `mwtfrs_status_changed( $request_id, $old_status, $new_status )` fires once per change, whether it comes from the edit screen, Quick Edit, Bulk Edit, the REST API, WP-CLI or other code, because all of them end in `wp_set_object_terms()`. The old status is read on `add_term_relationship`, just before WordPress adds the new one, since WordPress doesn't report the previous terms when terms are appended. A new request getting its first status is not a change, and neither is a change that other code undid. The notifier listens to this hook (see 5.8).
- **Notify switch (M7).** Next to the status on the edit screen, in Quick Edit and in Bulk Edit there is a ticked box, "Email followers when the status changes". Unticking it changes the status without an email. It counts only in a form that WordPress accepted for that request, so the REST API and other code can't carry it; code uses the `mwtfrs_notify_status_change` filter instead.
- **Profile (M7).** A user's profile screen lists the requests they follow, each with a box to stop following, and has the choice "Follow the requests I vote for or suggest". Those who may edit a user see the same on that user's edit screen.
- **Settings page** (Feature Requests → Settings): the default board and status, the new request emails and who gets them, whether users can follow requests and whether voting follows (M7), the "How we use your data" note for guests (see 5.10), and deleting data on uninstall. Settings that a save doesn't send keep their value, so saving one setting from code can't reset the others; the form's checkboxes send 0 when unticked.
- **Boards screen.** Each board's form has who can vote, who can suggest features, whether new requests wait for approval, and the board's page.
- **Board page.** A request's page links back to the page that shows its board ("← Back to Feature Requests"). The plugin can't know this page by itself, because the board is a block that can sit on any page. So the board remembers the first published page it is shown on, and the page can also be chosen on the board's form. If that page stops being published, the link goes away and the board takes the next page it is shown on.
- **Statuses screen.** Each status's form has its colour (the browser's colour picker), its position, and a "Closed for voting" switch. The list shows the colour and whether voting is open, in workflow order. A new status gets a colour and the last position from wherever it is created. The "show on roadmap" flag gets its field in 1.1, with the roadmap.
- The board, status and comment screens save their fields only when their own form was submitted, checked with a nonce. The REST API, WP-CLI and other code leave those settings alone.

### 5.8 Notifications

- The **new request** email (M4) is one `wp_mail()` to the moderators, sent when the request is submitted: the addresses in settings, or the site admin email. It says whether the request waits for approval and links to its edit screen. A site can turn it off.
- **Emails to followers (M7)** are the status change email and the approval email. Both go to a logged-in user as a follower of a request, so both end with the link that stops them.
- **Background sending.** They run through **Action Scheduler**, bundled via Composer. Every copy on a site registers itself and the newest one runs, so ours is always loaded and WooCommerce's newer copy wins where it exists. It is pinned to 3.9, the last line that supports WordPress 6.6; version 4 needs WordPress 6.8. Tools → Scheduled Actions shows the work under the group `feature-request-system`.
- **Status changes.** `StatusNotifier` listens to `mwtfrs_status_changed`.
  - It schedules one action per batch of 50 followers. A batch is a range of subscription IDs, so users who follow after the change aren't in any batch, and a batch reads its followers when it runs, so users who stopped following in between get nothing.
  - The emails are due **five minutes after the change**. The request remembers the one announcement that waits (`_mwtfrs_status_notice`: its ID, the status the followers know, the new status, who made the change, and when it is due). A later change replaces it, and the batches of the replaced announcement then send nothing. So a change that is corrected in time is never announced, and changes in a row become one email from the first status to the last.
  - Nothing is sent for a request that isn't published, for a change made with the notify switch unticked (which also drops an announcement that was waiting), or to the user who made the change. A batch also sends nothing if the request's status is no longer the announced one when it runs.
  - Filters: `mwtfrs_notify_status_change`, `mwtfrs_status_email_delay` and `mwtfrs_status_email_batch_size`.
- **Approval.** When a request that waited for approval is published, the user who suggested it gets an email, if they follow it and didn't approve it themselves. Visitors who suggested a request without an account aren't emailed: their address isn't verified, and the form tells them only the team sees it.
- **The emails** are plain text, like WordPress's own, so they work in every mail program and with plugins that wrap all site email in a design. `Mailer` sends each one in the language of the recipient's account.
  - The text comes from a template in `templates/emails/`, which a theme can override. A template gets its values as plain text and escapes them like any template; the result is turned back into plain text before sending.
  - The `mwtfrs_follower_email` filter can change an email or stop it.
  - HTML versions were planned and left out: plain text does the job, and a second version of every template would have to be kept in step.
- **Stopping the emails.** Every email ends with a link: the home URL with a `mwtfrs_unsubscribe` parameter that holds the subscription's secret token. It works without logging in.
  - Opening the link only asks. The user stops following when the page's button is pressed. Programs that open every link in an email to scan it therefore can't unsubscribe anybody.
  - The emails carry `List-Unsubscribe` and `List-Unsubscribe-Post` headers. A mail program's own unsubscribe button posts to the link, which stops the emails without a further step.
  - The page also offers to stop all emails at once. That ends every subscription of the user and turns off following by itself for them, so their next vote doesn't bring the emails back.
  - A request that isn't public is neither named nor linked on the page.
  - WordPress encodes long headers when it sends with PHP's `mail()`, which not every mail program reads. The headers arrive as written on sites that send through SMTP or a mail service. The link in the email works either way.
- All strings are translatable.

### 5.9 Capabilities

- Custom caps are mapped from the post type: `edit_mwtfrs_requests`, `edit_others_mwtfrs_requests`, `publish_mwtfrs_requests` and so on, plus `manage_mwtfrs_settings`.
- They are granted to Administrator (all) and Editor (all except settings) on activation.
- **Deactivation doesn't remove caps; uninstall does.**
- Front-end submitting and voting use board settings (roles, logged-in, guests), not caps, so site owners don't need a role editor.
- Adding a board or a status over the REST API takes the capability to manage them (M8). In a taxonomy without parents, WordPress would allow it to anyone who may assign its terms, as with tags.

### 5.10 Privacy, uninstall, upgrades

- **Privacy exporter and eraser (M8).** A person is found by their email address: as the user with that address, and as a visitor who gave it when they suggested a request. Visitors who voted without an account can't be found, because their votes hang on a random cookie.
  - The export holds the requests the person suggested (with the name and email a visitor gave), their votes, the requests they follow and why, and whether they turned off following by voting.
  - Erasing keeps what the site needs and cuts its tie to the person. Requests stay, without their submitter. Votes stay, so counts stay correct, but get a new random voter key and no user ID. Subscriptions and the stored choice are deleted. Comments are handled by WordPress itself.
  - Both work a page at a time, 100 items per step.
- **Plain text in emails and exports (M8).** `Mailer::plain()` returns text that holds no tag, also where it is shown as HTML after all: with a mail plugin that sends plain emails as HTML, or in the file of a personal data export. A visitor can type a tag as entities, which only becomes a tag when the entities are decoded, so every `<` that could open a tag gets a space after it.
- **The unsubscribe page** sends `Referrer-Policy: no-referrer`, and its links carry `rel="noreferrer"` (M8), so the page a follower goes to next, and the scripts on it, don't learn the address with the token.
- **Suggested privacy policy text (M8)** goes to WordPress's policy guide through `wp_add_privacy_policy_content`. It says what is stored about submitters, voters and followers, names the guest voter cookie (`mwtfrs_vid`, one year), explains the hashed rate limit counters, and mentions Akismet only when Akismet checks new requests.
- **"How we use your data" note (built in M6).** A settings field holds text the site owner writes for visitors who submit without an account. When it is set, the form shows it under the name and email fields, in a section the visitor opens ("How we use your data"), followed by a link to the site's privacy policy page when WordPress has one.
  - The section is a `<details>` element, not a tooltip or a modal. It works without JavaScript and on touch screens, suits keyboards and screen readers, holds several sentences and adds no script. A tooltip can't hold a paragraph and fails on phones; a modal needs script and focus handling for a few sentences of text.
  - The text allows links and emphasis only. It is sanitised when saved and again when shown. Every guest gets the same text, so cached pages stay correct.
  - It is empty by default, which shows nothing. The settings field offers a suggested text built from what the plugin does on that site. The suggestion mentions Akismet only when Akismet is connected, which covers what the form's short "Only our team sees this" note leaves out.
  - It is one site-wide text, not one per board. Logged-in users don't see it, because the form asks them for nothing.
- **External services:** when the Akismet plugin is active and connected, a new request's title, details, the name and email a guest gave, their IP address and user agent go to Akismet for a spam check. The readme discloses this with links to Akismet's terms and privacy policy.
- **`uninstall.php`:** if "Delete all data on uninstall" is enabled, it drops the tables and deletes the posts, terms, options and caps. It also deletes each user's choice about following, cancels the emails that still wait, and removes the map of the category tree that WordPress keeps in an option of its own (`mwtfrs_category_children`; found in M8 by uninstalling on a real site). Action Scheduler's own tables stay, because other plugins may use them. It handles multisite by looping over sites.
- **Upgrades:** on `init` (after the content types register, because installing seeds terms), if `mwtfrs_db_version` is behind the code, run the installer. It is idempotent and wrapped in an option lock. This also covers network activation and sites where the files were copied in place.
- **Default board and status** can't be deleted from the admin or REST. If code such as WP-CLI deletes one anyway, the oldest remaining term becomes the default and the affected requests move to it.

### 5.11 Directory layout

```
feature-request-system/
├── feature-request-system.php      # header, constants, requirements check, bootstrap
├── uninstall.php
├── readme.txt                      # WordPress.org readme
├── composer.json / package.json
├── src/
│   ├── Plugin.php                  # wiring only: instantiates services, registers hooks
│   ├── Install/                    # Installer (tables, terms, caps, upgrades), Schema, Uninstaller
│   ├── Content/                    # RequestPostType, BoardTaxonomy, StatusTaxonomy, CategoryTaxonomy, BoardSettings
│   ├── Voting/                     # VoteRepository, VoteService, VoterIdentity
│   ├── Security/                   # RateLimiter, RequestOrigin
│   ├── Submission/                 # SubmissionService, SpamGuard, Akismet, SimilarRequests
│   ├── Notifications/              # NewRequestEmail, SubscriptionRepository, Subscriptions, StatusNotifier, ApprovalEmail, Mailer, Unsubscribe, Queue
│   ├── Rest/                       # RestApi, RequestsController, VotesController, StateController, SubscriptionsController, ManagedTermsController, Response
│   ├── Comments/                   # OfficialResponse, CommentForm, CommentThread
│   ├── Frontend/                   # Blocks, Board, SubmitForm, RequestView, PlainDescription, VoteForm, FollowForm, RequestNotice, VoteStore, Template
│   ├── Admin/                      # RequestList, StatusQuickEdit, NotifySwitch, PendingBadge, RequestDetailsBox, TermSelectBox, SettingsPage, BoardFields, StatusFields, CommentActions, ProfileFollows
│   ├── Privacy/                    # PersonalData (exporter and eraser), PolicyText, DepartedUsers
│   └── Cli/                        # Commands (`wp mwtfrs recount|seed`), SampleData
├── blocks/                         # board/, submit/, request/ (block.json, render.php, view.js, style.scss), shared/
├── templates/                      # overridable PHP partials, emails/ (plain text), and the block template single-request.html
├── languages/                      # feature-request-system.pot
├── .wordpress-org/                 # icon, banner and screenshots for the listing, with their sources
├── tests/
│   ├── php/Integration/            # WP test suite: slices end to end
│   ├── php/Unit/
│   ├── e2e/                        # Playwright
│   └── manual/                     # test-plan.md: what to try by hand before a release
└── build/                          # compiled by @wordpress/scripts (gitignored; in release zip)
```

---

## 6. Tooling, quality and release

| Area | Choice |
|---|---|
| Version control | `git init` on day one, GitHub repo, conventional commits, PR per milestone |
| PHP deps | Composer: PSR-4 autoload; runtime dependency `woocommerce/action-scheduler` only, pinned to 3.9 while the minimum is WordPress 6.6 |
| PHP QA | `wp-coding-standards/wpcs` 3, `phpcompatibility/phpcompatibility-wp`, PHPStan level 6 with `szepeviktor/phpstan-wordpress` |
| PHP tests | PHPUnit 9 + `yoast/phpunit-polyfills`, with WordPress core and its test library from Composer (`roots/wordpress-no-content`, `wp-phpunit/wp-phpunit`) against a MySQL database |
| JS/CSS | `@wordpress/scripts` (build, lint, format), block.json, Interactivity API |
| E2E | Playwright with `@wordpress/e2e-test-utils-playwright` on `wp-env`, and axe (`@axe-core/playwright`) for accessibility |
| Local development | Herd site for manual QA; PHP tests run against Herd's MySQL (database `frs_tests`), so Docker is only needed for E2E |
| CI (GitHub Actions) | Lint (PHP/JS/CSS) → PHPStan → PHPUnit matrix (PHP 7.4 to 8.4 × WordPress 6.6 and latest, plus one run as a multisite network) → E2E → **Plugin Check** (`wordpress/plugin-check-action`) → build zip artefact |
| Release | `.distignore`; `bin/build-zip.sh`; tag → `10up/action-wordpress-plugin-deploy` to SVN; WordPress.org banner, icon and screenshots in `.wordpress-org/` |
| Versioning | Semantic versioning. First public release is **1.0.0**; v0 and v0.5 were never published. |

**Definition of done for every milestone:**
- CI is green.
- The new behaviour is covered by an integration test through its real entry point.
- Plugin Check is clean.
- No new strings are left untranslated.
- A manual test was run on the Herd site with a block theme (Twenty Twenty-Five) and a classic theme.

---

## 7. Milestones

Each milestone is a vertical slice that ends with something demonstrable. Estimates are in focused working days.

| # | Milestone | Scope | Acceptance |
|---|---|---|---|
| **M0** ✅ | Foundation (1 day) | git, Composer (npm arrives in M3 with the first block), main file with requirements check, `Plugin` wiring, CI pipeline, Plugin Check, readme.txt stub | Plugin activates on minimum and latest WordPress; CI green; Plugin Check clean |
| **M1** ✅ | Content core (1–2 days) | Post type, 3 taxonomies, default statuses and board, caps, Installer, Migrations, `uninstall.php` | Tests: activation creates tables, terms and caps; uninstall with opt-in removes everything; re-activation is idempotent |
| **M2** ✅ | **Voting slice** (2 days) | VoteRepository, VoteService, VoterIdentity, RateLimiter, vote REST routes, count sync, `wp mwtfrs recount` | Tests: vote, re-vote (no duplicate), retract, guest via cookie, guests blocked when the board disallows them, closed status blocks votes, rate limit returns 429, `_mwtfrs_votes` is always accurate |
| **M3** ✅ | Board rendering (3 days) | Board block and shortcode, list/search/filter/sort/paginate, Interactivity vote button, no-JS fallback, `/me/state` hydration | E2E: visitor votes, count persists after reload, list sorts by votes, works with JavaScript disabled, correct behind a simulated page cache |
| **M4** ✅ | Submission (2–3 days) | Submit block and form, similar requests while typing, moderation flow, SpamGuard, admin new-request email | E2E: submit leads to pending, admin approves, it appears on the board. Tests: honeypot, timing and rate limit reject submissions; emails are never returned by public REST |
| **M5** ✅ | Single request and comments (2 days) | Single template (block and classic), comments, Official response, status badge | E2E: comment, admin marks it Official, it shows pinned |
| **M6** ✅ | Admin UX (2–3 days) | List columns, filters, sort by votes, Quick and Bulk Edit status, pending bubble, meta box with voters, settings page (including the "How we use your data" note for guests), board and status term screens (including each board's page, for the link back from a request) | Tests: status changes from every screen fire `mwtfrs_status_changed` exactly once; the guest note shows on the form only when it is set, keeps links and emphasis, and drops any other HTML; a request's page links to its board's page, and showing the board on a published page sets that page |
| **M7** ✅ | Close the loop (2–3 days) | Subscriptions table, following by voting, by suggesting and with the Follow button, status emails in batches through Action Scheduler with a five-minute wait, the approval email, plain text email templates, the unsubscribe link, the notify switch, followed requests on the profile | Tests: a status change on a request with 120 followers schedules 3 batches; the unsubscribe link works without logging in, and opening it alone unsubscribes nobody; no mail goes to someone who stopped following; a change corrected in time is never announced. E2E: follow and stop, a vote follows, a status change emails the follower and the link in the email stops the emails |
| **M8** ✅ | Launch hardening (2–3 days) | Privacy exporter and eraser and policy text, accessibility pass (axe in E2E), translation-ready (`.pot`), readme.txt, screenshots, banner and icon, performance check (board with 1,000 requests and 50k votes). Also: categories per board, the filter that turns the stylesheets off, `wp mwtfrs seed`, an independent security review with its fixes, and a manual test plan (`tests/manual/test-plan.md`, 129 cases) | Plugin Check 2.1.0 finds no errors and no warnings. axe finds no violation of WCAG 2.2 AA in Twenty Twenty-Five and Twenty Twenty-One. With 1,000 requests and 50,000 votes the board block renders in about 16 ms with 14 to 21 queries, and its page answers in 56 ms, against 41 ms for a page without it. 418 PHP tests pass on WordPress 7.1 and 6.6, each also as a multisite network; 43 end-to-end tests pass in a block theme, a classic theme and on WordPress 6.6. 175 rules were broken on purpose: 27 had no test and got one, and two new tests that could not fail were corrected. The security review found nothing critical or high; its two medium and six low findings are fixed (section 5.4 and 5.10) |
| **→** | **Release 1.0.0** | Tag, deploy to SVN, publish, announce | Listing is live on WordPress.org |
| M9 | 1.1 | Roadmap block, merge, trending, Turnstile, vote limits, vote threshold, CSV export, dashboard widget, email-verified guests | Per-feature slices with tests |
| M10 | 1.2 | Changelog / releases with shipped notifications, IdeaPush and SFR importers, webhooks | Importers tested against fixture dumps of each plugin |
| M11 | 1.3 | WooCommerce / EDD customer insights and customer-only boards | Tests with WooCommerce active and inactive |

About 17–22 working days to reach 1.0.0.

---

## 8. Open decisions (recommendations marked ★)

- **D1. Prefix:** ★ keep `mwtfrs`. It's unique, already associated with you, and meets the 4+ character guideline. The alternative is a fresh `frsys`.
- **D2. Minimum versions:** ★ WordPress 6.6+ (Interactivity API is stable and `viewScriptModule` works) with **PHP 7.4+** for reach. The alternative is PHP 8.1+ for cleaner code (enums, readonly), at the cost of sites still on PHP 7.4–8.0. Check the current share on the WordPress.org stats page before deciding.
- **D3. Downvotes:** ★ upvote only (like Canny, SFR and FeatureQuest; fewer hurt feelings, simpler UI). The schema keeps `value` so downvotes could be added later as an option.
- **D4. Guest defaults:** ★ guests can *view* but not vote or submit by default; each board can enable them. Email-verified guests come in 1.1.
- **D5. Changelog in 1.2:** ★ yes, but kept small ("releases that ship requests"). It completes the "close the loop" story. It isn't meant to compete with Changeloger's full changelog builder.
- **D6. Free vs Pro:** ★ everything up to 1.3 is free; importers stay free because they drive adoption. Decide on Pro after 1.0 feedback.

## 9. Risks

| Risk | Mitigation |
|---|---|
| Small market with many new 2026 entrants | Lead with "close the loop" and polish; target plugin, theme and SaaS makers directly (dogfood it on cornelraiu.com for your own plugins) |
| Slug dormancy: the approved slug has had no code for years | SVN repo exists with an empty trunk. Commit a real release soon, and email plugins@wordpress.org first if unsure whether the slug is still yours. |
| Guest vote stuffing | Off by default, cookie plus IP-hash rate limits, verified-email guests in 1.1, admin can see and remove votes |
| Email sending at scale on shared hosts | Batched Action Scheduler jobs; recommend an SMTP plugin in the docs. The batches aren't spaced out, so a host's hourly limit can still be hit by a request with very many followers; a sending rate is a candidate for 1.1 |
| Action Scheduler 4 needs WordPress 6.8 | Pinned to 3.9 (WordPress 6.5+). Move to 4 when the plugin's minimum WordPress version reaches 6.8 |
| Scope creep (the v0.5 failure mode) | Milestones are vertical slices; nothing moves to 1.x until 1.0 ships |
| **Local:** if v0.5 was active in this WordPress install, the stored active-plugin path `feature-request-system/feature-request-system.php` now points at this folder, so the new plugin activates itself as soon as its main file exists | Check Plugins → Installed before M0, or deactivate v0.5 first |
