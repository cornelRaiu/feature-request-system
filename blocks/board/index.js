/**
 * Registers the Feature Request Board block in the editor, and its compact form as a
 * block of its own in the inserter: the Feature Request List.
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';

import { boardIcon, listIcon } from '../shared/icons';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

registerBlockType( metadata.name, {
	icon: boardIcon,
	edit: Edit,
	// Rendered on the server by render.php.
	save: () => null,
	variations: [
		{
			name: 'list',
			title: __( 'Feature Request List', 'feature-request-system' ),
			description: __(
				'A short list of the most-voted or the newest feature requests, with their vote buttons and a link to the board.',
				'feature-request-system'
			),
			keywords: [
				__( 'top', 'feature-request-system' ),
				__( 'popular', 'feature-request-system' ),
				__( 'most wanted', 'feature-request-system' ),
			],
			icon: listIcon,
			attributes: { compact: true, perPage: 5 },
			example: { attributes: { compact: true, perPage: 5 } },
			isActive: [ 'compact' ],
			scope: [ 'inserter' ],
		},
	],
} );
