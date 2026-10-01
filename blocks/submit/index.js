/**
 * Registers the Feature Request Form block in the editor.
 */
import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import './style.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	// Rendered on the server by render.php.
	save: () => null,
} );
