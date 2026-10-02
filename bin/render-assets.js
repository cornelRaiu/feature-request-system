#!/usr/bin/env node
/**
 * Renders the WordPress.org icon and banner from their sources in .wordpress-org/.
 *
 * Usage: node bin/render-assets.js
 *
 * Needs the Chromium that Playwright installs (npx playwright install chromium).
 */
const path = require( 'node:path' );
const { pathToFileURL } = require( 'node:url' );
const { chromium } = require( '@playwright/test' );

const assets = path.join( __dirname, '..', '.wordpress-org' );

( async () => {
	const browser = await chromium.launch();

	// The banner is drawn at its large size. The small one is the same picture at half the size.
	for ( const [ file, scale ] of [
		[ 'banner-1544x500.png', 1 ],
		[ 'banner-772x250.png', 0.5 ],
	] ) {
		const page = await browser.newPage( {
			viewport: { width: 1544 * scale, height: 500 * scale },
		} );
		await page.goto(
			pathToFileURL( path.join( assets, 'src', 'banner.html' ) ).href
		);
		await page.evaluate( ( zoom ) => {
			document.documentElement.style.zoom = zoom;
		}, scale );
		await page.screenshot( { path: path.join( assets, file ) } );
		await page.close();
	}

	for ( const size of [ 256, 128 ] ) {
		const page = await browser.newPage( {
			viewport: { width: size, height: size },
		} );
		await page.goto(
			pathToFileURL( path.join( assets, 'icon.svg' ) ).href
		);
		await page.screenshot( {
			path: path.join( assets, `icon-${ size }x${ size }.png` ),
			omitBackground: true,
		} );
		await page.close();
	}

	await browser.close();
} )();
