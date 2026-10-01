/**
 * Editor view of the Feature Request Board block: a live server-rendered preview and
 * sidebar settings for the board and the number of requests per page.
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Disabled, PanelBody, RangeControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import BoardSelect from '../shared/board-select';
import metadata from './block.json';

export default function Edit( { attributes, setAttributes } ) {
	const { board, perPage } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Board', 'feature-request-system' ) }>
					<BoardSelect
						label={ __(
							'Board to show',
							'feature-request-system'
						) }
						value={ board }
						onChange={ ( value ) =>
							setAttributes( { board: value } )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Requests per page',
							'feature-request-system'
						) }
						min={ 5 }
						max={ 50 }
						value={ perPage }
						onChange={ ( value ) =>
							setAttributes( { perPage: value } )
						}
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
