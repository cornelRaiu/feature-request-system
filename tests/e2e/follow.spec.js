/**
 * Following a request, and the emails to followers, in a real browser.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpEval } = require( './wp-cli' );

/**
 * Replaces all requests with one published request, resets what these tests change,
 * and returns the request.
 *
 * @param {Object}   options
 * @param {string}   options.title       Request title.
 * @param {string[]} [options.followers] Logins of users who follow the request. They are created when missing.
 * @return {{id: number, url: string}} The request's ID and page.
 */
function setUpRequest( { title, followers = [] } ) {
	const data = Buffer.from( JSON.stringify( { title, followers } ) ).toString(
		'base64'
	);

	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Notifications\\Queue;
			use FeatureRequestSystem\\Notifications\\SubscriptionRepository;
			use FeatureRequestSystem\\Notifications\\Subscriptions;
			use FeatureRequestSystem\\Security\\RateLimiter;
			use FeatureRequestSystem\\Settings;
			global $wpdb;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%' ) );
			Queue::cancel_all();
			Settings::update( Settings::NOTIFY_FOLLOWERS, true );
			Settings::update( Settings::SUBSCRIBE_VOTERS, true );
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), BoardSettings::defaults() );
			Subscriptions::set_automatic( get_user_by( 'login', 'admin' )->ID, true );

			$data = json_decode( base64_decode( '${ data }' ), true );
			$id   = wp_insert_post( [
				'post_type'    => RequestPostType::NAME,
				'post_status'  => 'publish',
				'post_title'   => $data['title'],
				'post_content' => 'Described in a sentence or two.',
			] );

			foreach ( $data['followers'] as $login ) {
				$user    = get_user_by( 'login', $login );
				$user_id = $user ? $user->ID : wp_insert_user( [ 'user_login' => $login, 'user_email' => $login . '@example.com', 'role' => 'subscriber', 'user_pass' => wp_generate_password() ] );
				SubscriptionRepository::add( $id, $user_id, SubscriptionRepository::SOURCE_VOTE );
			}

			echo wp_json_encode( [ 'id' => $id, 'url' => get_permalink( $id ) ] );
		` )
	);
}

/**
 * Runs the plugin's background work that is waiting, also what is only due in a few
 * minutes, and returns the emails it would have sent.
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
 * Says whether a user follows a request.
 *
 * @param {number} requestId Request ID.
 * @param {string} login     The user's login.
 * @return {boolean} Whether they follow it.
 */
function isFollowing( requestId, login ) {
	return (
		wpEval( `
			use FeatureRequestSystem\\Notifications\\Subscriptions;
			echo Subscriptions::is_following( ${ requestId }, get_user_by( 'login', '${ login }' )->ID ) ? 'yes' : 'no';
		` ) === 'yes'
	);
}

/**
 * Changes a request's status on its edit screen.
 *
 * @param {import('@playwright/test').Page} page      Browser page.
 * @param {number}                          requestId Request ID.
 * @param {string}                          status    Name of the new status.
 * @param {boolean}                         notify    Whether to leave the box that emails the followers ticked.
 */
async function changeStatus( page, requestId, status, notify = true ) {
	await page.goto( `/wp-admin/post.php?post=${ requestId }&action=edit` );
	await page
		.locator( '#mwtfrs_status-select' )
		.selectOption( { label: status } );
	if ( ! notify ) {
		await page.getByLabel( /Email \d+ followers? when/ ).uncheck();
	}
	await page.getByRole( 'button', { name: 'Update', exact: true } ).click();
	await expect( page.locator( '#message' ) ).toBeVisible();
}

test.describe( 'Following a feature request', () => {
	test( 'a user follows a request and stops, and the page remembers both', async ( {
		page,
	} ) => {
		const request = setUpRequest( { title: 'Dark mode' } );
		await page.goto( request.url );
		const follow = page.getByRole( 'button', {
			name: 'Follow',
			exact: true,
		} );
		const stop = page.getByRole( 'button', { name: 'Stop following' } );

		await expect( follow ).toBeVisible();
		await expect(
			page.getByText( 'Get an email when the status changes.', {
				exact: true,
			} )
		).toBeVisible();

		await follow.click();
		await expect( stop ).toBeVisible();
		await expect(
			page.getByText( 'You get an email when the status changes.', {
				exact: true,
			} )
		).toBeVisible();
		await expect( follow ).toHaveCount( 0 );

		await page.reload();
		await expect( stop ).toBeVisible();

		await stop.click();
		await expect( follow ).toBeVisible();

		await page.reload();
		await expect( follow ).toBeVisible();
		expect( isFollowing( request.id, 'admin' ) ).toBe( false );
	} );

	test( 'voting for a request follows it, and taking the vote back ends that', async ( {
		page,
	} ) => {
		const request = setUpRequest( { title: 'Dark mode' } );
		await page.goto( request.url );
		const vote = page.getByRole( 'button', {
			name: /Vote for Dark mode/,
		} );
		const follow = page.getByRole( 'button', {
			name: 'Follow',
			exact: true,
		} );
		const stop = page.getByRole( 'button', { name: 'Stop following' } );

		await vote.click();
		await expect( vote ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( stop ).toBeVisible();

		await vote.click();
		await expect( vote ).toHaveAttribute( 'aria-pressed', 'false' );
		await expect( follow ).toBeVisible();
	} );

	test( 'a status change emails the followers, and the link in the email stops the emails', async ( {
		page,
		browser,
	} ) => {
		const request = setUpRequest( {
			title: 'Dark mode',
			followers: [ 'e2e-follower' ],
		} );

		await changeStatus( page, request.id, 'Planned' );

		const emails = sendWaitingEmails();
		expect( emails ).toHaveLength( 1 );
		expect( emails[ 0 ].to ).toBe( 'e2e-follower@example.com' );
		expect( emails[ 0 ].subject ).toContain(
			'Status changed to Planned: Dark mode'
		);
		expect( emails[ 0 ].message ).toContain( 'Status: Planned (was Open)' );
		expect( emails[ 0 ].message ).toContain( request.url );
		const link = emails[ 0 ].message.match(
			/Stop following it: (\S+)/
		)[ 1 ];

		// The follower opens the link from their mail program, where they are not logged in.
		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const visitor = await context.newPage();
		const opened = await visitor.goto( link );
		await expect(
			visitor.getByRole( 'heading', {
				name: 'Stop following this request?',
			} )
		).toBeVisible();
		await expect(
			visitor.getByText(
				'You will get no more emails about "Dark mode".'
			)
		).toBeVisible();
		expect( isFollowing( request.id, 'e2e-follower' ) ).toBe( true );

		// The link holds a secret. The page a follower goes to from here must not learn it.
		expect( opened.headers()[ 'referrer-policy' ] ).toBe( 'no-referrer' );
		await visitor
			.getByRole( 'link', {
				name: 'Keep following and go to the request',
			} )
			.click();
		await expect(
			visitor.getByRole( 'heading', { name: 'Dark mode', level: 1 } )
		).toBeVisible();
		expect( await visitor.evaluate( () => document.referrer ) ).toBe( '' );
		expect( isFollowing( request.id, 'e2e-follower' ) ).toBe( true );
		await visitor.goto( link );

		await visitor
			.getByRole( 'button', { name: 'Stop following', exact: true } )
			.click();
		await expect(
			visitor.getByRole( 'heading', {
				name: 'You no longer follow this request',
			} )
		).toBeVisible();
		expect( isFollowing( request.id, 'e2e-follower' ) ).toBe( false );

		await visitor.goto( link );
		await expect(
			visitor.getByRole( 'heading', {
				name: 'This link no longer works',
			} )
		).toBeVisible();
		await context.close();

		await changeStatus( page, request.id, 'Shipped' );
		expect( sendWaitingEmails() ).toEqual( [] );
	} );

	test( 'a moderator changes the status without an email by unticking the box', async ( {
		page,
	} ) => {
		const request = setUpRequest( {
			title: 'Dark mode',
			followers: [ 'e2e-follower', 'e2e-follower-2' ],
		} );
		await page.goto(
			`/wp-admin/post.php?post=${ request.id }&action=edit`
		);

		await expect(
			page.getByLabel( 'Email 2 followers when the status changes' )
		).toBeChecked();

		await changeStatus( page, request.id, 'Planned', false );

		await expect( page.locator( '#mwtfrs_status-select' ) ).toHaveValue(
			/\d+/
		);
		await expect(
			page.locator( '#mwtfrs_status-select option:checked' )
		).toHaveText( 'Planned' );
		expect( sendWaitingEmails() ).toEqual( [] );
	} );

	test( 'the profile lists the followed requests and stops following the ticked one', async ( {
		page,
	} ) => {
		const request = setUpRequest( { title: 'Dark mode' } );
		await page.goto( request.url );
		await page
			.getByRole( 'button', { name: 'Follow', exact: true } )
			.click();
		await expect(
			page.getByRole( 'button', { name: 'Stop following' } )
		).toBeVisible();

		await page.goto( '/wp-admin/profile.php' );
		await expect(
			page.getByLabel( 'Follow the requests I vote for or suggest' )
		).toBeChecked();
		await expect(
			page.getByRole( 'link', { name: 'Dark mode' } )
		).toHaveAttribute( 'href', request.url );

		await page.getByLabel( 'Stop following Dark mode' ).check();
		await page.getByRole( 'button', { name: 'Update Profile' } ).click();

		await expect( page.getByText( 'Profile updated.' ) ).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Dark mode' } )
		).toHaveCount( 0 );
		expect( isFollowing( request.id, 'admin' ) ).toBe( false );
	} );

	test.describe( 'for visitors who are not logged in', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'there is no Follow button', async ( { page } ) => {
			const request = setUpRequest( { title: 'Dark mode' } );
			await page.goto( request.url );

			await expect(
				page.getByRole( 'heading', { name: 'Dark mode', level: 1 } )
			).toBeVisible();
			await expect(
				page.getByRole( 'button', { name: /follow/i } )
			).toHaveCount( 0 );
		} );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'the Follow button still works and says what happened', async ( {
			page,
		} ) => {
			const request = setUpRequest( { title: 'Dark mode' } );
			await page.goto( request.url );

			await page
				.getByRole( 'button', { name: 'Follow', exact: true } )
				.click();
			await expect(
				page.getByText( 'You now follow this request.' )
			).toBeVisible();

			await page
				.getByRole( 'button', { name: 'Stop following' } )
				.click();
			await expect(
				page.getByText( 'You no longer follow this request.' )
			).toBeVisible();
			await expect(
				page.getByRole( 'button', { name: 'Follow', exact: true } )
			).toBeVisible();
		} );
	} );
} );
