<?php
/**
 * "Test connection": a step-by-step SMTP report (§10a check 2).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Mail;

use PHPMailer\PHPMailer\SMTP;

defined( 'ABSPATH' ) || exit;

/**
 * DNS → TCP → greeting → TLS (certificate verified) → AUTH, each with a 10-second limit, using
 * PHPMailer's own SMTP class (the one wp_mail uses). Each step reports pass or fail with the
 * server's reply line and, on failure, what to try. The password never appears in the report.
 */
final class Smtp_Check {

	/** Seconds per step (§10a). */
	public const TIMEOUT = 10;

	/**
	 * Why a target may not be tested, or null (§10b). Private, reserved and link-local addresses are
	 * refused unless the site is local or development or the `sigma_smtp_allow_private`
	 * filter allows them; ports are 25, 465, 587, 2525 (and 1025 on local sites), filter
	 * `sigma_smtp_ports`; AUTH without encryption only to this machine.
	 *
	 * @param string   $host       Host.
	 * @param string[] $ips        Its addresses.
	 * @param int      $port       Port.
	 * @param string   $encryption none|ssl|starttls.
	 * @param bool     $auth       Authentication on.
	 */
	public static function refused_target( string $host, array $ips, int $port, string $encryption, bool $auth ): ?string {
		$local = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		$ports = array( 25, 465, 587, 2525 );
		if ( $local ) {
			$ports[] = 1025;
		}
		/**
		 * Filters the SMTP ports the connection test may use (§10b).
		 *
		 * @param int[] $ports Ports.
		 */
		$ports = array_map( 'intval', (array) \TwentyOnePress\Sigma\Hooks::filter( 'smtp_ports', $ports ) );
		if ( ! in_array( $port, $ports, true ) ) {
			/* translators: 1: port, 2: allowed ports. */
			return sprintf( __( 'Port %1$d is not an SMTP port this test uses (%2$s).', 'sigma' ), $port, implode( ', ', $ports ) );
		}
		/**
		 * Filters whether the SMTP test may reach private, reserved or link-local addresses (§10b).
		 *
		 * @param bool $allow Default: true on local and development sites.
		 */
		$private_ok = (bool) \TwentyOnePress\Sigma\Hooks::filter( 'smtp_allow_private', $local );
		foreach ( $ips as $ip ) {
			if ( ! $private_ok && false === filter_var( (string) $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				/* translators: %s: an IP address. */
				return sprintf( __( '%s is a private or reserved address. The test only connects to public mail servers.', 'sigma' ), (string) $ip );
			}
		}
		if ( $auth && 'none' === $encryption && ! \TwentyOnePress\Sigma\Settings::is_loopback( $host ) ) {
			return __( 'A password is never sent without encryption. Choose STARTTLS or SSL.', 'sigma' );
		}
		return null;
	}

	/**
	 * Run the check.
	 *
	 * @param array{host: string, port: int, encryption: string, auth: bool, username: string, password: string} $cfg          Settings.
	 * @param array<string, mixed>                                                                               $ssl_options  Stream SSL options.
	 * @return list<array{step: string, ok: bool|null, detail: string, hint: string}> null = skipped.
	 */
	public static function run( array $cfg, array $ssl_options ): array {
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';

		$host     = trim( (string) $cfg['host'] );
		$port     = (int) $cfg['port'];
		$enc      = (string) $cfg['encryption'];
		$redact   = static fn( string $s ): string => '' !== (string) $cfg['password'] ? str_replace( array( (string) $cfg['password'], base64_encode( (string) $cfg['password'] ), base64_encode( "\0" . $cfg['username'] . "\0" . $cfg['password'] ) ), '[redacted]', $s ) : $s; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- AUTH forms.
		$steps    = array();
		$add      = static function ( string $step, ?bool $ok, string $detail, string $hint = '' ) use ( &$steps, $redact ): bool {
			$steps[] = array(
				'step'   => $step,
				'ok'     => $ok,
				'detail' => $redact( trim( $detail ) ),
				'hint'   => $hint,
			);
			return false !== $ok;
		};
		$skip_all = static function ( array $names ) use ( $add ): void {
			foreach ( $names as $name ) {
				$add( $name, null, __( 'Not run: an earlier step failed.', 'sigma' ) );
			}
		};

		// 1. DNS.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = array( $host );
			$add( 'dns', true, sprintf( 'IP address %s, no lookup needed.', $host ) );
		} else {
			$ips = self::resolve( $host );
			if ( ! $add( 'dns', (bool) $ips, $ips ? implode( ', ', array_slice( $ips, 0, 4 ) ) : sprintf( 'No address for %s.', $host ), $ips ? '' : __( 'The host name does not resolve. Check it for typos, for example smtp.example.com.', 'sigma' ) ) ) {
				$skip_all( array( 'tcp', 'greeting', 'tls', 'auth' ) );
				return $steps;
			}
		}
		// §10b: never a probe of the internal network, odd ports, or a password sent in the clear.
		$refused = self::refused_target( $host, $ips, $port, $enc, (bool) $cfg['auth'] );
		if ( null !== $refused ) {
			$add( 'tcp', false, $refused, __( 'Use your mail provider\'s SMTP host on port 587 with STARTTLS, or 465 with SSL.', 'sigma' ) );
			$skip_all( array( 'greeting', 'tls', 'auth' ) );
			return $steps;
		}

		// 2. TCP.
		$errno   = 0;
		$errstr  = '';
		$started = microtime( true );
		$socket  = @stream_socket_client( 'tcp://' . self::address( $host ) . ':' . $port, $errno, $errstr, self::TIMEOUT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The failure is reported as a step.
		$took    = round( microtime( true ) - $started, 2 );
		if ( false === $socket ) {
			$timed_out = $took >= self::TIMEOUT - 0.5 || 110 === $errno || 60 === $errno || str_contains( strtolower( $errstr ), 'timed out' );
			$add( 'tcp', false, sprintf( '%s:%d: %s (%ss)', $host, $port, $errstr ? $errstr : 'error ' . $errno, $took ), self::tcp_hint( $host, $port, $timed_out ) );
			$skip_all( array( 'greeting', 'tls', 'auth' ) );
			return $steps;
		}
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$add( 'tcp', true, sprintf( 'Connected to %s:%d in %ss.', $host, $port, $took ) );

		// 3. Greeting (for implicit TLS the handshake happens first, so it is part of this step).
		$smtp            = new SMTP();
		$smtp->Timeout   = self::TIMEOUT; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$smtp->Timelimit = self::TIMEOUT; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$connected       = $smtp->connect( ( 'ssl' === $enc ? 'ssl://' : '' ) . $host, $port, self::TIMEOUT, array( 'ssl' => $ssl_options ) );
		if ( ! $connected ) {
			$error = $smtp->getError();
			$add(
				'greeting',
				false,
				trim( $error['error'] . ' ' . $error['detail'] . ' ' . $smtp->getLastReply() ),
				'ssl' === $enc
					? __( 'The TLS handshake or the greeting failed. If the certificate is the problem, use the host name the certificate is issued for. If the server expects STARTTLS, use port 587 with STARTTLS.', 'sigma' )
					: __( 'The server did not greet like an SMTP server. Port 465 needs SSL; 587 needs STARTTLS.', 'sigma' )
			);
			$skip_all( array( 'tls', 'auth' ) );
			return $steps;
		}
		$add( 'greeting', true, $smtp->getLastReply() );

		$helo = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $smtp->hello( '' !== $helo ? $helo : 'localhost' ) ) {
			$add( 'tls', false, 'EHLO refused: ' . $smtp->getLastReply(), __( 'The server refused EHLO; check the host and port.', 'sigma' ) );
			$skip_all( array( 'auth' ) );
			$smtp->close();
			return $steps;
		}

		// 4. TLS.
		if ( 'ssl' === $enc ) {
			$add( 'tls', true, __( 'Implicit TLS on connect; the certificate was verified.', 'sigma' ) );
		} elseif ( 'starttls' === $enc ) {
			if ( ! $smtp->getServerExt( 'STARTTLS' ) ) {
				$add( 'tls', false, __( 'The server does not offer STARTTLS.', 'sigma' ), __( 'Use the port that offers STARTTLS (usually 587), or SSL on 465. Sending without encryption exposes the password.', 'sigma' ) );
				$skip_all( array( 'auth' ) );
				$smtp->close();
				return $steps;
			}
			if ( ! $smtp->startTLS() ) {
				$error = $smtp->getError();
				$add( 'tls', false, trim( $error['error'] . ' ' . $error['detail'] . ' ' . self::last_php_error() ), __( 'The TLS handshake failed. Usually the certificate does not match the host name or is not trusted: use the host name on the certificate.', 'sigma' ) );
				$skip_all( array( 'auth' ) );
				$smtp->close();
				return $steps;
			}
			$smtp->hello( '' !== $helo ? $helo : 'localhost' );
			$add( 'tls', true, __( 'STARTTLS accepted; the certificate was verified.', 'sigma' ) );
		} else {
			$add( 'tls', null, __( 'Skipped: no encryption chosen.', 'sigma' ), $cfg['auth'] ? __( 'The password would cross the network in the clear. Use STARTTLS (587) or SSL (465).', 'sigma' ) : '' );
		}

		// 5. AUTH.
		if ( ! $cfg['auth'] ) {
			$add( 'auth', null, __( 'Skipped: authentication is off.', 'sigma' ) );
		} elseif ( $smtp->authenticate( (string) $cfg['username'], (string) $cfg['password'] ) ) {
			$add( 'auth', true, $smtp->getLastReply() );
		} else {
			$error = $smtp->getError();
			$add( 'auth', false, trim( $error['error'] . ' ' . $smtp->getLastReply() ), __( 'The server refused the login. Check the username and password; many providers need an SMTP key or app password, not the account password.', 'sigma' ) );
		}
		$smtp->quit();
		$smtp->close();
		return $steps;
	}

