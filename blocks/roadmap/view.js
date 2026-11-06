/**
 * Front-end script of the Feature Request Roadmap block: voting, and "Show more".
 *
 * "Show more" is a link to the same page with one more page of its column in the URL,
 * so it works without JavaScript. With JavaScript, the Interactivity API's router fetches
 * that page and swaps the roadmap in place. The column that grew has a new data-wp-key,
 * so it is drawn anew and callbacks.showMoreShown runs for it: it asks which of the
 * column's requests the visitor voted for, and moves the focus to the first request
 * that was added, where the visitor reads on. The other columns stay as they are.
 */
import { store, getContext } from '@wordpress/interactivity';

import '../shared/votes';

// Until a visitor uses "Show more", the roadmap's own callbacks.hydrate covers every column.
let shownMore = false;

const { callbacks } = store( 'mwtfrs/votes', {
	actions: {
		/**
		 * Shows more requests in a column without loading the whole page.
		 *
		 * @param {MouseEvent} event Click on the "Show more" link.
		 */
		*showMore( event ) {
			// A click that asks for a new tab or window goes to the link as usual.
			if (
				event.button !== 0 ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey
			) {
				return;
			}

			event.preventDefault();
			const { href } = event.currentTarget;
			const { actions } = yield import(
				// WordPress provides the router as a script module; it is no package of this plugin.
				// eslint-disable-next-line import/no-unresolved
				'@wordpress/interactivity-router'
			);

			shownMore = true;
			// No new history entry: Back leaves the page, as after any other link on it.
			// The focus moves to the new requests, which says enough to screen readers.
			yield actions.navigate( href, {
				replace: true,
				screenReaderAnnouncement: false,
			} );
		},
	},
	callbacks: {
		/**
		 * Runs when a column is drawn. After "Show more", marks the column's requests the
		 * visitor voted for, and focuses the first one that was added.
		 */
		*showMoreShown() {
			if ( ! shownMore ) {
				return;
			}

			const { focus } = getContext();
			if ( focus ) {
				document
					.getElementById( focus )
					?.querySelector( '.mwtfrs-request__title a' )
					?.focus();
			}

			yield callbacks.hydrate();
		},
	},
} );
