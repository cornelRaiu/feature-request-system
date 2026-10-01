/**
 * Requests to the plugin's REST API from the blocks' front-end scripts.
 */

/**
 * Sends a request to the plugin's REST API.
 *
 * Logged-in visitors send the REST nonce. If it expired while the page was open,
 * a fresh one is fetched and the request is sent once more.
 *
 * This is a generator, like the store actions that call it with yield*, so the
 * Interactivity API can restore the element's scope while it waits.
 *
 * @param {Object} state            Store state with restUrl, ajaxUrl and nonce.
 * @param {string} path             Route relative to the plugin's REST namespace.
 * @param {Object} [options]        Request options.
 * @param {string} [options.method] HTTP method. Defaults to GET.
 * @param {Object} [options.body]   JSON body.
 * @param {Object} [options.query]  Query parameters.
 * @return {Response} The response to the request.
 */
export function* send(
	state,
	path,
	{ method = 'GET', body, query = {} } = {}
) {
	// Building a URL object keeps sites without pretty permalinks working,
	// where the REST URL already has a ?rest_route= parameter.
	const url = new URL( state.restUrl + path );
	Object.entries( query ).forEach( ( [ key, value ] ) =>
		url.searchParams.set( key, value )
	);

	const request = () =>
		fetch( url, {
			method,
			credentials: 'same-origin',
			headers: {
				...( body ? { 'Content-Type': 'application/json' } : {} ),
				...( state.nonce ? { 'X-WP-Nonce': state.nonce } : {} ),
			},
			body: body ? JSON.stringify( body ) : undefined,
		} );

	let response = yield request();

	if ( response.status === 403 && state.nonce ) {
		const error = yield response.clone().json();
		if ( error.code === 'rest_cookie_invalid_nonce' ) {
			const refreshed = yield fetch(
				`${ state.ajaxUrl }?action=rest-nonce`,
				{ credentials: 'same-origin' }
			);
			if ( refreshed.ok ) {
				state.nonce = yield refreshed.text();
				response = yield request();
			}
		}
	}

	return response;
}
