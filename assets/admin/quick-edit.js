/**
 * Selects a request's current status when Quick Edit opens.
 *
 * WordPress fills its own Quick Edit fields from hidden data in each row. The status
 * dropdown is added by this plugin, so it is filled here the same way.
 */
( function () {
	const { inlineEditPost } = window;
	if ( ! inlineEditPost ) {
		return;
	}

	const edit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		edit.apply( this, arguments );

		const postId = typeof id === 'object' ? this.getId( id ) : id;
		const current = document.querySelector(
			`#inline_${ postId } .mwtfrs_new_status`
		);
		const select = document.querySelector(
			`#edit-${ postId } select[name="mwtfrs_new_status"]`
		);

		if ( current && select ) {
			select.value = current.textContent;
		}
	};
} )();
