/**
 * Editor view of the Feature Request Board block: a live server-rendered preview and
 * sidebar settings for the board and the number of requests per page.
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	RangeControl,
	SelectControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';

const BOARD_TAXONOMY = 'mwtfrs_board';

export default function Edit( { attributes, setAttributes } ) {
	const { board, perPage } = attributes;

	const boards = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', BOARD_TAXONOMY, {
				per_page: -1,
				context: 'view',
			} ),
		[]
	);

	const boardOptions = [
		{ label: __( 'Default board', 'feature-request-system' ), value: 0 },
		...( boards ?? [] ).map( ( term ) => ( {
			label: term.name,
			value: term.id,
		} ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Board', 'feature-request-system' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Board to show',
							'feature-request-system'
						) }
						value={ board }
						options={ boardOptions }
						onChange={ ( value ) =>
							setAttributes( { board: Number( value ) } )
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
