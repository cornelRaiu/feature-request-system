/**
 * Board picker for the blocks' sidebar settings.
 */
import { SelectControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';

const BOARD_TAXONOMY = 'mwtfrs_board';

/**
 * Lists the site's boards, below the choice that names none of them.
 *
 * @param {Object}                  props           Component props.
 * @param {string}                  props.label     Field label.
 * @param {string}                  props.noneLabel Label of the choice with the value 0: the default board, or every board.
 * @param {number}                  props.value     Selected board term ID, or 0.
 * @param {(board: number) => void} props.onChange  Called with the new board term ID.
 */
export default function BoardSelect( { label, noneLabel, value, onChange } ) {
	const boards = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', BOARD_TAXONOMY, {
				per_page: -1,
				context: 'view',
			} ),
		[]
	);

	const options = [
		{ label: noneLabel, value: 0 },
		...( boards ?? [] ).map( ( term ) => ( {
			label: term.name,
			value: term.id,
		} ) ),
	];

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ value }
			options={ options }
			onChange={ ( board ) => onChange( Number( board ) ) }
		/>
	);
}
