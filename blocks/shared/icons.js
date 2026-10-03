/**
 * The blocks' icons in the inserter: the plugin's logo for the board, and for the other
 * blocks the part of it they show.
 *
 * They are drawn on the 20 units of the admin menu's icon and take the editor's colours.
 */

/**
 * Wraps the shapes of an icon. The margin gives it the size of the editor's own icons.
 *
 * @param {Object}                    props          Component props.
 * @param {import('react').ReactNode} props.children Shapes of the icon.
 */
function Icon( { children } ) {
	return (
		<svg
			xmlns="http://www.w3.org/2000/svg"
			viewBox="-2 -2 24 24"
			width="24"
			height="24"
			aria-hidden="true"
			focusable="false"
		>
			{ children }
		</svg>
	);
}

/**
 * The logo: a board of requests. The one in front has a vote arrow and a title.
 */
export const boardIcon = (
	<Icon>
		<path
			fillOpacity=".45"
			d="M4 4V2.5A1.5 1.5 0 0 1 5.5 1h9A1.5 1.5 0 0 1 16 2.5V4z"
		/>
		<path
			fillOpacity=".7"
			d="M2 8V5.5A1.5 1.5 0 0 1 3.5 4h13A1.5 1.5 0 0 1 18 5.5V8z"
		/>
		<path
			fillRule="evenodd"
			d="M3 8h14a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2zm2.5 3.2L8 13.7l-1.131 1.131L5.5 13.463l-1.369 1.368L3 13.7zM11 12h5a1 1 0 0 1 0 2h-5a1 1 0 0 1 0-2z"
		/>
	</Icon>
);

/**
 * A short list: three requests, the first one strongest.
 */
export const listIcon = (
	<Icon>
		<path d="M3 2h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zm6 1h8a1 1 0 0 1 0 2H9a1 1 0 0 1 0-2z" />
		<path
			fillOpacity=".7"
			d="M3 8h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1zm6 1h6a1 1 0 0 1 0 2H9a1 1 0 0 1 0-2z"
		/>
		<path
			fillOpacity=".45"
			d="M3 14h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-2a1 1 0 0 1 1-1zm6 1h3a1 1 0 0 1 0 2H9a1 1 0 0 1 0-2z"
		/>
	</Icon>
);

/**
 * One request: the card in front of the logo's board.
 */
export const cardIcon = (
	<Icon>
		<path
			fillRule="evenodd"
			d="M3 5h14a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zm2.5 3.2L8 10.7l-1.131 1.131L5.5 10.463l-1.369 1.368L3 10.7zM11 9h5a1 1 0 0 1 0 2h-5a1 1 0 0 1 0-2z"
		/>
	</Icon>
);

/**
 * A new request: a card with a plus sign and two lines to fill in.
 */
export const formIcon = (
	<Icon>
		<path
			fillRule="evenodd"
			d="M3 4h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm2.25 4v1.25H4v1.5h1.25V12h1.5v-1.25H8v-1.5H6.75V8zm6 -.5a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5zm0 3.5a.75.75 0 0 0 0 1.5h2.5a.75.75 0 0 0 0-1.5z"
		/>
	</Icon>
);

/**
 * A request's details: its vote button, and its status and categories beside it.
 */
export const detailsIcon = (
	<Icon>
		<path
			fillRule="evenodd"
			d="M3 6h4a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zm2 2.45L2.9 10.55l1.061 1.061L5 10.571l1.039 1.04L7.1 10.55z"
		/>
		<path d="M12 7h6a1 1 0 0 1 0 2h-6a1 1 0 0 1 0-2z" />
		<path fillOpacity=".7" d="M12 11h3a1 1 0 0 1 0 2h-3a1 1 0 0 1 0-2z" />
	</Icon>
);
