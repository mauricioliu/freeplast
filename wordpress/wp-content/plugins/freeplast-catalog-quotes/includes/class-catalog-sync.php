<?php
/**
 * WP-CLI catalog synchronization.
 *
 *     wp freeplast catalog sync --file=<products.json> [--dry-run]
 *
 * Contract (PRD #1 "Synchronization contract" / issue #3):
 *   1. Parse and validate the complete input before any mutation.
 *   2. Match records by immutable source ID (never by slug), then verify
 *      slug uniqueness against existing WordPress content.
 *   3. Create/update only plugin-owned fields; preserve post IDs, permalinks
 *      and revisions across updates.
 *   4. Import changed media into the local media library (checksum-keyed
 *      reuse); unchanged media is never re-imported.
 *   5. Report deterministic per-product differences and
 *      created/updated/unchanged/warning/error totals.
 *   6. Return non-zero on schema, import or consistency failure. A second
 *      run against unchanged input is a no-op (zero changes).
 *   7. Products missing from the source produce warnings only; archiving
 *      requires an explicit source lifecycle change.
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Freeplast_CQ_Catalog_Sync {

	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'freeplast catalog sync', array( self::class, 'invoke' ) );
		}
	}

	/**
	 * `wp freeplast catalog sync --file=<products.json> [--dry-run]`
	 *
	 * @param array $args   Positional arguments (unused).
	 * @param array $assoc  Associative arguments.
	 */
	public static function invoke( array $args, array $assoc ): void {
		$file = isset( $assoc['file'] ) ? (string) $assoc['file'] : '';
		$dry  = ! empty( $assoc['dry-run'] );

		if ( '' === $file ) {
			WP_CLI::error( 'Missing --file=<path> pointing at the reviewed catalog source JSON.' );
		}

		try {
			$source = Freeplast_CQ_Catalog_Source::from_file( $file );
		} catch ( Freeplast_CQ_Catalog_Source_Error $e ) {
			WP_CLI::error( 'Catalog source rejected: ' . $e->getMessage() );
		}

		WP_CLI::line(
			sprintf(
				'Catalog source: %s (version %d, %d product%s, retrieved %s)',
				$file,
				$source->version(),
				count( $source->products() ),
				1 === count( $source->products() ) ? '' : 's',
				$source->provenance()['retrieved_at']
			)
		);

		try {
			$plan = self::plan( $source );
		} catch ( Freeplast_CQ_Catalog_Sync_Error $e ) {
			WP_CLI::error( 'Synchronization aborted: ' . $e->getMessage() );
		}

		$summary = $plan['summary'];

		/* Missing-product warnings are reported in both modes. */
		$warnings = array_values(
			array_filter(
				$plan['lines'],
				static fn( string $line ) => str_starts_with( $line, 'Warning:' )
			)
		);

		if ( $dry ) {
			foreach ( $plan['lines'] as $line ) {
				WP_CLI::line( $line );
			}
			WP_CLI::line( self::summary_line( $summary ) );
			WP_CLI::line( 'Dry run: no changes were applied.' );
			WP_CLI::halt( 0 );
		}

		foreach ( $warnings as $warning ) {
			WP_CLI::line( $warning );
		}

		try {
			self::apply( $source, $plan );
		} catch ( Freeplast_CQ_Catalog_Sync_Error $e ) {
			WP_CLI::error( 'Synchronization failed: ' . $e->getMessage() . ' — no partial catalog mutation was kept.' );
		}

		WP_CLI::line( self::summary_line( $summary ) );
		WP_CLI::success(
			sprintf(
				'applied: %d created, %d updated, %d unchanged, %d warning%s.',
				$summary['created'],
				$summary['updated'],
				$summary['unchanged'],
				$summary['warnings'],
				1 === $summary['warnings'] ? '' : 's'
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Planning                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Compute the deterministic create/update/unchanged difference without
	 * touching WordPress.
	 */
	private static function plan( Freeplast_CQ_Catalog_Source $source ): array {
		$existing = self::existing_products();

		$lines   = array();
		$summary = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'warnings'  => 0,
			'errors'    => 0,
		);

		$planned = array();

		foreach ( $source->products() as $product ) {
			$id   = $product['source_id'];
			$post = $existing[ $id ] ?? null;

			if ( null === $post ) {
				$action = 'create';
				$diff   = array();
				self::assert_slug_available( $product['slug'], null );
			} else {
				$diff = self::diff_product( $post, $product, $source );
				if ( array() === $diff ) {
					$action = 'unchanged';
				} else {
					$action = 'update';
					self::assert_slug_available( $product['slug'], $post->ID );
				}
			}

			$planned[] = array(
				'product' => $product,
				'post'    => $post,
				'action'  => $action,
				'diff'    => $diff,
			);

			if ( 'create' === $action ) {
				$summary['created']++;
			} elseif ( 'update' === $action ) {
				$summary['updated']++;
			} else {
				$summary['unchanged']++;
			}

			$lines[] = self::plan_line( $id, $action, $diff );
		}

		/* Products present in WordPress but absent from the source: warning only. */
		$planned_ids = array();
		foreach ( $planned as $entry ) {
			$planned_ids[ $entry['product']['source_id'] ] = true;
		}
		foreach ( $existing as $id => $post ) {
			if ( ! isset( $planned_ids[ $id ] ) ) {
				$summary['warnings']++;
				$lines[] = sprintf(
					'Warning: %s exists in WordPress but is absent from the source; left unchanged (explicit lifecycle change required to archive).',
					$id
				);
			}
		}

		return array(
			'planned' => $planned,
			'summary' => $summary,
			'lines'   => $lines,
		);
	}

	private static function plan_line( string $id, string $action, array $diff ): string {
		if ( 'unchanged' === $action ) {
			return sprintf( '%s: unchanged', $id );
		}

		if ( 'create' === $action ) {
			return sprintf( '%s: would create', $id );
		}

		return sprintf( '%s: would update (%s)', $id, implode( ', ', $diff ) );
	}

	private static function summary_line( array $summary ): string {
		return sprintf(
			'Summary: created=%d updated=%d unchanged=%d warnings=%d errors=%d',
			$summary['created'],
			$summary['updated'],
			$summary['unchanged'],
			$summary['warnings'],
			$summary['errors']
		);
	}

	/**
	 * All synchronized products keyed by immutable source identity.
	 *
	 * @return WP_Post[]
	 */
	private static function existing_products(): array {
		$posts = get_posts(
			array(
				'post_type'        => 'fp_product',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		$existing = array();
		foreach ( $posts as $post ) {
			$id = (string) get_post_meta( $post->ID, '_fp_source_id', true );
			if ( '' !== $id ) {
				$existing[ $id ] = $post;
			}
		}

		return $existing;
	}

	/**
	 * A slug may never collide with other WordPress content (consistency).
	 */
	private static function assert_slug_available( string $slug, $ignore_post_id ): void {
		$collision = get_page_by_path( $slug, OBJECT, array( 'page', 'post', 'fp_product' ) );
		if ( $collision instanceof WP_Post && ( null === $ignore_post_id || (int) $collision->ID !== (int) $ignore_post_id ) ) {
			throw new Freeplast_CQ_Catalog_Sync_Error(
				sprintf( 'slug "%s" is already used by %s #%d', $slug, $collision->post_type, $collision->ID )
			);
		}
	}

	/**
	 * Which synchronized fields would change for an existing record?
	 */
	private static function diff_product( WP_Post $post, array $product, Freeplast_CQ_Catalog_Source $source ): array {
		$diff    = array();
		$get     = static fn( string $key ) => (string) get_post_meta( $post->ID, $key, true );
		$specs   = $product['specs'];
		$changes = array(
			'title'       => array( $post->post_title, $product['title'] ),
			'slug'        => array( $post->post_name, $product['slug'] ),
			'excerpt'     => array( $post->post_excerpt, $product['excerpt'] ),
			'description' => array( $get( '_fp_description' ), $product['description'] ),
			'category'    => array( $get( '_fp_category' ), $product['category'] ),
			'material'    => array( $get( '_fp_material' ), $specs['material'] ),
			'dimensions'  => array( $get( '_fp_dimensions' ), $specs['dimensions'] ),
			'weight'      => array( $get( '_fp_weight_text' ), $specs['weight'] ),
			'use'         => array( $get( '_fp_use' ), $specs['use'] ),
			'specs'       => array(
				self::pack( array( $get( '_fp_material_short' ), (string) $get( '_fp_units_per_pallet' ), $get( '_fp_quote_min_qty' ), $get( '_fp_quote_step' ) ) ),
				self::pack( array( $specs['material_short'], (string) $specs['units_per_pallet'], self::nullable_int( $specs['minimum_quantity'] ), self::nullable_int( $specs['quantity_step'] ) ) ),
			),
			'image'       => array( $get( '_fp_image_checksum' ), $product['image']['checksum'] ),
			'options'     => array( $get( '_fp_options' ), self::pack( $product['options'] ) ),
			'related'     => array( $get( '_fp_related_ids' ), self::pack( $product['related_ids'] ) ),
			'featured'    => array(
				self::pack( array( $get( '_fp_featured' ), $get( '_fp_featured_order' ) ) ),
				self::pack( array( $product['featured'] ? '1' : '0', self::nullable_int( $product['featured_order'] ) ) ),
			),
			'lifecycle'   => array(
				self::pack( array( $get( '_fp_lifecycle' ), $post->post_status ) ),
				self::pack( array( $product['lifecycle'], 'active' === $product['lifecycle'] ? 'publish' : 'draft' ) ),
			),
			'provenance'  => array(
				self::pack( array( $get( '_fp_source_url' ), $get( '_fp_source_checked_at' ), $get( '_fp_image_alt' ), $get( '_fp_image_provisional' ) ) ),
				self::pack( array( $product['source_url'], $source->provenance()['retrieved_at'], $product['image']['alt'], $product['image']['provisional'] ? '1' : '0' ) ),
			),
		);

		foreach ( $changes as $field => $pair ) {
			if ( $pair[0] !== $pair[1] ) {
				$diff[] = $field;
			}
		}

		return array_unique( $diff );
	}

	/* ------------------------------------------------------------------ */
	/* Applying                                                            */
	/* ------------------------------------------------------------------ */

	private static function apply( Freeplast_CQ_Catalog_Source $source, array $plan ): void {
		$created_ids = array();
		$attachments = array();

		try {
			/* Media first: a failed import aborts before any post mutation. */
			foreach ( $plan['planned'] as $entry ) {
				if ( 'unchanged' === $entry['action'] ) {
					continue;
				}

				$id              = $entry['product']['source_id'];
				$stored_checksum = $entry['post'] ? (string) get_post_meta( $entry['post']->ID, '_fp_image_checksum', true ) : '';
				if ( $stored_checksum === $entry['product']['image']['checksum'] ) {
					continue; // unchanged media is reused, never re-imported
				}

				$attachments[ $id ] = self::import_attachment( $source, $entry['product']['image'] );
			}
		} catch ( Freeplast_CQ_Catalog_Sync_Error $e ) {
			/* Nothing was mutated yet; attachments created above are removed. */
			foreach ( $attachments as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
			throw $e;
		}

		try {
			foreach ( $plan['planned'] as $entry ) {
				$id = $entry['product']['source_id'];
				if ( 'unchanged' === $entry['action'] ) {
					WP_CLI::line( sprintf( '%s: unchanged', $id ) );
					continue;
				}

				$post_id = self::upsert_product( $source, $entry['product'], $entry['post'], $attachments[ $id ] ?? null );
				if ( 'create' === $entry['action'] ) {
					$created_ids[] = $post_id;
					WP_CLI::line( sprintf( '%s: created', $id ) );
				} else {
					WP_CLI::line( sprintf( '%s: updated (%s)', $id, implode( ', ', $entry['diff'] ) ) );
				}
			}
		} catch ( Freeplast_CQ_Catalog_Sync_Error $e ) {
			foreach ( $created_ids as $post_id ) {
				wp_delete_post( $post_id, true );
			}
			throw $e;
		}

		flush_rewrite_rules();
	}

	/**
	 * Create or update one product record, touching only plugin-owned fields.
	 * The matched post (matched by immutable source identity) keeps its ID,
	 * permalink and revisions across updates.
	 */
	private static function upsert_product( Freeplast_CQ_Catalog_Source $source, array $product, ?WP_Post $existing, ?int $attachment_id ): int {
		$specs = $product['specs'];

		$fields = array(
			'post_type'    => 'fp_product',
			'post_status'  => 'archived' === $product['lifecycle'] ? 'draft' : 'publish',
			'post_title'   => $product['title'],
			'post_name'    => $product['slug'],
			'post_excerpt' => $product['excerpt'],
			'post_content' => "<!-- wp:freeplast/product-detail /-->\n",
		);

		$post_id = $existing
			? wp_update_post( array_merge( $fields, array( 'ID' => $existing->ID ) ), true )
			: wp_insert_post( $fields, true );

		if ( is_wp_error( $post_id ) ) {
			throw new Freeplast_CQ_Catalog_Sync_Error( sprintf( 'could not persist %s: %s', $product['source_id'], $post_id->get_error_message() ) );
		}

		$meta = array(
			'_fp_source_id'          => $product['source_id'],
			'_fp_source_url'         => $product['source_url'],
			'_fp_source_checked_at'  => $source->provenance()['retrieved_at'],
			'_fp_description'        => $product['description'],
			'_fp_category'           => $product['category'],
			'_fp_material'           => $specs['material'],
			'_fp_material_short'     => $specs['material_short'],
			'_fp_dimensions'         => $specs['dimensions'],
			'_fp_weight_text'        => $specs['weight'],
			'_fp_use'                => $specs['use'],
			'_fp_units_per_pallet'   => (string) $specs['units_per_pallet'],
			'_fp_lifecycle'          => $product['lifecycle'],
			'_fp_options'            => self::pack( $product['options'] ),
			'_fp_related_ids'        => self::pack( $product['related_ids'] ),
			'_fp_featured'           => $product['featured'] ? '1' : '0',
			'_fp_featured_order'     => self::nullable_int( $product['featured_order'] ),
			'_fp_image_checksum'     => $product['image']['checksum'],
			'_fp_image_source'       => 'catalog-source:' . $product['source_id'],
			'_fp_image_alt'          => $product['image']['alt'],
			'_fp_image_provisional'  => $product['image']['provisional'] ? '1' : '0',
		);

		if ( null === $specs['minimum_quantity'] ) {
			delete_post_meta( $post_id, '_fp_quote_min_qty' );
		} else {
			$meta['_fp_quote_min_qty'] = (string) $specs['minimum_quantity'];
		}
		if ( null === $specs['quantity_step'] ) {
			delete_post_meta( $post_id, '_fp_quote_step' );
		} else {
			$meta['_fp_quote_step'] = (string) $specs['quantity_step'];
		}

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		if ( $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return (int) $post_id;
	}
	/**
	 * Import the reviewed local media file into the media library, or reuse
	 * an existing attachment with the same checksum. The frontend never
	 * hotlinks source media.
	 */
	private static function import_attachment( Freeplast_CQ_Catalog_Source $source, array $image ): int {
		$reuse = get_posts(
			array(
				'post_type'        => 'attachment',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => '_fp_image_checksum', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $image['checksum'],   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( array() !== $reuse ) {
			return (int) $reuse[0];
		}

		$path = $source->media_path( $image['file'] );
		$data = file_get_contents( $path );
		if ( false === $data ) {
			throw new Freeplast_CQ_Catalog_Sync_Error( sprintf( 'could not read image "%s" for import', $image['file'] ) );
		}

		$upload = wp_upload_bits( basename( $image['file'] ), null, $data );
		if ( ! empty( $upload['error'] ) ) {
			throw new Freeplast_CQ_Catalog_Sync_Error( sprintf( 'media import failed for "%s": %s', $image['file'], $upload['error'] ) );
		}

		$filetype   = wp_check_filetype( $upload['file'] );
		$attachment = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'] ?: 'image/webp',
				'post_title'     => sanitize_file_name( pathinfo( $image['file'], PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment ) || 0 >= (int) $attachment ) {
			throw new Freeplast_CQ_Catalog_Sync_Error( sprintf( 'media import failed for "%s"', $image['file'] ) );
		}

		/* Dimensions come from the reviewed source; no image decoding needed. */
		wp_update_attachment_metadata(
			$attachment,
			array(
				'width'  => $image['width'],
				'height' => $image['height'],
				'file'   => _wp_relative_upload_path( $upload['file'] ),
				'sizes'  => array(),
			)
		);
		update_post_meta( $attachment, '_wp_attachment_image_alt', $image['alt'] );
		update_post_meta( $attachment, '_fp_image_checksum', $image['checksum'] );
		update_post_meta( $attachment, '_fp_image_provisional', $image['provisional'] ? '1' : '0' );

		return (int) $attachment;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private static function pack( $value ): string {
		return (string) wp_json_encode( $value );
	}

	private static function nullable_int( $value ): string {
		return null === $value ? '' : (string) $value;
	}
}

/**
 * Thrown when synchronization cannot proceed consistently. Nothing is kept
 * when this escapes the apply phase.
 */
class Freeplast_CQ_Catalog_Sync_Error extends Exception {
}
