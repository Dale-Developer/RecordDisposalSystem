<?php
// get_due_tomorrow.php
// This file specifically handles getting records due for archive tomorrow

/**
 * Enhanced version: Checks period_to and calculates from date_created + total_years
 */
function getRecordsDueTomorrowEnhanced($pdo)
{
    $records = [];

    try {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $today = date('Y-m-d');

        // Get records that:
        // 1. Are due tomorrow (for warning)
        // 2. OR are overdue but still active (for alert)

        $query = "
            SELECT 
                r.record_id,
                r.record_title,
                o.office_name,
                r.status,
                r.disposition_type,
                r.period_to,
                r.date_created,
                r.total_years,
                CASE 
                    WHEN r.period_to = ? THEN 'Archive Tomorrow'
                    WHEN DATE_ADD(r.date_created, INTERVAL r.total_years YEAR) = ? THEN 'Archive Tomorrow (Retention)'
                    WHEN r.period_to < ? THEN 'OVERDUE - Should be Archived'
                    WHEN DATE_ADD(r.date_created, INTERVAL r.total_years YEAR) < ? THEN 'OVERDUE - Retention Period Ended'
                    ELSE 'Archive Scheduled'
                END as action,
                CASE 
                    WHEN r.period_to IS NOT NULL THEN r.period_to
                    ELSE DATE_ADD(r.date_created, INTERVAL r.total_years YEAR)
                END as retention_period_end,
                CASE 
                    WHEN r.period_to < ? OR DATE_ADD(r.date_created, INTERVAL r.total_years YEAR) < ? THEN 'overdue'
                    WHEN r.period_to = ? OR DATE_ADD(r.date_created, INTERVAL r.total_years YEAR) = ? THEN 'due-tomorrow'
                    ELSE 'upcoming'
                END as urgency
            FROM records r
            INNER JOIN offices o ON r.office_id = o.office_id
            WHERE r.status = 'Active' 
            AND r.disposition_type = 'Archive'
            AND (
                r.period_to <= ? 
                OR DATE_ADD(r.date_created, INTERVAL r.total_years YEAR) <= ?
                OR r.period_to = ?
                OR DATE_ADD(r.date_created, INTERVAL r.total_years YEAR) = ?
            )
            ORDER BY 
                CASE urgency
                    WHEN 'overdue' THEN 1
                    WHEN 'due-tomorrow' THEN 2
                    ELSE 3
                END,
                retention_period_end ASC
            LIMIT 15
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $tomorrow,
            $tomorrow,  // For tomorrow checks
            $today,
            $today,        // For overdue checks
            $today,
            $today,        // For urgency classification
            $tomorrow,
            $tomorrow,  // For urgency classification
            $tomorrow,
            $tomorrow,  // For WHERE clause (<= tomorrow)
            $tomorrow,
            $tomorrow   // For WHERE clause (= tomorrow)
        ]);

        $results = $stmt->fetchAll();

        foreach ($results as $row) {
            $files_query = "
                SELECT file_id, file_name, file_path 
                FROM record_files 
                WHERE record_id = ? 
                ORDER BY file_id ASC
            ";
            $files_stmt = $pdo->prepare($files_query);
            $files_stmt->execute([$row['record_id']]);
            $files = $files_stmt->fetchAll();

            $records[] = [
                'record_id' => $row['record_id'],
                'record_title' => $row['record_title'],
                'office_name' => $row['office_name'],
                'retention_period_end' => $row['retention_period_end'],
                'action' => $row['action'],
                'urgency' => $row['urgency'],
                'files' => $files
            ];
        }

    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());
    }

    return $records;
}
/**
 * Original function for backward compatibility
 * Gets records due for archive tomorrow (regardless of disposition_type)
 */
function getRecordsDueTomorrow($pdo)
{
    // For backward compatibility, call the enhanced version
    return getRecordsDueTomorrowEnhanced($pdo);
}

/**
 * Alternative: Get records due within X days (optional - for future use)
 * This shows records due in the next X days, not just tomorrow
 */
