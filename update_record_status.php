<?php
// update_record_status.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Load database connection
require_once 'db_connect.php';

try {
    $current_date = date('Y-m-d');
    $log_file = 'status_update_log.txt';

    // Log start
    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Starting record status update check\n", FILE_APPEND);

    echo "[" . date('Y-m-d H:i:s') . "] Starting record status update check\n";

    // Query to find records that are past their period_to date but still active/inactive
    $sql = "SELECT record_id, record_series_title, period_to, status 
            FROM records 
            WHERE status IN ('Active', 'Inactive') 
            AND period_to IS NOT NULL 
            AND period_to < :current_date";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['current_date' => $current_date]);
    $records_to_archive = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = count($records_to_archive);
    echo "[" . date('Y-m-d H:i:s') . "] Found {$count} records to archive\n";
    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Found {$count} records to archive\n", FILE_APPEND);

    if ($count > 0) {
        $record_ids = [];

        foreach ($records_to_archive as $record) {
            $record_ids[] = $record['record_id'];
            echo "[" . date('Y-m-d H:i:s') . "] Marking for archive: ID {$record['record_id']} - {$record['record_series_title']} (Period ends: {$record['period_to']})\n";
            file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Marking for archive: ID {$record['record_id']} - {$record['record_series_title']} (Period ends: {$record['period_to']})\n", FILE_APPEND);
        }

        // Update all records at once
        $placeholders = implode(',', array_fill(0, count($record_ids), '?'));
        $update_sql = "UPDATE records SET status = 'Archived' WHERE record_id IN ($placeholders)";

        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute($record_ids);

        $updated_count = $update_stmt->rowCount();
        echo "[" . date('Y-m-d H:i:s') . "] Successfully archived {$updated_count} records\n";
        file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Successfully archived {$updated_count} records\n", FILE_APPEND);
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] No records need archiving.\n";
    }

    // Also check for records that should be marked for disposal based on total years
    $disposal_sql = "SELECT r.record_id, r.record_series_title, r.period_from, r.total_years, r.status,
                            DATE_ADD(r.period_from, INTERVAL r.total_years YEAR) as disposal_date
                     FROM records r
                     WHERE r.status IN ('Active', 'Inactive')
                     AND r.total_years > 0
                     AND r.period_from IS NOT NULL
                     AND DATE_ADD(r.period_from, INTERVAL r.total_years YEAR) < :current_date";

    $disposal_stmt = $pdo->prepare($disposal_sql);
    $disposal_stmt->execute(['current_date' => $current_date]);
    $records_to_dispose = $disposal_stmt->fetchAll(PDO::FETCH_ASSOC);

    $disposal_count = count($records_to_dispose);
    echo "[" . date('Y-m-d H:i:s') . "] Found {$disposal_count} records for disposal\n";
    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Found {$disposal_count} records for disposal\n", FILE_APPEND);

    if ($disposal_count > 0) {
        $disposal_ids = [];

        foreach ($records_to_dispose as $record) {
            $disposal_ids[] = $record['record_id'];
            echo "[" . date('Y-m-d H:i:s') . "] Marking for disposal: ID {$record['record_id']} - {$record['record_series_title']} (Disposal date: {$record['disposal_date']})\n";
            file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Marking for disposal: ID {$record['record_id']} - {$record['record_series_title']} (Disposal date: {$record['disposal_date']})\n", FILE_APPEND);
        }

        // Update to Scheduled for Disposal
        $disposal_placeholders = implode(',', array_fill(0, count($disposal_ids), '?'));
        $disposal_update_sql = "UPDATE records SET status = 'Scheduled for Disposal' WHERE record_id IN ($disposal_placeholders)";

        $disposal_update_stmt = $pdo->prepare($disposal_update_sql);
        $disposal_update_stmt->execute($disposal_ids);

        $disposal_updated_count = $disposal_update_stmt->rowCount();
        echo "[" . date('Y-m-d H:i:s') . "] Successfully marked {$disposal_updated_count} records for disposal\n";
        file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Successfully marked {$disposal_updated_count} records for disposal\n", FILE_APPEND);
    }

    echo "[" . date('Y-m-d H:i:s') . "] Status update completed\n\n";
    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Status update completed\n\n", FILE_APPEND);
} catch (Exception $e) {
    $error_msg = "[" . date('Y-m-d H:i:s') . "] Error updating record status: " . $e->getMessage() . "\n";
    echo $error_msg;
    file_put_contents($log_file, $error_msg, FILE_APPEND);
    error_log("Error updating record status: " . $e->getMessage());
}
