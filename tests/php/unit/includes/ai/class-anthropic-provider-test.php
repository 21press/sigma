<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use TwentyOnePress\Sigma\AI\Anthropic_Provider;
use TwentyOnePress\Sigma\AI\Prompt;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * @covers \TwentyOnePress\Sigma\AI\Anthropic_Provider
 */
final class Anthropic_Provider_Test extends Unit_Test_Case {

	protected function set_up(): void {
		parent::set_up();
		Source::load( 'includes/ai/interface-provider.php', 'includes/ai/interface-streaming-provider.php', 'includes/ai/class-prompt.php', 'includes/ai/class-answer.php', 'includes/ai/class-sse-parser.php', 'includes/ai/class-stream-transport.php', 'includes/ai/class-anthropic-provider.php' );
	}

	private function body( string $model ): array {
		return ( new Anthropic_Provider( 'k' ) )->body(
			new Prompt( 'RULES AND DOCS', 'PAGE AND DATE' ),
			array(
				array(
					'role'    => 'user',
					'content' => 'Q',
				),
			),
			array(
				'model'      => $model,
				'max_tokens' => 900,
			)
		);
	}

	public function test_only_the_stable_block_is_cached(): void {
		$body = $this->body( 'claude-haiku-5-5' );
		$this->assertSame( array( 'type' => 'ephemeral' ), $body['system'][0]['cache_control'] );
		$this->assertSame( 'RULES AND DOCS', $body['system'][0]['text'] );
		$this->assertArrayNotHasKey( 'cache_control', $body['system'][1], 'the per-request block is not cached' );
	}

	public function test_no_sampling_parameters_are_sent(): void {
		$body = $this->body( 'claude-haiku-5-5' );
		foreach ( array( 'temperature', 'top_p', 'top_k' ) as $param ) {
			$this->assertArrayNotHasKey( $param, $body, "{$param} answers 400 on current models" );
		}
	}

	public function test_thinking_is_switched_off_the_way_each_model_allows(): void {
		$this->assertSame( array( 'type' => 'disabled' ), $this->body( 'claude-haiku-5-5' )['thinking'] );
		$this->assertSame( array( 'type' => 'between_tools' ), $this->body( 'claude-sonnet-5-5' )['thinking'] );
		$opus = $this->body( 'claude-opus-5-5' );
		$this->assertArrayNotHasKey( 'thinking', $opus, 'Opus 5.5 400s on disabled: no thinking field' );
		$this->assertSame( array( 'effort' => 'low' ), $opus['output_config'], 'its lowest setting instead (§10b)' );
	}

	public function test_no_model_gets_more_than_1200_output_tokens(): void {
		foreach ( array( 'claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-future-9' ) as $model ) {
			$this->assertLessThanOrEqual( 1200, $this->body( $model )['max_tokens'], $model );
			$this->assertLessThanOrEqual( 1200, ( new Anthropic_Provider( 'k' ) )->body( new Prompt( 'c', 'd' ), array(), array( 'model' => $model, 'max_tokens' => 4000 ) )['max_tokens'], "{$model}: a request for 4,000 is capped" );
		}
	}
}
