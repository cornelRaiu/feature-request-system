/**
 * Finds the request to merge into by its title, in the Merge box of a request's edit screen.
 *
 * Without JavaScript the box offers the requests of the board with similar titles. This
 * adds a search field: what is typed is looked up among the board's other published
 * requests, and what is found replaces those choices. Emptying the field brings them back.
 */
( function () {
	const search = document.getElementById( 'mwtfrs-merge-search' );
	const choices = document.getElementById( 'mwtfrs-merge-choices' );
	const status = document.getElementById( 'mwtfrs-merge-status' );
	const { apiFetch, i18n } = window.wp || {};
	if ( ! search || ! choices || ! status || ! apiFetch || ! i18n ) {
		return;
	}

	const { __, _n, sprintf } = i18n;
	const similar = Array.from( choices.childNodes );
	let timer = 0;
	let latest = 0;

	/**
	 * Shows the requests that were found as radio buttons.
	 *
	 * @param {Array<{id: number, title: {raw: string}}>} found Requests from the REST API.
	 */
	function show( found ) {
		if ( ! found.length ) {
			const none = document.createElement( 'p' );
			none.textContent = __(
				'No other published request on this board has that title.',
				'feature-request-system'
			);
			choices.replaceChildren( none );
			status.textContent = '';
			return;
		}

		const list = document.createElement( 'ul' );
		for ( const request of found ) {
			const item = document.createElement( 'li' );
			const label = document.createElement( 'label' );
			const radio = document.createElement( 'input' );
			radio.type = 'radio';
			radio.name = search.dataset.field;
			radio.value = String( request.id );
			label.append( radio, ' ', request.title.raw );
			item.append( label );
			list.append( item );
		}
		choices.replaceChildren( list );
		status.textContent = sprintf(
			/* translators: %d: number of requests found. */
			_n(
				'%d request found.',
				'%d requests found.',
				found.length,
				'feature-request-system'
			),
			found.length
		);
	}

	/**
	 * Looks up what was typed. An answer that arrives after a newer search is dropped.
	 */
	async function find() {
		const text = search.value.trim();
		const call = ++latest;

		if ( text.length < 2 ) {
			choices.replaceChildren( ...similar );
			status.textContent = '';
			return;
		}

		let found = null;
		try {
			found = await apiFetch( {
				path: `${ search.dataset.path }&search=${ encodeURIComponent(
					text
				) }`,
			} );
		} catch {
			// The message below says so.
		}
		if ( call !== latest ) {
			return;
		}

		if ( Array.isArray( found ) ) {
			show( found );
		} else {
			status.textContent = __(
				'The search did not work. Please try again.',
				'feature-request-system'
			);
		}
	}

	search.addEventListener( 'input', () => {
		window.clearTimeout( timer );
		timer = window.setTimeout( find, 300 );
	} );

	// What was typed before the script ran, or what the browser kept when going back.
	if ( search.value ) {
		find();
	}

	// Enter in the field would save the request.
	search.addEventListener( 'keydown', ( event ) => {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			window.clearTimeout( timer );
			find();
		}
	} );
} )();
