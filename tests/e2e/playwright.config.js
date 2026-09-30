/**
 * Playwright configuration for the end-to-end tests.
 *
 * The tests run against a WordPress site with the plugin active. By default that is the
 * wp-env tests site (npx wp-env start). To use another site, set WP_BASE_URL, and set
 * WP_CLI to the command that runs WP-CLI on it, for example "wp --path=/path/to/site".
 * The admin login defaults to admin / password (WP_USERNAME, WP_PASSWORD).
 */
const path = require( 'node:path' );
const { defineConfig, devices } = require( '@playwright/test' );

const STORAGE_STATE_PATH =
	process.env.STORAGE_STATE_PATH ||
	path.join( process.cwd(), 'artifacts/storage-states/admin.json' );

module.exports = defineConfig( {
	testDir: '.',
	outputDir: path.join( process.cwd(), 'test-results' ),
	globalSetup: require.resolve( './global-setup.js' ),
	// The tests share one WordPress site, so they run one at a time.
	workers: 1,
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : 'list',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
		storageState: STORAGE_STATE_PATH,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
