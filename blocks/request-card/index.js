/**
 * Registers the Feature Request Card block in the editor.
 */
import { registerBlockType } from '@wordpress/blocks';

import { cardIcon } from '../shared/icons';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

registerBlockType( metadata.name, {
	icon: cardIcon,
	edit: Edit,
	// Rendered on the server by render.php.
	save: () => null,
} );
