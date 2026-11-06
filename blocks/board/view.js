/**
 * Front-end script of the Feature Request Board block: voting, and filters that apply
 * without a page load.
 *
 * The filter form is a plain GET form and the page links are plain links, so the board
 * works without this script. With it, the router of the Interactivity API fetches the
 * same public URL the form or the link would open, and replaces only the board's
 * results. Page caches serve that URL as they serve any other. The form stays as it is,
 * so the control in use keeps its focus and what is being typed in it.
 *
 * The results carry a key of their own for each set of filters. New results therefore
 * start afresh and ask which of them the visitor voted for, as on the first load.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

import '../shared/votes';

/**
 * How long the search waits after the last key press, in milliseconds.
 */
const SEARCH_DELAY = 300;

/**
 * Regions whose first results are shown. Results that replace them are announced.
 */
const shown = new Set();

/**
 * The region that gets the focus once its new results are in: the one whose page link
 * was used, because the link that had the focus is gone.
 */
let focusRegion = '';

/**
 * The URL being opened, so that the same change is not applied twice.
 */
let opening = '';

/**
 * Timer of the search that waits for typing to stop.
 */
let searchTimer;

/**
 * The URL the filter form would open: its fields, without the empty ones and without
 * the values the board takes anyway.
 *
 * @param {HTMLFormElement}        form     The filter form.
 * @param {Object<string, string>} defaults Values of parameters that can be left out.
 * @return {string} The URL.
 */
function urlOf( form, defaults ) {
	const url = new URL( form.action );
	// A GET form replaces the query string. The form repeats what the page's own URL needs.
	url.search = '';
	url.hash = '';
	new window.FormData( form ).forEach( ( value, name ) => {
		if ( value !== '' && defaults[ name ] !== value ) {
			url.searchParams.append( name, value );
		}
	} );

	return url.href;
}

/**
 * The results region of a board.
 *
 * @param {string} region Region ID.
 * @return {HTMLElement|null} The region, or null.
 */
function regionOf( region ) {
	return document.querySelector(
		`[data-wp-router-region="${ window.CSS.escape( region ) }"]`
	);
}

/**
 * Opens a board's results for a URL without loading the page. If the router cannot be
 * loaded, the browser opens the URL.
 *
 * @param {string}  url     URL of the page with the new results.
 * @param {string}  region  ID of the board's results region.
 * @param {boolean} [focus] Whether the results get the focus once they are in.
 */
async function open( url, region, focus = false ) {
	if ( url === ( opening || window.location.href ) ) {
		return;
	}

	opening = url;
	focusRegion = focus ? region : '';
	regionOf( region )?.setAttribute( 'aria-busy', 'true' );

	try {
		// WordPress provides the router as a script module, loaded only when it is first needed.
		// eslint-disable-next-line import/no-unresolved -- Not an npm package of the plugin.
		const { actions } = await import( '@wordpress/interactivity-router' );
		// The board says how many requests it shows, which tells more than "Page loaded".
		await actions.navigate( url, { screenReaderAnnouncement: false } );
	} catch {
		window.location.assign( url );
	} finally {
		if ( opening === url ) {
			opening = '';
			regionOf( region )?.removeAttribute( 'aria-busy' );
		}
	}
}

const { callbacks } = store( 'mwtfrs/votes', {
	actions: {
		/**
		 * Applies the filters at once: when a select changes, when the search field is
		 * left, or when the form is sent with the Enter key.
		 *
		 * @param {Event} event Change or submit event of the form.
		 */
		filter( event ) {
			const context = getContext();
			if ( ! context.live ) {
				return;
			}

			event.preventDefault();
			clearTimeout( searchTimer );
			open( urlOf( getElement().ref, context.defaults ), context.region );
		},

		/**
		 * Applies the search once typing stops.
		 */
		search() {
			const context = getContext();
			if ( ! context.live ) {
				return;
			}

			const { form } = getElement().ref;
			clearTimeout( searchTimer );
			searchTimer = setTimeout(
				() => open( urlOf( form, context.defaults ), context.region ),
				SEARCH_DELAY
			);
		},

		/**
		 * Opens another page of results, unless the visitor asked for a new tab or window.
		 *
		 * @param {MouseEvent} event Click in the page links.
		 */
		turnPage( event ) {
			const link = event.target.closest( 'a[href]' );
			const region = getElement().ref.closest(
				'[data-wp-router-region]'
			);
			const elsewhere =
				event.button !== 0 ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey;
			if ( ! link || ! region || elsewhere ) {
				return;
			}

			event.preventDefault();
			open(
				link.href,
				region.getAttribute( 'data-wp-router-region' ),
				true
			);
		},
	},
	callbacks: {
		/**
		 * Turns on live filtering where the board's results can be replaced. A theme's
		 * copy of the board template from before live filtering has no such region.
		 */
		startFilters() {
			const context = getContext();
			context.live = Boolean( regionOf( context.region ) );
		},

		/**
		 * Puts the form's fields back in line with the URL after Back or Forward, which
		 * bring back the results of that URL.
		 */
		restoreFilters() {
			const params = new URL( window.location.href ).searchParams;
			clearTimeout( searchTimer );
			Array.from( getElement().ref.elements ).forEach( ( field ) => {
				if ( ! field.name || field.type === 'hidden' ) {
					return;
				}
				field.value = params.get( field.name ) ?? '';
				// A select without that value falls back to its first choice.
				if ( field.tagName === 'SELECT' && field.selectedIndex < 0 ) {
					field.selectedIndex = 0;
				}
			} );
		},

		/**
		 * Shows a set of results: says how many there are when they replaced others,
		 * gives them the focus after a page link, and marks the visitor's votes.
		 */
		*showResults() {
			const region = getElement()
				.ref.closest( '[data-wp-router-region]' )
				.getAttribute( 'data-wp-router-region' );

			if ( shown.has( region ) ) {
				const live = document.getElementById( `${ region }-summary` );
				const { summary } = getContext();
				if ( live ) {
					// Screen readers do not read the same words again, so a repeat ends in a
					// no-break space, as wp.a11y.speak() does it.
					live.textContent =
						live.textContent === summary
							? `${ summary }\u00A0`
							: summary;
				}
				if ( focusRegion === region ) {
					focusRegion = '';
					regionOf( region )?.focus();
				}
			}
			shown.add( region );

			yield callbacks.hydrate();
		},
	},
} );
