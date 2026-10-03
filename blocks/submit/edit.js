/**
 * Editor view of the Feature Request Form block: a server-rendered preview and
 * a sidebar setting for the board that receives the requests.
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Disabled, PanelBody } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import BoardSelect from '../shared/board-select';
import metadata from './block.json';

export default function Edit( { attributes, setAttributes } ) {
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Board', 'feature-request-system' ) }>
					<BoardSelect
						label={ __(
							'Board that receives requests',
							'feature-request-system'
						) }
						noneLabel={ __(
							'Default board',
							'feature-request-system'
						) }
						value={ attributes.board }
						onChange={ ( board ) => setAttributes( { board } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<Disabled>
					<ServerSideRender
						block={ metadata.name }
						attributes={ attributes }
					/>
				</Disabled>
			</div>
		</>
	);
}
