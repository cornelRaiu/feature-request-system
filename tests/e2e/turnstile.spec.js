/**
 * Cloudflare Turnstile on the Feature Request Form, in a real browser.
 *
 * Cloudflare is never called. The browser gets a stand-in for Cloudflare's script,
 * which puts a token in the form as the widget does, and the site gets a must-use
 * plugin that answers for Cloudflare's siteverify endpoint.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpEval } = require( './wp-cli' );

const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
const SITE_KEY = '1x00000000000000000000AA';
const SECRET = 'e2e-turnstile-secret';
const GOOD_TOKEN = 'e2e-confirmed-token';

// Without JavaScript the server refuses a form sent within 3 seconds of loading it.
const HUMAN_DELAY = 3500;

/**
 * Answers for siteverify on the site: it confirms GOOD_TOKEN sent with SECRET.
 */
const FAKE_SITEVERIFY = `<?php
add_filter( 'pre_http_request', static function ( $preempt, $args, $url ) {
	if ( 0 !== strpos( $url, 'https://challenges.cloudflare.com/turnstile/v0/siteverify' ) ) {
		return $preempt;
	}
	$body = (array) ( $args['body'] ?? [] );
	$good = '${ SECRET }' === ( $body['secret'] ?? '' ) && '${ GOOD_TOKEN }' === ( $body['response'] ?? '' );
	return [ 'headers' => [], 'body' => wp_json_encode( [ 'success' => $good ] ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
}, 10, 3 );
`;

/**
 * Removes all requests, lets guests suggest features, and saves the Turnstile keys,
 * or removes them.
 *
 * @param {Object}  options
 * @param {boolean} [options.keys] Whether to save both keys.
 * @return {{url: string}} A page with the form above the board.
 */
function setUp( { keys = true } = {} ) {
	const plugin = Buffer.from( FAKE_SITEVERIFY ).toString( 'base64' );

	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Frontend\\Blocks;
			use FeatureRequestSystem\\Security\\RateLimiter;
			use FeatureRequestSystem\\Settings;
			global $wpdb;

			wp_mkdir_p( WPMU_PLUGIN_DIR );
			file_put_contents( WPMU_PLUGIN_DIR . '/mwtfrs-e2e-turnstile.php', base64_decode( '${ plugin }' ) );

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%' ) );
			Settings::update( Settings::TURNSTILE_SITE_KEY, ${ keys ? `'${ SITE_KEY }'` : "''" } );
			Settings::update( Settings::TURNSTILE_SECRET_KEY, ${ keys ? `'${ SECRET }'` : "''" } );
			Settings::update( Settings::TURNSTILE_LOGGED_IN, false );
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), array_merge( BoardSettings::defaults(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] ) );

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
 * Titles of the requests saved on the site, in any status.
 *
 * @return {string[]} Titles.
 */
