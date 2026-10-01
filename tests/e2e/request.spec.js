/**
 * The page of a single feature request in a real browser.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpEval } = require( './wp-cli' );

/**
 * Replaces all requests with one published request and returns its page.
 *
 * Also clears rate limit counters, so repeated runs within the hour do not hit them.
 *
 * @param {Object} options
 * @param {string} options.title         Request title.
 * @param {number} [options.votes]       Votes the request starts with.
 * @param {string} [options.teamComment] Text of a comment by the admin, not marked as official.
 * @return {{url: string}} The request's page.
 */
function setUpRequest( { title, votes = 0, teamComment = '' } ) {
	const data = Buffer.from(
		JSON.stringify( { title, votes, teamComment } )
	).toString( 'base64' );

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
			BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::LOGGED_IN ] );

			$data = json_decode( base64_decode( '${ data }' ), true );
			$id   = wp_insert_post( [
				'post_type'      => RequestPostType::NAME,
				'post_status'    => 'publish',
				'post_title'     => $data['title'],
				'post_content'   => 'Described in a sentence or two.',
				'comment_status' => 'open',
			] );
			for ( $i = 0; $i < $data['votes']; $i++ ) {
				VoteRepository::add( $id, hash( 'sha256', 'e2e:' . $id . ':' . $i ), 0 );
			}
			VoteCount::sync( $id );

			if ( $data['teamComment'] ) {
				$admin = get_user_by( 'login', 'admin' );
				wp_insert_comment( [
					'comment_post_ID'      => $id,
					'user_id'              => $admin->ID,
					'comment_author'       => $admin->display_name,
					'comment_author_email' => $admin->user_email,
					'comment_content'      => $data['teamComment'],
					'comment_approved'     => 1,
				] );
			}

			echo wp_json_encode( [ 'url' => get_permalink( $id ) ] );
		` )
	);
}

test.describe( 'Feature request page', () => {
	test( 'shows the vote button, and a vote survives a reload', async ( {
		page,
	} ) => {
		const request = setUpRequest( { title: 'Dark mode', votes: 3 } );
		await page.goto( request.url );
		const button = page.getByRole( 'button', {
			name: /Vote for Dark mode/,
		} );

		await expect(
			page.getByRole( 'heading', { name: 'Dark mode', level: 1 } )
		).toBeVisible();
		await expect( button ).toHaveAttribute( 'aria-pressed', 'false' );

		await button.click();
		await expect( button ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( button ).toContainText( '4' );

		await page.reload();
		await expect( button ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( button ).toContainText( '4' );
	} );

	test( 'the team posts an official response from the comment form, and it is pinned', async ( {
		page,
	} ) => {
		const request = setUpRequest( { title: 'Export to CSV' } );
		await page.goto( request.url );
		const pinned = page.getByRole( 'region', {
			name: 'Official response',
		} );

		await expect( pinned ).toHaveCount( 0 );

		await page.locator( '#comment' ).fill( 'Planned for version 2.4.' );
		await page.getByLabel( 'Post as the official response' ).check();
		await page.getByRole( 'button', { name: 'Post Comment' } ).click();

		await expect( pinned ).toContainText( 'Planned for version 2.4.' );
		await expect( page.locator( '.mwtfrs-official-label' ) ).toHaveText(
			'Official response'
		);
	} );

	test( 'a moderator marks a comment as the official response from the Comments screen', async ( {
		page,
	} ) => {
		const request = setUpRequest( {
			title: 'Webhooks',
			teamComment: 'We are looking into webhooks.',
		} );
		await page.goto( '/wp-admin/edit-comments.php' );
		const row = page.locator( '#the-comment-list tr', {
			hasText: 'We are looking into webhooks.',
		} );

		await row.hover();
		await row
			.getByRole( 'link', { name: 'Mark as official response' } )
			.click();
		await expect(
			row.getByRole( 'link', { name: 'Remove official mark' } )
		).toBeAttached();

		await page.goto( request.url );
		await expect(
			page.getByRole( 'region', { name: 'Official response' } )
		).toContainText( 'We are looking into webhooks.' );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'the vote button still adds the vote and says so', async ( {
			page,
		} ) => {
			const request = setUpRequest( { title: 'Offline mode', votes: 5 } );
			await page.goto( request.url );
			const button = page.getByRole( 'button', {
				name: /Vote for Offline mode/,
			} );

			await button.click();
			await expect(
				page.getByText( 'Your vote was added.' )
			).toBeVisible();
			await expect( button ).toContainText( '6' );
		} );
	} );
} );
