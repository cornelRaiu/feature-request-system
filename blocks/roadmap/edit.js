/**
 * Editor view of the Feature Request Roadmap block: a live server-rendered preview and
 * the board to show in the sidebar. The columns are the statuses marked "Show on
 * roadmap" on the Statuses screen.
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Disabled, PanelBody } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import BoardSelect from '../shared/board-select';
import metadata from './block.json';

export default function Edit( { attributes, setAttributes } ) {
	const { board } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Roadmap', 'feature-request-system' ) }>
					<BoardSelect
						label={ __( 'Requests of', 'feature-request-system' ) }
						noneLabel={ __(
							'All boards',
							'feature-request-system'
						) }
						value={ board }
						onChange={ ( value ) =>
							setAttributes( { board: value } )
						}
					/>
					<p>
						{ __(
							'Each column is a status that has “Show on roadmap” ticked on the Statuses screen.',
							'feature-request-system'
						) }
					</p>
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
