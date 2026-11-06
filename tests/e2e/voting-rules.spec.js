/**
 * The voting rules of 1.1 in a real browser: trending, vote limits, the vote threshold,
 * and the hooks that decide who may vote and suggest.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { default: AxeBuilder } = require( '@axe-core/playwright' );
const { wpEval } = require( './wp-cli' );

/**
 * Replaces all requests with the given ones on the default board, sets the board's rules,
 * and returns a page that shows the board and the form.
 *
 * @param {Object}   options
 * @param {string}   [options.voting]    Who may vote: ANYONE, LOGGED_IN or NOBODY.
 * @param {number}   [options.limit]     Votes each person can give, 0 for no limit.
 * @param {Object}   [options.threshold] Threshold: votes, and the slugs from and to.
 * @param {string}   [options.access]    What the site's access filters say: refuse, allow, or '' to keep the board's answer.
 * @param {Object[]} options.requests    Requests to create: title, and the ages in days of their votes.
 * @return {{url: string, board: number, ids: Object<string, number>}} The page, the board and the requests by title.
 */
function setUp( {
	voting = 'LOGGED_IN',
	limit = 0,
	threshold = null,
	access = '',
	requests,
} ) {
	const data = Buffer.from(
		JSON.stringify( { requests, threshold, access } )
	).toString( 'base64' );

	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Content\\StatusTaxonomy;
			use FeatureRequestSystem\\Install\\Schema;
			use FeatureRequestSystem\\Notifications\\Queue;
			use FeatureRequestSystem\\Security\\RateLimiter;
			use FeatureRequestSystem\\Voting\\Trending;
			use FeatureRequestSystem\\Voting\\VoteCount;
			use FeatureRequestSystem\\Voting\\VoteRepository;
			global $wpdb;

			$data = json_decode( base64_decode( '${ data }' ), true );

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%' ) );
			Queue::cancel_all();

			$board     = RequestTerms::default_term( BoardTaxonomy::NAME );
			$threshold = [];
			if ( $data['threshold'] ) {
				$threshold = [
					'votes' => $data['threshold']['votes'],
					'from'  => get_term_by( 'slug', $data['threshold']['from'], StatusTaxonomy::NAME )->term_id,
					'to'    => get_term_by( 'slug', $data['threshold']['to'], StatusTaxonomy::NAME )->term_id,
				];
			}
			BoardSettings::update( $board, [
				BoardSettings::VOTING     => BoardSettings::${ voting },
				BoardSettings::SUBMITTING => BoardSettings::LOGGED_IN,
				BoardSettings::MODERATION => true,
				BoardSettings::VOTE_LIMIT => ${ Number( limit ) },
				BoardSettings::THRESHOLD  => $threshold,
			] );

			// The site's own code decides who may vote and suggest, as a Pro plugin would.
			$mu = WP_CONTENT_DIR . '/mu-plugins/e2e-access.php';
			if ( $data['access'] ) {
				wp_mkdir_p( dirname( $mu ) );
				file_put_contents( $mu, '<?php foreach ( [ "mwtfrs_can_vote", "mwtfrs_can_submit" ] as $hook ) { add_filter( $hook, "__return_' . ( 'allow' === $data['access'] ? 'true' : 'false' ) . '" ); }' );
			} elseif ( file_exists( $mu ) ) {
				unlink( $mu );
			}

			$ids = [];
			foreach ( $data['requests'] as $index => $request ) {
				$id = wp_insert_post( [
					'post_type'   => RequestPostType::NAME,
					'post_status' => 'publish',
					'post_title'  => $request['title'],
				] );
				foreach ( $request['votes'] as $number => $age ) {
					$key = hash( 'sha256', 'e2e:' . $id . ':' . $number );
					VoteRepository::add( $id, $key, 0 );
					$wpdb->update( Schema::table( Schema::VOTES ), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $age * DAY_IN_SECONDS ) ], [ 'voter_key' => $key ] );
				}
				VoteCount::sync( $id );
				Trending::sync( $id );
				$ids[ $request['title'] ] = $id;
			}

			$page = get_page_by_path( 'e2e-voting-rules' );
			$page_id = $page ? $page->ID : wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'E2E voting rules',
				'post_name'    => 'e2e-voting-rules',
				'post_content' => '<!-- wp:mwtfrs/submit /-->' . "\\n\\n" . '<!-- wp:mwtfrs/board /-->',
			] );
			echo wp_json_encode( [ 'url' => get_permalink( $page_id ), 'board' => $board, 'ids' => $ids ] );
		` )
	);
}

/**
 * Puts the board's rules back as other tests expect them, and removes the access filters.
 */
function tearDown() {
	wpEval( `
		use FeatureRequestSystem\\Content\\BoardSettings;
		use FeatureRequestSystem\\Content\\BoardTaxonomy;
		use FeatureRequestSystem\\Content\\RequestTerms;

		BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTE_LIMIT => 0, BoardSettings::THRESHOLD => [] ] );
		$mu = WP_CONTENT_DIR . '/mu-plugins/e2e-access.php';
		if ( file_exists( $mu ) ) {
			unlink( $mu );
		}
	` );
}

/**
 * The vote button of a request.
 *
 * @param {import('@playwright/test').Page} page  Browser page.
 * @param {string}                          title Request title.
 * @return {import('@playwright/test').Locator} The button.
 */
function voteButton( page, title ) {
	return page.getByRole( 'button', {
		name: new RegExp( `Vote for ${ title }` ),
	} );
}

test.describe( 'Voting rules', () => {
	test.afterAll( tearDown );

	test( 'the board sorts by trending: the most votes of the last 30 days first', async ( {
		page,
	} ) => {
		const board = setUp( {
			requests: [
				{ title: 'Old favourite', votes: [ 90, 80, 70, 60 ] },
				{ title: 'Rising idea', votes: [ 1, 2 ] },
				{ title: 'Quiet idea', votes: [ 3 ] },
			],
		} );
		await page.goto( board.url );
		const titles = page.locator( '.mwtfrs-request__title' );
		await expect( titles ).toHaveText( [
			'Old favourite',
			'Rising idea',
			'Quiet idea',
		] );

		// With JavaScript the board applies the order as soon as it is chosen.
		await page.getByLabel( 'Sort by' ).selectOption( 'trending' );

		await expect( page ).toHaveURL( /fr-sort=trending/ );
		await expect( titles ).toHaveText( [
			'Rising idea',
			'Quiet idea',
			'Old favourite',
		] );

		// A vote counts at once. Equal numbers of recent votes go by votes in all.
		await voteButton( page, 'Old favourite' ).click();
		await expect( voteButton( page, 'Old favourite' ) ).toHaveAttribute(
			'aria-pressed',
			'true'
		);
		await page.reload();
		await expect( titles ).toHaveText( [
			'Rising idea',
			'Old favourite',
			'Quiet idea',
		] );
	} );

	test( 'an administrator sets the vote limit and the threshold on the board’s screen', async ( {
		page,
	} ) => {
		const board = setUp( {
			requests: [ { title: 'Dark mode', votes: [ 1 ] } ],
		} );
		await page.goto(
			`/wp-admin/term.php?taxonomy=mwtfrs_board&tag_ID=${ board.board }&post_type=mwtfrs_request`
		);

		await page.getByLabel( 'Votes each person can give' ).fill( '3' );
		await page.getByLabel( 'Vote threshold' ).fill( '2' );
		await page
			.getByLabel( 'Status to move the request from' )
			.selectOption( { label: 'Open' } );
		await page
			.getByLabel( 'Status to move the request to' )
			.selectOption( { label: 'Planned' } );
		await page.getByRole( 'button', { name: 'Update' } ).click();
		await expect( page.locator( '#message' ) ).toBeVisible();
		await expect(
			page.getByLabel( 'Votes each person can give' )
		).toHaveValue( '3' );
		await expect( page.getByLabel( 'Vote threshold' ) ).toHaveValue( '2' );

		await page.goto( board.url );
		await expect(
			page.getByText(
				'Each person can vote for 3 requests on this board.'
			)
		).toBeVisible();
		await expect(
			page.getByText( 'Votes you have left: 3.' )
		).toBeVisible();

		// The second vote reaches the threshold and moves the request.
		await voteButton( page, 'Dark mode' ).click();
		await expect(
			page.getByText( 'Votes you have left: 2.' )
		).toBeVisible();
		await page.reload();
		await expect(
			page.locator( '.mwtfrs-request', { hasText: 'Dark mode' } )
		).toContainText( 'Planned' );
	} );

	test( 'a user votes up to the limit, and gets a vote back by taking one back', async ( {
		page,
	} ) => {
		const board = setUp( {
			limit: 2,
			requests: [
				{ title: 'First idea', votes: [] },
				{ title: 'Second idea', votes: [] },
				{ title: 'Third idea', votes: [] },
			],
		} );
		await page.goto( board.url );
		const left = page.locator( '.mwtfrs-board__allowance' );
		await expect( left ).toContainText(
			'Each person can vote for 2 requests on this board.'
		);
		await expect( left ).toContainText( 'Votes you have left: 2.' );

		await voteButton( page, 'First idea' ).click();
		await expect( left ).toContainText( 'Votes you have left: 1.' );
		await voteButton( page, 'Second idea' ).click();
		await expect( left ).toContainText( 'Votes you have left: 0.' );

		await voteButton( page, 'Third idea' ).click();
		await expect(
			page
				.locator( '.mwtfrs-request', { hasText: 'Third idea' } )
				.getByText( 'You have used all your votes on this board.' )
		).toBeVisible();
		await expect( voteButton( page, 'Third idea' ) ).toHaveAttribute(
			'aria-pressed',
			'false'
		);
		await expect( voteButton( page, 'Third idea' ) ).toContainText( '0' );

		await voteButton( page, 'First idea' ).click();
		await expect( left ).toContainText( 'Votes you have left: 1.' );
		await voteButton( page, 'Third idea' ).click();
		await expect( voteButton( page, 'Third idea' ) ).toHaveAttribute(
			'aria-pressed',
			'true'
		);
		await expect( left ).toContainText( 'Votes you have left: 0.' );

		const { violations } = await new AxeBuilder( { page } )
			.withTags( [
				'wcag2a',
				'wcag2aa',
				'wcag21a',
				'wcag21aa',
				'wcag22aa',
			] )
			.include( '.wp-block-mwtfrs-board' )
			.analyze();
		expect( violations.map( ( violation ) => violation.id ) ).toEqual( [] );
	} );

	test( 'the site’s code can refuse votes and suggestions that the board allows', async ( {
		page,
	} ) => {
		const board = setUp( {
			access: 'refuse',
			requests: [ { title: 'Dark mode', votes: [ 1, 2 ] } ],
		} );
		await page.goto( board.url );

		await expect( voteButton( page, 'Dark mode' ) ).toHaveCount( 0 );
		await expect(
			page.locator( '.mwtfrs-request', { hasText: 'Dark mode' } )
		).toContainText( 'Voting is closed.' );
		await expect(
			page.getByText( 'You cannot suggest features on this board.' )
		).toBeVisible();
		await expect( page.getByLabel( 'Title' ) ).toHaveCount( 0 );
	} );

	test.describe( 'for visitors who are not logged in', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'a guest learns how many votes are left after the page loads', async ( {
			page,
		} ) => {
			const board = setUp( {
				voting: 'ANYONE',
				limit: 1,
				requests: [
					{ title: 'Offline mode', votes: [] },
					{ title: 'Calendar view', votes: [] },
				],
			} );
			await page.goto( board.url );
			const left = page.locator( '.mwtfrs-board__allowance' );
			await expect( left ).toContainText( 'Votes you have left: 1.' );

			await voteButton( page, 'Offline mode' ).click();
			await expect( left ).toContainText( 'Votes you have left: 0.' );
			await page.reload();
			await expect( left ).toContainText( 'Votes you have left: 0.' );

			await voteButton( page, 'Calendar view' ).click();
			await expect(
				page.getByText( 'You have used all your votes on this board.' )
			).toBeVisible();
		} );

		test( 'the site’s code can let guests vote and suggest where the board wants users', async ( {
			page,
		} ) => {
			const board = setUp( {
				access: 'allow',
				requests: [ { title: 'Offline mode', votes: [] } ],
			} );
			await page.goto( board.url );

			await voteButton( page, 'Offline mode' ).click();
			await expect( voteButton( page, 'Offline mode' ) ).toHaveAttribute(
				'aria-pressed',
				'true'
			);
			await page.reload();
			await expect( voteButton( page, 'Offline mode' ) ).toHaveAttribute(
				'aria-pressed',
				'true'
			);

			await page.getByLabel( 'Title' ).fill( 'Calendar view' );
			await page.getByRole( 'button', { name: 'Send request' } ).click();
			await expect(
				page.getByText(
					'Thanks! Your request will appear on the board once it is approved.'
				)
			).toBeVisible();
		} );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'the board says when the votes are used up', async ( {
			page,
		} ) => {
			const board = setUp( {
				limit: 1,
				requests: [
					{ title: 'First idea', votes: [] },
					{ title: 'Second idea', votes: [] },
				],
			} );
			await page.goto( board.url );
			await expect(
				page.getByText( 'Votes you have left: 1.' )
			).toBeVisible();

			await voteButton( page, 'First idea' ).click();
			await expect(
				page.getByText( 'Your vote was added.' )
			).toBeVisible();
			await expect(
				page.getByText( 'Votes you have left: 0.' )
			).toBeVisible();

			await voteButton( page, 'Second idea' ).click();
			await expect(
				page.getByText( 'You have used all your votes on this board.' )
			).toBeVisible();
			await expect( voteButton( page, 'Second idea' ) ).toContainText(
				'0'
			);
		} );
	} );
} );