function savedTitles() {
	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\RequestPostType;
			echo wp_json_encode( wp_list_pluck( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1 ] ), 'post_title' ) );
		` )
	);
}

/**
 * Serves a stand-in for Cloudflare's script, which gives each widget the token, and
 * counts how often the form asks for a new one. Records which scripts were asked for.
 *
 * @param {import('@playwright/test').Page} page  Browser page.
 * @param {string}                          token Token the widget gives.
 * @return {string[]} The addresses asked for at Cloudflare, filled in as the page loads.
 */
async function fakeCloudflare( page, token ) {
	const asked = [];
	await page.route( 'https://challenges.cloudflare.com/**', ( route ) => {
		asked.push( route.request().url() );
		route.fulfill( {
			contentType: 'text/javascript',
			body: `
				window.e2eRenewed = 0;
				const fill = ( widget ) => {
					let input = widget.querySelector( 'input' );
					if ( ! input ) {
						// As wide as Cloudflare's widget at the size the form asks for.
						const box = document.createElement( 'div' );
						box.style.cssText =
							widget.dataset.size === 'compact'
								? 'width: 150px; height: 140px'
								: 'min-width: 300px; height: 65px';
						input = document.createElement( 'input' );
						input.type = 'hidden';
						input.name = widget.dataset.responseFieldName;
						widget.append( box, input );
					}
					input.value = ${ JSON.stringify( token ) };
				};
				window.turnstile = { reset( widget ) { window.e2eRenewed++; fill( widget ); } };
				document.querySelectorAll( '.cf-turnstile' ).forEach( fill );
			`,
		} );
	} );

	return asked;
}

test.describe( 'Cloudflare Turnstile', () => {
	test.afterAll( () => {
		wpEval( `
			use FeatureRequestSystem\\Settings;
			Settings::update( Settings::TURNSTILE_SITE_KEY, '' );
			Settings::update( Settings::TURNSTILE_SECRET_KEY, '' );
			Settings::update( Settings::TURNSTILE_LOGGED_IN, false );
			wp_delete_file( WPMU_PLUGIN_DIR . '/mwtfrs-e2e-turnstile.php' );
		` );
	} );

	test( 'the settings page saves the keys and never shows the secret key again', async ( {
		page,
	} ) => {
		setUp( { keys: false } );
		await page.goto(
			'/wp-admin/edit.php?post_type=mwtfrs_request&page=mwtfrs-settings'
		);

		await page.getByLabel( 'Turnstile site key' ).fill( SITE_KEY );
		await page
			.getByLabel( 'Turnstile secret key' )
			.fill( 'typed-secret-of-this-site' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();

		await expect( page.getByLabel( 'Turnstile site key' ) ).toHaveValue(
			SITE_KEY
		);
		const secret = page.getByLabel( 'Turnstile secret key' );
		await expect( secret ).toHaveValue( '' );
		await expect( secret ).toHaveAttribute( 'placeholder', 'Saved' );
		await expect(
			page.getByText( 'A secret key is saved. It is not shown again.' )
		).toBeVisible();
		expect( await page.content() ).not.toContain(
			'typed-secret-of-this-site'
		);

		// Saving again with the field left empty keeps the key.
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		expect(
			wpEval( `
				use FeatureRequestSystem\\Settings;
				echo Settings::get( Settings::TURNSTILE_SECRET_KEY );
			` )
		).toBe( 'typed-secret-of-this-site' );

		await page
			.getByLabel(
				'Remove the saved secret key, which turns the check off'
			)
			.check();
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		await expect(
			page.getByLabel( 'Turnstile secret key' )
		).not.toHaveAttribute( 'placeholder', 'Saved' );
	} );

	test.describe( 'for visitors who are not logged in', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'a guest’s request goes through once Cloudflare confirms the token', async ( {
			page,
		} ) => {
			const form = setUp();
			const asked = await fakeCloudflare( page, GOOD_TOKEN );
			await page.goto( form.url );

			await expect( page.locator( '.cf-turnstile' ) ).toHaveAttribute(
				'data-sitekey',
				SITE_KEY
			);
			await expect.poll( () => asked ).toEqual( [ SCRIPT ] );
			await expect( page.locator( '.cf-turnstile' ) ).toHaveAttribute(
				'data-size',
				'flexible'
			);
			expect( await page.content() ).not.toContain( SECRET );

			await page.getByLabel( 'Title' ).fill( 'Calendar view' );
			await page.getByRole( 'button', { name: 'Send request' } ).click();

			await expect(
				page.getByText(
					'Thanks! Your request will appear on the board once it is approved.'
				)
			).toBeVisible();
			expect( savedTitles() ).toEqual( [ 'Calendar view' ] );
			// A token works once, so the form asks the widget for a new one.
			expect( await page.evaluate( () => window.e2eRenewed ) ).toBe( 1 );
		} );

		test( 'a token Cloudflare does not confirm is refused, and the guest is told what to do', async ( {
			page,
		} ) => {
			const form = setUp();
			await fakeCloudflare( page, 'forged-token' );
			await page.goto( form.url );

			await page.getByLabel( 'Title' ).fill( 'Calendar view' );
			await page.getByRole( 'button', { name: 'Send request' } ).click();

			await expect(
				page.getByText(
					'Complete the spam check above the button, then send your request again.'
				)
			).toBeVisible();
			expect( savedTitles() ).toEqual( [] );
		} );

		test( 'on a narrow screen the widget takes its compact size, so nothing sticks out', async ( {
			page,
		} ) => {
			const form = setUp();
			await fakeCloudflare( page, GOOD_TOKEN );
			// WCAG 1.4.10: content fits a width of 320 CSS pixels.
			await page.setViewportSize( { width: 320, height: 640 } );
			await page.goto( form.url );

			const widget = page.locator( '.cf-turnstile' );
			await expect( widget ).toHaveAttribute( 'data-size', 'compact' );
			await expect( widget.locator( 'input' ) ).toHaveCount( 1 );
			const overflow = await page.evaluate( () => {
				const element = document.querySelector(
					'.wp-block-mwtfrs-submit'
				);
				return (
					element.scrollWidth > element.clientWidth + 1 ||
					element.getBoundingClientRect().right >
						window.innerWidth + 1 ||
					document.documentElement.scrollWidth > window.innerWidth + 1
				);
			} );
			expect( overflow ).toBe( false );
		} );

		test( 'without both keys nothing is loaded from Cloudflare', async ( {
			page,
		} ) => {
			const form = setUp( { keys: false } );
			const asked = await fakeCloudflare( page, GOOD_TOKEN );
			await page.goto( form.url );

			await expect( page.getByLabel( 'Title' ) ).toBeVisible();
			await expect( page.locator( '.cf-turnstile' ) ).toHaveCount( 0 );
			await page.waitForLoadState( 'networkidle' );
			expect( asked ).toEqual( [] );
		} );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( {
			javaScriptEnabled: false,
			storageState: { cookies: [], origins: [] },
		} );

		test( 'the form says it needs JavaScript, and refuses a request sent without it', async ( {
			page,
		} ) => {
			const form = setUp();
			const response = await page.goto( form.url );
			const loadedAt = Date.now();

			// The browser here only stops scripts from running. It still parses the page
			// as one with scripts and leaves <noscript> out, so the test reads the HTML.
			expect( await response.text() ).toMatch(
				/<noscript><p[^>]*>The spam check of this form needs JavaScript\. Turn it on to send your request\.<\/p><\/noscript>/
			);
			await page.getByLabel( 'Title' ).fill( 'Export to PDF' );
			await page.waitForTimeout(
				Math.max( 0, HUMAN_DELAY - ( Date.now() - loadedAt ) )
			);
			await page.getByRole( 'button', { name: 'Send request' } ).click();

			await expect(
				page.getByText(
					'Complete the spam check above the button, then send your request again.'
				)
			).toBeVisible();
			expect( savedTitles() ).toEqual( [] );
		} );
	} );
} );
