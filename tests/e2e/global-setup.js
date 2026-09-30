/**
 * Logs in as the admin once and saves the session for the tests.
 */
const { request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

module.exports = async function globalSetup( config ) {
	const { baseURL, storageState } = config.projects[ 0 ].use;
	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath:
			typeof storageState === 'string' ? storageState : undefined,
		baseURL,
	} );

	await requestUtils.setupRest();
	await requestContext.dispose();
};
