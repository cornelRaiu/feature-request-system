/**
 * The question asked when the plugin is deactivated, on the Plugins screen.
 *
 * These tests never click "Send & Deactivate": that would post a real answer to
 * master-webtools.com. The PHP tests check what is sent.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpCli } = require( './wp-cli' );

const SNOOZE_META = 'mwtfrs_deactivation_feedback_snooze';

/**
 * Activates the plugin and removes the admin's pause of the question, if there is one.
 */
function reset() {
	wpCli( [ 'plugin', 'activate', 'feature-request-system' ] );
	try {
		wpCli( [ 'user', 'meta', 'delete', 'admin', SNOOZE_META ] );
	} catch {
		// Nothing to delete.
	}
}

/**
 * The plugin's row on the Plugins screen.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
function pluginRow( page ) {
	return page.locator(
		'tr[data-plugin="feature-request-system/feature-request-system.php"]'
	);
}

test.describe( 'Deactivation question', () => {
	test.beforeEach( reset );
	test.afterAll( reset );

	test( 'Deactivate asks why; Cancel and Escape keep the plugin active', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/plugins.php' );
		await pluginRow( page )
			.getByRole( 'link', { name: /Deactivate/ } )
			.click();

		const dialog = page.getByRole( 'dialog', {
			name: /why you are deactivating Feature Request System/,
		} );
		await expect( dialog ).toBeVisible();
		await expect( dialog.getByRole( 'radio' ) ).toHaveCount( 7 );
		await expect(
			dialog.getByRole( 'button', { name: 'Skip & Deactivate' } )
		).toBeVisible();

		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toBeHidden();

		await pluginRow( page )
			.getByRole( 'link', { name: /Deactivate/ } )
			.click();
		await expect( dialog ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();

		await expect( pluginRow( page ) ).toHaveClass( /\bactive\b/ );
	} );

	test( 'each answer shows what goes with it, and the button says whether it sends', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/plugins.php' );
		await pluginRow( page )
			.getByRole( 'link', { name: /Deactivate/ } )
			.click();
		const dialog = page.getByRole( 'dialog' );
		const submit = dialog.locator( '.mwtfrs-df-submit' );

		await dialog.getByLabel( 'It stopped working' ).check();
		await expect(
			dialog.getByRole( 'link', {
				name: /Get help in the support forum/,
			} )
		).toBeVisible();
		await expect( submit ).toHaveText( 'Send & Deactivate' );

		await dialog.getByLabel( 'Other', { exact: true } ).check();
		await expect( submit ).toHaveText( 'Deactivate' );
		await dialog
			.getByLabel( 'Tell us the reason, so we can improve the plugin.' )
			.fill( 'We moved to another tool' );
		await expect( submit ).toHaveText( 'Send & Deactivate' );

		await dialog.getByLabel( /It is temporary/ ).check();
		await expect(
			dialog.getByLabel( /Don't ask again for 24 hours/ )
		).toBeVisible();
		await expect( dialog.locator( '.mwtfrs-df-note' ) ).toBeHidden();
		await expect( submit ).toHaveText( 'Deactivate' );
	} );

	test( 'Skip & Deactivate deactivates without asking the site anything', async ( {
		page,
	} ) => {
		const answers = [];
		page.on( 'request', ( request ) => {
			if ( request.url().includes( 'admin-ajax.php' ) ) {
				answers.push( request.postData() );
			}
		} );

		await page.goto( '/wp-admin/plugins.php' );
		await pluginRow( page )
			.getByRole( 'link', { name: /Deactivate/ } )
			.click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Skip & Deactivate' } )
			.click();

		await expect( page.getByText( 'Plugin deactivated.' ) ).toBeVisible();
		await expect( pluginRow( page ) ).toHaveClass( /\binactive\b/ );
		expect( answers ).toEqual( [] );
	} );

	test( 'a temporary deactivation can put the question off for a day', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/plugins.php' );
		await pluginRow( page )
			.getByRole( 'link', { name: /Deactivate/ } )
			.click();
		const dialog = page.getByRole( 'dialog' );
		await dialog.getByLabel( /It is temporary/ ).check();
		await dialog.getByLabel( /Don't ask again for 24 hours/ ).check();
		await dialog.locator( '.mwtfrs-df-submit' ).click();

		await expect( page.getByText( 'Plugin deactivated.' ) ).toBeVisible();
		expect(
			Number( wpCli( [ 'user', 'meta', 'get', 'admin', SNOOZE_META ] ) )
		).toBeGreaterThan( Date.now() / 1000 );

		// Active again, the plugin doesn't ask for a day: Deactivate works at once.
		wpCli( [ 'plugin', 'activate', 'feature-request-system' ] );
		await page.goto( '/wp-admin/plugins.php' );
		await pluginRow( page )
			.getByRole( 'link', { name: /Deactivate/ } )
			.click();
		await expect( page.getByText( 'Plugin deactivated.' ) ).toBeVisible();
	} );
} );
