/**
 * Editor view of the Feature Request Details block.
 *
 * The block shows whichever request is being viewed, and a template has none,
 * so the editor shows a description instead of a preview.
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';

export default function Edit() {
	return (
		<div { ...useBlockProps() }>
			<Placeholder
				icon="thumbs-up"
				label={ __(
					'Feature Request Details',
					'feature-request-system'
				) }
				instructions={ __(
					'Shows the vote button, status and categories of the feature request being viewed.',
					'feature-request-system'
				) }
			/>
		</div>
	);
}
