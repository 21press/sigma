<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use TwentyOnePress\Sigma\Knowledge\Extractor;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * @covers \TwentyOnePress\Sigma\Knowledge\Extractor
 */
final class Extractor_Test extends Unit_Test_Case {

	protected function set_up(): void {
		parent::set_up();
		Source::load( 'includes/knowledge/class-extractor.php' );
	}

	public function test_headings_lists_and_paragraphs_become_light_markdown(): void {
		$html = '<h2 class="wp-block-heading">Switch it on</h2><ol><li>Go to <strong>Delta &rarr; Features</strong>.</li><li>Click <code>Open</code>.</li></ol><p>Done&nbsp;now.</p>';
		$this->assertSame( "## Switch it on\n\n- Go to **Delta → Features**.\n- Click `Open`.\n\nDone now.", Extractor::from_html( $html ) );
	}

	public function test_code_blocks_are_kept_verbatim_and_fenced(): void {
		$html = "<p>Add:</p><pre class=\"wp-block-code\"><code>add_filter( 'x', '__return_true' );\n\$a = 1 &lt; 2;</code></pre>";
		$this->assertSame( "Add:\n\n```\nadd_filter( 'x', '__return_true' );\n\$a = 1 < 2;\n```", Extractor::from_html( $html ) );
	}

	public function test_scripts_styles_forms_and_comments_are_dropped(): void {
		$html = '<p>Keep</p><script>alert(1)</script><style>p{}</style><!-- wp:paragraph --><form><input></form><button>Buy</button>';
		$this->assertSame( 'Keep', Extractor::from_html( $html ) );
	}

	public function test_blank_lines_collapse(): void {
		$this->assertSame( "a\n\nb", Extractor::from_html( "<p>a</p>\n\n\n\n<p>b</p>" ) );
	}
}
