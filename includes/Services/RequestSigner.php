<?php
/**
 * Request signing helper.
 *
 * @package TaskShunt\Services
 */

declare(strict_types=1);

namespace TaskShunt\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes and verifies the HMAC-SHA256 signature attached to every receiver request.
 *
 * Replaces the earlier static-bearer-token model: the API key never travels on
 * the wire — it is the shared HMAC secret on each side. Each request also
 * carries a timestamp and a nonce, both signed, so the receiver can reject
 * stale requests and remember nonces to defeat replays.
 *
 * The canonical message includes the HTTP method and path so a signature minted
 * for one endpoint cannot be replayed against another.
 */
final class RequestSigner {

	/**
	 * HMAC algorithm.
	 */
	private const ALGO = 'sha256';

	/**
	 * Sign a request.
	 *
	 * @param string $method    HTTP method (GET, POST, ...).
	 * @param string $path      REST route path (e.g. /taskshunt/v1/receive).
	 * @param int    $timestamp Unix timestamp in seconds.
	 * @param string $nonce     Per-request nonce.
	 * @param string $body      Raw request body (empty string for GET).
	 * @param string $key       Shared HMAC secret.
	 * @return string Hex-encoded signature.
	 */
	public static function sign( string $method, string $path, int $timestamp, string $nonce, string $body, string $key ): string {
		return hash_hmac( self::ALGO, self::canonical_message( $method, $path, $timestamp, $nonce, $body ), $key );
	}

	/**
	 * Verify a candidate signature in constant time.
	 *
	 * @param string $method    HTTP method.
	 * @param string $path      REST route path.
	 * @param int    $timestamp Unix timestamp claimed by the request.
	 * @param string $nonce     Nonce claimed by the request.
	 * @param string $body      Raw request body.
	 * @param string $signature Hex-encoded signature claimed by the request.
	 * @param string $key       Shared HMAC secret.
	 * @return bool
	 */
	public static function verify( string $method, string $path, int $timestamp, string $nonce, string $body, string $signature, string $key ): bool {
		return hash_equals( self::sign( $method, $path, $timestamp, $nonce, $body, $key ), $signature );
	}

	/**
	 * Newline-delimited canonical form. Order is part of the wire contract —
	 * never reorder fields without a payload-version bump.
	 *
	 * @param string $method    HTTP method.
	 * @param string $path      REST route path.
	 * @param int    $timestamp Unix timestamp.
	 * @param string $nonce     Per-request nonce.
	 * @param string $body      Raw request body.
	 * @return string
	 */
	private static function canonical_message( string $method, string $path, int $timestamp, string $nonce, string $body ): string {
		return strtoupper( $method ) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body;
	}
}
