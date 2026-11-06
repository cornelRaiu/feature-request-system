/**
 * Merging a duplicate request from its edit screen, in a real browser.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { default: AxeBuilder } = require( '@axe-core/playwright' );
const { wpEval } = require( './wp-cli' );

/**
 * Replaces all requests with two that ask for the same: "Dark mode" with three votes
 * from guests, and "Dark theme" with two from guests, one of whom voted for both, and
 * one from a user, who follows it because of that vote.
 *
 * @return {{target: {id: number, url: string}, duplicate: {id: number, url: string}}} The two requests.
 */
function setUp() {
	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Notifications\\Queue;
			use FeatureRequestSystem\\Notifications\\SubscriptionRepository;
			use FeatureRequestSystem\\Settings;
			use FeatureRequestSystem\\Voting\\VoteCount;
			use FeatureRequestSystem\\Voting\\VoteRepository;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			Queue::cancel_all();
			Settings::update( Settings::NOTIFY_FOLLOWERS, true );
			Settings::update( Settings::MERGE_EMAIL, Settings::MERGE_EMAIL_FOLLOWERS );
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), BoardSettings::defaults() );

			$ids = [];
			foreach ( [ 'Dark mode' => [ 'a', 'b', 'both' ], 'Dark theme' => [ 'both', 'c' ] ] as $title => $guests ) {
				$id = wp_insert_post( [
					'post_type'    => RequestPostType::NAME,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => 'Described in a sentence or two.',
				] );
				foreach ( $guests as $guest ) {
					VoteRepository::add( $id, hash( 'sha256', 'g:e2e-merge-' . $guest ), 0 );
				}
				$ids[] = $id;
			}

			$login   = 'e2e-merge-follower';
			$user    = get_user_by( 'login', $login );
			$user_id = $user ? $user->ID : wp_insert_user( [ 'user_login' => $login, 'user_email' => $login . '@example.com', 'role' => 'subscriber', 'user_pass' => wp_generate_password() ] );
			VoteRepository::add( $ids[1], hash( 'sha256', 'u:' . $user_id ), $user_id );
			SubscriptionRepository::add( $ids[1], $user_id, SubscriptionRepository::SOURCE_VOTE );

			foreach ( $ids as $id ) {
				VoteCount::sync( $id );
			}
			echo wp_json_encode( [
				'target'    => [ 'id' => $ids[0], 'url' => get_permalink( $ids[0] ) ],
				'duplicate' => [ 'id' => $ids[1], 'url' => get_permalink( $ids[1] ) ],
			] );
		` )
	);
}

/**
 * Runs the plugin's background work that is waiting, and returns the emails it would have sent.
 *
 * @return {{to: string, subject: string, message: string}[]} The emails.
 */
function sendWaitingEmails() {
	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Notifications\\Queue;

			$sent = [];
			add_filter(
				'pre_wp_mail',
				static function ( $pre, $mail ) use ( &$sent ) {
					$sent[] = [ 'to' => $mail['to'], 'subject' => $mail['subject'], 'message' => $mail['message'] ];
					return true;
				},
				10,
				2
			);

			$waiting = as_get_scheduled_actions( [ 'group' => Queue::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ], 'ids' );
			foreach ( $waiting as $action_id ) {
				ActionScheduler::runner()->process_action( $action_id, 'e2e' );
			}
			echo wp_json_encode( $sent );
		` )
	);
}

/**
 * Finds a request by its title in the Merge box and chooses it.
 *
 * @param {import('@playwright/test').Locator} box   The Merge box.
 * @param {string}                             title Title to search for.
 */
async function choose( box, title ) {
	await box.getByLabel( 'Find another request by its title' ).fill( title );
	await expect( box.getByRole( 'status' ) ).toHaveText( '1 request found.' );
	await box.getByRole( 'radio', { name: title, exact: true } ).check();
}

