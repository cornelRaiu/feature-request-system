/**
 * The Feature Request List, the Feature Request Card and the patterns in a real browser.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpEval } = require( './wp-cli' );

/**
 * Replaces all requests with the given ones, and returns a page that shows a list of the
 * two with the most votes and, below it, a card of the first request given.
 *
 * Also clears rate limit counters, so repeated runs within the hour do not hit them.
 *
 * @param {Object}   options
 * @param {boolean}  [options.guests] Whether visitors who are not logged in may vote.
 * @param {Object[]} options.requests Requests to create: title, votes, daysAgo.
 * @return {{url: string, board: string, ids: number[]}} The page, the page that shows the board, and the IDs of the requests.
 */
function setUp( { guests = false, requests } ) {
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

			$board = get_page_by_path( 'e2e-board' );
			$board = $board ? $board->ID : wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'E2E board',
				'post_name'    => 'e2e-board',
				'post_content' => '<!-- wp:mwtfrs/board /-->',
			] );
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [
				BoardSettings::VOTING => BoardSettings::${ voting },
				BoardSettings::PAGE   => $board,
			] );

			$ids = [];
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
				$ids[] = $id;
			}

			$page = get_page_by_path( 'e2e-blocks' );
			$page = wp_insert_post( [
				'ID'           => $page ? $page->ID : 0,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'E2E blocks',
				'post_name'    => 'e2e-blocks',
				'post_content' => '<!-- wp:mwtfrs/board {"compact":true,"perPage":2} /-->' . "\\n\\n" . '<!-- wp:mwtfrs/request-card {"requestId":' . (int) $ids[0] . '} /-->',
			] );
			echo wp_json_encode( [ 'url' => get_permalink( $page ), 'board' => get_permalink( $board ), 'ids' => $ids ] );
		` )
	);
}

const LIST = '.mwtfrs-board--compact';
const CARD = '.wp-block-mwtfrs-request-card';
const REQUESTS = [
	{ title: 'Dark mode', votes: 3, daysAgo: 1 },
	{ title: 'Export to CSV', votes: 5, daysAgo: 3 },
	{ title: 'Calendar view', votes: 1, daysAgo: 2 },
];

test.describe( 'Feature request list and card', () => {
	test( 'a list shows the requests with the most votes, and a vote on a card shows on the list too', async ( {
		page,
	} ) => {
		const blocks = setUp( { requests: REQUESTS } );
		await page.goto( blocks.url );
		const list = page.locator( LIST );
		const card = page.locator( CARD );

		await expect( list.locator( '.mwtfrs-request__title' ) ).toHaveText( [
			'Export to CSV',
			'Dark mode',
		] );
		await expect(
			list.locator( 'select, input:not([type=hidden])' )
		).toHaveCount( 0 );
		await expect(
			list.getByRole( 'link', { name: 'See all requests' } )
		).toHaveAttribute( 'href', blocks.board );
		await expect( card.locator( '.mwtfrs-request__title' ) ).toHaveText(
			'Dark mode'
		);

		// The same request, in two places.
		const onList = list.getByRole( 'button', {
			name: /Vote for Dark mode/,
		} );
		const onCard = card.getByRole( 'button', {
			name: /Vote for Dark mode/,
		} );
		expect(
			await page
				.locator( `[id$="-request-${ blocks.ids[ 0 ] }"]` )
				.evaluateAll( ( elements ) => elements.map( ( { id } ) => id ) )
		).toEqual( [
			expect.stringMatching( /^mwtfrs-list-\d+-request-\d+$/ ),
			expect.stringMatching( /^mwtfrs-card-\d+-request-\d+$/ ),
		] );

		await onCard.click();
		await expect( onCard ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( onCard ).toContainText( '4' );
		await expect( onList ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( onList ).toContainText( '4' );
		// Screen readers hear it once, from the block the vote was cast in.
		await expect( card.locator( '[aria-live]' ) ).toHaveText(
			'Vote added.'
		);
		await expect( list.locator( '[aria-live]' ) ).toHaveText( '' );

		await page.reload();
		await expect( onList ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( onCard ).toHaveAttribute( 'aria-pressed', 'true' );

		await onList.click();
		await expect( onList ).toHaveAttribute( 'aria-pressed', 'false' );
		await expect( onCard ).toHaveAttribute( 'aria-pressed', 'false' );
		await expect( onCard ).toContainText( '3' );
		await expect( list.locator( '[aria-live]' ) ).toHaveText(
			'Vote removed.'
		);
	} );

	test( 'in the editor, a card finds its request by the title', async ( {
		admin,
		editor,
		page,
	} ) => {
		// More requests than the field lists, and the wanted one is the oldest:
		// it is found only by asking the site for it.
		setUp( {
			requests: [
				{ title: 'Export to CSV', votes: 5, daysAgo: 30 },
				...Array.from( { length: 21 }, ( _, index ) => ( {
					title: `Request ${ index + 1 }`,
					votes: 0,
					daysAgo: index,
				} ) ),
			],
		} );
		await admin.createNewPost( { title: 'About a request' } );
		await editor.insertBlock( { name: 'mwtfrs/request-card' } );
		const block = editor.canvas.locator(
			'[data-type="mwtfrs/request-card"]'
		);

		await block
			.getByRole( 'combobox', { name: 'Feature request' } )
			.fill( 'Export' );
		await block.getByRole( 'option', { name: 'Export to CSV' } ).click();

		await expect( block.locator( '.mwtfrs-request__title' ) ).toHaveText(
			'Export to CSV'
		);

		const postId = await editor.publishPost();
		await page.goto( `/?p=${ postId }` );
		await expect(
			page.locator( CARD ).getByRole( 'link', { name: 'Export to CSV' } )
		).toBeVisible();
		await expect(
			page.locator( CARD ).getByRole( 'button', {
				name: /Vote for Export to CSV/,
			} )
		).toContainText( '5' );
	} );

	test( 'in the editor, the list is in the inserter and has its own settings', async ( {
		admin,
		editor,
		page,
	} ) => {
		setUp( { requests: REQUESTS } );
		await admin.createNewPost();

		await page
			.getByRole( 'button', { name: /block inserter/i } )
			.first()
			.click();
		await page
			.getByRole( 'searchbox', { name: /Search/ } )
			.fill( 'Feature Request List' );
		await page
			.getByRole( 'option', { name: 'Feature Request List' } )
			.click();

		const block = editor.canvas.locator( '[data-type="mwtfrs/board"]' );
		const titles = block.locator( '.mwtfrs-request__title' );
		await expect( titles ).toHaveText( [
			'Export to CSV',
			'Dark mode',
			'Calendar view',
		] );
		await expect( block.locator( '.mwtfrs-board__filters' ) ).toHaveCount(
			0
		);

		await editor.openDocumentSettingsSidebar();
		const settings = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		await settings
			.getByRole( 'combobox', { name: 'Order' } )
			.selectOption( 'Newest' );
		await expect( titles ).toHaveText( [
			'Dark mode',
			'Calendar view',
			'Export to CSV',
		] );
		await expect(
			settings.getByRole( 'combobox', { name: 'Requests of' } )
		).toHaveValue( '0' );
		await expect(
			settings.getByRole( 'combobox', { name: 'Status' } )
		).toHaveValue( '' );

		expect( ( await editor.getBlocks() )[ 0 ].attributes ).toMatchObject( {
			compact: true,
			perPage: 5,
			sort: 'new',
		} );
	} );

	test( 'the patterns are made of blocks the editor accepts', async ( {
		admin,
		page,
	} ) => {
		const blocks = setUp( { requests: REQUESTS } );
		await admin.createNewPost();

		const patterns = await page.evaluate( async () => {
			const { resolveSelect } = window.wp.data;
			const notValid = ( parsed ) =>
				parsed.flatMap( ( block ) => [
					...( block.isValid ? [] : [ block.name ] ),
					...notValid( block.innerBlocks ),
				] );
			const all = await resolveSelect( 'core' ).getBlockPatterns();

			return all
				.filter( ( { name } ) => name.startsWith( 'mwtfrs/' ) )
				.map( ( { name, content } ) => ( {
					name,
					notValid: notValid( window.wp.blocks.parse( content ) ),
					link: content.match( /href="([^"]+)"/ )?.[ 1 ] ?? '',
				} ) )
				.sort( ( a, b ) => a.name.localeCompare( b.name ) );
		} );

		expect( patterns ).toEqual( [
			{ name: 'mwtfrs/feature-request-page', notValid: [], link: '' },
			{ name: 'mwtfrs/form-beside-board', notValid: [], link: '' },
			{ name: 'mwtfrs/most-wanted', notValid: [], link: '' },
			// Its button leads to the page that shows the board.
			{
				name: 'mwtfrs/suggest-a-feature',
				notValid: [],
				link: blocks.board,
			},
		] );
	} );

	test.describe( 'for visitors who are not logged in', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'the page asks for a guest’s votes only where guests can vote', async ( {
			page,
		} ) => {
			const asked = [];
			page.on( 'request', ( request ) => {
				if ( /me(\/|%2F)state/.test( request.url() ) ) {
					asked.push( request.url() );
				}
			} );

			let blocks = setUp( { requests: REQUESTS } );
			await page.goto( blocks.url, { waitUntil: 'networkidle' } );
			// The script is there and answers: a guest who votes is asked to log in.
			await page
				.locator( CARD )
				.getByRole( 'button', { name: /Vote for Dark mode/ } )
				.click();
			await expect(
				page
					.locator( CARD )
					.getByText( 'Log in to vote on this request.' )
			).toBeVisible();
			expect( asked ).toEqual( [] );

			blocks = setUp( { guests: true, requests: REQUESTS } );
			await page.goto( blocks.url, { waitUntil: 'networkidle' } );
			// Once for the list and once for the card.
			expect( asked ).toHaveLength( 2 );

			const onCard = page
				.locator( CARD )
				.getByRole( 'button', { name: /Vote for Dark mode/ } );
			await onCard.click();
			await expect( onCard ).toHaveAttribute( 'aria-pressed', 'true' );
			await page.reload();
			await expect( onCard ).toHaveAttribute( 'aria-pressed', 'true' );
			await expect(
				page
					.locator( LIST )
					.getByRole( 'button', { name: /Vote for Dark mode/ } )
			).toHaveAttribute( 'aria-pressed', 'true' );
		} );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'a vote on a card comes back to the card', async ( { page } ) => {
			const blocks = setUp( { requests: REQUESTS } );
			await page.goto( blocks.url );
			const card = page.locator( CARD );

			await card
				.getByRole( 'button', { name: /Vote for Dark mode/ } )
				.click();

			await expect(
				card.getByText( 'Your vote was added.' )
			).toBeVisible();
			await expect( card.locator( '.mwtfrs-vote__count' ) ).toHaveText(
				'4'
			);
			const { hash } = new URL( page.url() );
			expect( hash ).toMatch( /^#mwtfrs-card-\d+-request-\d+$/ );
			await expect( card.locator( hash ) ).toHaveCount( 1 );
		} );
	} );
} );
