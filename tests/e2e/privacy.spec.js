/**
 * WordPress's privacy tools with the plugin's data, in a real browser.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpEval } = require( './wp-cli' );

const EMAIL = 'e2e-privacy@example.com';

/**
 * Replaces all requests with two published ones and a user who suggested one, voted for
 * the other and follows both. Clears earlier privacy requests for that user.
 *
 * @return {{suggested: number, voted: number}} The IDs of the two requests.
 */
function setUp() {
	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Notifications\\SubscriptionRepository;
			use FeatureRequestSystem\\Voting\\VoteCount;
			use FeatureRequestSystem\\Voting\\VoteRepository;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			foreach ( get_posts( [ 'post_type' => 'user_request', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}

			$user = get_user_by( 'email', '${ EMAIL }' );
			$user = $user ? $user->ID : wp_insert_user( [ 'user_login' => 'e2e-privacy', 'user_email' => '${ EMAIL }', 'role' => 'subscriber', 'user_pass' => wp_generate_password() ] );

			$ids = [];
			foreach ( [ 'suggested' => 'Offline mode', 'voted' => 'Dark mode' ] as $key => $title ) {
				$ids[ $key ] = wp_insert_post( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => 'Described in a sentence.' ] );
				SubscriptionRepository::add( $ids[ $key ], $user, SubscriptionRepository::SOURCE_VOTE );
			}
			update_post_meta( $ids['suggested'], RequestPostType::META_SUBMITTER_ID, $user );
			VoteRepository::add( $ids['voted'], hash( 'sha256', 'u:' . $user ), $user );
			VoteRepository::add( $ids['voted'], hash( 'sha256', 'someone else' ), 0 );
			VoteCount::sync( $ids['voted'] );

			echo wp_json_encode( $ids );
		` )
	);
}

/**
 * Says what the plugin still knows about the user.
 *
 * @param {{suggested: number, voted: number}} ids The IDs of the two requests.
 * @return {{submitter: string, votes: number, ownVotes: number, follows: number}} The submitter recorded for the suggested request, the votes of the other request, how many of them are tied to the user, and how many requests the user follows.
 */
function stored( ids ) {
	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Notifications\\SubscriptionRepository;
			use FeatureRequestSystem\\Voting\\VoteRepository;

			$user = get_user_by( 'email', '${ EMAIL }' )->ID;
			echo wp_json_encode( [
				'submitter' => (string) get_post_meta( ${ ids.suggested }, RequestPostType::META_SUBMITTER_ID, true ),
				'votes'     => VoteRepository::count( ${ ids.voted } ),
				'ownVotes'  => count( VoteRepository::of_user( $user, 10, 0 ) ),
				'follows'   => SubscriptionRepository::count_of_user( $user ),
			] );
		` )
	);
}

/**
 * Files a privacy request for the user on one of the two tools screens, without the
 * confirmation email, so that it can be carried out at once.
 *
 * @param {import('@playwright/test').Page} page   Browser page.
 * @param {string}                          screen export-personal-data.php or erase-personal-data.php.
 */
async function fileRequest( page, screen ) {
	await page.goto( `/wp-admin/${ screen }` );
	await page
		.locator( '#username_or_email_for_privacy_request' )
		.fill( EMAIL );
	await page.locator( '#send_confirmation_email' ).uncheck();
	await page.getByRole( 'button', { name: 'Send Request' } ).click();
	await expect( page.locator( '#the-list' ) ).toContainText( EMAIL );
}

test.describe( 'Privacy tools', () => {
	test( 'the export has what a user suggested, voted for and follows', async ( {
		page,
	} ) => {
		setUp();
		await fileRequest( page, 'export-personal-data.php' );
		const row = page.locator( '#the-list tr', { hasText: EMAIL } );

		// The export is built step by step in the background of the page, then offered as a download.
		const download = page.waitForEvent( 'download' );
		await row.hover();
		await row
			.getByRole( 'button', { name: 'Download personal data' } )
			.first()
			.click();
		const file = await download;
		expect( file.suggestedFilename() ).toMatch( /\.zip$/ );

		const groups = JSON.parse(
			wpEval( `
				use FeatureRequestSystem\\Privacy\\PersonalData;
				echo wp_json_encode( array_values( array_unique( wp_list_pluck( PersonalData::export( '${ EMAIL }' )['data'], 'group_label' ) ) ) );
			` )
		);
		expect( groups ).toEqual( [
			'Feature requests',
			'Feature request votes',
			'Followed feature requests',
		] );
	} );

	test( 'erasing keeps the request and the vote count, without the user', async ( {
		page,
	} ) => {
		const ids = setUp();
		expect( stored( ids ) ).toEqual( {
			submitter: expect.stringMatching( /^\d+$/ ),
			votes: 2,
			ownVotes: 1,
			follows: 2,
		} );

		await fileRequest( page, 'erase-personal-data.php' );
		const row = page.locator( '#the-list tr', { hasText: EMAIL } );
		await row.hover();
		await row
			.getByRole( 'button', { name: 'Erase personal data' } )
			.first()
			.click();

		await expect(
			page.getByText(
				'Votes for feature requests were kept, so that vote counts stay correct, without the record of whose they are.'
			)
		).toBeVisible();
		await expect(
			page.getByText(
				'Feature requests this person suggested were kept, without the record of who suggested them.'
			)
		).toBeVisible();

		expect( stored( ids ) ).toEqual( {
			submitter: '',
			votes: 2,
			ownVotes: 0,
			follows: 0,
		} );
	} );

	test( 'the policy guide offers a text about the plugin', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/options-privacy.php?tab=policyguide' );

		// Each plugin's text is in a panel that its heading opens.
		await page
			.getByRole( 'button', { name: 'Feature Request System' } )
			.click();
		const panel = page.locator( '.privacy-settings-accordion-panel', {
			hasText: 'a cookie named mwtfrs_vid',
		} );

		await expect( panel ).toBeVisible();
		await expect( panel ).toContainText(
			'We do not store your IP address.'
		);
	} );
} );
