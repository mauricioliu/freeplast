<?php
/**
 * Versioned Catalog Source: schema definition and complete pre-mutation validation.
 *
 * The reviewed, version-controlled file (wordpress/data/products.json) is the
 * only authority for catalog content after bootstrap. This class owns its
 * versioned schema and validates the ENTIRE input before the synchronizer is
 * allowed to touch WordPress:
 *
 *   - unknown keys are rejected at every object level (schema version 2);
 *   - duplicate source IDs and duplicate slugs are rejected (identity);
 *   - clean canonical slugs are enforced (lowercase URL-safe segments);
 *   - URLs must be absolute http(s), legacy paths must be clean absolute paths;
 *   - quantities must be positive integers, a confirmed minimum must be
 *     aligned with its step, and units-per-pallet may be null while the
 *     packaging fact is unconfirmed (rendered as "Consultar");
 *   - related-product IDs must exist in the same source (max three, no self);
 *   - color options must come from the supported color vocabulary; and
 *   - referenced local media must exist with a matching sha256 checksum.
 *
 * Schema version history:
 *   1 — initial schema (issue #3): identity, lifecycle, slug/legacy paths,
 *       title, excerpt/description, category, local image metadata, structured
 *       specs (incl. units-per-pallet and unconfirmed minimum/step), options,
 *       related IDs, featured state/order and review flags.
 *   2 — full catalog union (issue #4): units-per-pallet becomes nullable
 *       (unknown packaging facts are honestly absent instead of invented)
 *       and options may declare a "color" group whose ids are restricted to
 *       the supported color vocabulary (blanco, rojo, amarillo, azul, verde).
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Freeplast_CQ_Catalog_Source {

	public const SCHEMA_VERSION = 2;

	/** Product categories exposed to customers (Todos is a view filter only). */
	public const CATEGORIES = array( 'agricola', 'otros' );

	/** Supported color options for Color configurations (PRD #1). */
	public const SUPPORTED_COLORS = array( 'blanco', 'rojo', 'amarillo', 'azul', 'verde' );

	/** @var array Raw decoded source document. */
	private array $document;

	/** @var string Absolute path the document was loaded from. */
	private string $file;

	/** @var array Validated products keyed by source_id. */
	private array $products = array();

	/**
	 * Load and completely validate a catalog source file.
	 *
	 * @throws Freeplast_CQ_Catalog_Source_Error on any schema/content failure.
	 */
	public static function from_file( string $file ): self {
		$real = realpath( $file );
		if ( false === $real || ! is_file( $real ) ) {
			throw self::error( sprintf( 'catalog source not found: %s', $file ) );
		}

		$raw = file_get_contents( $real );
		if ( false === $raw ) {
			throw self::error( sprintf( 'catalog source is not readable: %s', $file ) );
		}

		$document = json_decode( $raw, true );
		if ( ! is_array( $document ) ) {
			throw self::error( sprintf( 'catalog source is not valid JSON: %s', json_last_error_msg() ) );
		}

		$source          = new self( $document, $real );
		$source->validate();

		return $source;
	}

	private function __construct( array $document, string $file ) {
		$this->document = $document;
		$this->file     = $file;
	}

	public function version(): int {
		return (int) $this->document['version'];
	}

	public function provenance(): array {
		return $this->document['source'];
	}

	/** @return array Validated products in file order. */
	public function products(): array {
		return array_values( $this->products );
	}

	/** Absolute path of a media file referenced by the source. */
	public function media_path( string $relative ): string {
		return dirname( $this->file ) . '/' . ltrim( $relative, '/' );
	}

	/* ------------------------------------------------------------------ */
	/* Validation                                                          */
	/* ------------------------------------------------------------------ */

	private function validate(): void {
		$this->expect_keys( $this->document, array( 'version', 'source', 'products' ), '$' );

		if ( ! is_int( $this->document['version'] ) ) {
			throw self::error( '$.version must be an integer' );
		}
		if ( self::SCHEMA_VERSION !== $this->document['version'] ) {
			throw self::error(
				sprintf(
					'unsupported catalog source version %d (supported schema version: %d)',
					$this->document['version'],
					self::SCHEMA_VERSION
				)
			);
		}

		$this->document['source'] = $this->validate_source_block( $this->document['source'] );

		if ( ! is_array( $this->document['products'] ) ) {
			throw self::error( '$.products must be an array' );
		}

		$seen_ids   = array();
		$seen_slugs = array();

		foreach ( $this->document['products'] as $index => $product ) {
			$this->validate_product( $product, $index, $seen_ids, $seen_slugs );
		}

		// Related products reference source identities within the same file.
		foreach ( $this->products as $id => $product ) {
			foreach ( $product['related_ids'] as $related ) {
				if ( $related === $id ) {
					throw self::error( sprintf( '$.products[%s].related_ids must not reference itself', $id ) );
				}
				if ( ! isset( $this->products[ $related ] ) ) {
					throw self::error( sprintf( '$.products[%s].related_ids references unknown source id "%s"', $id, $related ) );
				}
			}
		}
	}

	private function validate_source_block( $source ): array {
		if ( ! is_array( $source ) ) {
			throw self::error( '$.source must be an object' );
		}
		$this->expect_keys( $source, array( 'name', 'provenance', 'retrieved_at' ), '$.source' );

		if ( ! self::is_token( (string) $source['name'] ) ) {
			throw self::error( '$.source.name must be a short lowercase identifier' );
		}
		if ( '' === trim( (string) $source['provenance'] ) ) {
			throw self::error( '$.source.provenance must describe where the facts come from' );
		}
		$checked = strtotime( (string) $source['retrieved_at'] );
		if ( false === $checked || 0 > $checked ) {
			throw self::error( '$.source.retrieved_at must be an ISO-8601 timestamp' );
		}

		/* Normalize to the UTC form WordPress stores and returns for timestamps
	   so repeated synchronization compares canonical values. */
		$source['retrieved_at'] = gmdate( 'Y-m-d H:i:s', $checked );

		return $source;
	}

	private function validate_product( $product, int $index, array &$seen_ids, array &$seen_slugs ): void {
		$at = sprintf( '$.products[%d]', $index );

		if ( ! is_array( $product ) ) {
			throw self::error( "$at must be an object" );
		}

		$this->expect_keys(
			$product,
			array(
				'source_id',
				'source_url',
				'lifecycle',
				'slug',
				'legacy_paths',
				'title',
				'excerpt',
				'description',
				'category',
				'image',
				'specs',
				'options',
				'related_ids',
				'featured',
				'featured_order',
				'review',
			),
			$at
		);

		/* Identity */
		$source_id = (string) $product['source_id'];
		if ( ! self::is_token( $source_id ) ) {
			throw self::error( "$at.source_id must be a lowercase identity of up to 64 URL-safe characters" );
		}
		if ( isset( $seen_ids[ $source_id ] ) ) {
			throw self::error( sprintf( 'duplicate source id "%s" (%s and %s)', $source_id, $seen_ids[ $source_id ], $at ) );
		}
		$seen_ids[ $source_id ] = $at;

		/* Provenance URL */
		if ( ! wp_http_validate_url( (string) $product['source_url'] ) ) {
			throw self::error( sprintf( '%s.source_url must be an absolute http(s) URL (got "%s")', $at, (string) $product['source_url'] ) );
		}

		/* Lifecycle */
		if ( ! in_array( (string) $product['lifecycle'], array( 'active', 'archived' ), true ) ) {
			throw self::error( "$at.lifecycle must be \"active\" or \"archived\"" );
		}

		/* Canonical slug */
		$slug = (string) $product['slug'];
		if ( ! self::is_clean_slug( $slug ) ) {
			throw self::error( sprintf( '%s.slug must be a clean canonical slug of lowercase words (got "%s")', $at, $slug ) );
		}
		if ( isset( $seen_slugs[ $slug ] ) ) {
			throw self::error( sprintf( 'duplicate slug "%s" (%s and %s)', $slug, $seen_slugs[ $slug ], $at ) );
		}
		$seen_slugs[ $slug ] = $at;

		/* Legacy paths (retained for a future production cutover; not active on staging) */
		if ( ! is_array( $product['legacy_paths'] ) ) {
			throw self::error( "$at.legacy_paths must be an array" );
		}
		foreach ( $product['legacy_paths'] as $path ) {
			if ( ! is_string( $path ) || ! preg_match( '#^/[a-z0-9][a-z0-9-]*(/[a-z0-9][a-z0-9-]*)*/$#', $path ) ) {
				throw self::error( sprintf( '%s.legacy_paths entries must be clean absolute paths with a trailing slash (got "%s")', $at, (string) $path ) );
			}
		}

		/* Copy */
		foreach ( array( 'title', 'excerpt', 'description' ) as $field ) {
			$value = (string) $product[ $field ];
			if ( '' === trim( $value ) ) {
				throw self::error( "$at.$field must not be empty" );
			}
		}

		/* Category */
		if ( ! in_array( (string) $product['category'], self::CATEGORIES, true ) ) {
			throw self::error( sprintf( '%s.category must be one of: %s', $at, implode( ', ', self::CATEGORIES ) ) );
		}

		/* Local media */
		$this->validate_image( $product['image'], "$at.image" );

		/* Structured specifications */
		$this->validate_specs( $product['specs'], "$at.specs" );

		/* Options (e.g. Universal colors; empty for Caja Cosechera 3/4) */
		if ( ! is_array( $product['options'] ) ) {
			throw self::error( "$at.options must be an array" );
		}
		$option_ids = array();
		foreach ( $product['options'] as $option ) {
			if ( ! is_array( $option ) ) {
				throw self::error( "$at.options entries must be objects" );
			}
			$this->expect_keys( $option, array( 'id', 'label', 'group' ), "$at.options[]" );
			$option_id = (string) $option['id'];
			if ( ! self::is_token( $option_id ) ) {
				throw self::error( sprintf( '%s.options[].id must be a lowercase identifier (got "%s")', $at, $option_id ) );
			}
			if ( '' === trim( (string) $option['label'] ) ) {
				throw self::error( "$at.options[].label must not be empty" );
			}
			/* Color configurations require a supported color option: an option
		   grouped as a color must come from the reviewed vocabulary. */
			if ( array_key_exists( 'group', $option ) ) {
				if ( 'color' !== $option['group'] ) {
					throw self::error( sprintf( '%s.options[].group must be "color" when present (got "%s")', $at, (string) $option['group'] ) );
				}
				if ( ! in_array( $option_id, self::SUPPORTED_COLORS, true ) ) {
					throw self::error(
						sprintf(
							'%s.options contains the unsupported color "%s" (supported: %s)',
							$at,
							$option_id,
							implode( ', ', self::SUPPORTED_COLORS )
						)
					);
				}
			}
			if ( in_array( $option_id, $option_ids, true ) ) {
				throw self::error( sprintf( '%s.options contains the duplicate id "%s"', $at, $option_id ) );
			}
			$option_ids[] = $option_id;
		}

		/* Related products */
		if ( ! is_array( $product['related_ids'] ) ) {
			throw self::error( "$at.related_ids must be an array" );
		}
		if ( count( $product['related_ids'] ) > 3 ) {
			throw self::error( "$at.related_ids may list at most three reviewed source ids" );
		}
		foreach ( $product['related_ids'] as $related ) {
			if ( ! is_string( $related ) || ! self::is_token( $related ) ) {
				throw self::error( sprintf( '%s.related_ids entries must be source ids (got "%s")', $at, (string) $related ) );
			}
		}

		/* Featured state */
		if ( ! is_bool( $product['featured'] ) ) {
			throw self::error( "$at.featured must be a boolean" );
		}
		if ( $product['featured'] ) {
			$order = $product['featured_order'];
			if ( ! is_int( $order ) || 1 > $order || 999 < $order ) {
				throw self::error( "$at.featured_order must be an integer between 1 and 999 when featured is true" );
			}
		} elseif ( null !== $product['featured_order'] ) {
			throw self::error( "$at.featured_order must be null when featured is false" );
		}

		/* Review flags */
		$review = $product['review'];
		if ( ! is_array( $review ) ) {
			throw self::error( "$at.review must be an object" );
		}
		$this->expect_keys( $review, array( 'description_approved', 'notes' ), "$at.review" );
		if ( ! is_bool( $review['description_approved'] ) ) {
			throw self::error( "$at.review.description_approved must be a boolean" );
		}
		if ( ! is_array( $review['notes'] ) ) {
			throw self::error( "$at.review.notes must be an array" );
		}
		foreach ( $review['notes'] as $note ) {
			if ( ! is_string( $note ) || '' === trim( $note ) ) {
				throw self::error( "$at.review.notes entries must be non-empty strings" );
			}
		}

		$this->products[ $source_id ] = $product;
	}

	private function validate_image( $image, string $at ): void {
		if ( ! is_array( $image ) ) {
			throw self::error( "$at must be an object" );
		}
		$this->expect_keys( $image, array( 'file', 'checksum', 'width', 'height', 'alt', 'provisional' ), $at );

		$file = (string) $image['file'];
		if ( ! preg_match( '#^[a-z0-9][a-z0-9._/-]*$#', $file ) || str_contains( $file, '..' ) ) {
			throw self::error( sprintf( '%s.file must be a relative path inside the source directory (got "%s")', $at, $file ) );
		}

		$checksum = (string) $image['checksum'];
		if ( ! preg_match( '/^sha256:[0-9a-f]{64}$/', $checksum ) ) {
			throw self::error( "$at.checksum must look like sha256:<64 hex digits>" );
		}

		foreach ( array( 'width', 'height' ) as $dimension ) {
			if ( ! is_int( $image[ $dimension ] ) || 1 > $image[ $dimension ] ) {
				throw self::error( "$at.$dimension must be a positive integer" );
			}
		}

		if ( '' === trim( (string) $image['alt'] ) ) {
			throw self::error( "$at.alt must not be empty" );
		}
		if ( ! is_bool( $image['provisional'] ) ) {
			throw self::error( "$at.provisional must be a boolean" );
		}

		/* The media must be present and intact before any mutation is planned. */
		$absolute = $this->media_path( $file );
		if ( ! is_file( $absolute ) ) {
			throw self::error( sprintf( '%s.file "%s" does not exist next to the catalog source', $at, $file ) );
		}
		$actual = 'sha256:' . hash_file( 'sha256', $absolute );
		if ( $actual !== $checksum ) {
			throw self::error( sprintf( '%s.file "%s" does not match its checksum (expected %s, found %s)', $at, $file, $checksum, $actual ) );
		}
	}

	private function validate_specs( $specs, string $at ): void {
		if ( ! is_array( $specs ) ) {
			throw self::error( "$at must be an object" );
		}
		$this->expect_keys(
			$specs,
			array(
				'material',
				'material_short',
				'dimensions',
				'weight',
				'use',
				'units_per_pallet',
				'minimum_quantity',
				'quantity_step',
			),
			$at
		);

		foreach ( array( 'material', 'material_short', 'dimensions', 'weight', 'use' ) as $field ) {
			$value = $specs[ $field ];
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				throw self::error( "$at.$field must be published source wording (non-empty string)" );
			}
		}

		$units = $specs['units_per_pallet'];
		if ( null !== $units && ( ! is_int( $units ) || 1 > $units ) ) {
			throw self::error( "$at.units_per_pallet must be null (unconfirmed) or a positive integer (a packaging fact)" );
		}

		$minimum = $specs['minimum_quantity'];
		if ( null !== $minimum && ( ! is_int( $minimum ) || 1 > $minimum ) ) {
			throw self::error( "$at.minimum_quantity must be null (unconfirmed) or a positive integer" );
		}

		$step = $specs['quantity_step'];
		if ( null !== $step && ( ! is_int( $step ) || 1 > $step ) ) {
			throw self::error( "$at.quantity_step must be null (unconfirmed) or a positive integer" );
		}

		if ( null !== $minimum && null !== $step && 0 !== $minimum % $step ) {
			throw self::error( sprintf( '%s.minimum_quantity (%d) must align with quantity_step (%d)', $at, $minimum, $step ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private function expect_keys( array $object, array $allowed, string $at ): void {
		$unknown = array_diff( array_keys( $object ), $allowed );
		if ( array() !== $unknown ) {
			throw self::error( sprintf( '%s contains unknown keys: %s (allowed: %s)', $at, implode( ', ', $unknown ), implode( ', ', $allowed ) ) );
		}
	}

	private static function is_clean_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ) && strlen( $slug ) <= 100;
	}

	private static function is_token( string $token ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $token );
	}

	private static function error( string $message ): Freeplast_CQ_Catalog_Source_Error {
		return new Freeplast_CQ_Catalog_Source_Error( $message );
	}
}

/**
 * Thrown when the reviewed Catalog Source fails validation. Synchronization
 * must abort before mutation whenever this escapes.
 */
class Freeplast_CQ_Catalog_Source_Error extends Exception {
}
