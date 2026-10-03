/**
 * The admin screens in a real browser.
 */
const crypto = require( 'node:crypto' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpEval } = require( './wp-cli' );

/**
 * Replaces all requests with the given ones, resets the default board and the plugin's
 * settings, and returns a page that shows the form and the board.
 *
 * @param {Object[]} requests Requests to create: title, votes, status (slug), postStatus.
 * @return {{url: string, ids: number[]}} The page, and the IDs of the requests.
 */
function setUp( requests ) {
	const data = Buffer.from( JSON.stringify( requests ) ).toString( 'base64' );

	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Content\\StatusTaxonomy;
			use FeatureRequestSystem\\Frontend\\Blocks;
			use FeatureRequestSystem\\Security\\RateLimiter;
			use FeatureRequestSystem\\Settings;
			use FeatureRequestSystem\\Voting\\VoteCount;
			use FeatureRequestSystem\\Voting\\VoteRepository;
			global $wpdb;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%' ) );
			Settings::update( Settings::DATA_USE_NOTE, '' );
			Settings::update( Settings::VOTES_PER_ADDRESS_USERS, 60 );
			Settings::update( Settings::VOTES_PER_ADDRESS_GUESTS, 60 );
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), BoardSettings::defaults() );

			$ids = [];
			foreach ( json_decode( base64_decode( '${ data }' ), true ) as $request ) {
				$id = wp_insert_post( [
					'post_type'    => RequestPostType::NAME,
					'post_status'  => $request['postStatus'] ?? 'publish',
					'post_title'   => $request['title'],
					'post_content' => 'Described in a sentence or two.',
				] );
				if ( ! empty( $request['status'] ) ) {
					wp_set_object_terms( $id, get_term_by( 'slug', $request['status'], StatusTaxonomy::NAME )->term_id, StatusTaxonomy::NAME );
				}
				for ( $i = 0; $i < ( $request['votes'] ?? 0 ); $i++ ) {
					VoteRepository::add( $id, hash( 'sha256', 'e2e:' . $id . ':' . $i ), 0 );
				}
				VoteCount::sync( $id );
				$ids[] = $id;
			}

			$page    = get_page_by_path( 'e2e-submit' );
			$page_id = $page ? $page->ID : wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'E2E submit',
				'post_name'    => 'e2e-submit',
				'post_content' => WP_Block_Patterns_Registry::get_instance()->get_registered( Blocks::PATTERN )['content'],
			] );
			echo wp_json_encode( [ 'url' => get_permalink( $page_id ), 'ids' => $ids ] );
		` )
	);
}

const LIST = '/wp-admin/edit.php?post_type=mwtfrs_request';

test.describe( 'Feature request admin', () => {
	test( 'the list shows votes and status, and Quick Edit changes the status', async ( {
		page,
	} ) => {
		const { ids } = setUp( [ { title: 'Dark mode', votes: 3 } ] );
		await page.goto( LIST );
		const row = page.locator( `#post-${ ids[ 0 ] }` );

		await expect( row.locator( '.column-mwtfrs_vote_count' ) ).toHaveText(
			'3'
		);
		await expect(
			row.locator( '.column-taxonomy-mwtfrs_status' )
		).toHaveText( 'Open' );

		await row.hover();
		await row.getByRole( 'button', { name: /Quick edit/ } ).click();
		const status = page
			.locator( `#edit-${ ids[ 0 ] }` )
			.getByLabel( 'Request status' );
		await expect( status ).toHaveValue( /\d+/ );
		await expect( status.locator( 'option:checked' ) ).toHaveText( 'Open' );

		await status.selectOption( { label: 'Planned' } );
		await page
			.locator( `#edit-${ ids[ 0 ] }` )
			.getByRole( 'button', { name: 'Update' } )
			.click();

		await expect(
			row.locator( '.column-taxonomy-mwtfrs_status' )
		).toHaveText( 'Planned' );
	} );

	test( 'Bulk Edit changes the status of several requests at once', async ( {
		page,
	} ) => {
		const { ids } = setUp( [
			{ title: 'Webhooks' },
			{ title: 'API keys', status: 'planned' },
		] );
		await page.goto( LIST );

		await page.locator( '#cb-select-all-1' ).check();
		await page
			.locator( '#bulk-action-selector-top' )
			.selectOption( 'edit' );
		await page.locator( '#doaction' ).click();
		await page
			.locator( '#bulk-edit' )
			.getByLabel( 'Request status' )
			.selectOption( { label: 'Shipped' } );
		await page
			.locator( '#bulk-edit' )
			.getByRole( 'button', { name: 'Update' } )
			.click();

		for ( const id of ids ) {
			await expect(
				page.locator( `#post-${ id } .column-taxonomy-mwtfrs_status` )
			).toHaveText( 'Shipped' );
		}
	} );

	test( 'the menu shows how many requests wait for approval', async ( {
		page,
	} ) => {
		setUp( [
			{ title: 'Single sign-on', postStatus: 'pending' },
			{ title: 'Offline mode', postStatus: 'pending' },
			{ title: 'Dark mode' },
		] );
		await page.goto( LIST );

		// WordPress repeats a menu's title as a hidden heading of its submenu.
		await expect(
			page.locator( '#menu-posts-mwtfrs_request > a .pending-count' )
		).toHaveText( '2' );
	} );

	test( 'the menu icon takes the colours of the admin colour scheme', async ( {
		page,
	} ) => {
		const menu = page.locator( '#menu-posts-mwtfrs_request' );
		// WordPress paints the icon by rewriting the fills of the SVG it shows.
		const fills = async () => {
			const style = await menu
				.locator( '.wp-menu-image' )
				.getAttribute( 'style' );
			const svg = Buffer.from(
				style.match( /base64,([A-Za-z0-9+/=]+)/ )[ 1 ],
				'base64'
			).toString();

			return [
				...new Set(
					[ ...svg.matchAll( /fill="([^"]+)"/g ) ].map(
						( match ) => match[ 1 ]
					)
				),
			];
		};

		await page.goto( '/wp-admin/index.php' );
		await expect( menu.locator( '.wp-menu-image' ) ).toHaveClass(
			/\bsvg\b/
		);
		// The icon colours of the user's scheme, which WordPress gives its painter.
		const colours = await page.evaluate(
			() => window._wpColorScheme.icons
		);
		await expect.poll( fills ).toEqual( [ colours.base ] );
		await menu.hover();
		await expect.poll( fills ).toEqual( [ colours.focus ] );

		await page.goto( LIST );
		await expect.poll( fills ).toEqual( [ colours.current ] );
	} );

	test( 'a note written in settings shows on the form for guests', async ( {
		page,
		browser,
	} ) => {
		const form = setUp( [] );
		await page.goto( `${ LIST }&page=mwtfrs-settings` );
		await page
			.getByLabel( 'How we use your data' )
			.fill( 'We keep your email only to reply to you.' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();

		await page.goto( '/wp-admin/edit-tags.php?taxonomy=mwtfrs_board' );
		await page.locator( '#the-list a.row-title' ).first().click();
		await page
			.getByLabel( 'Who can suggest features' )
			.selectOption( 'anyone' );
		await page.getByRole( 'button', { name: 'Update' } ).click();

		const guest = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
			baseURL: new URL( form.url ).origin,
		} );
		const visitor = await guest.newPage();
		await visitor.goto( form.url );

		const note = visitor.getByText(
			'We keep your email only to reply to you.'
		);
		await expect( note ).toBeHidden();
		await visitor.getByText( 'How we use your data' ).click();
		await expect( note ).toBeVisible();
		await guest.close();
	} );

	test( 'the vote limits on the settings page apply, to guests and to logged-in users apart', async ( {
		page,
		browser,
	} ) => {
		const form = setUp( [ { title: 'Dark mode', votes: 0 } ] );
		const password = crypto.randomBytes( 16 ).toString( 'hex' );
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestTerms;
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );

			$user = get_user_by( 'login', 'e2e-subscriber' );
			$id   = $user ? $user->ID : wp_insert_user( [ 'user_login' => 'e2e-subscriber', 'user_email' => 'e2e-subscriber@example.com', 'role' => 'subscriber', 'user_pass' => wp_generate_password() ] );
			wp_set_password( '${ password }', $id );
		` );

		await page.goto( `${ LIST }&page=mwtfrs-settings` );
		const guests = page.getByLabel( 'Visitors without an account', {
			exact: true,
		} );
		await expect( guests ).toHaveValue( '60' );
		await guests.fill( '2' );
		await page.getByLabel( 'Logged-in users', { exact: true } ).fill( '1' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		await expect( guests ).toHaveValue( '2' );

		const visit = async () => {
			const context = await browser.newContext( {
				storageState: { cookies: [], origins: [] },
				baseURL: new URL( form.url ).origin,
			} );

			return context.newPage();
		};
		const vote = async ( visitor ) => {
			await visitor.goto( form.url );
			const button = visitor.getByRole( 'button', {
				name: /Vote for Dark mode/,
			} );
			await button.click();

			return button;
		};

		// Three visitors without an account at one address: the third is one too many.
		for ( const count of [ '1', '2' ] ) {
			const visitor = await visit();
			const button = await vote( visitor );
			await expect( button ).toHaveAttribute( 'aria-pressed', 'true' );
			await expect( button ).toContainText( count );
			await visitor.context().close();
		}
		const third = await visit();
		const refused = await vote( third );
		await expect(
			third.getByText( /Too many votes in a short time/ )
		).toBeVisible();
		await expect( refused ).toHaveAttribute( 'aria-pressed', 'false' );
		await third.context().close();

		// A logged-in user at that address has the other limit, and the guests used none of it.
		const user = await visit();
		await user.goto( '/wp-login.php' );
		await user.locator( '#user_login' ).fill( 'e2e-subscriber' );
		await user.locator( '#user_pass' ).fill( password );
		await user.locator( '#wp-submit' ).click();
		await user.waitForURL( /wp-admin/ );
		const own = await vote( user );
		await expect( own ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( own ).toContainText( '3' );
		await user.context().close();
	} );

	test( 'a board limited to some categories offers only those on its form', async ( {
		page,
	} ) => {
		const form = setUp( [] );
		wpEval( `
			use FeatureRequestSystem\\Content\\CategoryTaxonomy;
			foreach ( [ 'Billing', 'Mobile app' ] as $name ) {
				if ( ! term_exists( $name, CategoryTaxonomy::NAME ) ) {
					wp_insert_term( $name, CategoryTaxonomy::NAME );
				}
			}
		` );

		await page.goto( form.url );
		const category = page.getByLabel( 'Category' ).last();
		await expect(
			category.locator( 'option', { hasText: 'Mobile app' } )
		).toHaveCount( 1 );

		await page.goto( '/wp-admin/edit-tags.php?taxonomy=mwtfrs_board' );
		await page.locator( '#the-list a.row-title' ).first().click();
		const boxes = page.getByRole( 'group', { name: 'Categories' } );
		await boxes.getByLabel( 'Billing' ).check();
		await page.getByRole( 'button', { name: 'Update' } ).click();
		await expect( boxes.getByLabel( 'Billing' ) ).toBeChecked();

		await page.goto( form.url );
		await expect(
			category.locator( 'option', { hasText: 'Billing' } )
		).toHaveCount( 1 );
		await expect(
			category.locator( 'option', { hasText: 'Mobile app' } )
		).toHaveCount( 0 );
	} );

	test( 'a request links back to the page that shows its board', async ( {
		page,
	} ) => {
		const board = setUp( [ { title: 'Calendar view' } ] );
		await page.goto( board.url );

		await page.getByRole( 'link', { name: 'Calendar view' } ).click();
		await page
			.getByRole( 'link', { name: 'Back to Feature Requests' } )
			.click();

		await expect( page ).toHaveURL( board.url );
	} );
} );
