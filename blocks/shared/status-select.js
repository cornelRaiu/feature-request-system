/**
 * Status picker for the list's sidebar settings.
 */
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';

const STATUS_TAXONOMY = 'mwtfrs_status';

/**
 * Offers what the board's own status filter offers: the requests still open for voting,
 * every request, or the requests in one status.
 *
 * @param {Object}                   props          Component props.
 * @param {string}                   props.label    Field label.
 * @param {string}                   props.value    Status slug, "all", or "" for the requests open for voting.
 * @param {(status: string) => void} props.onChange Called with the new value.
 */
export default function StatusSelect( { label, value, onChange } ) {
	const statuses = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', STATUS_TAXONOMY, {
				per_page: -1,
				context: 'view',
			} ),
		[]
	);

	const options = [
		{
			label: __( 'Open for voting', 'feature-request-system' ),
			value: '',
		},
		{ label: __( 'All statuses', 'feature-request-system' ), value: 'all' },
		...( statuses ?? [] ).map( ( term ) => ( {
			label: term.name,
			value: term.slug,
		} ) ),
	];

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ value }
			options={ options }
			onChange={ onChange }
		/>
	);
}
