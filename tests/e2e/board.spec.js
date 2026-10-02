/**
 * The Feature Request Board in a real browser.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpEval } = require( './wp-cli' );

/**
 * Replaces all requests with the given ones and returns a page showing the board.
 *
 * Also clears rate limit counters, so repeated runs within the hour do not hit them.
 *
 * @param {Object}   options
 * @param {boolean}  [options.guests] Whether visitors who are not logged in may vote.
 * @param {Object[]} options.requests Requests to create: title, votes, daysAgo.
 * @return {{url: string}} The board page.
 */
function setUpBoard( { guests = false, requests } ) {
	const data = Buffer.from( JSON.stringify( requests ) ).toString( 'base64' );
	const voting = guests ? 'ANYONE' : 'LOGGED_IN';

	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Security\\RateLimiter;
			use FeatureRequestSystem\\Voting\\VoteCount;
			use FeatureRequestSystem\\Voting\\VoteRepository;
			global $wpdb;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%' ) );
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::${ voting } ] );

			foreach ( json_decode( base64_decode( '${ data }' ), true ) as $request ) {
				$id = wp_insert_post( [
					'post_type'   => RequestPostType::NAME,
					'post_status' => 'publish',
					'post_title'  => $request['title'],
					'post_date'   => gmdate( 'Y-m-d H:i:s', time() - ( $request['daysAgo'] ?? 0 ) * DAY_IN_SECONDS ),
				] );
				for ( $i = 0; $i < $request['votes']; $i++ ) {
					VoteRepository::add( $id, hash( 'sha256', 'e2e:' . $id . ':' . $i ), 0 );
				}
				VoteCount::sync( $id );
			}

			$page = get_page_by_path( 'e2e-board' );
			$page_id = $page ? $page->ID : wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'E2E board',
				'post_name'    => 'e2e-board',
				'post_content' => '<!-- wp:mwtfrs/board /-->',
			] );
			echo wp_json_encode( [ 'url' => get_permalink( $page_id ) ] );
		` )
	);
}

test.describe( 'Feature request board', () => {
	test( 'a logged-in user votes, still sees the vote after reloading, and takes it back', async ( {
		page,
	} ) => {
		const board = setUpBoard( {
			requests: [ { title: 'Dark mode', votes: 3 } ],
		} );
		await page.goto( board.url );
		const button = page.getByRole( 'button', {
			name: /Vote for Dark mode/,
		} );

		await expect( button ).toHaveAttribute( 'aria-pressed', 'false' );
		await button.click();
		await expect( button ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( button ).toContainText( '4' );

		await page.reload();
		await expect( button ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( button ).toContainText( '4' );

		await button.click();
		await expect( button ).toHaveAttribute( 'aria-pressed', 'false' );
		await expect( button ).toContainText( '3' );
	} );

	test( 'the board lists the most voted requests first and can show the newest first', async ( {
		page,
	} ) => {
		const board = setUpBoard( {
			requests: [
				{ title: 'Popular older idea', votes: 9, daysAgo: 5 },
				{ title: 'Quiet newer idea', votes: 1, daysAgo: 1 },
			],
		} );
		await page.goto( board.url );
		const titles = page.locator( '.mwtfrs-request__title' );

		await expect( titles ).toHaveText( [
			'Popular older idea',
			'Quiet newer idea',
		] );

		await page.getByLabel( 'Sort by' ).selectOption( 'new' );
		await page.getByRole( 'button', { name: 'Show requests' } ).click();
		await expect( titles ).toHaveText( [
			'Quiet newer idea',
			'Popular older idea',
		] );
	} );

	test.describe( 'for visitors who are not logged in', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'a guest votes on a board that allows guests and is remembered', async ( {
			page,
		} ) => {
			const board = setUpBoard( {
				guests: true,
				requests: [ { title: 'Offline mode', votes: 0 } ],
			} );
			await page.goto( board.url );
			const button = page.getByRole( 'button', {
				name: /Vote for Offline mode/,
			} );

			await button.click();
			await expect( button ).toHaveAttribute( 'aria-pressed', 'true' );
			await expect( button ).toContainText( '1' );

			await page.reload();
			await expect( button ).toHaveAttribute( 'aria-pressed', 'true' );
		} );

		test( 'a link on a page of the site cannot make a guest vote', async ( {
			page,
		} ) => {
			const board = setUpBoard( {
				guests: true,
				requests: [ { title: 'Offline mode', votes: 0 } ],
			} );
			const id = wpEval(
				`echo get_posts( [ 'post_type' => FeatureRequestSystem\\Content\\RequestPostType::NAME, 'fields' => 'ids', 'numberposts' => 1 ] )[0];`
			);
			await page.goto( board.url );

			// WordPress lets a GET act as a POST with _method=POST. A link or an image on a page
			// of the site sends just that: a GET that names the site as its referrer.
			const status = await page.evaluate( async ( requestId ) => {
				const root = document.querySelector(
					'link[rel="https://api.w.org/"]'
				).href;
				const route = `${ root }mwtfrs/v1/requests/${ requestId }/vote`;
				const answer = await fetch(
					route +
						( route.includes( '?' ) ? '&' : '?' ) +
						'_method=POST',
					{ credentials: 'same-origin' }
				);

				return answer.status;
			}, id );
			expect( status ).toBe( 403 );

			await page.reload();
			const button = page.getByRole( 'button', {
				name: /Vote for Offline mode/,
			} );
			await expect( button ).toHaveAttribute( 'aria-pressed', 'false' );
			await expect( button ).toContainText( '0' );
		} );

		test( 'a guest is asked to log in on a board for logged-in users', async ( {
			page,
		} ) => {
			const board = setUpBoard( {
				requests: [ { title: 'Slack notifications', votes: 2 } ],
			} );
			await page.goto( board.url );

			await page
				.getByRole( 'button', { name: /Vote for Slack notifications/ } )
				.click();

			await expect(
				page.getByText( 'Log in to vote on this request.' )
			).toBeVisible();
			await expect(
				page.getByRole( 'link', { name: 'Log in', exact: true } )
			).toBeVisible();
		} );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'the vote button still adds and removes the vote', async ( {
			page,
		} ) => {
			const board = setUpBoard( {
				requests: [ { title: 'Export to CSV', votes: 5 } ],
			} );
			await page.goto( board.url );
			const button = page.getByRole( 'button', {
				name: /Vote for Export to CSV/,
			} );

			await button.click();
			await expect(
				page.getByText( 'Your vote was added.' )
			).toBeVisible();
			await expect( button ).toContainText( '6' );

			await button.click();
			await expect(
				page.getByText( 'Your vote was removed.' )
			).toBeVisible();
			await expect( button ).toContainText( '5' );
		} );
	} );
} );
