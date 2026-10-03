/**
 * Registers the Feature Request Details block in the editor.
 */
import { registerBlockType } from '@wordpress/blocks';

import { detailsIcon } from '../shared/icons';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

registerBlockType( metadata.name, {
	icon: detailsIcon,
	edit: Edit,
	// Rendered on the server by render.php.
	save: () => null,
} );
