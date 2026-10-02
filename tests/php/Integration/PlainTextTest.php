<?php
/**
 * Tests for the plain text that goes into emails and exports.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Notifications\Mailer;

/**
 * Stored titles and names can hold tags and entities. Emails are plain text.
 */
final class PlainTextTest extends TestCase {

	/**
	 * Stored text, and the plain text it becomes.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function texts(): array {
		return [
			'entities are decoded'               => [ 'Fish &amp; chips, &quot;to go&quot;, it&#039;s', 'Fish & chips, "to go", it\'s' ],
			'tags are removed'                   => [ '<b>Bold</b> <a href="https://example.org">move</a>', 'Bold move' ],
			'a tag typed as entities'            => [ '&lt;a href="https://evil.example"&gt;Log in&lt;/a&gt;', '< a href="https://evil.example">Log in< /a>' ],
			'a tag that is never closed'         => [ 'See &lt;img src=x onerror=alert(1)', 'See < img src=x onerror=alert(1)' ],
			'a comment and a declaration'        => [ '&lt;!-- hidden --&gt; &lt;?php', '< !-- hidden --> < ?php' ],
			'a tag in capitals'                  => [ '&lt;SCRIPT&gt;', '< SCRIPT>' ],
			'entities encoded twice stay text'   => [ '&amp;lt;b&amp;gt;', '&lt;b&gt;' ],
			'less than with spaces is untouched' => [ '5 &lt; 6 and 7 &gt; 3', '5 < 6 and 7 > 3' ],
			'less than before a number'          => [ 'Load in &lt;2 seconds', 'Load in <2 seconds' ],
			'less than before a letter'          => [ 'When a&lt;b', 'When a< b' ],
		];
	}

	/**
	 * @dataProvider texts
	 *
	 * @param string $stored   Text as stored.
	 * @param string $expected The plain text.
	 */
	public function test_stored_text_becomes_plain_text_without_a_tag( string $stored, string $expected ): void {
		$plain = Mailer::plain( $stored );

		$this->assertSame( $expected, $plain );
		$this->assertDoesNotMatchRegularExpression( '/<[a-z\/!?]/i', $plain, 'Nothing in it can open a tag.' );
	}
}
