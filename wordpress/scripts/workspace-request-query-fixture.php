<?php
/** SQL contract fixture; no WordPress bootstrap or external connection. */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../wp-content/plugins/freeplast-woo/workspace-requests.php';
class FPW_Request_Query_DB {
	public $prefix = 'wp_', $posts = 'wp_posts', $postmeta = 'wp_postmeta', $options = 'wp_options', $last_error = '';
	public $queries = array();
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%[sd]/', static function( $m ) use ( $args, &$i ) { $v = $args[ $i++ ]; return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", $v ) . "'"; }, $sql );
	}
	public function get_var( $sql ) { $this->queries[] = $sql; return 26; }
	public function get_col( $sql ) { $this->queries[] = $sql; return array( 1 ); }
}
$wpdb = new FPW_Request_Query_DB();
$out = array();
foreach ( array( false, true ) as $hpos ) {
	foreach ( array( 'all' => array( '', 'all' ), 'pending' => array( '', 'pendientes' ), 'sent' => array( '', 'sent-quotes' ), 'old-filter' => array( '', 'sent' ), 'company' => array( 'Antigua', 'all' ), 'rut' => array( '76.543.210-K', 'all' ), 'reference' => array( 'FP-2024-000001', 'all' ), 'legacy-reference' => array( 'COT-ANTIGUA', 'all' ), 'injection' => array( "' OR 1=1 --", 'all' ) ) as $name => [ $search, $stage ] ) {
		$q = fpw_workspace_request_query_parts( $search, $stage, $hpos );
		$out[ $hpos ? 'hpos' : 'cpt' ][ $name ] = 'SELECT ' . $q['id'] . $q['source'] . ' ORDER BY ' . $q['id'];
	}
}
$page = fpw_workspace_query( '', 'all', 'invalid direction', 999 );
if ( 2 !== $page['page'] || 2 !== $page['pages'] || ! str_contains( $wpdb->queries[1], 'DESC, o.ID DESC LIMIT 25 OFFSET 25' ) ) { throw new RuntimeException( 'pagination/order are not bounded' ); }
if ( 3 !== count( fpw_workspace_stages() ) || array( 'Pendientes', 'Enviadas', 'Todas' ) !== array_values( fpw_workspace_stages() ) ) { throw new RuntimeException( 'the three owner views must be Pendientes, Enviadas and Todas' ); }
echo json_encode( $out );
