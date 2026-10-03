/**
 * Editor view of the Feature Request Board block: a live server-rendered preview and
 * sidebar settings. The board has its board and the number of requests per page. Its
 * compact form, the list, also has an order and a status.
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	RangeControl,
	SelectControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import BoardSelect from '../shared/board-select';
import StatusSelect from '../shared/status-select';
import metadata from './block.json';

export default function Edit( { attributes, setAttributes } ) {
	const { board, perPage, compact, sort, status } = attributes;

	return (
		<>
			<InspectorControls>
				{ compact ? (
					<PanelBody title={ __( 'List', 'feature-request-system' ) }>
						<BoardSelect
							label={ __(
								'Requests of',
								'feature-request-system'
							) }
							noneLabel={ __(
								'All boards',
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
								'Number of requests',
								'feature-request-system'
							) }
							min={ 1 }
							max={ 20 }
							value={ perPage }
							onChange={ ( value ) =>
								setAttributes( { perPage: value } )
							}
						/>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Order', 'feature-request-system' ) }
							value={ sort }
							options={ [
								{
									label: __(
										'Most votes',
										'feature-request-system'
									),
									value: 'top',
								},
								{
									label: __(
										'Newest',
										'feature-request-system'
									),
									value: 'new',
								},
							] }
							onChange={ ( value ) =>
								setAttributes( { sort: value } )
							}
						/>
						<StatusSelect
							label={ __( 'Status', 'feature-request-system' ) }
							value={ status }
							onChange={ ( value ) =>
								setAttributes( { status: value } )
							}
						/>
					</PanelBody>
				) : (
					<PanelBody
						title={ __( 'Board', 'feature-request-system' ) }
					>
						<BoardSelect
							label={ __(
								'Board to show',
								'feature-request-system'
							) }
							noneLabel={ __(
								'Default board',
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
				) }
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
