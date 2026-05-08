<?php
/**
 * Encryption-at-rest helper.
 *
 * @package TaskShunt\Services
 */

declare(strict_types=1);

namespace TaskShunt\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts and decrypts secrets stored in the database.
 *
 * Uses libsodium's authenticated symmetric encryption (XSalsa20-Poly1305).
 * The encryption key is derived via HKDF from AUTH_KEY + SECURE_AUTH_KEY in
 * wp-config.php — so a database-only compromise (SQL injection, leaked dump,
 * exfiltrated backup) cannot recover plaintext without filesystem access.
 *
 * Wire format: base64( nonce[24] || ciphertext+mac ).
 */
final class Crypto {

	/**
	 * Domain-separation tag for the HKDF derivation. Bumping this string
	 * invalidates every previously-encrypted blob.
	 */
	private const HKDF_INFO = 'taskshunt-secret-v1';

	/**
	 * Encrypt a plaintext string for at-rest storage.
	 *
	 * @param string $plaintext Value to protect.
	 * @return string Encoded ciphertext.
	 *
	 * @throws \RuntimeException If the wp-config secrets needed for key derivation are missing.
	 */
	public static function encrypt( string $plaintext ): string {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, self::derive_key() );
		return base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value previously produced by encrypt().
	 *
	 * @param string $encoded Encoded ciphertext.
	 * @return string Decrypted plaintext.
	 *
	 * @throws \RuntimeException If the input is malformed or authentication fails.
	 */
	public static function decrypt( string $encoded ): string {
		$blob = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $blob || strlen( $blob ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new \RuntimeException( 'Invalid ciphertext blob.' );
		}

		$nonce      = substr( $blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::derive_key() );

		if ( false === $plaintext ) {
			throw new \RuntimeException( 'Decryption failed (wrong key or tampered ciphertext).' );
		}

		return $plaintext;
	}

	/**
	 * Derive the symmetric key from wp-config secrets via HKDF-SHA256.
	 *
	 * AUTH_KEY and SECURE_AUTH_KEY live in wp-config.php; we deliberately do
	 * not fall back to wp_salt() because that path may store synthesized
	 * salts in the options table, which would defeat at-rest protection.
	 *
	 * @return string 32-byte secretbox key.
	 *
	 * @throws \RuntimeException If the required constants are missing or too short.
	 */
	private static function derive_key(): string {
		$auth        = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$secure_auth = defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '';
		$ikm         = $auth . $secure_auth;

		if ( strlen( $ikm ) < 32 ) {
			throw new \RuntimeException( 'AUTH_KEY and SECURE_AUTH_KEY must be defined in wp-config.php for TaskShunt encryption.' );
		}

		return hash_hkdf( 'sha256', $ikm, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::HKDF_INFO );
	}
}
