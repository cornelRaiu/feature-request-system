/**
 * The texts a site can change on the Feature Request Form, in a real browser.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpEval } = require( './wp-cli' );

// Without JavaScript the server refuses a form sent within 3 seconds of loading it.
const HUMAN_DELAY = 3500;

/**
 * Removes all requests, clears the rate limit counters, and sets who may suggest
 * features on the default board and whether their requests wait for approval.
 *
 * @param {Object}  options
 * @param {string}  [options.submitting] Who may submit: ANYONE, LOGGED_IN or NOBODY.
 * @param {boolean} [options.moderation] Whether new requests wait for approval.
 * @param {string}  [options.content]    Content of a page to create, if any.
 * @return {{url: string}} The page, if one was created.
 */
function setUp( {
	submitting = 'LOGGED_IN',
	moderation = true,
	content = '',
} = {} ) {
	const encoded = Buffer.from( content ).toString( 'base64' );

	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\BoardSettings;
			use FeatureRequestSystem\\Content\\BoardTaxonomy;
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\RequestTerms;
			use FeatureRequestSystem\\Security\\RateLimiter;
			global $wpdb;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%' ) );
			BoardSettings::update(
				RequestTerms::default_term( BoardTaxonomy::NAME ),
				array_merge( BoardSettings::defaults(), [
					BoardSettings::SUBMITTING => BoardSettings::${ submitting },
					BoardSettings::MODERATION => ${ moderation ? 'true' : 'false' },
				] )
			);

			$url     = '';
			$content = base64_decode( '${ encoded }' );
			if ( '' !== $content ) {
				$page = get_page_by_path( 'e2e-form-texts' );
				$id   = wp_insert_post( [
					'ID'           => $page ? $page->ID : 0,
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'E2E form texts',
					'post_name'    => 'e2e-form-texts',
					'post_content' => $content,
				] );
				$url = get_permalink( $id );
			}
			echo wp_json_encode( [ 'url' => $url ] );
		` )
	);
}

test.describe( 'Form texts', () => {
	test( 'in the editor, each text shows its default and the form takes the one written', async ( {
		admin,
		editor,
		page,
	} ) => {
		setUp( { moderation: true } );
		await admin.createNewPost( {
			postType: 'page',
			title: 'Ideas',
		} );
		await editor.insertBlock( { name: 'mwtfrs/submit' } );
		const block = editor.canvas.locator( '[data-type="mwtfrs/submit"]' );
		await expect(
			block.getByRole( 'heading', { name: 'Suggest a feature' } )
		).toBeVisible();

		await editor.openDocumentSettingsSidebar();
		const settings = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		await settings.getByRole( 'button', { name: 'Texts' } ).click();

		const heading = settings.getByRole( 'textbox', { name: 'Heading' } );
		await expect( heading ).toHaveValue( '' );
		await expect( heading ).toHaveAttribute(
			'placeholder',
			'Suggest a feature'
		);
		await expect(
			settings.getByRole( 'textbox', { name: 'Button' } )
		).toHaveAttribute( 'placeholder', 'Send request' );
		await expect(
			settings.getByRole( 'textbox', {
				name: 'Message when the request is published',
			} )
		).toHaveAttribute(
			'placeholder',
			'Thanks! Your request is on the board.'
		);

		await heading.fill( 'Tell us what to build' );
		await settings
			.getByRole( 'textbox', { name: 'Title field' } )
			.fill( 'Your idea' );
		await settings
			.getByRole( 'textbox', { name: 'Button' } )
			.fill( 'Send idea' );
		await settings
			.getByRole( 'textbox', {
				name: 'Message when the request is published',
			} )
			.fill( 'Got it! Your idea is up.' );

		// The preview comes from the server, so it shows what visitors will see.
		await expect(
			block.getByRole( 'heading', { name: 'Tell us what to build' } )
		).toBeVisible();
		await expect(
			block.getByRole( 'button', { name: 'Send idea' } )
		).toBeVisible();
		expect( ( await editor.getBlocks() )[ 0 ].attributes ).toMatchObject( {
			heading: 'Tell us what to build',
			titleLabel: 'Your idea',
			submitLabel: 'Send idea',
			publishedMessage: 'Got it! Your idea is up.',
			pendingMessage: '',
		} );

		const pageId = await editor.publishPost();
		await page.goto( `/?page_id=${ pageId }` );

		await expect(
			page.getByRole( 'heading', { name: 'Tell us what to build' } )
		).toBeVisible();
		await expect(
			page.getByText( 'Suggest a feature', { exact: true } )
		).toHaveCount( 0 );
		await page.getByLabel( 'Your idea' ).fill( 'Webhooks' );
		await page.getByRole( 'button', { name: 'Send idea' } ).click();

		// An administrator's request is published straight away.
		await expect(
			page.getByText( 'Got it! Your idea is up.' )
		).toBeVisible();
		await expect(
			page.getByText( 'Thanks! Your request is on the board.' )
		).toHaveCount( 0 );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( {
			javaScriptEnabled: false,
			storageState: { cookies: [], origins: [] },
		} );

		test( 'the shortcode’s texts show, and its message after a guest’s request', async ( {
			page,
		} ) => {
			const form = setUp( {
				submitting: 'ANYONE',
				content:
					'[feature_request_form heading="Tell us what to build" title_label="Your idea" title_help="In a few words." details_label="Tell us more" submit_label="Send idea" pending_message="Got it! We read every idea before it goes up."]',
			} );
			await page.goto( form.url );
			const loadedAt = Date.now();

			await expect(
				page.getByRole( 'heading', { name: 'Tell us what to build' } )
			).toBeVisible();
			await expect( page.getByText( 'In a few words.' ) ).toBeVisible();
			await page.getByLabel( 'Your idea' ).fill( 'Export to PDF' );
			await page
				.getByLabel( 'Tell us more' )
				.fill( 'For the accountant.' );
			await page.waitForTimeout(
				Math.max( 0, HUMAN_DELAY - ( Date.now() - loadedAt ) )
			);
			await page.getByRole( 'button', { name: 'Send idea' } ).click();

			await expect(
				page.getByText(
					'Got it! We read every idea before it goes up.'
				)
			).toBeVisible();
			await expect(
				page.getByText(
					'Thanks! Your request will appear on the board once it is approved.'
				)
			).toHaveCount( 0 );
		} );
	} );
} );