test.describe( 'Merging a duplicate', () => {
	test( 'a moderator merges a duplicate from its edit screen, and its address leads to the other request', async ( {
		page,
		browser,
	} ) => {
		const { target, duplicate } = setUp();
		await page.goto(
			`/wp-admin/post.php?post=${ duplicate.id }&action=edit`
		);
		const box = page.locator( '#mwtfrs-merge' );

		// Without a search, the box offers the requests with similar titles.
		await expect(
			box.getByRole( 'radio', { name: 'Dark mode (3 votes · Open)' } )
		).toBeVisible();

		// Merging cannot be undone, so nothing happens until the box is ticked.
		await choose( box, 'Dark mode' );
		await box.getByRole( 'button', { name: 'Merge' } ).click();
		await expect(
			page.getByText( 'Nothing was merged. Tick the box to confirm' )
		).toBeVisible();
		await expect( page ).toHaveURL(
			new RegExp( `post=${ duplicate.id }` )
		);
		await page.waitForLoadState();

		await choose( box, 'Dark mode' );
		// The accessibility checks see the box as it opens. Here it shows what a search found.
		const { violations } = await new AxeBuilder( { page } )
			.withTags( [
				'wcag2a',
				'wcag2aa',
				'wcag21a',
				'wcag21aa',
				'wcag22aa',
			] )
			.include( '#mwtfrs-merge' )
			.analyze();
		expect( violations.map( ( violation ) => violation.id ) ).toEqual( [] );
		await box
			.getByLabel(
				'Merging cannot be undone. I want to merge this request.'
			)
			.check();
		await box.getByRole( 'button', { name: 'Merge' } ).click();

		await expect( page ).toHaveURL( new RegExp( `post=${ target.id }` ) );
		await expect(
			page.getByText( '"Dark theme" was merged into this request.' )
		).toBeVisible();
		// Three votes and three, less the guest who voted for both.
		await expect( page.locator( '#mwtfrs-request-details' ) ).toContainText(
			'5 votes'
		);

		const emails = sendWaitingEmails();
		expect( emails ).toHaveLength( 1 );
		expect( emails[ 0 ].to ).toBe( 'e2e-merge-follower@example.com' );
		expect( emails[ 0 ].subject ).toContain(
			'Dark theme was merged into Dark mode'
		);
		expect( emails[ 0 ].message ).toContain(
			'"Dark theme" was merged into "Dark mode", and your vote now counts there.'
		);
		expect( emails[ 0 ].message ).toContain( target.url );

		// The duplicate has a view of its own in the list, outside All.
		await page.goto( '/wp-admin/edit.php?post_type=mwtfrs_request' );
		await expect(
			page.locator( '.subsubsub' ).getByRole( 'link', { name: /Merged/ } )
		).toHaveText( 'Merged (1)' );
		await expect(
			page.locator( '.subsubsub' ).getByRole( 'link', { name: /All/ } )
		).toHaveText( 'All (1)' );

		// A visitor who follows an old link lands on the request it was merged into.
		const visitor = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const guestPage = await visitor.newPage();
		const response = await guestPage.goto( duplicate.url );
		expect( response.request().redirectedFrom() ).not.toBeNull();
		expect(
			( await response.request().redirectedFrom().response() ).status()
		).toBe( 301 );
		await expect( guestPage ).toHaveURL( target.url );
		await expect(
			guestPage.getByRole( 'heading', { name: 'Dark mode', level: 1 } )
		).toBeVisible();
		await visitor.close();
	} );

	test( 'the merged request’s edit screen says where it went, and it can only go to the trash', async ( {
		page,
	} ) => {
		const { target, duplicate } = setUp();
		wpEval( `
			wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
			FeatureRequestSystem\\Merging\\MergeService::merge( ${ duplicate.id }, ${ target.id } );
		` );

		await page.goto(
			`/wp-admin/post.php?post=${ duplicate.id }&action=edit`
		);
		const box = page.locator( '#mwtfrs-merge' );

		await expect( box ).toContainText(
			'This request was merged into Dark mode. Its address leads there.'
		);
		await expect(
			box.getByRole( 'link', { name: 'Dark mode' } )
		).toHaveAttribute( 'href', new RegExp( `post=${ target.id }` ) );
		await expect(
			box.getByRole( 'button', { name: 'Merge' } )
		).toHaveCount( 0 );

		// Publishing or saving it would change nothing, so the Publish box gives way to this one.
		await expect( page.locator( '#submitdiv' ) ).toHaveCount( 0 );
		await box.getByRole( 'link', { name: 'Move to Trash' } ).click();
		await expect( page ).toHaveURL( /edit\.php\?post_type=mwtfrs_request/ );
		expect(
			wpEval( `echo get_post_status( ${ duplicate.id } );` ).trim()
		).toBe( 'trash' );
	} );
} );
