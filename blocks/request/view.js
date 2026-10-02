/**
 * Front-end script of the Feature Request Details block: voting and following.
 *
 * Voting is shared with the board. Following is added here to the same store, because
 * a vote can make the user follow the request, and the two buttons share the request's
 * message and the announcements for screen readers.
 */
import { store, getContext } from '@wordpress/interactivity';

import '../shared/votes';
import { send } from '../shared/rest';

const { state } = store( 'mwtfrs/votes', {
	actions: {
		/**
		 * Follows the request whose form was submitted, or stops following it.
		 *
		 * The form is only shown to logged-in users, and the page says whether they
		 * follow the request. It is a real form, so following still works if this
		 * script never runs.
		 *
		 * @param {SubmitEvent} event Form submit event.
		 */
		*toggleFollow( event ) {
			event.preventDefault();

			const context = getContext();
			if ( context.followBusy ) {
				return;
			}

			context.followBusy = true;
			context.message = '';
			context.needsLogin = false;

			try {
				const response = yield* send(
					state,
					`requests/${ context.id }/subscription`,
					{ method: context.following ? 'DELETE' : 'POST' }
				);
				const data = yield response.json();

				if ( response.ok ) {
					context.following = data.following;
					state.announcement = data.following
						? state.strings.following
						: state.strings.unfollowed;
				} else {
					context.message = data.message || state.strings.followError;
					context.needsLogin = response.status === 401;
				}
			} catch {
				context.message = state.strings.followError;
			} finally {
				context.followBusy = false;
			}
		},
	},
} );
