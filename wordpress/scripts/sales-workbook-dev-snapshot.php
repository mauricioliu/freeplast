<?php
/** Read-only, complete DEV database inventory. No WordPress bootstrap/hooks. */
if ( PHP_SAPI !== 'cli' ) { exit(1); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('WORDPRESS_DB_HOST'), getenv('WORDPRESS_DB_USER'), trim(file_get_contents(getenv('WORDPRESS_DB_PASSWORD_FILE'))), getenv('WORDPRESS_DB_NAME'));
$db->set_charset('utf8mb4');
$capture_start = time();
$db->query('SET TRANSACTION READ ONLY');
$db->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
try {
    $urls = $db->query("SELECT option_name,option_value FROM wp_options WHERE option_name IN ('home','siteurl')")->fetch_all(MYSQLI_ASSOC);
    if (count($urls) !== 2 || array_unique(array_column($urls, 'option_value')) !== array('http://mliu:8131')) { throw new RuntimeException('Not the approved DEV database.'); }
    $tables = $db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);
    $snapshot = array('url' => 'http://mliu:8131', 'captured_at_start' => $capture_start, 'tables' => array(), 'tables_except_scheduler_lock_value' => array(), 'operational_lock' => null, 'sales_options' => array());
    foreach ($tables as [$table]) {
        if (!preg_match('/^wp_[a-zA-Z0-9_]+$/D', $table)) { throw new RuntimeException('Unexpected table name.'); }
        $rows = $db->query('SELECT * FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC);
        $hashes = array();
        $audited_hashes = array();
        foreach ($rows as $row) {
            if ($table === 'wp_options' && (in_array($row['option_name'], array('fpw_sales_register', 'fpw_sales_batch'), true) || str_starts_with($row['option_name'], 'fpw_sales_receipt_'))) {
                $snapshot['sales_options'][$row['option_name']] = $row;
                continue;
            }
            // Include IDs, autoload, all columns and all non-sales options, including transient state.
            $hashes[] = hash('sha256', serialize($row));
            if ($table === 'wp_options' && $row['option_name'] === 'action_scheduler_lock_async-request-runner') {
                $snapshot['operational_lock'] = $row;
                $row['option_value'] = 'AUDITED_SCHEDULER_LOCK_VALUE';
            }
            $audited_hashes[] = hash('sha256', serialize($row));
        }
        sort($hashes, SORT_STRING);
        $snapshot['tables'][$table] = array('protected_rows' => count($hashes), 'sha256' => hash('sha256', implode("\n", $hashes)));
        sort($audited_hashes, SORT_STRING);
        $snapshot['tables_except_scheduler_lock_value'][$table] = array('protected_rows' => count($audited_hashes), 'sha256' => hash('sha256', implode("\n", $audited_hashes)));
    }
    ksort($snapshot['tables']);
    ksort($snapshot['tables_except_scheduler_lock_value']);
    $snapshot['captured_at_end'] = time();
    ksort($snapshot['sales_options']);
    $snapshot['protected_sha256'] = hash('sha256', json_encode($snapshot['tables'], JSON_THROW_ON_ERROR));
    echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} finally { $db->rollback(); }
