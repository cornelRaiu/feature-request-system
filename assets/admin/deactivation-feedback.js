/**
 * Asks why the plugin is being deactivated, when its Deactivate link on the Plugins screen is clicked.
 *
 * The link opens a dialog instead of deactivating. Answering is optional: "Skip & Deactivate"
 * deactivates at once, and only "Send & Deactivate" sends the answer, through admin-ajax, before
 * following the link. The link is followed after five seconds at most, whatever happens to the answer.
 * Without the dialog element the link works as usual.
 */
( function () {
	const settings = window.mwtfrsDeactivationFeedback;
	const dialog = document.getElementById( 'mwtfrs-df' );
	const row =
		settings &&
		document.querySelector(
			'tr[data-plugin="' + window.CSS.escape( settings.plugin ) + '"]'
		);
	const link = row && row.querySelector( '.deactivate a' );
	const { i18n } = window.wp || {};
	if (
		! dialog ||
		! link ||
		! i18n ||
		typeof dialog.showModal !== 'function'
	) {
		return;
	}

	const { __ } = i18n;
	const form = dialog.querySelector( 'form' );
	const submit = dialog.querySelector( '.mwtfrs-df-submit' );
	const snooze = dialog.querySelector( '.mwtfrs-df-snooze' );
	const note = dialog.querySelector( '.mwtfrs-df-note' );

	/**
	 * The chosen answer, or '' before one is chosen.
	 *
	 * @return {string} The answer's code.
	 */
	function selected() {
		const radio = form.querySelector( 'input[name="reason"]:checked' );
		return radio ? radio.value : '';
	}

	/**
	 * The text typed under the chosen answer.
	 *
	 * @return {string} The text, trimmed.
	 */
	function details() {
		const input = form.querySelector(
			'input[name="details"]:not([disabled])'
		);
		return input ? input.value.trim() : '';
	}

	/**
	 * Whether the answer is sent: nothing is sent for "It is temporary", or for "Other" with nothing typed.
	 *
	 * @return {boolean} Whether it is sent.
	 */
	function willSend() {
		const reason = selected();
		return (
			reason !== '' &&
			reason !== 'temporary' &&
			( reason !== 'other' || details() !== '' )
		);
	}

	/**
	 * Shows what belongs to the chosen answer, and names the button after what it will do.
	 */
	function update() {
		const reason = selected();

		form.querySelectorAll( '.mwtfrs-df-reason' ).forEach( ( item ) => {
			const active = item.dataset.reason === reason;
			const more = item.querySelector( '.mwtfrs-df-more' );
			const input = item.querySelector( 'input[name="details"]' );

			more.hidden = ! active || ! more.children.length;
			if ( input ) {
				input.disabled = ! active;
			}
		} );

		snooze.hidden = reason !== 'temporary';
		note.hidden = reason === 'temporary';
		if ( willSend() ) {
			submit.textContent = __(
				'Send & Deactivate',
				'feature-request-system'
			);
		} else if ( reason ) {
			submit.textContent = __( 'Deactivate', 'feature-request-system' );
		} else {
			submit.textContent = __(
				'Skip & Deactivate',
				'feature-request-system'
			);
		}
		submit.classList.toggle( 'button-primary', reason !== '' );
	}

	function deactivate() {
		window.location.href = link.href;
	}

	/**
	 * Sends the answer to the site, which passes it on.
	 *
	 * @return {Promise<Response>} The site's reply.
	 */
	function send() {
		const body = new window.FormData();
		body.append( 'action', settings.action );
		body.append( '_ajax_nonce', settings.nonce );
		body.append( 'reason', selected() );
		body.append( 'details', details() );
		if ( form.elements.snooze.checked ) {
			body.append( 'snooze', '1' );
		}

		const controller =
			typeof window.AbortController === 'function'
				? new window.AbortController()
				: null;
		if ( controller ) {
			setTimeout( () => controller.abort(), 5000 );
		}

		return window.fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
			signal: controller ? controller.signal : undefined,
		} );
	}

	link.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		form.reset();
		update();
		dialog.showModal();
	} );

	form.addEventListener( 'change', update );
	form.addEventListener( 'input', update );

	// Cancel, Escape and a click on the dark area around the dialog close it without deactivating.
	dialog
		.querySelector( '.mwtfrs-df-cancel' )
		.addEventListener( 'click', () => dialog.close() );
	dialog.addEventListener( 'click', ( event ) => {
		if ( event.target === dialog ) {
			dialog.close();
		}
	} );

	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		const reason = selected();

		if (
			! willSend() &&
			! ( reason === 'temporary' && form.elements.snooze.checked )
		) {
			deactivate();
			return;
		}

		form.querySelectorAll( 'button' ).forEach( ( button ) => {
			button.disabled = true;
		} );
		submit.textContent = __( 'Deactivating…', 'feature-request-system' );
		send()
			.catch( () => {} )
			.then( deactivate );
	} );
} )();
