<?php
/**
 * Single request page tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Comments\OfficialResponse;
use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Frontend\RequestItem;
use FeatureRequestSystem\Frontend\RequestView;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Frontend\VoteStore;
use FeatureRequestSystem\Voting\VoterIdentity;
use WP_Block_Templates_Registry;
use WP_Block_Type_Registry;
use WP_REST_Request;

/**
 * Loads a request's page the way a theme does, and reads the content it would show.
 */
final class RequestViewTest extends TestCase {

	private const DESCRIPTION = 'Easier on the eyes at night.';

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
		unset( $_COOKIE[ VoterIdentity::COOKIE ], $GLOBALS['_wp_current_template_content'] );
	}

	public function tear_down(): void {
		$_GET = [];
		unset( $_COOKIE[ VoterIdentity::COOKIE ], $GLOBALS['_wp_current_template_content'] );
		parent::tear_down();
	}

	public function test_request_page_shows_its_votes_status_and_categories_above_the_description(): void {
		$request  = $this->request();
		$category = self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Appearance',
			]
		);
		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );
		wp_set_object_terms( $request, $category, CategoryTaxonomy::NAME );
		update_post_meta( $request, RequestPostType::META_VOTES, 7 );

		$html = $this->content_of( $request );

		$this->assertStringContainsString( 'id="' . RequestItem::dom_id( $request ) . '"', $html );
		$this->assertStringContainsString( 'data-wp-interactive="' . VoteStore::NAME . '"', $html );
		$this->assertMatchesRegularExpression( '/<span class="mwtfrs-vote__count"[^>]*>7<\/span>/', $html );
		$this->assertStringContainsString( '>Planned</span>', $html );
		$this->assertStringContainsString( '>Appearance</span>', $html );
		$this->assertLessThan( strpos( $html, self::DESCRIPTION ), strpos( $html, 'mwtfrs-single' ), 'The details come before the description.' );
	}

	public function test_votable_requests_get_a_working_vote_form(): void {
		wp_set_current_user( self::factory()->user->create() );
		$request = $this->request();

		$html = $this->content_of( $request );

		$this->assertStringContainsString( 'action="' . admin_url( 'admin-post.php' ) . '"', $html );
		$this->assertStringContainsString( 'name="action" value="' . VoteForm::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="request_id" value="' . $request . '"', $html );
		$this->assertStringContainsString( 'name="redirect_to" value="' . esc_url( get_permalink( $request ) ) . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( VoteForm::ACTION ) . '"', $html );
	}

	public function test_every_guest_gets_the_same_html_without_a_nonce(): void {
		$request = $this->request();

		$first  = $this->content_of( $request );
		$second = $this->content_of( $request );

		$this->assertSame( $first, $second );
		$this->assertStringContainsString( '<form class="mwtfrs-request__vote"', $first );
		$this->assertStringNotContainsString( '_wpnonce', $first );
		$this->assertSame( '', wp_interactivity_state( VoteStore::NAME )['nonce'] );
	}

	public function test_closed_requests_and_boards_with_voting_off_show_the_count_without_a_form(): void {
		$shipped = $this->request();
		wp_set_object_terms( $shipped, $this->status( 'shipped' ), StatusTaxonomy::NAME );
		$this->assertStringNotContainsString( '<form', $this->content_of( $shipped ) );
		$this->assertStringContainsString( 'Voting is closed.', $this->content_of( $shipped ) );

		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::NOBODY ] );
		$this->assertStringNotContainsString( '<form', $this->content_of( $this->request() ) );
	}

	public function test_requests_waiting_for_approval_cannot_be_voted_on(): void {
		$pending = $this->create_request( [ 'post_status' => 'pending' ] );

		$html = RequestView::render_details( $pending, '' );

		$this->assertStringContainsString( 'mwtfrs-vote--closed', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_result_of_a_vote_without_javascript_is_shown(): void {
		$request = $this->request();
		$notice  = [
			VoteForm::PARAM_NOTICE  => 'added',
			VoteForm::PARAM_REQUEST => (string) $request,
		];

		$this->assertStringContainsString( '<span data-wp-text="context.message">Your vote was added.</span>', $this->content_of( $request, $notice ) );
	}

	public function test_details_stay_out_of_excerpts_the_rest_api_and_other_content(): void {
		// Without an excerpt of its own, so WordPress makes one from the content.
		$request = $this->request( [ 'post_excerpt' => '' ] );
		$page    = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => 'About us.',
			]
		);

		$rest = rest_do_request( new WP_REST_Request( 'GET', rest_get_route_for_post( $request ) ) );
		$this->assertStringContainsString( self::DESCRIPTION, $rest->get_data()['content']['rendered'] );
		$this->assertStringNotContainsString( 'mwtfrs', $rest->get_data()['content']['rendered'] );

		$this->go_to( get_permalink( $request ) );
		$this->assertStringNotContainsString( 'mwtfrs', (string) apply_filters( 'the_content', self::DESCRIPTION ), 'Not outside the loop, as when a plugin builds a meta description.' );

		the_post();
		$this->assertSame( self::DESCRIPTION, get_the_excerpt(), 'The excerpt is the description alone, without the vote button\'s text.' );

		$comment_queries = 0;
		add_filter(
			'comments_pre_query',
			static function ( $result ) use ( &$comment_queries ) {
				++$comment_queries;

				return $result;
			}
		);
		$this->assertStringNotContainsString( 'mwtfrs', $this->content_of( $page ) );
		$this->assertSame( 0, $comment_queries, 'Other pages are not searched for an official response.' );
	}

	public function test_password_protected_requests_show_nothing_until_unlocked(): void {
		$request = $this->request( [ 'post_password' => 'secret' ] );
		$this->official_response( $request, 'Planned for 2.4.' );

		$html = $this->content_of( $request );

		$this->assertStringNotContainsString( 'mwtfrs-single', $html );
		$this->assertStringNotContainsString( 'Planned for 2.4.', $html );
		$this->assertSame( '', RequestView::render_details( $request, '' ), 'Nor does the details block, wherever a template puts it.' );
	}

	public function test_a_template_that_already_has_the_details_block_does_not_get_them_twice(): void {
		$request = $this->request();
		$this->official_response( $request, 'Planned for 2.4.' );
		$GLOBALS['_wp_current_template_content'] = '<!-- wp:post-title /--><!-- wp:' . RequestView::BLOCK . ' /--><!-- wp:post-content /-->';

		$html = $this->content_of( $request );

		$this->assertStringNotContainsString( 'mwtfrs-single', $html, 'The template renders the block itself.' );
		$this->assertStringContainsString( 'Planned for 2.4.', $html, 'The official response still follows the description.' );
	}

	public function test_the_newest_approved_official_response_is_pinned_below_the_description(): void {
		$request = $this->request();
		$this->official_response( $request, 'We are looking into it.', '2026-01-10 10:00:00' );
		$this->official_response( $request, 'Planned for 2.4.', '2026-03-01 10:00:00', 'Dana from the team' );
		$this->official_response( $request, 'A draft nobody approved.', '2026-04-01 10:00:00', '', '0' );
		self::factory()->comment->create(
			[
				'comment_post_ID' => $request,
				'comment_content' => 'A newer comment from a visitor.',
				'comment_date'    => '2026-05-01 10:00:00',
			]
		);

		$html   = $this->content_of( $request );
		$pinned = substr( $html, (int) strpos( $html, 'mwtfrs-official-response' ) );

		$this->assertGreaterThan( strpos( $html, self::DESCRIPTION ), strpos( $html, 'mwtfrs-official-response' ), 'The response comes after the description.' );
		$this->assertStringContainsString( 'Planned for 2.4.', $pinned );
		$this->assertStringContainsString( 'Dana from the team', $pinned );
		$this->assertStringContainsString( '>March 1, 2026</time>', $pinned );
		$this->assertStringNotContainsString( 'We are looking into it.', $pinned );
		$this->assertStringNotContainsString( 'nobody approved', $pinned );
		$this->assertStringNotContainsString( 'from a visitor', $pinned );
		$this->assertSame( 1, substr_count( $pinned, 'Official response' ), 'The heading says it once; the thread label is left out.' );
	}

	public function test_requests_without_an_official_response_pin_nothing(): void {
		$request = $this->request();
		self::factory()->comment->create( [ 'comment_post_ID' => $request ] );

		$this->assertStringNotContainsString( 'mwtfrs-official-response', $this->content_of( $request ) );
	}

	public function test_the_details_block_and_a_block_template_for_requests_are_registered(): void {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( RequestView::BLOCK ) );

		if ( ! function_exists( 'register_block_template' ) ) {
			$this->markTestSkipped( 'Plugins can register block templates since WordPress 6.7.' );
		}

		$template = WP_Block_Templates_Registry::get_instance()->get_registered( RequestView::TEMPLATE );
		$this->assertTrue( has_block( RequestView::BLOCK, $template->content ) );
		$this->assertTrue( has_block( 'core/post-content', $template->content ) );
		$this->assertTrue( has_block( 'core/comments', $template->content ) );
	}

	/**
	 * Creates a published request with a description.
	 *
	 * @param array<string, mixed> $args Post fields to override.
	 */
	private function request( array $args = [] ): int {
		return $this->create_request( array_merge( [ 'post_content' => self::DESCRIPTION ], $args ) );
	}

	/**
	 * Adds a moderator's comment to a request and marks it as an official response.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $text       Comment text.
	 * @param string $date       Comment date.
	 * @param string $author     Display name of the moderator.
	 * @param string $approved   Comment status: 1 for approved, 0 for waiting.
	 */
	private function official_response( int $request_id, string $text, string $date = '2026-02-01 10:00:00', string $author = '', string $approved = '1' ): int {
		$moderator = self::factory()->user->create(
			array_filter(
				[
					'role'         => 'editor',
					'display_name' => $author,
				]
			)
		);
		$comment   = self::factory()->comment->create(
			[
				'comment_post_ID'  => $request_id,
				'user_id'          => $moderator,
				'comment_author'   => get_userdata( $moderator )->display_name,
				'comment_content'  => $text,
				'comment_date'     => $date,
				'comment_date_gmt' => $date,
				'comment_approved' => $approved,
			]
		);
		update_comment_meta( $comment, OfficialResponse::META, 1 );

		return $comment;
	}

	/**
	 * Opens a post's page and returns its content as a theme prints it inside the loop.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $query   Query parameters to add to the page's URL.
	 */
	private function content_of( int $post_id, array $query = [] ): string {
		$this->go_to( add_query_arg( $query, get_permalink( $post_id ) ) );
		$this->assertTrue( have_posts(), 'The page shows the post.' );
		the_post();

		return (string) apply_filters( 'the_content', get_the_content() );
	}
}
