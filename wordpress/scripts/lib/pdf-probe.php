<?php
/** Independent Poppler inspection for tests only; never shipped with the adapter. */
function fpw_test_pdf_probe( string $bytes ): array {
	$path = tempnam( sys_get_temp_dir(), 'fpw-pdf-probe-' );
	if ( false === $path ) { throw new RuntimeException( 'PDF probe temp file unavailable' ); }
	try {
		file_put_contents( $path, $bytes );
		$out = array();
		foreach ( array( 'text' => array( 'pdftotext', '-layout', '-enc', 'UTF-8', $path, '-' ), 'info' => array( 'pdfinfo', $path ), 'fonts' => array( 'pdffonts', $path ), 'bounds' => array( 'pdftotext', '-bbox', $path, '-' ) ) as $key => $command ) {
			$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Poppler tools required for PDF tests' ); }
			fclose( $pipes[0] );
			$out[ $key ] = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] ); fclose( $pipes[2] );
			if ( 0 !== proc_close( $process ) || '' !== trim( $error ) ) { throw new RuntimeException( 'PDF inspection failed: ' . $error ); }
		}
		return $out;
	} finally { unlink( $path ); }
}
