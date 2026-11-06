/**
 * The Feature Request Roadmap in a real browser: its columns, voting on a card and
 * "Show more", with and without JavaScript, and the "Show on roadmap" switch.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpEval } = require( './wp-cli' );

/**
 * Replaces all requests with the given ones, and returns a page that shows the roadmap
 * pattern and, below it, a list of the requests open for voting with the most votes.
 *
 * Also clears rate limit counters, and puts the roadmap back to the statuses the plugin
 * starts with: Planned, In Progress and Shipped.
 *
 * @param {Object}   options
 * @param {boolean}  [options.guests] Whether visitors who are not logged in may vote.
 * @param {Object[]} options.requests Requests to create: title, status (slug) and votes.
 * @return {{url: string, ids: number[]}} The page, and the IDs of the requests.
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
			use FeatureRequestSystem\\Content\\StatusTaxonomy;
			use FeatureRequestSystem\\Frontend\\Blocks;
			use FeatureRequestSystem\\Security\\RateLimiter;
			use FeatureRequestSystem\\Voting\\VoteCount;
			use FeatureRequestSystem\\Voting\\VoteRepository;
			global $wpdb;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%' ) );
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::${ voting } ] );
			foreach ( StatusTaxonomy::defaults() as $status ) {
				$term = get_term_by( 'slug', $status['slug'], StatusTaxonomy::NAME );
				if ( $term ) {
					update_term_meta( $term->term_id, StatusTaxonomy::META_ROADMAP, $status['meta'][ StatusTaxonomy::META_ROADMAP ] );
				}
			}

			$ids = [];
			foreach ( json_decode( base64_decode( '${ data }' ), true ) as $request ) {
				$id = wp_insert_post( [
					'post_type'   => RequestPostType::NAME,
					'post_status' => 'publish',
					'post_title'  => $request['title'],
				] );
				wp_set_object_terms( $id, get_term_by( 'slug', $request['status'], StatusTaxonomy::NAME )->term_id, StatusTaxonomy::NAME );
				for ( $i = 0; $i < $request['votes']; $i++ ) {
					VoteRepository::add( $id, hash( 'sha256', 'e2e:' . $id . ':' . $i ), 0 );
				}
				VoteCount::sync( $id );
				$ids[] = $id;
			}

			$page = get_page_by_path( 'e2e-roadmap' );
			$page = wp_insert_post( [
				'ID'           => $page ? $page->ID : 0,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'E2E roadmap',
				'post_name'    => 'e2e-roadmap',
				'post_content' => WP_Block_Patterns_Registry::get_instance()->get_registered( Blocks::PATTERN_ROADMAP )['content'] . "\\n\\n" . '<!-- wp:mwtfrs/board {"compact":true,"perPage":3} /-->',
			] );
			echo wp_json_encode( [ 'url' => get_permalink( $page ), 'ids' => $ids ] );
		` )
	);
}

const ROADMAP = '.wp-block-mwtfrs-roadmap';
const LIST = '.mwtfrs-board--compact';

/**
 * Twelve planned requests, "Planned 1" with the most votes, two in progress, one shipped
 * and one open, which is not on the roadmap.
 */
const REQUESTS = [
	...Array.from( { length: 12 }, ( _, index ) => ( {
		title: `Planned ${ index + 1 }`,
		status: 'planned',
		votes: 30 - index,
	} ) ),
	{ title: 'Calendar view', status: 'in-progress', votes: 4 },
	{ title: 'Dark mode', status: 'in-progress', votes: 2 },
	{ title: 'Two-factor login', status: 'shipped', votes: 9 },
	{ title: 'Export to CSV', status: 'open', votes: 50 },
];

/**
 * The column of a status.
 *
 * @param {import('@playwright/test').Page} page Browser page.
 * @param {string}                          name Status name.
 */
function column( page, name ) {
	return page.locator( `${ ROADMAP } .mwtfrs-roadmap__column` ).filter( {
		has: page.getByRole( 'heading', { name: new RegExp( `^${ name }` ) } ),
	} );
}