function getRecordsDueWithinDays($pdo, $days = 7)
{
    $records = [];

    try {
        date_default_timezone_set('Asia/Manila');

        $startDate = date('Y-m-d', strtotime('+1 day'));
        $endDate = date('Y-m-d', strtotime('+' . $days . ' days'));

        $query = "
            SELECT 
                r.record_id,
                r.record_title,
                o.office_name,
                r.disposition_type,
                COALESCE(
                    r.period_to,
                    DATE_ADD(r.date_created, INTERVAL r.total_years YEAR)
                ) as retention_period_end,
                DATEDIFF(
                    COALESCE(
                        r.period_to,
                        DATE_ADD(r.date_created, INTERVAL r.total_years YEAR)
                    ), 
                    CURDATE()
                ) as days_until,
                CASE 
                    WHEN DATEDIFF(
                        COALESCE(
                            r.period_to,
                            DATE_ADD(r.date_created, INTERVAL r.total_years YEAR)
                        ), 
                        CURDATE()
                    ) = 1 THEN 'Due Tomorrow'
                    ELSE CONCAT('Due in ', 
                        DATEDIFF(
                            COALESCE(
                                r.period_to,
                                DATE_ADD(r.date_created, INTERVAL r.total_years YEAR)
                            ), 
                            CURDATE()
                        ), ' days')
                END as action
            FROM records r
            LEFT JOIN offices o ON r.office_id = o.office_id
            WHERE r.status = 'Active' 
            AND (
                DATE(r.period_to) BETWEEN :startDate AND :endDate
                OR (r.period_to IS NULL AND r.total_years IS NOT NULL AND DATE(DATE_ADD(r.date_created, INTERVAL r.total_years YEAR)) BETWEEN :startDate AND :endDate)
            )
            ORDER BY retention_period_end ASC 
            LIMIT 20
        ";

        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':startDate', $startDate);
        $stmt->bindParam(':endDate', $endDate);
        $stmt->execute();
        $results = $stmt->fetchAll();

        foreach ($results as $row) {
            // Get files
            $files_query = "
                SELECT file_id, file_name, file_path 
                FROM record_files 
                WHERE record_id = ? 
                ORDER BY file_id ASC
            ";
            $files_stmt = $pdo->prepare($files_query);
            $files_stmt->execute([$row['record_id']]);
            $files = $files_stmt->fetchAll();

            $records[] = [
                'record_id' => $row['record_id'],
                'record_title' => $row['record_title'],
                'office_name' => $row['office_name'],
                'disposition_type' => $row['disposition_type'],
                'retention_period_end' => $row['retention_period_end'],
                'due_date' => $row['retention_period_end'],
                'days_until' => $row['days_until'],
                'action' => $row['action'],
                'files' => $files
            ];
        }

    } catch (Exception $e) {
        error_log("Error in getRecordsDueWithinDays: " . $e->getMessage());
    }

    return $records;
}

/**
 * Simplified version: Only checks period_to column (no calculation)
 * Use this if you only want to check records with period_to set
 */
function getRecordsDueTomorrowSimple($pdo)
{
    $records = [];

    try {
        // Set timezone
        date_default_timezone_set('Asia/Manila');

        // Get tomorrow's date
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        // Query to get records where period_to is tomorrow
        $query = "
            SELECT 
                r.record_id,
                r.record_title,
                o.office_name,
                r.disposition_type,
                r.period_to as retention_period_end,
                'Due for Archive Tomorrow' as action
            FROM records r
            LEFT JOIN offices o ON r.office_id = o.office_id
            WHERE r.status = 'Active' 
            AND r.period_to IS NOT NULL
            AND DATE(r.period_to) = :tomorrow
            ORDER BY r.period_to ASC 
            LIMIT 15
        ";

        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':tomorrow', $tomorrow);
        $stmt->execute();
        $results = $stmt->fetchAll();

        foreach ($results as $row) {
            // Get file information for this record
            $files_query = "
                SELECT file_id, file_name, file_path 
                FROM record_files 
                WHERE record_id = ? 
                ORDER BY file_id ASC
            ";
            $files_stmt = $pdo->prepare($files_query);
            $files_stmt->execute([$row['record_id']]);
            $files = $files_stmt->fetchAll();

            $records[] = [
                'record_id' => $row['record_id'],
                'record_title' => $row['record_title'],
                'office_name' => $row['office_name'],
                'disposition_type' => $row['disposition_type'],
                'retention_period_end' => $row['retention_period_end'],
                'due_date' => $row['retention_period_end'],
                'action' => $row['action'],
                'files' => $files
            ];
        }

    } catch (Exception $e) {
        error_log("Error in getRecordsDueTomorrowSimple: " . $e->getMessage());
    }

    return $records;
}
?>