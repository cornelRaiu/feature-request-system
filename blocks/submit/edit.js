/**
 * Editor view of the Feature Request Form block: a server-rendered preview and
 * sidebar settings for the board that receives the requests and for the form's texts.
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import BoardSelect from '../shared/board-select';
import metadata from './block.json';

/**
 * The texts a site can change, each with its default as the placeholder. The form shows
 * the default while a text is empty. They are the server's defaults, translated the same way.
 */
const TEXTS = [
	{
		attribute: 'heading',
		label: __( 'Heading', 'feature-request-system' ),
		placeholder: __( 'Suggest a feature', 'feature-request-system' ),
	},
	{
		attribute: 'titleLabel',
		label: __( 'Title field', 'feature-request-system' ),
		placeholder: __( 'Title', 'feature-request-system' ),
	},
	{
		attribute: 'titleHelp',
		label: __( 'Help under the title', 'feature-request-system' ),
		placeholder: __(
			'One short sentence, such as "Export requests as CSV".',
			'feature-request-system'
		),
	},
	{
		attribute: 'detailsLabel',
		label: __( 'Details field', 'feature-request-system' ),
		placeholder: __( 'Details', 'feature-request-system' ),
	},
	{
		attribute: 'submitLabel',
		label: __( 'Button', 'feature-request-system' ),
		placeholder: __( 'Send request', 'feature-request-system' ),
	},
	{
		attribute: 'pendingMessage',
		label: __(
			'Message when the request waits for approval',
			'feature-request-system'
		),
		placeholder: __(
			'Thanks! Your request will appear on the board once it is approved.',
			'feature-request-system'
		),
		long: true,
	},
	{
		attribute: 'publishedMessage',
		label: __(
			'Message when the request is published',
			'feature-request-system'
		),
		placeholder: __(
			'Thanks! Your request is on the board.',
			'feature-request-system'
		),
		long: true,
	},
];

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
				<PanelBody
					title={ __( 'Texts', 'feature-request-system' ) }
					initialOpen={ false }
				>
					<p>
						{ __(
							'Leave a text empty to show the default, which is translated into the language of the site. Texts are shown as plain text.',
							'feature-request-system'
						) }
					</p>
					<div style={ { display: 'grid', gap: '16px' } }>
						{ TEXTS.map(
							( { attribute, label, placeholder, long } ) => {
								const Control = long
									? TextareaControl
									: TextControl;

								return (
									<Control
										key={ attribute }
										__next40pxDefaultSize={
											long ? undefined : true
										}
										__nextHasNoMarginBottom
										label={ label }
										placeholder={ placeholder }
										value={ attributes[ attribute ] }
										onChange={ ( value ) =>
											setAttributes( {
												[ attribute ]: value,
											} )
										}
									/>
								);
							}
						) }
					</div>
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