	/**
	 * A–AAAA lookup that also honours /etc/hosts (localhost).
	 *
	 * @param string $host Host.
	 * @return list<string>
	 */
	private static function resolve( string $host ): array {
		$ips     = array();
		$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is a result.
		foreach ( (array) $records as $record ) {
			$ips[] = (string) ( $record['ip'] ?? $record['ipv6'] ?? '' );
		}
		if ( ! array_filter( $ips ) ) {
			$v4 = gethostbyname( $host );
			if ( $v4 !== $host ) {
				$ips[] = $v4;
			}
		}
		return array_values( array_filter( $ips ) );
	}

	/**
	 * IPv6 literals need brackets in a socket address.
	 *
	 * @param string $host Host.
	 */
	private static function address( string $host ): string {
		return filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '[' . $host . ']' : $host;
	}

	/**
	 * What to try when TCP fails. Pure.
	 *
	 * @param string $host      Host.
	 * @param int    $port      Port.
	 * @param bool   $timed_out Timed out rather than refused.
	 */
	public static function tcp_hint( string $host, int $port, bool $timed_out ): string {
		if ( $timed_out && in_array( $port, array( 25, 465 ), true ) ) {
			/* translators: %d: port. */
			return sprintf( __( 'Port %d timed out. Many hosts block it (Hetzner, for example, blocks 25 and 465 by default); try 587 with STARTTLS.', 'sigma' ), $port );
		}
		if ( $timed_out ) {
			/* translators: 1: host, 2: port. */
			return sprintf( __( 'No answer from %1$s:%2$d within 10 seconds. A firewall may block the port, or the host name is wrong.', 'sigma' ), $host, $port );
		}
		/* translators: 1: host, 2: port. */
		return sprintf( __( 'Nothing accepts connections on %1$s:%2$d. Check the port: 587 for STARTTLS, 465 for SSL.', 'sigma' ), $host, $port );
	}

	/**
	 * The last PHP warning (OpenSSL puts certificate errors there).
	 */
	private static function last_php_error(): string {
		$error = error_get_last();
		return is_array( $error ) && str_contains( (string) $error['message'], 'SSL' ) ? (string) $error['message'] : '';
	}
}
