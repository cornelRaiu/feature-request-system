/**
 * Visitors who confirm their email, in a real browser: from the vote that asks for an
 * address, through the emailed link, to the vote that counts.
 *
 * The site does not send mail here. A small must-use plugin, added for these tests and
 * removed after them, keeps what the plugin would have sent, so the tests can read the
 * link as a visitor reads it in their mail program.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { default: AxeBuilder } = require( '@axe-core/playwright' );
const { wpEval } = require( './wp-cli' );

const RULES = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

// The server refuses forms sent within 3 seconds of rendering them, as bots send
// them. With JavaScript the page waits by itself; without it, the tests wait.
const HUMAN_DELAY = 3500;

// Option where the must-use plugin keeps the emails.
const MAILBOX = 'mwtfrs_e2e_mailbox';

/**
 * Adds the must-use plugin that keeps emails instead of sending them.
 */
function installMailbox() {
	wpEval( `
		wp_mkdir_p( WPMU_PLUGIN_DIR );
		file_put_contents(
			WPMU_PLUGIN_DIR . '/mwtfrs-e2e-mailbox.php',
			'<?php
			add_filter( "pre_wp_mail", static function ( $pre, $mail ) {
				$box   = get_option( "${ MAILBOX }", [] );
				$box[] = [ "to" => (array) $mail["to"], "subject" => $mail["subject"], "message" => $mail["message"] ];
				update_option( "${ MAILBOX }", $box, false );
				return true;
			}, 10, 2 );'
		);
	` );
}

/**
 * Removes the must-use plugin and what it kept.
 */
function removeMailbox() {
	wpEval( `
		@unlink( WPMU_PLUGIN_DIR . '/mwtfrs-e2e-mailbox.php' );
		delete_option( '${ MAILBOX }' );
	` );
}

/**
 * Returns the emails kept since the last setUp().
 *
 * @return {{to: string[], subject: string, message: string}[]} The emails.
 */
function mailbox() {
	return JSON.parse(
		wpEval( `echo wp_json_encode( get_option( '${ MAILBOX }', [] ) );` )
	);
}

/**
 * Returns the confirmation link in the newest email to an address.
 *
 * @param {string} address Email address.
 * @return {string} The link.
 */
function linkFor( address ) {
	const emails = mailbox().filter( ( email ) =>
		email.to.includes( address )
	);
	expect( emails.length ).toBeGreaterThan( 0 );

	return emails[ emails.length - 1 ].message.match(
		/(http\S+mwtfrs_confirm=[a-f0-9]{32})/
	)[ 1 ];
}

/**
 * Replaces all requests and guests with one published request on a board that asks
 * visitors to confirm their email, and returns the pages to use.
 *
 * @return {{board: string, request: string, id: number}} The page with the form and the board, and the request.
 */
function setUp() {
	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Frontend\\Blocks;
			use FeatureRequestSystem\\Install\\Schema;
			use FeatureRequestSystem\\Notifications\\Queue;
			use FeatureRequestSystem\\Security\\RateLimiter;
			use FeatureRequestSystem\\Settings;
			global $wpdb;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( Schema::GUESTS ) ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%' ) );
			Queue::cancel_all();
			delete_option( '${ MAILBOX }' );
			Settings::update( Settings::NOTIFY_FOLLOWERS, true );
			BoardSettings::update(
				RequestTerms::default_term( BoardTaxonomy::NAME ),
				array_merge( BoardSettings::defaults(), [ BoardSettings::VOTING => BoardSettings::VERIFIED, BoardSettings::SUBMITTING => BoardSettings::VERIFIED ] )
			);

			$id = wp_insert_post( [
				'post_type'    => RequestPostType::NAME,
				'post_status'  => 'publish',
				'post_title'   => 'Dark mode',
				'post_content' => 'Described in a sentence or two.',
			] );

			$page    = get_page_by_path( 'e2e-submit' );
			$page_id = $page ? $page->ID : wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'E2E submit',
				'post_name'    => 'e2e-submit',
				'post_content' => WP_Block_Patterns_Registry::get_instance()->get_registered( Blocks::PATTERN )['content'],
			] );

			echo wp_json_encode( [ 'board' => get_permalink( $page_id ), 'request' => get_permalink( $id ), 'id' => $id ] );
		` )
	);
}

/**
 * Returns what the site stores about a request and an address.
 *
 * @param {number} requestId Request ID.
 * @param {string} address   Email address.
 * @return {{votes: number, confirmed: boolean, follows: boolean}} The request's votes, and the guest's state.
 */
function stored( requestId, address ) {
	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Guests\\GuestRepository;
			use FeatureRequestSystem\\Notifications\\SubscriptionRepository;
			use FeatureRequestSystem\\Voting\\VoteRepository;

			$guest = GuestRepository::find_by_email( '${ address }' );
			echo wp_json_encode( [
				'votes'     => VoteRepository::count( ${ requestId } ),
				'confirmed' => $guest && '' !== $guest['confirmed_at'],
				'follows'   => $guest && null !== SubscriptionRepository::find_guest( ${ requestId }, $guest['id'] ),
			] );
		` )
	);
}

