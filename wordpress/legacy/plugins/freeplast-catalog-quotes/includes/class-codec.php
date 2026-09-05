<?php
/**
 * One JSON codec for every stored-meta value (issue #17).
 *
 * Quote Request meta, Catalog Sync, Notifications, the basket session
 * store and the Delivery Address flow previously carried their own
 * identical JSON encode/decode helpers. This plugin-level pair is the
 * single owner of the stored form: every stored-meta read and write
 * goes through it, so the bytes on disk are decided in exactly one
 * place.
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shared JSON codec for stored meta.
 */
class Freeplast_CQ_Codec {

	/**
	 * Encode one stored-meta value. Slashes and unicode stay unescaped —
	 * a documented decision (BUILD-DECISIONS, issue #4):
	 * update_post_meta() unslashes scalar values, so escaped forms would
	 * never round-trip byte for byte; unescaped JSON survives the
	 * metadata API unscathed, so the stored form equals the compared
	 * form on every later run.
	 *
	 * @param mixed $value The value to encode (arrays by convention).
	 */
	public static function encode( $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Decode one stored-meta value: the decoded array, or an empty array
	 * when the value is absent, corrupt or not an array — unknown facts
	 * read as empty, never as errors.
	 *
	 * @param string $json The stored JSON.
	 */
	public static function decode( string $json ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
