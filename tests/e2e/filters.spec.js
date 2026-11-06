/**
 * The board's filters in a real browser: the switches that turn them off, and filters
 * and page links that change the list without loading the page.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { default: AxeBuilder } = require( '@axe-core/playwright' );
const { wpEval } = require( './wp-cli' );

/**
 * Replaces all requests with the given ones and returns a page that shows the board
 * with the given block attributes.
 *
 * Also clears rate limit counters, so repeated runs within the hour do not hit them.
 *
 * @param {Object}   options
 * @param {Object}   [options.attributes] Attributes of the board block.
 * @param {boolean}  [options.guests]     Whether visitors who are not logged in may vote.
 * @param {Object[]} options.requests     Requests to create: title, votes, daysAgo, status (a slug), and
 *                                        adminVoted (whether the admin voted for it).
 * @return {{url: string}} The page.
 */
function setUpBoard( { attributes = {}, guests = false, requests } ) {
	const data = Buffer.from( JSON.stringify( requests ) ).toString( 'base64' );
	const block = Buffer.from(
		`<!-- wp:mwtfrs/board ${ JSON.stringify( attributes ) } /-->`
	).toString( 'base64' );
	const voting = guests ? 'ANYONE' : 'LOGGED_IN';

	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Content\\StatusTaxonomy;
			use FeatureRequestSystem\\Security\\RateLimiter;
			use FeatureRequestSystem\\Voting\\VoteCount;
			use FeatureRequestSystem\\Voting\\VoteRepository;
			use FeatureRequestSystem\\Voting\\VoterIdentity;
			global $wpdb;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%' ) );
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::${ voting } ] );
			$admin = get_user_by( 'login', 'admin' );

			foreach ( json_decode( base64_decode( '${ data }' ), true ) as $request ) {
				$id = wp_insert_post( [
					'post_type'   => RequestPostType::NAME,
					'post_status' => 'publish',
					'post_title'  => $request['title'],
					'post_date'   => gmdate( 'Y-m-d H:i:s', time() - ( $request['daysAgo'] ?? 0 ) * DAY_IN_SECONDS ),
				] );
				wp_set_object_terms( $id, get_term_by( 'slug', $request['status'] ?? 'open', StatusTaxonomy::NAME )->term_id, StatusTaxonomy::NAME );
				for ( $i = 0; $i < $request['votes']; $i++ ) {
					VoteRepository::add( $id, hash( 'sha256', 'e2e:' . $id . ':' . $i ), 0 );
				}
				if ( ! empty( $request['adminVoted'] ) ) {
					wp_set_current_user( $admin->ID );
					VoteRepository::add( $id, VoterIdentity::key(), $admin->ID );
					wp_set_current_user( 0 );
				}
				VoteCount::sync( $id );
			}

			$page = get_page_by_path( 'e2e-filters' );
			$page = wp_insert_post( [
				'ID'           => $page ? $page->ID : 0,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'E2E filters',
				'post_name'    => 'e2e-filters',
				'post_content' => base64_decode( '${ block }' ),
			] );
			echo wp_json_encode( [ 'url' => get_permalink( $page ) ] );
		` )
	);
}

const REQUESTS = [
	{ title: 'Dark mode', votes: 9, daysAgo: 6, status: 'planned' },
	{ title: 'Export to CSV', votes: 5, daysAgo: 1 },
	{ title: 'Calendar view', votes: 2, daysAgo: 3 },
	{ title: 'Two-factor login', votes: 7, daysAgo: 9, status: 'shipped' },
];

/**
 * Marks the page, so a test can tell that it was not loaded again.
 *
 * @param {import('@playwright/test').Page} page Browser page.
 */
async function markPage( page ) {
	await page.evaluate( () => ( window.mwtfrsNotReloaded = true ) );
}

/**
 * Whether the page is still the one that was marked.
 *
 * @param {import('@playwright/test').Page} page Browser page.
 * @return {Promise<boolean>} True when the page was not loaded again.
 */
function notReloaded( page ) {
	return page.evaluate( () => window.mwtfrsNotReloaded === true );
}

test.describe( 'Board filters', () => {
	test( 'the status, a search and the order apply without loading the page, and Back brings the earlier list back', async ( {
		page,
	} ) => {
		const board = setUpBoard( { requests: REQUESTS } );
		await page.goto( board.url );
		await markPage( page );
		const titles = page.locator( '.mwtfrs-request__title' );
		const status = page.getByLabel( 'Status' );
		const search = page.getByLabel( 'Search requests' );
		const summary = page.locator( '[id$="-summary"]' );

		await expect(
			page.getByRole( 'button', { name: 'Show requests' } )
		).toBeHidden();
		await expect( titles ).toHaveText( [
			'Dark mode',
			'Export to CSV',
			'Calendar view',
		] );

		// Playwright picks the option without moving the focus, as a mouse would not.
		await status.focus();
		await status.selectOption( 'planned' );
		await expect( titles ).toHaveText( [ 'Dark mode' ] );
		await expect( page ).toHaveURL( /[?&]fr-status=planned(&|$)/ );
		await expect( summary ).toHaveText( 'Showing 1 request.' );
		await expect( summary ).toHaveAttribute( 'aria-live', 'polite' );
		await expect( status ).toBeFocused();

		await status.selectOption( 'all' );
		await expect( titles ).toHaveText( [
			'Dark mode',
			'Two-factor login',
			'Export to CSV',
			'Calendar view',
		] );

		await search.click();
		await search.pressSequentially( 'expor', { delay: 40 } );
		await expect( titles ).toHaveText( [ 'Export to CSV' ] );
		await expect( page ).toHaveURL( /[?&]fr-search=expor(&|$)/ );
		await expect( search ).toBeFocused();
		await expect( search ).toHaveValue( 'expor' );

		await search.fill( '' );
		await page.getByLabel( 'Sort by' ).selectOption( 'new' );
		await expect( titles ).toHaveText( [
			'Export to CSV',
			'Calendar view',
			'Dark mode',
			'Two-factor login',
		] );
		await expect( summary ).toHaveText( 'Showing 4 requests.' );

		// Back to the search, then to all statuses, then to the planned ones.
		await page.goBack();
		await expect( page ).toHaveURL( /[?&]fr-search=expor(&|$)/ );
		await expect( titles ).toHaveText( [ 'Export to CSV' ] );
		await expect( search ).toHaveValue( 'expor' );
		await expect( page.getByLabel( 'Sort by' ) ).toHaveValue( 'top' );

		await page.goBack();
		await page.goBack();
		await expect( titles ).toHaveText( [ 'Dark mode' ] );
		await expect( status ).toHaveValue( 'planned' );
		await expect( search ).toHaveValue( '' );

		await page.goForward();
		await expect( titles ).toHaveCount( 4 );
		await expect( status ).toHaveValue( 'all' );

		expect( await notReloaded( page ) ).toBe( true );
	} );

	test( 'a search waits until typing stops', async ( { page } ) => {
		const board = setUpBoard( { requests: REQUESTS } );
		await page.goto( board.url );
		const fetched = [];
		page.on( 'request', ( request ) => {
			if ( request.url().includes( 'fr-search=' ) ) {
				fetched.push( request.url() );
			}
		} );

		await page.getByLabel( 'Search requests' ).pressSequentially( 'dark', {
			delay: 50,
		} );
		await expect( page.locator( '.mwtfrs-request__title' ) ).toHaveText( [
			'Dark mode',
		] );

		expect( fetched ).toHaveLength( 1 );
		expect( fetched[ 0 ] ).toMatch( /fr-search=dark(&|$)/ );
	} );

	test( 'Enter applies a search at once with one history entry, and the same count is said again', async ( {
		page,
	} ) => {
		const board = setUpBoard( { requests: REQUESTS } );
		await page.goto( board.url );
		await markPage( page );
		const titles = page.locator( '.mwtfrs-request__title' );
		const summary = page.locator( '[id$="-summary"]' );

		await page.getByLabel( 'Status' ).selectOption( 'planned' );
		await expect( summary ).toHaveText( 'Showing 1 request.' );
		const said = await summary.textContent();
		const entries = await page.evaluate( () => window.history.length );

		// Enter sends the form and also changes the field: both apply the same search.
		const search = page.getByLabel( 'Search requests' );
		await search.fill( 'dark' );
		await search.press( 'Enter' );
		await expect( page ).toHaveURL( /[?&]fr-search=dark(&|$)/ );
		await expect( titles ).toHaveText( [ 'Dark mode' ] );
		await expect( summary ).toHaveText( 'Showing 1 request.' );
		expect( await summary.textContent() ).not.toBe( said );
		expect( await page.evaluate( () => window.history.length ) ).toBe(
			entries + 1
		);

		await page.goBack();
		await expect( page ).not.toHaveURL( /fr-search=/ );
		await expect( titles ).toHaveText( [ 'Dark mode' ] );
		await expect( search ).toHaveValue( '' );
		expect( await notReloaded( page ) ).toBe( true );
	} );

	test( 'a page link opened in a new tab leaves the list as it is', async ( {
		context,
		page,
	} ) => {
		const board = setUpBoard( {
			attributes: { perPage: 2 },
			requests: REQUESTS,
		} );
		await page.goto( board.url );
		const titles = page.locator( '.mwtfrs-request__title' );
		await expect( titles ).toHaveText( [ 'Dark mode', 'Export to CSV' ] );

		const opened = context.waitForEvent( 'page' );
		await page
			.getByRole( 'navigation', { name: 'More requests' } )
			.getByRole( 'link', { name: '2' } )
			.click( { modifiers: [ 'ControlOrMeta' ] } );
		const tab = await opened;

		await expect( tab ).toHaveURL( /[?&]fr-page=2(&|$)/ );
		await expect( tab.locator( '.mwtfrs-request__title' ) ).toHaveText( [
			'Calendar view',
		] );
		await tab.close();
		await expect( page ).not.toHaveURL( /fr-page=/ );
		await expect( titles ).toHaveText( [ 'Dark mode', 'Export to CSV' ] );
	} );

	test( 'page links turn pages in place, and the votes on the new page are marked', async ( {
		page,
	} ) => {
		const board = setUpBoard( {
			attributes: { perPage: 2 },
			requests: [
				{ title: 'Dark mode', votes: 9 },
				{ title: 'Export to CSV', votes: 5 },
				{ title: 'Calendar view', votes: 2, adminVoted: true },
			],
		} );
		await page.goto( board.url );
		await markPage( page );
		const titles = page.locator( '.mwtfrs-request__title' );

		await page
			.getByRole( 'navigation', { name: 'More requests' } )
			.getByRole( 'link', { name: '2' } )
			.click();

		await expect( titles ).toHaveText( [ 'Calendar view' ] );
		await expect( page ).toHaveURL( /[?&]fr-page=2(&|$)/ );
		await expect(
			page.getByRole( 'button', { name: /Vote for Calendar view/ } )
		).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( page.locator( '.mwtfrs-board__results' ) ).toBeFocused();
		await expect( page.locator( '[id$="-summary"]' ) ).toHaveText(
			'Showing 1 of 3 requests.'
		);

		// A vote on new results works as on the first ones.
		const button = page.getByRole( 'button', {
			name: /Vote for Calendar view/,
		} );
		await button.click();
		await expect( button ).toHaveAttribute( 'aria-pressed', 'false' );
		await expect( button ).toContainText( '2' );

		await page.goBack();
		await expect( titles ).toHaveText( [ 'Dark mode', 'Export to CSV' ] );
		expect( await notReloaded( page ) ).toBe( true );

		expect(
			(
				await new AxeBuilder( { page } )
					.withTags( [
						'wcag2a',
						'wcag2aa',
						'wcag21a',
						'wcag21aa',
						'wcag22aa',
					] )
					.include( '.wp-block-mwtfrs-board' )
					.analyze()
			).violations
		).toEqual( [] );
	} );

	test( 'a board with every control turned off has no filter form', async ( {
		page,
	} ) => {
		const board = setUpBoard( {
			attributes: {
				showSearch: false,
				showStatus: false,
				showCategory: false,
				showSort: false,
			},
			requests: REQUESTS,
		} );
		await page.goto( `${ board.url }?fr-status=planned` );

		await expect( page.locator( '.mwtfrs-board__filters' ) ).toHaveCount(
			0
		);
		// Only the board's own: a classic theme can have a search form of its own.
		await expect(
			page.locator( '.wp-block-mwtfrs-board' ).getByRole( 'search' )
		).toHaveCount( 0 );
		// A link with a filter still filters.
		await expect( page.locator( '.mwtfrs-request__title' ) ).toHaveText( [
			'Dark mode',
		] );
	} );

	test( 'a board without the status control keeps the status of its link when searching', async ( {
		page,
	} ) => {
		const board = setUpBoard( {
			attributes: { showStatus: false },
			requests: [
				...REQUESTS,
				{ title: 'Dark mode for reports', votes: 1 },
			],
		} );
		await page.goto( `${ board.url }?fr-status=planned` );

		await expect( page.getByLabel( 'Status' ) ).toHaveCount( 0 );
		await page.getByLabel( 'Search requests' ).fill( 'dark' );
		await expect( page ).toHaveURL( /fr-search=dark/ );
		await expect( page ).toHaveURL( /fr-status=planned/ );
		await expect( page.locator( '.mwtfrs-request__title' ) ).toHaveText( [
			'Dark mode',
		] );
	} );

	test( 'the controls are switched off in the block’s settings, and the list has none', async ( {
		admin,
		editor,
		page,
	} ) => {
		setUpBoard( { requests: REQUESTS } );
		await admin.createNewPost();
		await editor.insertBlock( { name: 'mwtfrs/board' } );
		const block = editor.canvas.locator( '[data-type="mwtfrs/board"]' );
		await expect( block.getByLabel( 'Search requests' ) ).toBeVisible();

		await editor.openDocumentSettingsSidebar();
		const settings = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		await settings.getByRole( 'checkbox', { name: 'Search' } ).uncheck();
		await settings
			.getByRole( 'checkbox', { name: 'Status filter' } )
			.uncheck();

		await expect( block.getByLabel( 'Search requests' ) ).toHaveCount( 0 );
		await expect( block.getByLabel( 'Status' ) ).toHaveCount( 0 );
		await expect( block.getByLabel( 'Sort by' ) ).toBeVisible();
		expect( ( await editor.getBlocks() )[ 0 ].attributes ).toMatchObject( {
			showSearch: false,
			showStatus: false,
			showCategory: true,
			showSort: true,
		} );

		// The list, the board in its compact form, has no filters to switch.
		await editor.insertBlock( {
			name: 'mwtfrs/board',
			attributes: { compact: true, perPage: 3 },
		} );
		await expect(
			settings.getByRole( 'checkbox', { name: 'Search' } )
		).toHaveCount( 0 );
		await expect(
			editor.canvas
				.locator( '.mwtfrs-board--compact .mwtfrs-request__title' )
				.first()
		).toBeVisible();
	} );

	test.describe( 'for visitors who are not logged in', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'a guest’s votes are marked on results that come in later', async ( {
			page,
		} ) => {
			const board = setUpBoard( {
				guests: true,
				requests: [
					{ title: 'Dark mode', votes: 9, status: 'planned' },
					{ title: 'Export to CSV', votes: 5 },
				],
			} );
			await page.goto( board.url );
			await page
				.getByRole( 'button', { name: /Vote for Export to CSV/ } )
				.click();
			await expect(
				page.getByRole( 'button', { name: /Vote for Export to CSV/ } )
			).toHaveAttribute( 'aria-pressed', 'true' );

			// A fresh page with a filter, then the list the vote is on, without a page load.
			await page.goto( `${ board.url }?fr-status=planned` );
			await markPage( page );
			await page.getByLabel( 'Status' ).selectOption( '' );

			const button = page.getByRole( 'button', {
				name: /Vote for Export to CSV/,
			} );
			await expect( button ).toHaveAttribute( 'aria-pressed', 'true' );
			await expect( button ).toContainText( '6' );
			expect( await notReloaded( page ) ).toBe( true );
		} );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'the filters apply with the Show requests button', async ( {
			page,
		} ) => {
			const board = setUpBoard( { requests: REQUESTS } );
			await page.goto( board.url );

			await page.getByLabel( 'Status' ).selectOption( 'planned' );
			await page.getByRole( 'button', { name: 'Show requests' } ).click();

			await expect( page ).toHaveURL( /[?&]fr-status=planned(&|$)/ );
			await expect( page.locator( '.mwtfrs-request__title' ) ).toHaveText(
				[ 'Dark mode' ]
			);
		} );
	} );
} );
