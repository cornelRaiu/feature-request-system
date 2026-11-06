/**
 * The CSV export on the requests list and the dashboard widget, in a real browser.
 */
const fs = require( 'node:fs' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpEval } = require( './wp-cli' );

const LIST = '/wp-admin/edit.php?post_type=mwtfrs_request';

/**
 * Replaces all requests with the given ones and returns their IDs.
 *
 * @param {Object[]} requests Requests to create: title, votes, status (slug), postStatus, daysAgo, submitter ({name, email}).
 * @return {number[]} The IDs of the requests, in the order given.
 */
function setUp( requests ) {
	const data = Buffer.from( JSON.stringify( requests ) ).toString( 'base64' );

	return JSON.parse(
		wpEval( `
			use FeatureRequestSystem\\Content\\RequestPostType;
			use FeatureRequestSystem\\Content\\StatusTaxonomy;
			use FeatureRequestSystem\\Voting\\VoteCount;
			use FeatureRequestSystem\\Voting\\VoteRepository;

			foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $old ) {
				wp_delete_post( $old, true );
			}

			$ids = [];
			foreach ( json_decode( base64_decode( '${ data }' ), true ) as $request ) {
				$id = wp_insert_post( [
					'post_type'     => RequestPostType::NAME,
					'post_status'   => $request['postStatus'] ?? 'publish',
					'post_title'    => $request['title'],
					'post_content'  => 'Described in a sentence or two.',
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( $request['daysAgo'] ?? 0 ) * DAY_IN_SECONDS ),
				] );
				if ( ! empty( $request['status'] ) ) {
					wp_set_object_terms( $id, get_term_by( 'slug', $request['status'], StatusTaxonomy::NAME )->term_id, StatusTaxonomy::NAME );
				}
				if ( ! empty( $request['submitter'] ) ) {
					update_post_meta( $id, RequestPostType::META_SUBMITTER_NAME, $request['submitter']['name'] );
					update_post_meta( $id, RequestPostType::META_SUBMITTER_EMAIL, $request['submitter']['email'] );
				}
				for ( $i = 0; $i < ( $request['votes'] ?? 0 ); $i++ ) {
					VoteRepository::add( $id, hash( 'sha256', 'e2e:' . $id . ':' . $i ), 0 );
				}
				VoteCount::sync( $id );
				$ids[] = $id;
			}
			echo wp_json_encode( $ids );
		` )
	);
}

/**
 * Clicks a link that downloads a file and returns the file's name and its rows.
 *
 * @param {import('@playwright/test').Page}    page Browser page.
 * @param {import('@playwright/test').Locator} link The link.
 * @return {Promise<{name: string, text: string, rows: string[]}>} The file name, its text without the byte order mark, and its lines.
 */
async function download( page, link ) {
	const [ file ] = await Promise.all( [
		page.waitForEvent( 'download' ),
		link.click(),
	] );
	const raw = fs.readFileSync( await file.path(), 'utf8' );
	expect( raw.startsWith( '﻿' ) ).toBe( true );
	const text = raw.slice( 1 );

	return {
		name: file.suggestedFilename(),
		text,
		rows: text.split( '\r\n' ).filter( Boolean ),
	};
}

