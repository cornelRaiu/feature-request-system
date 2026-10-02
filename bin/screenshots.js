#!/usr/bin/env node
/**
 * Takes the screenshots for the WordPress.org listing, into .wordpress-org/.
 *
 * Usage: WP_BASE_URL=http://localhost:8081 WP_CLI="wp --path=/path/to/site" node bin/screenshots.js
 *
 * Run it against a throwaway site with the plugin active and Twenty Twenty-Five as its
 * theme: it replaces all feature requests on that site with sample ones. The site needs
 * an "admin" user with the password "password", or set WP_USERNAME and WP_PASSWORD. The
 * captions of the screenshots are in readme.txt, in the same order.
 */
const crypto = require( 'node:crypto' );
const path = require( 'node:path' );
const { chromium } = require( '@playwright/test' );
const { wpEval } = require( '../tests/e2e/wp-cli' );

const base = process.env.WP_BASE_URL || 'http://localhost:8889';
const out = path.join( __dirname, '..', '.wordpress-org' );
const admin = {
	username: process.env.WP_USERNAME || 'admin',
	password: process.env.WP_PASSWORD || 'password',
};
const visitor = {
	username: 'mwtfrs-demo-user',
	password: crypto.randomBytes( 16 ).toString( 'hex' ),
};

/**
 * Fills the site with a board that looks lived in, and returns the pages to shoot.
 *
 * @return {{page: string, request: string, board: number}} The page with the form and the board, the request with the most votes, and the board's ID.
 */