test.describe( 'Feature request roadmap', () => {
	test( 'columns by status, a vote on a card, and Show more without reloading the page', async ( {
		page,
	} ) => {
		const { url } = setUp( { requests: REQUESTS } );
		await page.goto( url );
		const roadmap = page.locator( ROADMAP );
		const planned = column( page, 'Planned' );

		await expect( roadmap.getByRole( 'heading', { level: 3 } ) ).toHaveText(
			[
				'Planned 12 12 requests',
				'In Progress 2 2 requests',
				'Shipped 1 1 request',
			]
		);
		await expect( roadmap.getByText( 'Export to CSV' ) ).toHaveCount( 0 );
		await expect(
			column( page, 'In Progress' ).locator( '.mwtfrs-request__title' )
		).toHaveText( [ 'Calendar view', 'Dark mode' ] );
		await expect( planned.locator( '.mwtfrs-request__title' ) ).toHaveCount(
			10
		);
		await expect(
			column( page, 'Shipped' ).getByRole( 'button' )
		).toHaveCount( 0 );

		// A vote on a card shows on the list below too: the same store.
		const onRoadmap = planned.getByRole( 'button', {
			name: /Vote for Planned 1$/,
		} );
		const onList = page
			.locator( LIST )
			.getByRole( 'button', { name: /Vote for Planned 1$/ } );
		await onRoadmap.click();
		await expect( onRoadmap ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( onRoadmap ).toContainText( '31' );
		await expect( onList ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( onList ).toContainText( '31' );
		await expect( planned.locator( '[aria-live]' ) ).toHaveText(
			'Vote added.'
		);

		// Show more swaps the roadmap in place: the page is not loaded again.
		await page.evaluate( () => ( window.mwtfrsSamePage = true ) );
		await planned.getByRole( 'link', { name: 'Show more' } ).click();
		await expect( planned.locator( '.mwtfrs-request__title' ) ).toHaveCount(
			12
		);
		await expect( page ).toHaveURL( /fr-roadmap-planned=2/ );
		expect( await page.evaluate( () => window.mwtfrsSamePage ) ).toBe(
			true
		);
		await expect(
			planned.getByRole( 'link', { name: 'Planned 11', exact: true } )
		).toBeFocused();
		await expect(
			planned.getByRole( 'link', { name: 'Show more' } )
		).toHaveCount( 0 );
		// The vote cast before is still marked, in the column drawn anew.
		await expect( onRoadmap ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( onRoadmap ).toContainText( '31' );

		// The requests it added vote like the others.
		const added = planned.getByRole( 'button', {
			name: /Vote for Planned 12$/,
		} );
		await added.click();
		await expect( added ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( added ).toContainText( '20' );

		await page.reload();
		await expect( planned.locator( '.mwtfrs-request__title' ) ).toHaveCount(
			12
		);
		await expect( added ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( onRoadmap ).toHaveAttribute( 'aria-pressed', 'true' );
	} );

	test( 'after Show more, the column marks what the visitor voted for among the requests it added', async ( {
		page,
	} ) => {
		const { url, ids } = setUp( { requests: REQUESTS } );
		// A vote for the twelfth request, cast before the page was opened.
		wpEval( `
			wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
			FeatureRequestSystem\\Voting\\VoteService::cast( ${ ids[ 11 ] } );
		` );
		const asked = [];
		page.on( 'request', ( request ) => {
			if ( /me(\/|%2F)state/.test( request.url() ) ) {
				asked.push( request.postDataJSON().ids );
			}
		} );

		await page.goto( url, { waitUntil: 'networkidle' } );
		const planned = column( page, 'Planned' );
		await planned.getByRole( 'link', { name: 'Show more' } ).click();

		const twelfth = planned.getByRole( 'button', {
			name: /Vote for Planned 12$/,
		} );
		await expect( twelfth ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( twelfth ).toContainText( '20' );
		// Once for the roadmap, once for the list, and once for the column that grew.
		expect( asked ).toHaveLength( 3 );
		expect( asked[ 2 ] ).toHaveLength( 12 );
	} );

	test( 'a status ticked “Show on roadmap” on the Statuses screen gets a column', async ( {
		page,
	} ) => {
		const { url } = setUp( { requests: REQUESTS } );
		const id = wpEval(
			`echo get_term_by( 'slug', 'open', FeatureRequestSystem\\Content\\StatusTaxonomy::NAME )->term_id;`
		);

		await page.goto(
			`/wp-admin/term.php?taxonomy=mwtfrs_status&tag_ID=${ id }&post_type=mwtfrs_request`
		);
		const box = page.getByRole( 'checkbox', { name: 'Show on roadmap' } );
		await expect( box ).not.toBeChecked();
		await box.check();
		await page.getByRole( 'button', { name: 'Update' } ).click();
		await expect( page.getByText( /updated\./ ) ).toBeVisible();

		await page.goto(
			'/wp-admin/edit-tags.php?taxonomy=mwtfrs_status&post_type=mwtfrs_request'
		);
		await expect(
			page.locator( `#tag-${ id } .column-mwtfrs_roadmap` )
		).toHaveText( 'Shown' );

		await page.goto( url );
		await expect(
			page.locator( ROADMAP ).getByRole( 'heading', { level: 3 } )
		).toContainText( [ 'Open', 'Planned', 'In Progress', 'Shipped' ] );
		await expect(
			column( page, 'Open' ).locator( '.mwtfrs-request__title' )
		).toHaveText( [ 'Export to CSV' ] );
	} );

	test( 'in the editor, the roadmap is in the inserter and shows its columns', async ( {
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
			.fill( 'Feature Request Roadmap' );
		await page
			.getByRole( 'option', {
				name: 'Feature Request Roadmap',
				exact: true,
			} )
			.click();

		const block = editor.canvas.locator( '[data-type="mwtfrs/roadmap"]' );
		await expect( block.locator( '.mwtfrs-roadmap__heading' ) ).toHaveText(
			[
				'Planned 12 12 requests',
				'In Progress 2 2 requests',
				'Shipped 1 1 request',
			]
		);

		await editor.openDocumentSettingsSidebar();
		await expect(
			page
				.getByRole( 'region', { name: 'Editor settings' } )
				.getByRole( 'combobox', { name: 'Requests of' } )
		).toHaveValue( '0' );
	} );

	test( 'Show more is big enough to hit, and nothing scrolls sideways on a narrow screen', async ( {
		page,
	} ) => {
		const { url } = setUp( { requests: REQUESTS } );
		await page.setViewportSize( { width: 320, height: 640 } );
		await page.goto( url );

		const box = await column( page, 'Planned' )
			.getByRole( 'link', { name: 'Show more' } )
			.boundingBox();
		expect( box.height ).toBeGreaterThanOrEqual( 24 );
		expect( box.width ).toBeGreaterThanOrEqual( 24 );

		const columns = await page
			.locator( `${ ROADMAP } .mwtfrs-roadmap__column` )
			.evaluateAll( ( elements ) =>
				elements.map( ( element ) => element.getBoundingClientRect().x )
			);
		expect( new Set( columns ).size ).toBe( 1 );
	} );

	test.describe( 'for visitors who are not logged in', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'a guest votes on a card where guests may vote', async ( {
			page,
		} ) => {
			const { url } = setUp( { guests: true, requests: REQUESTS } );
			await page.goto( url );

			const card = column( page, 'In Progress' ).getByRole( 'button', {
				name: /Vote for Dark mode/,
			} );
			await card.click();
			await expect( card ).toHaveAttribute( 'aria-pressed', 'true' );
			await expect( card ).toContainText( '3' );

			await page.reload();
			await expect( card ).toHaveAttribute( 'aria-pressed', 'true' );
		} );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'Show more is a link that shows more, and a vote on a card comes back to it', async ( {
			page,
		} ) => {
			const { url } = setUp( { requests: REQUESTS } );
			await page.goto( url );
			const planned = column( page, 'Planned' );

			await planned.getByRole( 'link', { name: 'Show more' } ).click();
			await expect(
				planned.locator( '.mwtfrs-request__title' )
			).toHaveCount( 12 );
			const { hash, searchParams } = new URL( page.url() );
			expect( searchParams.get( 'fr-roadmap-planned' ) ).toBe( '2' );
			await expect( planned.locator( hash ) ).toContainText(
				'Planned 11'
			);

			await planned
				.getByRole( 'button', { name: /Vote for Planned 12$/ } )
				.click();
			await expect(
				planned.getByText( 'Your vote was added.' )
			).toBeVisible();
			await expect(
				planned.locator( '.mwtfrs-request__title' )
			).toHaveCount( 12 );
			expect( new URL( page.url() ).hash ).toMatch(
				/^#mwtfrs-roadmap-\d+-request-\d+$/
			);
		} );
	} );
} );
