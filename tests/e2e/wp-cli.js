/**
 * Runs PHP on the site under test through WP-CLI, to set up test data.
 */
const { execFileSync } = require( 'node:child_process' );

/**
 * Runs a WP-CLI command against the site under test.
 *
 * Uses the command in the WP_CLI environment variable, or the wp-env tests site.
 *
 * @param {string[]} args WP-CLI arguments.
 * @return {string} The command's output.
 */
function wpCli( args ) {
	const [ bin, ...base ] = (
		process.env.WP_CLI || 'npx wp-env run tests-cli wp'
	).split( ' ' );

	return execFileSync( bin, [ ...base, ...args ], {
		encoding: 'utf8',
		env: { ...process.env, XDEBUG_MODE: 'off' },
	} ).trim();
}

/**
 * Evaluates PHP with WordPress loaded and returns what it prints.
 *
 * @param {string} php PHP code without an opening tag.
 * @return {string} The printed output.
 */
function wpEval( php ) {
	return wpCli( [ 'eval', php ] );
}

module.exports = { wpCli, wpEval };