function setUp() {
	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Cli\\SampleData;
			use FeatureRequestSystem\\Comments\\OfficialResponse;
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Content\\StatusTaxonomy;
			use FeatureRequestSystem\\Frontend\\Blocks;
			use FeatureRequestSystem\\Notifications\\Queue;
			use FeatureRequestSystem\\Notifications\\SubscriptionRepository;
			use FeatureRequestSystem\\Voting\\VoteCount;
			use FeatureRequestSystem\\Voting\\VoteRepository;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			Queue::cancel_all();
			$board = RequestTerms::default_term( BoardTaxonomy::NAME );
			BoardSettings::update( $board, BoardSettings::defaults() );
			SampleData::create( 24, 900 );

			$requests = get_posts( [ 'post_type' => RequestPostType::NAME, 'numberposts' => 3, 'meta_key' => RequestPostType::META_VOTES, 'orderby' => 'meta_value_num', 'order' => 'DESC' ] );
			$top      = $requests[0]->ID;
			wp_set_object_terms( $top, get_term_by( 'slug', 'planned', StatusTaxonomy::NAME )->term_id, StatusTaxonomy::NAME );
			wp_update_post( [ 'ID' => $top, 'post_content' => 'Our team works late, and the bright screen is hard on the eyes. A dark colour scheme that follows the setting of the device would help a lot.' ] );

			// A visitor with an account, who voted for two requests and follows them.
			$user = get_user_by( 'login', '${ visitor.username }' );
			$user = $user ? $user->ID : wp_insert_user( [ 'user_login' => '${ visitor.username }', 'user_email' => '${ visitor.username }@example.com', 'display_name' => 'Jamie Rivera', 'role' => 'subscriber', 'user_pass' => wp_generate_password() ] );
			wp_set_password( '${ visitor.password }', $user );
			update_user_meta( $user, 'show_admin_bar_front', 'false' );
			foreach ( [ $top, $requests[2]->ID ] as $voted ) {
				VoteRepository::add( $voted, hash( 'sha256', 'u:' . $user ), $user );
				VoteCount::sync( $voted );
				SubscriptionRepository::add( $voted, $user, SubscriptionRepository::SOURCE_VOTE );
			}

			// The team's answer, and a comment before it. The team member gets a name for the pictures.
			$team = get_user_by( 'login', '${ admin.username }' );
			update_user_meta( $team->ID, 'mwtfrs_demo_name', $team->display_name );
			wp_update_user( [ 'ID' => $team->ID, 'display_name' => 'Maya from the team' ] );
			$team = get_userdata( $team->ID );
			wp_insert_comment( [ 'comment_post_ID' => $top, 'comment_author' => 'Priya', 'comment_author_email' => 'priya@example.com', 'comment_content' => 'Yes please. Even just for the editor would be a start.', 'comment_approved' => 1, 'comment_date' => gmdate( 'Y-m-d H:i:s', time() - 6 * DAY_IN_SECONDS ) ] );
			$answer = wp_insert_comment( [ 'comment_post_ID' => $top, 'user_id' => $team->ID, 'comment_author' => $team->display_name, 'comment_author_email' => $team->user_email, 'comment_content' => 'We are building this now. It will follow your device setting, with a switch to override it. Expect it in the next release.', 'comment_approved' => 1, 'comment_date' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ] );
			update_comment_meta( $answer, OfficialResponse::META, 1 );

			// Two requests that wait for approval, for the badge in the admin menu.
			foreach ( [ 'Print a packing slip', 'Sort the dashboard by due date' ] as $title ) {
				wp_insert_post( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'pending', 'post_title' => $title, 'post_content' => 'Sent from the form.' ] );
			}

			$page    = get_page_by_path( 'mwtfrs-demo' );
			$page_id = $page ? $page->ID : wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Feature requests',
				'post_name'    => 'mwtfrs-demo',
				'post_content' => WP_Block_Patterns_Registry::get_instance()->get_registered( Blocks::PATTERN )['content'],
			] );

			echo wp_json_encode( [ 'page' => get_permalink( $page_id ), 'request' => get_permalink( $top ), 'board' => $board ] );
		` )
	);
}

/**
 * Gives the admin user their own name back.
 */
function tearDown() {
	wpEval( `
		$team = get_user_by( 'login', '${ admin.username }' );
		$name = get_user_meta( $team->ID, 'mwtfrs_demo_name', true );
		if ( $name ) {
			wp_update_user( [ 'ID' => $team->ID, 'display_name' => $name ] );
			delete_user_meta( $team->ID, 'mwtfrs_demo_name' );
		}
	` );
}

/**
 * Opens a browser window that is logged in.
 *
 * @param {import('@playwright/test').Browser}   browser Browser.
 * @param {{username: string, password: string}} login   Login details.
 * @return {Promise<import('@playwright/test').Page>} A page in that window.
 */
async function loggedIn( browser, login ) {
	const context = await browser.newContext( {
		viewport: { width: 1280, height: 800 },
		deviceScaleFactor: 2,
	} );
	const page = await context.newPage();
	await page.goto( `${ base }/wp-login.php` );
	await page.locator( '#user_login' ).fill( login.username );
	await page.locator( '#user_pass' ).fill( login.password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );

	return page;
}

/**
 * Saves a picture of part of the page, as wide as the window: from just above one
 * element down to a given height, or to the end of that element when it is shorter.
 *
 * @param {import('@playwright/test').Page} page                Browser page.
 * @param {string}                          selector            Element whose top starts the picture.
 * @param {string}                          file                File name in .wordpress-org/.
 * @param {Object}                          options
 * @param {number}                          options.height      Most height in CSS pixels.
 * @param {boolean}                         [options.wholePage] Whether to go on past the end of the element.
 * @param {number}                          [options.margin]    Space to leave above the element, in CSS pixels.
 */
async function shoot(
	page,
	selector,
	file,
	{ height, wholePage = false, margin = 40 }
) {
	const box = await page
		.locator( selector )
		.first()
		.evaluate( ( element ) => {
			const rect = element.getBoundingClientRect();
			return {
				top: rect.top + window.scrollY,
				height: rect.height,
				pageHeight: document.documentElement.scrollHeight,
			};
		} );
	const top = Math.max( 0, box.top - margin );
	const wanted = wholePage
		? height
		: Math.min( height, box.height + 2 * margin );

	await page.screenshot( {
		path: path.join( out, file ),
		fullPage: true,
		clip: {
			x: 0,
			y: top,
			width: 1280,
			height: Math.min( wanted, box.pageHeight - top ),
		},
	} );
}

( async () => {
	const urls = setUp();
	const browser = await chromium.launch();

	// 1 to 3: what visitors see.
	const front = await loggedIn( browser, visitor );
	await front.goto( urls.page );
	await front.waitForLoadState( 'networkidle' );
	// From the heading above the board, which the page pattern puts there.
	await shoot( front, 'h2:has-text("Browse requests")', 'screenshot-1.png', {
		height: 900,
		wholePage: true,
		margin: 24,
	} );

	await front.getByLabel( 'Title' ).fill( 'Dark mode in the editor' );
	await front.locator( '.mwtfrs-submit__similar-list a' ).first().waitFor();
	await front
		.getByLabel( 'Details' )
		.fill( 'The editor is where I spend most of my day.' );
	await shoot( front, '.wp-block-mwtfrs-submit', 'screenshot-2.png', {
		height: 860,
	} );

	await front.goto( urls.request );
	await front.waitForLoadState( 'networkidle' );
	await shoot( front, 'h1', 'screenshot-3.png', {
		height: 900,
		wholePage: true,
	} );

	// 4 to 6: what the team sees.
	const back = await loggedIn( browser, admin );
	await back.goto(
		`${ base }/wp-admin/edit.php?post_type=mwtfrs_request&orderby=mwtfrs_vote_count&order=desc`
	);
	await back.screenshot( { path: path.join( out, 'screenshot-4.png' ) } );

	await back.goto(
		`${ base }/wp-admin/term.php?taxonomy=mwtfrs_board&tag_ID=${ urls.board }&post_type=mwtfrs_request`
	);
	await back.screenshot( {
		path: path.join( out, 'screenshot-5.png' ),
		fullPage: true,
	} );

	await back.goto(
		`${ base }/wp-admin/edit.php?post_type=mwtfrs_request&page=mwtfrs-settings`
	);
	await back.screenshot( {
		path: path.join( out, 'screenshot-6.png' ),
		fullPage: true,
	} );

	await browser.close();
	tearDown();
} )();