/**
 * Runs axe on parts of the page and returns what it found.
 *
 * @param {import('@playwright/test').Page} page    Browser page.
 * @param {string[]}                        include Selectors of the parts to check.
 * @return {Promise<string[]>} One line per problem.
 */
async function problems( page, include ) {
	let builder = new AxeBuilder( { page } ).withTags( RULES );
	for ( const selector of include ) {
		builder = builder.include( selector );
	}
	const { violations } = await builder.analyze();

	return violations.flatMap( ( violation ) =>
		violation.nodes.map(
			( node ) => `${ violation.id }: ${ node.target.join( ' ' ) }`
		)
	);
}

test.describe( 'Visitors who confirm their email', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( installMailbox );

	test.afterAll( () => {
		removeMailbox();
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestTerms;

			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), BoardSettings::defaults() );
		` );
	} );

	test( 'a vote asks for the email, the emailed link confirms it, and then the vote counts', async ( {
		page,
		context,
	} ) => {
		const urls = setUp();
		await page.goto( urls.board );
		const vote = page.getByRole( 'button', { name: /Vote for Dark mode/ } );
		const email = page.getByLabel( 'Your email address' );

		await expect( email ).toBeHidden();
		await vote.click();
		await expect(
			page.getByText(
				'Confirm your email address to vote. We will email you a link.'
			)
		).toBeVisible();
		await expect( email ).toBeFocused();
		expect( await problems( page, [ '.wp-block-mwtfrs-board' ] ) ).toEqual(
			[]
		);

		await email.fill( 'jane@example.com' );
		await page.getByRole( 'button', { name: 'Send me the link' } ).click();
		await expect(
			page.getByText( /^Check your email\. Open the link we sent you/ )
		).toBeVisible();
		await expect( email ).toBeHidden();
		await expect( vote ).toHaveAttribute( 'aria-pressed', 'false' );
		expect( stored( urls.id, 'jane@example.com' ) ).toEqual( {
			votes: 0,
			confirmed: false,
			follows: false,
		} );

		// The visitor opens the link from their mail program. Opening it only asks.
		const link = linkFor( 'jane@example.com' );
		const opened = await page.goto( link );
		expect( opened.headers()[ 'referrer-policy' ] ).toBe( 'strict-origin' );
		expect( opened.headers()[ 'cache-control' ] ).toContain( 'no-store' );
		await expect(
			page.getByRole( 'heading', { name: 'Confirm your email address' } )
		).toBeVisible();
		await expect(
			page.getByText( 'Your vote for "Dark mode" is added.' )
		).toBeVisible();
		await page.reload();
		expect( stored( urls.id, 'jane@example.com' ).confirmed ).toBe( false );
		expect( await problems( page, [ 'body' ] ) ).toEqual( [] );

		await page
			.getByRole( 'button', { name: 'Confirm my email address' } )
			.click();
		await expect(
			page.getByRole( 'heading', {
				name: 'Your email address is confirmed',
			} )
		).toBeVisible();
		await expect(
			page.getByText( 'Your vote for "Dark mode" was added.' )
		).toBeVisible();
		expect( await problems( page, [ 'body' ] ) ).toEqual( [] );
		expect( stored( urls.id, 'jane@example.com' ) ).toEqual( {
			votes: 1,
			confirmed: true,
			follows: true,
		} );

		const cookie = ( await context.cookies() ).find(
			( { name } ) => name === 'mwtfrs_guest'
		);
		expect( cookie.httpOnly ).toBe( true );
		expect( cookie.sameSite ).toBe( 'Lax' );
		expect( cookie.expires * 1000 ).toBeGreaterThan(
			Date.now() + 360 * 24 * 60 * 60 * 1000
		);

		// The page is the same for everyone. The guest's vote shows once it has loaded.
		await page.getByRole( 'link', { name: 'Go to the request' } ).click();
		const pressed = page.getByRole( 'button', {
			name: /Vote for Dark mode/,
		} );
		await expect( pressed ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( pressed ).toContainText( '1' );
		expect( await page.evaluate( () => document.referrer ) ).toBe( '' );

		// The link works once.
		await page.goto( link );
		await expect(
			page.getByRole( 'heading', { name: 'This link no longer works' } )
		).toBeVisible();

		// From now on the guest votes straight away.
		await page.goto( urls.board );
		const again = page.getByRole( 'button', {
			name: /Vote for Dark mode/,
		} );
		await expect( again ).toHaveAttribute( 'aria-pressed', 'true' );
		await again.click();
		await expect( again ).toHaveAttribute( 'aria-pressed', 'false' );
		await expect( page.getByLabel( 'Your email address' ) ).toBeHidden();
		expect( stored( urls.id, 'jane@example.com' ).votes ).toBe( 0 );
	} );

	test( 'a suggestion waits for the email to be confirmed, and then reaches the board', async ( {
		page,
	} ) => {
		const urls = setUp();
		await page.goto( urls.board );

		await page.getByLabel( 'Title' ).fill( 'Calendar view' );
		await page.getByLabel( 'Name' ).fill( 'Jane' );
		await page
			.getByLabel( 'Email', { exact: true } )
			.fill( 'jane@example.com' );
		await page.getByRole( 'button', { name: 'Send request' } ).click();
		await expect(
			page.getByText( /^Check your email\. Open the link we sent you/ )
		).toBeVisible();

		const email = mailbox().find( ( { to } ) =>
			to.includes( 'jane@example.com' )
		);
		expect( email.message ).toContain( 'Your feature request is sent.' );
		expect( email.message ).not.toContain( 'Calendar view' );

		await page.goto( linkFor( 'jane@example.com' ) );
		await expect(
			page.getByText( 'Your feature request "Calendar view" is sent.' )
		).toBeVisible();
		await page
			.getByRole( 'button', { name: 'Confirm my email address' } )
			.click();
		await expect(
			page.getByText(
				'Your feature request "Calendar view" was sent. It will appear on the board once it is approved.'
			)
		).toBeVisible();
		expect(
			wpEval( `
				use FeatureRequestSystem\\Content\\RequestPostType;
				$found = get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'pending', 'title' => 'Calendar view', 'fields' => 'ids' ] );
				echo $found ? get_post_meta( $found[0], RequestPostType::META_SUBMITTER_EMAIL, true ) : 'none';
			` )
		).toBe( 'jane@example.com' );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'the vote asks for the email next to the request, and the link still confirms it', async ( {
			page,
		} ) => {
			const urls = setUp();
			await page.goto( urls.board );

			await page
				.getByRole( 'button', { name: /Vote for Dark mode/ } )
				.click();
			await expect(
				page.getByText(
					'Confirm your email address to vote. We will email you a link.'
				)
			).toBeVisible();

			await page.waitForTimeout( HUMAN_DELAY );
			await page
				.getByLabel( 'Your email address' )
				.fill( 'john@example.com' );
			await page
				.getByRole( 'button', { name: 'Send me the link' } )
				.click();
			await expect(
				page.getByText(
					/^Check your email\. Open the link we sent you/
				)
			).toBeVisible();

			await page.goto( linkFor( 'john@example.com' ) );
			await page
				.getByRole( 'button', { name: 'Confirm my email address' } )
				.click();
			await expect(
				page.getByText( 'Your vote for "Dark mode" was added.' )
			).toBeVisible();
			expect( stored( urls.id, 'john@example.com' ).votes ).toBe( 1 );
		} );
	} );
} );
