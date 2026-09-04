<?php

defined( 'ABSPATH' ) || exit;

class GFRA_Encryption {

	public static function get_key() {
		if ( defined( 'GFRA_ENCRYPTION_KEY' ) && ! empty( GFRA_ENCRYPTION_KEY ) ) {
			return hex2bin( GFRA_ENCRYPTION_KEY );
		}
		// Fallback only — see the admin_notices nudge in GFRA_AddOn::init().
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	public static function encrypt( $plaintext ) {
		if ( '' === (string) $plaintext ) {
			return '';
		}
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, self::get_key() );
		return base64_encode( $nonce . $ciphertext );
	}

	public static function decrypt( $stored ) {
		if ( '' === (string) $stored ) {
			return '';
		}
		$decoded = base64_decode( $stored, true );
		if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::get_key() );
		return false === $plaintext ? '' : $plaintext;
	}
}
