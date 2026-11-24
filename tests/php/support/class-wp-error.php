<?php
/**
 * A stand-in for WP_Error in the unit layer.
 *
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

if ( class_exists( 'WP_Error', false ) ) {
	return;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile, WordPress.Files.FileName -- The class name is WordPress's own.

/**
 * The parts of WP_Error that Sigma reads: codes, messages and data. Loaded only when
 * WordPress is not (the integration layer uses the real class).
 */
class WP_Error {

	/**
	 * Messages by code.
	 *
	 * @var array<string|int, list<string>>
	 */
	public $errors = array();

	/**
	 * Data by code.
	 *
	 * @var array<string|int, mixed>
	 */
	public $error_data = array();

	/**
	 * Create an error.
	 *
	 * @param string|int $code    Code.
	 * @param string     $message Message.
	 * @param mixed      $data    Data.
	 */
	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' === $code ) {
			return;
		}
		$this->add( $code, $message, $data );
	}

	/**
	 * Add a message, and data when given.
	 *
	 * @param string|int $code    Code.
	 * @param string     $message Message.
	 * @param mixed      $data    Data.
	 */
	public function add( $code, $message, $data = '' ): void {
		$this->errors[ $code ][] = $message;
		if ( '' !== $data ) {
			$this->error_data[ $code ] = $data;
		}
	}

	/**
	 * Every code.
	 *
	 * @return list<string|int>
	 */
	public function get_error_codes(): array {
		return array_keys( $this->errors );
	}

	/**
	 * First code.
	 *
	 * @return string|int
	 */
	public function get_error_code() {
		return $this->get_error_codes()[0] ?? '';
	}

	/**
	 * Messages of a code, or of every code.
	 *
	 * @param string|int $code Code.
	 * @return list<string>
	 */
	public function get_error_messages( $code = '' ): array {
		if ( '' === $code ) {
			return array() === $this->errors ? array() : array_merge( ...array_values( $this->errors ) );
		}
		return $this->errors[ $code ] ?? array();
	}

	/**
	 * First message of a code.
	 *
	 * @param string|int $code Code.
	 */
	public function get_error_message( $code = '' ): string {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}
		return $this->get_error_messages( $code )[0] ?? '';
	}

	/**
	 * Data of a code.
	 *
	 * @param string|int $code Code.
	 * @return mixed
	 */
	public function get_error_data( $code = '' ) {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}
		return $this->error_data[ $code ] ?? null;
	}

	/**
	 * Whether any error was added.
	 */
	public function has_errors(): bool {
		return ! empty( $this->errors );
	}
}