test.describe( 'CSV export', () => {
	test( 'Export to CSV downloads the requests the list shows, without JavaScript too', async ( {
		browser,
	} ) => {
		const ids = setUp( [
			{
				title: 'Dark mode',
				status: 'planned',
				votes: 3,
				submitter: { name: 'Gina Guest', email: 'gina@example.com' },
			},
			{ title: '=HYPERLINK("https://evil.example")', status: 'planned' },
			{ title: 'Offline mode', status: 'open' },
		] );
		// The admin's session, in a browser that runs no scripts.
		const context = await browser.newContext( {
			javaScriptEnabled: false,
			acceptDownloads: true,
			storageState: test.info().project.use.storageState,
			baseURL: test.info().project.use.baseURL,
		} );
		const page = await context.newPage();

		await page.goto( LIST );
		await page
			.getByLabel( 'Filter by status' )
			.selectOption( { label: 'Planned' } );
		await page
			.getByRole( 'button', { name: 'Filter', exact: true } )
			.click();
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 2 );

		const file = await download(
			page,
			page.getByRole( 'link', { name: 'Export to CSV' } )
		);

		expect( file.name ).toMatch(
			/^feature-requests-\d{4}-\d{2}-\d{2}\.csv$/
		);
		expect( file.rows[ 0 ] ).toBe(
			'ID,Title,Status,Board,Categories,Votes,Followers,Date,Link,Submitter,Submitter email'
		);
		expect( file.rows ).toHaveLength( 3 );
		expect( file.text ).toContain(
			`\r\n${ ids[ 0 ] },Dark mode,Planned,Feature Requests,,3,0,`
		);
		expect( file.text ).toContain( ',Gina Guest,gina@example.com\r\n' );
		// A spreadsheet shows the title as text instead of running it.
		expect( file.text ).toContain(
			`\r\n${ ids[ 1 ] },"'=HYPERLINK(""https://evil.example"")",Planned,`
		);
		expect( file.text ).not.toContain( 'Offline mode' );
		await context.close();
	} );

	test( 'Export voters to CSV lists who voted for the requests the list shows', async ( {
		page,
	} ) => {
		const ids = setUp( [
			{ title: 'Dark mode', votes: 2 },
			{ title: 'Offline mode', votes: 1 },
		] );
		wpEval( `
			use FeatureRequestSystem\\Voting\\VoteCount;
			use FeatureRequestSystem\\Voting\\VoteRepository;
			$admin = get_user_by( 'login', 'admin' );
			VoteRepository::add( ${ ids[ 0 ] }, hash( 'sha256', 'u:' . $admin->ID ), $admin->ID );
			VoteCount::sync( ${ ids[ 0 ] } );
		` );
		const admin = JSON.parse(
			wpEval(
				`$admin = get_user_by( 'login', 'admin' ); echo wp_json_encode( [ 'name' => $admin->display_name, 'email' => $admin->user_email ] );`
			)
		);

		await page.goto( `${ LIST }&s=dark` );
		const file = await download(
			page,
			page.getByRole( 'link', { name: 'Export voters to CSV' } )
		);

		expect( file.name ).toMatch(
			/^feature-request-voters-\d{4}-\d{2}-\d{2}\.csv$/
		);
		expect( file.rows[ 0 ] ).toBe(
			'Request ID,Request,Voter,Email,Voted on'
		);
		expect( file.rows ).toHaveLength( 4 );
		expect(
			file.rows.filter( ( row ) =>
				row.startsWith( `${ ids[ 0 ] },Dark mode,Visitor,,` )
			)
		).toHaveLength( 2 );
		expect( file.text ).toContain(
			`\r\n${ ids[ 0 ] },Dark mode,${ admin.name },${ admin.email },`
		);
		expect( file.text ).not.toContain( 'Offline mode' );
	} );
} );

test.describe( 'Dashboard widget', () => {
	test( 'shows the requests that wait longest and the week’s top requests', async ( {
		page,
	} ) => {
		const ids = setUp( [
			{ title: 'Waiting a while', postStatus: 'pending', daysAgo: 4 },
			{ title: 'Waiting since today', postStatus: 'pending' },
			{ title: 'Most votes', votes: 5 },
			{ title: 'Some votes', votes: 2 },
			{ title: 'No votes' },
		] );

		await page.goto( '/wp-admin/index.php' );
		const widget = page.locator( '#mwtfrs-dashboard' );
		await expect(
			widget.getByRole( 'heading', { name: 'Feature Requests' } )
		).toBeVisible();

		const waiting = widget
			.locator( '.activity-block' )
			.filter( {
				has: page.getByRole( 'heading', {
					name: 'Waiting for approval',
				} ),
			} )
			.getByRole( 'listitem' );
		await expect( waiting ).toHaveText( [
			/^Waiting a while, suggested 4 days ago$/,
			/^Waiting since today, suggested \d+ (second|seconds|min|mins) ago$/,
		] );

		const top = widget
			.locator( '.activity-block' )
			.filter( {
				has: page.getByRole( 'heading', {
					name: 'Top requests this week',
				} ),
			} )
			.getByRole( 'listitem' );
		await expect( top ).toHaveText( [
			'Most votes, 5 votes in the last seven days',
			'Some votes, 2 votes in the last seven days',
		] );

		await waiting.getByRole( 'link', { name: 'Waiting a while' } ).click();
		await expect( page ).toHaveURL(
			new RegExp( `post\\.php\\?post=${ ids[ 0 ] }&action=edit` )
		);
	} );
} );
