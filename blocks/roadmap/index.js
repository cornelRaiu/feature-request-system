/**
 * Registers the Feature Request Roadmap block in the editor.
 */
import { registerBlockType } from '@wordpress/blocks';

import { roadmapIcon } from '../shared/icons';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

registerBlockType( metadata.name, {
	icon: roadmapIcon,
	edit: Edit,
	// Rendered on the server by render.php.
	save: () => null,
} );
