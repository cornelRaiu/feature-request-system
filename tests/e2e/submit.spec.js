/**
 * The Feature Request Form in a real browser.
 */
const crypto = require( 'node:crypto' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpEval } = require( './wp-cli' );

// The server refuses requests sent within 3 seconds of rendering the form, as bots
// send them. With JavaScript the form waits by itself; without it, the tests wait.
const HUMAN_DELAY = 3500;

/**
 * Replaces all requests with the given ones and returns a page with the form above the board.
 *
 * Also clears rate limit counters, so repeated runs within the hour do not hit them.
 *
 * @param {Object}   options
 * @param {string}   [options.submitting] Who may submit: ANYONE, LOGGED_IN or NOBODY.
 * @param {boolean}  [options.moderation] Whether new requests wait for approval.
 * @param {string[]} [options.requests]   Titles of published requests to create.
 * @return {{url: string}} The page.
 */
function setUpPage( {
	submitting = 'LOGGED_IN',
	moderation = true,
	requests = [],
} = {} ) {
	const titles = Buffer.from( JSON.stringify( requests ) ).toString(
		'base64'
	);

	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Frontend\\Blocks;
			use FeatureRequestSystem\\Security\\RateLimiter;
			global $wpdb;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%' ) );
			BoardSettings::update(
				RequestTerms::default_term( BoardTaxonomy::NAME ),
				[
					BoardSettings::SUBMITTING => BoardSettings::${ submitting },
					BoardSettings::MODERATION => ${ moderation ? 'true' : 'false' },
				]
			);

			foreach ( json_decode( base64_decode( '${ titles }' ), true ) as $title ) {
				wp_insert_post( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'publish', 'post_title' => $title ] );
			}

			$page    = get_page_by_path( 'e2e-submit' );
			$page_id = $page ? $page->ID : wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'E2E submit',
				'post_name'    => 'e2e-submit',
				'post_content' => WP_Block_Patterns_Registry::get_instance()->get_registered( Blocks::PATTERN )['content'],
			] );
			echo wp_json_encode( [ 'url' => get_permalink( $page_id ) ] );
		` )
	);
}

/**
 * Creates a subscriber, or gives the existing one a new password, and returns the login.
 *
 * @return {{username: string, password: string}} Login details.
 */
function subscriber() {
	const password = crypto.randomBytes( 16 ).toString( 'hex' );

	wpEval( `
		$user = get_user_by( 'login', 'e2e-subscriber' );
		$id   = $user ? $user->ID : wp_insert_user( [ 'user_login' => 'e2e-subscriber', 'user_email' => 'e2e-subscriber@example.com', 'role' => 'subscriber', 'user_pass' => wp_generate_password() ] );
		wp_set_password( '${ password }', $id );
	` );

	return { username: 'e2e-subscriber', password };
}

/**
 * Publishes the pending request with the given title, as a moderator approving it.
 *
 * @param {string} title Request title.
 */
function approve( title ) {
	const encoded = Buffer.from( title ).toString( 'base64' );

	wpEval( `
		use FeatureRequestSystem\\Content\\RequestPostType;
		$ids = get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'pending', 'title' => base64_decode( '${ encoded }' ), 'fields' => 'ids' ] );
		wp_publish_post( $ids[0] );
	` );
}

/**
 * Logs in through the login form.
 *
 * @param {import('@playwright/test').Page}      page  Browser page.
 * @param {{username: string, password: string}} login Login details.
 */
async function logIn( page, { username, password } ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );
}

/**
 * Waits until a person could have filled in the form since the page loaded.
 *
 * @param {import('@playwright/test').Page} page     Browser page.
 * @param {number}                          loadedAt When the page loaded, from Date.now().
 */
async function waitLikeAPerson( page, loadedAt ) {
	await page.waitForTimeout(
		Math.max( 0, HUMAN_DELAY - ( Date.now() - loadedAt ) )
	);
}

test.describe( 'Feature request form', () => {
	test( 'an administrator’s request is published straight away', async ( {
		page,
	} ) => {
		const form = setUpPage();
		await page.goto( form.url );

		// Sent at once: the script holds it until the server would accept it.
		await page.getByLabel( 'Title' ).fill( 'Webhooks' );
		await page.getByRole( 'button', { name: 'Send request' } ).click();

		await expect(
			page.getByText( 'Thanks! Your request is on the board.' )
		).toBeVisible();
		await page.getByRole( 'link', { name: 'View your request' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Webhooks' } )
		).toBeVisible();
	} );

	test.describe( 'for a subscriber', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'similar requests are suggested, and a new request appears once approved', async ( {
			page,
		} ) => {
			const form = setUpPage( { requests: [ 'Dark mode' ] } );
			await logIn( page, subscriber() );
			await page.goto( form.url );
			const title = page.getByLabel( 'Title' );
			const suggestions = page.getByRole( 'list', {
				name: 'Already requested? Vote for one of these instead:',
			} );

			await title.fill( 'Dark mode for reports' );
			await expect(
				suggestions.getByRole( 'link', { name: 'Dark mode' } )
			).toBeVisible();

			await title.fill( 'Weekly email digest' );
			await expect( suggestions ).toBeHidden();

			await page
				.getByLabel( 'Details' )
				.fill( 'A summary of new requests every Monday.' );
			await page.getByRole( 'button', { name: 'Send request' } ).click();

			await expect(
				page.getByText(
					'Thanks! Your request will appear on the board once it is approved.'
				)
			).toBeVisible();
			await expect( title ).toHaveValue( '' );
			await expect( page.locator( '.mwtfrs-request__title' ) ).toHaveText(
				[ 'Dark mode' ]
			);

			approve( 'Weekly email digest' );
			await page.reload();
			await expect(
				page.locator( '.mwtfrs-request__title', {
					hasText: 'Weekly email digest',
				} )
			).toBeVisible();
		} );
	} );

	test.describe( 'for visitors who are not logged in', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'a guest is asked to log in on a board for logged-in users', async ( {
			page,
		} ) => {
			const form = setUpPage();
			await page.goto( form.url );

			await expect(
				page.getByText( 'Log in to suggest a feature.' )
			).toBeVisible();
			await expect( page.getByLabel( 'Title' ) ).toHaveCount( 0 );
		} );

		test( 'a guest submits to a board that allows guests', async ( {
			page,
		} ) => {
			const form = setUpPage( { submitting: 'ANYONE' } );
			await page.goto( form.url );

			await page.getByLabel( 'Title' ).fill( 'Calendar view' );
			await page.getByLabel( 'Your name' ).fill( 'Robin' );
			await page.getByRole( 'button', { name: 'Send request' } ).click();

			await expect(
				page.getByText(
					'Thanks! Your request will appear on the board once it is approved.'
				)
			).toBeVisible();
		} );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( {
			javaScriptEnabled: false,
			storageState: { cookies: [], origins: [] },
		} );

		test( 'a guest’s request is sent and the page says what happened', async ( {
			page,
		} ) => {
			const form = setUpPage( {
				submitting: 'ANYONE',
				moderation: false,
			} );
			await page.goto( form.url );
			const loadedAt = Date.now();

			await page.getByLabel( 'Title' ).fill( 'Export to PDF' );
			await page.getByLabel( 'Email' ).fill( 'robin@example.com' );
			await waitLikeAPerson( page, loadedAt );
			await page.getByRole( 'button', { name: 'Send request' } ).click();

			await expect(
				page.getByText( 'Thanks! Your request is on the board.' )
			).toBeVisible();
			await expect( page.locator( '.mwtfrs-request__title' ) ).toHaveText(
				[ 'Export to PDF' ]
			);
			await expect( page.locator( 'body' ) ).not.toContainText(
				'robin@example.com'
			);
		} );
	} );
} );
