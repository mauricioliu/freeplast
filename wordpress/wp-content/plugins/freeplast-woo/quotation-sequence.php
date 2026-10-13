<?php
/**
 * El correlativo comercial de las cotizaciones (paso 7): una serie entera
 * propia, confirmada por el propietario (último usado 1760, próximo 1761),
 * SEPARADA de la referencia de solicitud FP-YYYY-NNNNNN — que conserva su
 * propio espacio sin interferencia.
 *
 * Asignación: UN solo INSERT en una tabla dedicada con AUTO_INCREMENT — el
 * motor serializa la clave, así que aprobaciones concurrentes, doble clic y
 * reintentos jamás toman el mismo número; no hay ventana transaccional que
 * custodiar. La tabla nace con AUTO_INCREMENT 1761 y SOLO si no existe: un
 * redeploy nunca reinicia el contador (CREATE TABLE IF NOT EXISTS nunca
 * toca una tabla existente) y ninguna ruta de código reescribe el valor.
 *
 * El número se congela dentro de la versión emitida (fpw_quotation_<id>) y
 * viaja congelado al PDF, al correo y a las pantallas privadas; las versiones
 * históricas sin número no se renumeran ni se rellenan. Si la escritura
 * duradera de la versión falla después de asignar, el número queda consumido
 * (hueco documentado): la recuperación asigna uno nuevo, nunca reutiliza ni
 * renumera — sin duplicados y sin estado parcial.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Owner-confirmed series: last used 1760, so a fresh table starts allocating 1761. */
define( 'FPW_QUOTATION_SEQUENCE_START', 1761 );

/** The dedicated allocation table: one row per issued number, nothing else. */
function fpw_quotation_sequence_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'fpw_quotation_sequence';
}

/** Idempotent bootstrap: IF NOT EXISTS never resets an existing counter, so redeployments never reseed the series. */
function fpw_quotation_sequence_ready(): bool {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) { return false; }
	$result = $wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . fpw_quotation_sequence_table() . ' ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, allocated_at BIGINT UNSIGNED NOT NULL, PRIMARY KEY (id) ) ENGINE=InnoDB AUTO_INCREMENT=' . FPW_QUOTATION_SEQUENCE_START );
	return false !== $result && null !== $result;
}

/** The next commercial number, or null when the durable allocation fails — nothing is half-written on failure. */
function fpw_quotation_allocate_number(): ?int {
	global $wpdb;
	if ( ! fpw_quotation_sequence_ready() ) { return null; }
	$result = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . fpw_quotation_sequence_table() . ' ( allocated_at ) VALUES ( %d )', time() ) );
	$number = (int) ( $wpdb->insert_id ?? 0 );
	if ( false === $result || $number < FPW_QUOTATION_SEQUENCE_START ) { return null; }
	return $number;
}
