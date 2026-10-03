/**
 * Editor view of the Feature Request Card block: a field that finds the request by its
 * title, and then a live server-rendered preview of its card.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	ComboboxControl,
	Disabled,
	PanelBody,
	Placeholder,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import ServerSideRender from '@wordpress/server-side-render';

import { cardIcon } from '../shared/icons';
import metadata from './block.json';

const REQUEST_POST_TYPE = 'mwtfrs_request';

/**
 * Finds published requests by their title, as the editor's user types.
 *
 * @param {Object}               props          Component props.
 * @param {number}               props.value    Chosen request ID, or 0.
 * @param {(id: number) => void} props.onChange Called with the chosen request ID, or 0.
 */
function RequestSearch( { value, onChange } ) {
	const [ search, setSearch ] = useState( '' );

	const { found, chosen } = useSelect(
		( select ) => {
			const { getEntityRecords, getEntityRecord } = select( coreStore );

			return {
				// Without a search, these are the newest requests.
				found: getEntityRecords( 'postType', REQUEST_POST_TYPE, {
					search,
					per_page: 20,
					status: 'publish',
					_fields: 'id,title',
				} ),
				// The chosen request is not always among the ones found.
				chosen: value
					? getEntityRecord( 'postType', REQUEST_POST_TYPE, value, {
							_fields: 'id,title',
						} )
					: undefined,
			};
		},
		[ search, value ]
	);

	const requests = [ ...( found ?? [] ) ];
	if ( chosen && ! requests.some( ( { id } ) => id === chosen.id ) ) {
		requests.unshift( chosen );
	}

	return (
		<ComboboxControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Feature request', 'feature-request-system' ) }
			help={ __(
				'Type a few letters of its title.',
				'feature-request-system'
			) }
			value={ value || null }
			options={ requests.map( ( { id, title } ) => ( {
				label: decodeEntities( title.rendered ),
				value: id,
			} ) ) }
			onFilterValueChange={ setSearch }
			onChange={ ( id ) => onChange( id ?? 0 ) }
		/>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const { requestId } = attributes;
	const search = (
		<RequestSearch
			value={ requestId }
			onChange={ ( id ) => setAttributes( { requestId: id } ) }
		/>
	);

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Request', 'feature-request-system' ) }>
					{ search }
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				{ requestId ? (
					<Disabled>
						<ServerSideRender
							block={ metadata.name }
							attributes={ attributes }
							EmptyResponsePlaceholder={ () => (
								<Placeholder
									icon={ cardIcon }
									label={ __(
										'Feature Request Card',
										'feature-request-system'
									) }
									instructions={ __(
										'This request is not published, so visitors see nothing here. Choose another one in the block settings.',
										'feature-request-system'
									) }
								/>
							) }
						/>
					</Disabled>
				) : (
					<Placeholder
						icon={ cardIcon }
						label={ __(
							'Feature Request Card',
							'feature-request-system'
						) }
						instructions={ __(
							'Choose the feature request to show, with its vote button.',
							'feature-request-system'
						) }
					>
						{ search }
					</Placeholder>
				) }
			</div>
		</>
	);
}
