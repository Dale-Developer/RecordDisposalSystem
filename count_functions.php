<?php
// count_functions.php

/**
 * Get all record counts for dashboard
 */
function getRecordCounts($pdo)
{
    $counts = [
        'active_files' => 0,
        'archived' => 0,
        'for_disposal' => 0,
        'pending_request' => 0
    ];

    try {
        // Count active files (records with status = 'Active')
        $active_query = "SELECT COUNT(*) as count FROM records WHERE status = 'Active'";
        $active_stmt = $pdo->query($active_query);
        $counts['active_files'] = $active_stmt->fetch()['count'];

        // Count archived files (records with status = 'Archived')
        $archived_query = "SELECT COUNT(*) as count FROM records WHERE status = 'Archived'";
        $archived_stmt = $pdo->query($archived_query);
        $counts['archived'] = $archived_stmt->fetch()['count'];

        // Count files for disposal (records eligible for disposal based on schedule)
        $disposal_query = "
            SELECT COUNT(DISTINCT r.record_id) as count 
            FROM records r 
            INNER JOIN disposal_schedule ds ON r.record_id = ds.record_id 
            WHERE ds.status IN ('Pending', 'For Review') 
            AND ds.schedule_date <= CURDATE()
            AND r.status = 'Active'
        ";
        $disposal_stmt = $pdo->query($disposal_query);
        $counts['for_disposal'] = $disposal_stmt->fetch()['count'];

        // Count pending requests (records due for archive/disposal but not yet processed)
        $pending_query = "
            SELECT COUNT(*) as count 
            FROM records 
            WHERE status = 'Active' 
            AND (
                (disposition_type = 'Archive' AND DATE_ADD(date_created, INTERVAL retention_period YEAR) <= CURDATE())
                OR 
                (disposition_type = 'Dispose' AND DATE_ADD(date_created, INTERVAL retention_period YEAR) <= CURDATE())
            )
        ";
        $pending_stmt = $pdo->query($pending_query);
        $counts['pending_request'] = $pending_stmt->fetch()['count'];

    } catch (Exception $e) {
        error_log("Error getting record counts: " . $e->getMessage());
    }

    return $counts;
}

/**
 * Get records due for archive
 */
function getRecordsDueForArchive($pdo)
{
    $records = [];

    try {
        $query = "
            SELECT 
                r.record_id,
                r.record_title,
                o.office_name,
                DATE_ADD(r.date_created, INTERVAL r.retention_period YEAR) as due_date,
                'for archiving' as action
            FROM records r
            INNER JOIN offices o ON r.office_id = o.office_id
            WHERE r.status = 'Active' 
            AND r.disposition_type = 'Archive'
            AND DATE_ADD(r.date_created, INTERVAL r.retention_period YEAR) <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY due_date ASC 
            LIMIT 15
        ";
        $stmt = $pdo->query($query);
        $records = $stmt->fetchAll();

    } catch (Exception $e) {
        error_log("Error getting records due for archive: " . $e->getMessage());
    }

    return $records;
}

/**
 * Get recent archive requests
 */
function getRecentArchiveRequests($pdo)
{
    $requests = [];

    try {
        $query = "
            SELECT 
                r.record_id,
                r.record_title,
                CONCAT(u.first_name, ' ', u.last_name) as requested_by,
                DATE_ADD(r.date_created, INTERVAL r.retention_period YEAR) as request_date
            FROM records r
            INNER JOIN users u ON r.created_by = u.user_id
            WHERE r.status = 'Active' 
            AND r.disposition_type = 'Archive'
            AND DATE_ADD(r.date_created, INTERVAL r.retention_period YEAR) <= CURDATE()
            ORDER BY request_date DESC 
            LIMIT 7
        ";
        $stmt = $pdo->query($query);
        $results = $stmt->fetchAll();

        foreach ($results as $row) {
            // Generate request code like AR-001, AR-002, etc.
            $request_code = 'AR-' . str_pad($row['record_id'], 3, '0', STR_PAD_LEFT);
            $requests[] = [
                'code' => $request_code,
                'details' => date('m/d/Y', strtotime($row['request_date'])) . ' - ' . $row['requested_by'],
                'record_title' => $row['record_title']
            ];
        }

    } catch (Exception $e) {
        error_log("Error getting recent archive requests: " . $e->getMessage());
    }

    return $requests;
}

/**
 * Get counts for a specific office (for custodians)
 */
function getOfficeRecordCounts($pdo, $office_id)
{
    $counts = [
        'office_active' => 0,
        'office_archived' => 0,
        'office_for_disposal' => 0
    ];

    try {
        // Count active files for specific office
        $active_query = "SELECT COUNT(*) as count FROM records WHERE status = 'Active' AND office_id = ?";
        $stmt = $pdo->prepare($active_query);
        $stmt->execute([$office_id]);
        $counts['office_active'] = $stmt->fetch()['count'];

        // Count archived files for specific office
        $archived_query = "SELECT COUNT(*) as count FROM records WHERE status = 'Archived' AND office_id = ?";
        $stmt = $pdo->prepare($archived_query);
        $stmt->execute([$office_id]);
        $counts['office_archived'] = $stmt->fetch()['count'];

        // Count files for disposal for specific office
        $disposal_query = "
            SELECT COUNT(DISTINCT r.record_id) as count 
            FROM records r 
            INNER JOIN disposal_schedule ds ON r.record_id = ds.record_id 
            WHERE ds.status IN ('Pending', 'For Review') 
            AND ds.schedule_date <= CURDATE()
            AND r.status = 'Active'
            AND r.office_id = ?
        ";
        $stmt = $pdo->prepare($disposal_query);
        $stmt->execute([$office_id]);
        $counts['office_for_disposal'] = $stmt->fetch()['count'];

    } catch (Exception $e) {
        error_log("Error getting office record counts: " . $e->getMessage());
    }

    return $counts;
}

/**
 * Get disposal schedule counts
 */
function getDisposalScheduleCounts($pdo)
{
    $counts = [
        'pending' => 0,
        'for_review' => 0,
        'approved' => 0,
        'disposed' => 0
    ];

    try {
        $query = "SELECT status, COUNT(*) as count FROM disposal_schedule GROUP BY status";
        $stmt = $pdo->query($query);
        $results = $stmt->fetchAll();

        foreach ($results as $row) {
            $counts[$row['status']] = $row['count'];
        }
    } catch (Exception $e) {
        error_log("Error getting disposal schedule counts: " . $e->getMessage());
    }

    return $counts;
}
?>