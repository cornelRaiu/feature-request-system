/**
 * Accessibility checks with axe, against WCAG 2.2 level AA.
 *
 * Each check looks only at what the plugin prints, so a theme's own issues do not fail
 * it. axe finds what a machine can find: missing names and labels, wrong roles, low
 * contrast. Keyboard use and reading order are checked by hand.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { default: AxeBuilder } = require( '@axe-core/playwright' );
const { wpEval } = require( './wp-cli' );

const RULES = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/**
 * Replaces all requests with a small board that shows every kind of content: several
 * statuses, a category, a closed request, an official response and comments.
 *
 * @param {Object} options
 * @param {string} [options.submitting] Who may submit: ANYONE, LOGGED_IN or NOBODY.
 * @return {{page: string, blocks: string, request: string, edit: string, stopLink: string}} URLs to check.
 */
function setUp( { submitting = 'LOGGED_IN' } = {} ) {
	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Comments\\OfficialResponse;
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\CategoryTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Content\\StatusTaxonomy;
			use FeatureRequestSystem\\Frontend\\Blocks;
			use FeatureRequestSystem\\Notifications\\Queue;
			use FeatureRequestSystem\\Notifications\\SubscriptionRepository;
			use FeatureRequestSystem\\Notifications\\Unsubscribe;
			use FeatureRequestSystem\\Security\\RateLimiter;
			use FeatureRequestSystem\\Settings;
			use FeatureRequestSystem\\Voting\\VoteCount;
			use FeatureRequestSystem\\Voting\\VoteRepository;
			global $wpdb;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%' ) );
			Queue::cancel_all();
			Settings::update( Settings::NOTIFY_FOLLOWERS, true );
			Settings::update( Settings::DATA_USE_NOTE, 'We keep your name and email with your request. Only our team sees them.' );
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), array_merge( BoardSettings::defaults(), [ BoardSettings::SUBMITTING => BoardSettings::${ submitting } ] ) );

			$category = term_exists( 'Appearance', CategoryTaxonomy::NAME ) ?: wp_insert_term( 'Appearance', CategoryTaxonomy::NAME );
			$admin    = get_user_by( 'login', 'admin' );
			$ids      = [];
			foreach ( [ 'Dark mode' => 'planned', 'Export to CSV' => 'open', 'Calendar view' => 'in-progress', 'Old idea' => 'shipped' ] as $title => $status ) {
				$id = wp_insert_post( [
					'post_type'      => RequestPostType::NAME,
					'post_status'    => 'publish',
					'post_title'     => $title,
					'post_content'   => 'Described in a sentence or two, so that there is some text to read.',
					'comment_status' => 'open',
				] );
				wp_set_object_terms( $id, get_term_by( 'slug', $status, StatusTaxonomy::NAME )->term_id, StatusTaxonomy::NAME );
				wp_set_object_terms( $id, (int) $category['term_id'], CategoryTaxonomy::NAME );
				for ( $i = 0; $i < 7; $i++ ) {
					VoteRepository::add( $id, hash( 'sha256', 'a11y:' . $id . ':' . $i ), 0 );
				}
				VoteCount::sync( $id );
				$ids[ $title ] = $id;
			}

			$request = $ids['Dark mode'];
			$comment = wp_insert_comment( [
				'comment_post_ID'      => $request,
				'user_id'              => $admin->ID,
				'comment_author'       => $admin->display_name,
				'comment_author_email' => $admin->user_email,
				'comment_content'      => 'Planned for version 2.4.',
				'comment_approved'     => 1,
			] );
			update_comment_meta( $comment, OfficialResponse::META, 1 );
			wp_insert_comment( [ 'comment_post_ID' => $request, 'comment_author' => 'A visitor', 'comment_author_email' => 'visitor@example.com', 'comment_content' => 'Yes please.', 'comment_approved' => 1 ] );

			$follower = get_user_by( 'login', 'e2e-follower' );
			$follower = $follower ? $follower->ID : wp_insert_user( [ 'user_login' => 'e2e-follower', 'user_email' => 'e2e-follower@example.com', 'role' => 'subscriber', 'user_pass' => wp_generate_password() ] );
			SubscriptionRepository::add( $request, $follower, SubscriptionRepository::SOURCE_VOTE );
			SubscriptionRepository::add( $ids['Export to CSV'], $follower, SubscriptionRepository::SOURCE_VOTE );
			SubscriptionRepository::add( $ids['Export to CSV'], $admin->ID, SubscriptionRepository::SOURCE_FOLLOW );

			$page    = get_page_by_path( 'e2e-submit' );
			$page_id = $page ? $page->ID : wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'E2E submit',
				'post_name'    => 'e2e-submit',
				'post_content' => WP_Block_Patterns_Registry::get_instance()->get_registered( Blocks::PATTERN )['content'],
			] );

			// A list, a card of a request that takes votes and a card of one that is closed.
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::PAGE => $page_id ] );
			$blocks = get_page_by_path( 'e2e-a11y-blocks' );
			$blocks = wp_insert_post( [
				'ID'           => $blocks ? $blocks->ID : 0,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'E2E list and cards',
				'post_name'    => 'e2e-a11y-blocks',
				'post_content' => '<!-- wp:mwtfrs/board {"compact":true,"perPage":3,"status":"all"} /-->' . "\n\n" . '<!-- wp:mwtfrs/request-card {"requestId":' . (int) $ids['Export to CSV'] . '} /-->' . "\n\n" . '<!-- wp:mwtfrs/request-card {"requestId":' . (int) $ids['Old idea'] . '} /-->',
			] );

			echo wp_json_encode( [
				'page'     => get_permalink( $page_id ),
				'blocks'   => get_permalink( $blocks ),
				'request'  => get_permalink( $request ),
				'edit'     => admin_url( 'post.php?post=' . $request . '&action=edit' ),
				'stopLink' => Unsubscribe::url( SubscriptionRepository::find( $request, $follower )['token'] ),
			] );
		` )
	);
}

/**
 * Runs axe on parts of the page and returns what it found, in a form that reads well
 * in a failed test.
 *
 * @param {import('@playwright/test').Page} page    Browser page.
 * @param {string[]}                        include Selectors of the parts to check.
 * @return {Promise<string[]>} One line per problem: its rule, how bad it is, and where.
 */
async function problems( page, include ) {
	let builder = new AxeBuilder( { page } ).withTags( RULES );
	for ( const selector of include ) {
		builder = builder.include( selector );
	}
	const { violations } = await builder.analyze();

	return violations.flatMap( ( violation ) =>
		violation.nodes.map(
			( node ) =>
				`${ violation.id } (${ violation.impact }): ${ node.target.join(
					' '
				) } — ${ node.failureSummary.replace( /\s+/g, ' ' ) }`
		)
	);
}

const BOARD = '.wp-block-mwtfrs-board';
const FORM = '.wp-block-mwtfrs-submit';
const DETAILS = '.wp-block-mwtfrs-request';
const CARD = '.wp-block-mwtfrs-request-card';

test.describe( 'Accessibility', () => {
	test( 'the board and the form for a logged-in user', async ( { page } ) => {
		const urls = setUp();
		await page.goto( urls.page );

		expect( await problems( page, [ BOARD, FORM ] ) ).toEqual( [] );

		// With a vote cast, similar requests listed and a message shown.
		await page
			.getByRole( 'button', { name: /Vote for Export to CSV/ } )
			.click();
		await page.getByLabel( 'Title' ).fill( 'Dark mode for reports' );
		await expect(
			page.getByRole( 'link', { name: 'Dark mode' } ).first()
		).toBeVisible();
		expect( await problems( page, [ BOARD, FORM ] ) ).toEqual( [] );
	} );

	test( 'a list and cards for a logged-in user', async ( { page } ) => {
		const urls = setUp();
		await page.goto( urls.blocks );

		expect( await problems( page, [ BOARD, CARD ] ) ).toEqual( [] );

		// With a vote cast on a card, which the list shows too.
		await page
			.locator( CARD )
			.getByRole( 'button', { name: /Vote for Export to CSV/ } )
			.click();
		await expect(
			page
				.locator( BOARD )
				.getByRole( 'button', { name: /Vote for Export to CSV/ } )
		).toHaveAttribute( 'aria-pressed', 'true' );
		expect( await problems( page, [ BOARD, CARD ] ) ).toEqual( [] );
	} );

	test( 'the board with every status shown and a search without results', async ( {
		page,
	} ) => {
		const urls = setUp();

		await page.goto( `${ urls.page }?fr-status=all` );
		expect( await problems( page, [ BOARD ] ) ).toEqual( [] );

		await page.goto( `${ urls.page }?fr-search=zzzz` );
		expect( await problems( page, [ BOARD ] ) ).toEqual( [] );
	} );

	test( 'a request page for a logged-in user, with an official response', async ( {
		page,
	} ) => {
		const urls = setUp();
		await page.goto( urls.request );

		const parts = [
			DETAILS,
			'.mwtfrs-official-response',
			'.mwtfrs-official-label',
			'.mwtfrs-official-toggle',
		];
		expect( await problems( page, parts ) ).toEqual( [] );

		await page
			.getByRole( 'button', { name: 'Follow', exact: true } )
			.click();
		await expect(
			page.getByRole( 'button', { name: 'Stop following' } )
		).toBeVisible();
		await page
			.getByRole( 'button', { name: /Vote for Dark mode/ } )
			.click();
		expect( await problems( page, parts ) ).toEqual( [] );
	} );

	test( 'the plugin’s parts of the admin screens', async ( { page } ) => {
		const urls = setUp();

		await page.goto( urls.edit );
		expect(
			await problems( page, [
				'#mwtfrs-request-details',
				'#mwtfrs_statusdiv',
				'#mwtfrs_boarddiv',
			] )
		).toEqual( [] );

		await page.goto(
			'/wp-admin/edit.php?post_type=mwtfrs_request&page=mwtfrs-settings'
		);
		expect( await problems( page, [ '#wpbody-content .wrap' ] ) ).toEqual(
			[]
		);

		await page.goto(
			'/wp-admin/edit-tags.php?taxonomy=mwtfrs_board&post_type=mwtfrs_request'
		);
		expect( await problems( page, [ '#addtag' ] ) ).toEqual( [] );

		await page.goto(
			'/wp-admin/edit-tags.php?taxonomy=mwtfrs_status&post_type=mwtfrs_request'
		);
		expect( await problems( page, [ '#addtag' ] ) ).toEqual( [] );

		await page.goto( '/wp-admin/profile.php' );
		expect(
			await problems( page, [ '#mwtfrs-following + table' ] )
		).toEqual( [] );
	} );

	test.describe( 'for visitors who are not logged in', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'the board, the login prompt and a request page', async ( {
			page,
		} ) => {
			const urls = setUp();

			await page.goto( urls.page );
			expect( await problems( page, [ BOARD, FORM ] ) ).toEqual( [] );

			// The message that asks a guest to log in.
			await page
				.getByRole( 'button', { name: /Vote for Dark mode/ } )
				.click();
			await expect(
				page.getByText( 'Log in to vote on this request.' )
			).toBeVisible();
			expect( await problems( page, [ BOARD ] ) ).toEqual( [] );

			await page.goto( urls.request );
			expect(
				await problems( page, [ DETAILS, '.mwtfrs-official-response' ] )
			).toEqual( [] );

			// A list and cards, with the same message on a card.
			await page.goto( urls.blocks );
			await page
				.locator( CARD )
				.getByRole( 'button', { name: /Vote for Export to CSV/ } )
				.click();
			await expect(
				page
					.locator( CARD )
					.getByText( 'Log in to vote on this request.' )
			).toBeVisible();
			expect( await problems( page, [ BOARD, CARD ] ) ).toEqual( [] );
		} );

		test( 'the form for guests, with its note and an error', async ( {
			page,
		} ) => {
			const urls = setUp( { submitting: 'ANYONE' } );
			await page.goto( urls.page );

			expect( await problems( page, [ FORM ] ) ).toEqual( [] );

			await page.getByText( 'How we use your data' ).click();
			await page.getByLabel( 'Title' ).fill( 'Night shift colours' );
			await page.getByLabel( 'Email' ).fill( 'not-an-email' );
			await page.getByLabel( 'Email' ).evaluate( ( input ) => {
				// Let the form go to the server, to see its own message.
				input.type = 'text';
			} );
			await page.getByRole( 'button', { name: 'Send request' } ).click();
			await expect(
				page.locator( '.mwtfrs-submit__message' )
			).toBeVisible();
			expect( await problems( page, [ FORM ] ) ).toEqual( [] );
		} );

		test( 'the pages behind the link that stops the emails', async ( {
			page,
		} ) => {
			const urls = setUp();

			await page.goto( urls.stopLink );
			expect( await problems( page, [ 'body' ] ) ).toEqual( [] );

			await page
				.getByRole( 'button', { name: 'Stop following', exact: true } )
				.click();
			await expect(
				page.getByRole( 'heading', {
					name: 'You no longer follow this request',
				} )
			).toBeVisible();
			expect( await problems( page, [ 'body' ] ) ).toEqual( [] );
		} );
	} );

	test( 'nothing is cut off or scrolls sideways on a narrow screen', async ( {
		page,
	} ) => {
		const urls = setUp();
		// WCAG 1.4.10: content fits a width of 320 CSS pixels.
		await page.setViewportSize( { width: 320, height: 640 } );

		for ( const url of [ urls.page, urls.request, urls.blocks ] ) {
			await page.goto( url );
			const overflow = await page.evaluate( () =>
				[
					...document.querySelectorAll(
						'.wp-block-mwtfrs-board, .wp-block-mwtfrs-submit, .wp-block-mwtfrs-request, .wp-block-mwtfrs-request-card, .mwtfrs-official-response'
					),
				]
					.filter(
						( element ) =>
							element.scrollWidth > element.clientWidth + 1 ||
							element.getBoundingClientRect().right >
								window.innerWidth + 1
					)
					.map( ( element ) => element.className )
			);
			expect( overflow ).toEqual( [] );
		}
	} );

	test( 'buttons and links that stand alone are big enough to hit', async ( {
		page,
	} ) => {
		const urls = setUp();
		// WCAG 2.5.8: targets are at least 24 by 24 CSS pixels, unless they sit in a line of text.
		for ( const url of [ urls.page, urls.request, urls.blocks ] ) {
			await page.goto( url );
			const small = await page.evaluate( () =>
				[
					...document.querySelectorAll(
						':is(.wp-block-mwtfrs-board, .wp-block-mwtfrs-submit, .wp-block-mwtfrs-request, .wp-block-mwtfrs-request-card) :is(button, select, input:not([type=hidden]), summary, .page-numbers, .mwtfrs-board__more a)'
					),
				]
					// Shown, and not the field that only bots fill in.
					.filter(
						( element ) =>
							element.offsetParent !== null &&
							! element.closest( '[aria-hidden="true"]' )
					)
					.map( ( element ) => {
						const box = element.getBoundingClientRect();
						return {
							what: `${ element.tagName.toLowerCase() }.${
								element.className
							}`,
							width: Math.round( box.width ),
							height: Math.round( box.height ),
						};
					} )
					.filter( ( box ) => box.width < 24 || box.height < 24 )
			);
			expect( small ).toEqual( [] );
		}
	} );
} );
