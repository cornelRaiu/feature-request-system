<?php
/**
 * Feature request card tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Frontend\Blocks;
use FeatureRequestSystem\Frontend\RequestCard;
use FeatureRequestSystem\Frontend\RequestNotice;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Frontend\VoteStore;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;

/**
 * Renders the Feature Request Card block, the way a page does.
 */
final class RequestCardTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
	}

	public function tear_down(): void {
		$_GET = [];
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		parent::tear_down();
	}

	public function test_a_card_shows_the_request_as_the_board_does_with_a_working_vote_form(): void {
		wp_set_current_user( self::factory()->user->create() );
		$request  = $this->create_request(
			[
				'post_title'   => 'Dark mode',
				'post_excerpt' => 'Easier on the eyes at night.',
			]
		);
		$category = self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Appearance',
			]
		);
		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );
		wp_set_object_terms( $request, $category, CategoryTaxonomy::NAME );
		update_post_meta( $request, RequestPostType::META_VOTES, 7 );

		$html = $this->render( $request );

		$this->assertMatchesRegularExpression( '/^<div\s+class="mwtfrs-card wp-block-mwtfrs-request-card"/', $html );
		$this->assertStringContainsString( 'data-wp-interactive="' . VoteStore::NAME . '"', $html );
		$this->assertStringContainsString( '<p class="mwtfrs-sr-only" aria-live="polite" data-wp-text="context.announcement"></p>', $html, 'What a vote says to screen readers belongs to this card.' );
		$this->assertStringContainsString( '<p class="mwtfrs-request__title"><a href="' . esc_url( get_permalink( $request ) ) . '">Dark mode</a></p>', $html, 'The title is not a heading: the card sits in the middle of a text.' );
		$this->assertStringContainsString( '>Easier on the eyes at night.</p>', $html );
		$this->assertStringContainsString( '>Planned</span>', $html );
		$this->assertStringContainsString( '>Appearance</span>', $html );
		$this->assertMatchesRegularExpression( '/class="mwtfrs-vote__count"[^>]*>7</', $html );
		$this->assertStringContainsString( 'name="action" value="' . VoteForm::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="request_id" value="' . $request . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( VoteForm::ACTION ) . '"', $html );
		$this->assertStringContainsString( 'aria-pressed="false"', $html, 'The server renders the button unpressed.' );
	}

	public function test_only_requests_that_visitors_can_see_get_a_card(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$hidden = [
			'pending'            => $this->create_request( [ 'post_status' => 'pending' ] ),
			'draft'              => $this->create_request( [ 'post_status' => 'draft' ] ),
			'private'            => $this->create_request( [ 'post_status' => 'private' ] ),
			'behind a password'  => $this->create_request( [ 'post_password' => 'secret' ] ),
			'a post'             => self::factory()->post->create(),
			'nothing'            => 0,
			'no longer existing' => 999999,
		];
		$trashed = $this->create_request();
		wp_trash_post( $trashed );
		$hidden['in the trash'] = $trashed;

		foreach ( $hidden as $what => $id ) {
			$this->assertSame( '', $this->render( $id ), $what );
		}
		$this->assertNotSame( '', $this->render( $this->create_request() ) );
	}

	public function test_closed_requests_and_boards_with_voting_off_show_the_count_without_a_form(): void {
		$shipped = $this->create_request();
		wp_set_object_terms( $shipped, $this->status( 'shipped' ), StatusTaxonomy::NAME );
		update_post_meta( $shipped, RequestPostType::META_VOTES, 9 );

		$html = $this->render( $shipped );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertMatchesRegularExpression( '/mwtfrs-vote--closed.*class="mwtfrs-vote__count">9</s', $html );

		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::NOBODY ] );
		$this->assertStringNotContainsString( '<form', $this->render( $this->create_request() ) );
	}

	public function test_the_script_asks_for_a_guests_vote_only_where_the_board_lets_guests_vote(): void {
		$request = $this->create_request();
		$this->assertFalse( $this->context_of( $this->render( $request ) )['guestVotes'] );

		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$context = $this->context_of( $this->render( $request ) );

		$this->assertTrue( $context['guestVotes'] );
		$this->assertSame( [ $request ], $context['ids'] );
		$this->assertSame( '', $context['announcement'] );
	}

	public function test_every_guest_gets_the_same_html_so_pages_can_be_cached(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$request                          = $this->create_request();
		$_COOKIE[ VoterIdentity::COOKIE ] = str_repeat( 'b2', 16 );
		VoteService::cast( $request );

		$voter_html = $this->render( $request );
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$stranger_html = $this->render( $request );

		$this->assertSame( $this->normalize( $stranger_html ), $this->normalize( $voter_html ) );
		$this->assertStringNotContainsString( 'aria-pressed="true"', $voter_html );
		$this->assertStringNotContainsString( '_wpnonce', $voter_html );
	}

	public function test_a_vote_without_javascript_comes_back_to_the_card_and_shows_what_happened(): void {
		wp_set_current_user( self::factory()->user->create() );
		$request = $this->create_request();

		$html = $this->render( $request );
		preg_match( '/id="(mwtfrs-card-\d+-request-' . $request . ')"/', $html, $id );
		preg_match( '/name="redirect_to" value="([^"]*)"/', $html, $field );
		$this->assertSame( home_url() . '#' . $id[1], html_entity_decode( $field[1] ) );

		$back = VoteForm::process(
			[
				'request_id'  => (string) $request,
				'redirect_to' => html_entity_decode( $field[1] ),
				'_wpnonce'    => wp_create_nonce( VoteForm::ACTION ),
			],
			'',
			''
		);
		$this->assertSame( home_url() . '?' . RequestNotice::PARAM . '=added&' . RequestNotice::PARAM_REQUEST . '=' . $request . '#' . $id[1], $back );

		$_GET = [
			RequestNotice::PARAM         => 'added',
			RequestNotice::PARAM_REQUEST => (string) $request,
		];
		$this->assertStringContainsString( 'data-wp-text="context.message">Your vote was added.</span>', $this->render( $request ) );
		$this->assertStringNotContainsString( 'Your vote was added.', $this->render( $this->create_request() ), 'Only on the card of the request voted for.' );
	}

	public function test_shortcode_renders_the_card(): void {
		$request = $this->create_request( [ 'post_title' => 'Dark mode' ] );

		$html = do_shortcode( '[' . Blocks::CARD_SHORTCODE . ' id="' . $request . '"]' );

		$this->assertStringContainsString( '>Dark mode</a>', $html );
		$this->assertMatchesRegularExpression( '/^<div\s+class="mwtfrs-card wp-block-mwtfrs-request-card"/', $html );
		$this->assertSame( '', do_shortcode( '[' . Blocks::CARD_SHORTCODE . ']' ), 'Without a request.' );
		$this->assertSame( '', do_shortcode( '[' . Blocks::CARD_SHORTCODE . ' id="dark-mode"]' ), 'The ID, not the slug.' );
	}

	/**
	 * Renders the card block.
	 *
	 * @param int $request_id Request chosen in the block.
	 */
	private function render( int $request_id ): string {
		return render_block(
			[
				'blockName'    => RequestCard::BLOCK,
				'attrs'        => [ 'requestId' => $request_id ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Returns the context the block gives its script.
	 *
	 * @param string $html Block HTML.
	 * @return array<string, mixed>
	 */
	private function context_of( string $html ): array {
		preg_match( "/data-wp-context='([^']*)'/", $html, $context );

		return (array) json_decode( $context[1], true );
	}

	/**
	 * Removes what legitimately differs between two renders: generated IDs.
	 *
	 * @param string $html Card HTML.
	 */
	private function normalize( string $html ): string {
		return (string) preg_replace( '/mwtfrs-card-\d+/', 'mwtfrs-card-N', $html );
	}
}
