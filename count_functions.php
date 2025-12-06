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
        // 1. Active Records - status = 'Active'
        $active_query = "SELECT COUNT(*) as count FROM records WHERE status = 'Active'";
        $active_stmt = $pdo->query($active_query);
        $counts['active_files'] = $active_stmt->fetch()['count'];

        // 2. Archived - status = 'archived' (lowercase)
        $archived_query = "SELECT COUNT(*) as count FROM records WHERE status = 'archived'";
        $archived_stmt = $pdo->query($archived_query);
        $counts['archived'] = $archived_stmt->fetch()['count'];

        // 3. Disposed - status = 'disposed' (lowercase)
        $disposed_query = "SELECT COUNT(*) as count FROM records WHERE status = 'disposed'";
        $disposed_stmt = $pdo->query($disposed_query);
        $counts['for_disposal'] = $disposed_stmt->fetch()['count'];

        // 4. Pending Request - from disposal_requests table (case-insensitive check)
        $counts['pending_request'] = getPendingDisposalRequestCount($pdo);

    } catch (Exception $e) {
        error_log("Error getting record counts: " . $e->getMessage());
    }

    return $counts;
}

/**
 * Get count of pending disposal requests (case-insensitive)
 */
function getPendingDisposalRequestCount($pdo)
{
    $count = 0;
    
    try {
        // Check if disposal_requests table exists (plural with 's')
        $table_exists = $pdo->query("SHOW TABLES LIKE 'disposal_requests'")->rowCount() > 0;
        
        if ($table_exists) {
            // Case-insensitive query to handle 'pending', 'Pending', 'PENDING', etc.
            $pending_query = "SELECT COUNT(*) as count FROM disposal_requests WHERE LOWER(status) = 'pending'";
            $pending_stmt = $pdo->query($pending_query);
            $result = $pending_stmt->fetch();
            $count = $result ? $result['count'] : 0;
        }
        
    } catch (Exception $e) {
        error_log("Error getting pending disposal request count: " . $e->getMessage());
    }
    
    return $count;
}

/**
 * Get records due for archive tomorrow (records whose retention period ends tomorrow)
 * CORRECTED VERSION - This will show records that end their retention period tomorrow
 */
function getRecordsDueForArchive($pdo)
{
    $records = [];

    try {
        // Set timezone first
        date_default_timezone_set('Asia/Manila');
        
        // Get tomorrow's date in YYYY-MM-DD format
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        // For debugging: Log what we're looking for
        error_log("[Archive Check] Looking for records with retention ending on: " . $tomorrow);
        
        // FIRST: Check if we have a direct retention_period_end field
        $fields_query = $pdo->query("DESCRIBE records");
        $fields = $fields_query->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('retention_period_end', $fields)) {
            // Option A: Use direct retention_period_end field
            error_log("[Archive Check] Using retention_period_end field");
            $query = "
                SELECT 
                    r.record_id,
                    r.record_title,
                    o.office_name,
                    r.retention_period_end,
                    'Due for Archive Tomorrow' as action
                FROM records r
                INNER JOIN offices o ON r.office_id = o.office_id
                WHERE r.status = 'Active' 
                AND DATE(r.retention_period_end) = ?
                AND r.retention_period_end IS NOT NULL
                ORDER BY r.retention_period_end ASC 
                LIMIT 15
            ";
        } else {
            // Option B: Calculate from date_created + retention_period
            error_log("[Archive Check] Calculating from date_created + retention_period");
            $query = "
                SELECT 
                    r.record_id,
                    r.record_title,
                    o.office_name,
                    DATE_ADD(r.date_created, INTERVAL r.retention_period YEAR) as retention_period_end,
                    'Due for Archive Tomorrow' as action
                FROM records r
                INNER JOIN offices o ON r.office_id = o.office_id
                WHERE r.status = 'Active' 
                AND DATE(DATE_ADD(r.date_created, INTERVAL r.retention_period YEAR)) = ?
                AND r.retention_period IS NOT NULL
                ORDER BY retention_period_end ASC 
                LIMIT 15
            ";
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$tomorrow]);
        $results = $stmt->fetchAll();
        
        // Log how many records were found
        error_log("[Archive Check] Found " . count($results) . " records due for archive tomorrow");

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
                'retention_period_end' => $row['retention_period_end'],
                'due_date' => $row['retention_period_end'],
                'action' => $row['action'],
                'files' => $files
            ];
        }

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
        // Check if archive_requests table exists
        $table_exists = $pdo->query("SHOW TABLES LIKE 'archive_requests'")->rowCount() > 0;
        
        if ($table_exists) {
            // Get actual archive requests with file information
            $query = "
                SELECT 
                    ar.request_id,
                    r.record_id,
                    r.record_title,
                    CONCAT(u.first_name, ' ', u.last_name) as requested_by,
                    ar.request_date,
                    ar.status,
                    rf.file_id,
                    rf.file_name,
                    rf.file_path
                FROM archive_requests ar
                INNER JOIN records r ON ar.record_id = r.record_id
                INNER JOIN users u ON ar.requested_by = u.user_id
                LEFT JOIN record_files rf ON r.record_id = rf.record_id
                ORDER BY ar.request_date DESC 
                LIMIT 7
            ";
        } else {
            // Fallback: get records that are due for archive with file information
            $query = "
                SELECT 
                    r.record_id,
                    r.record_title,
                    CONCAT(u.first_name, ' ', u.last_name) as requested_by,
                    DATE_ADD(r.date_created, INTERVAL r.retention_period YEAR) as request_date,
                    'Auto-generated' as status,
                    rf.file_id,
                    rf.file_name,
                    rf.file_path
                FROM records r
                INNER JOIN users u ON r.created_by = u.user_id
                LEFT JOIN record_files rf ON r.record_id = rf.record_id
                WHERE r.status = 'Active' 
                AND r.disposition_type = 'Archive'
                AND DATE_ADD(r.date_created, INTERVAL r.retention_period YEAR) <= CURDATE()
                ORDER BY request_date DESC 
                LIMIT 7
            ";
        }
        
        $stmt = $pdo->query($query);
        $results = $stmt->fetchAll();

        // Group files by request/record
        $grouped_results = [];
        foreach ($results as $row) {
            $key = $table_exists ? $row['request_id'] : $row['record_id'];
            
            if (!isset($grouped_results[$key])) {
                $grouped_results[$key] = [
                    'request_id' => $table_exists ? $row['request_id'] : null,
                    'record_id' => $row['record_id'],
                    'record_title' => $row['record_title'],
                    'requested_by' => $row['requested_by'],
                    'request_date' => $row['request_date'],
                    'status' => $row['status'],
                    'files' => []
                ];
            }
            
            // Add file information if available
            if ($row['file_id']) {
                $grouped_results[$key]['files'][] = [
                    'file_id' => $row['file_id'],
                    'file_name' => $row['file_name'],
                    'file_path' => $row['file_path']
                ];
            }
        }

        foreach ($grouped_results as $row) {
            if ($table_exists) {
                $request_code = 'AR-' . str_pad($row['request_id'], 3, '0', STR_PAD_LEFT);
            } else {
                $request_code = 'AR-' . str_pad($row['record_id'], 3, '0', STR_PAD_LEFT);
            }
            
            $requests[] = [
                'code' => $request_code,
                'details' => date('m/d/Y', strtotime($row['request_date'])) . ' - ' . $row['requested_by'],
                'record_title' => $row['record_title'],
                'files' => $row['files']
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
        $archived_query = "SELECT COUNT(*) as count FROM records WHERE status = 'archived' AND office_id = ?";
        $stmt = $pdo->prepare($archived_query);
        $stmt->execute([$office_id]);
        $counts['office_archived'] = $stmt->fetch()['count'];

        // Count files for disposal for specific office (from disposal_schedule table)
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

/**
 * Get file information for a specific record
 */
function getRecordFiles($pdo, $record_id)
{
    $files = [];
    
    try {
        $query = "
            SELECT file_id, file_name, file_path, uploaded_at 
            FROM record_files 
            WHERE record_id = ? 
            ORDER BY uploaded_at DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$record_id]);
        $files = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting record files: " . $e->getMessage());
    }
    
    return $files;
}

/**
 * Get all disposal requests
 */
function getAllDisposalRequests($pdo)
{
    $requests = [];

    try {
        // First check if disposal_request table exists (singular)
        $table_exists_singular = $pdo->query("SHOW TABLES LIKE 'disposal_request'")->rowCount() > 0;
        
        // Also check if disposal_requests table exists (plural)
        $table_exists_plural = $pdo->query("SHOW TABLES LIKE 'disposal_requests'")->rowCount() > 0;
        
        if ($table_exists_singular) {
            $table_name = 'disposal_request';
        } elseif ($table_exists_plural) {
            $table_name = 'disposal_requests';
        } else {
            // Neither table exists
            error_log("Neither disposal_request nor disposal_requests table found");
            return $requests;
        }
        
        $query = "SELECT request_id, status FROM $table_name ORDER BY request_id DESC";
        $stmt = $pdo->query($query);
        $results = $stmt->fetchAll();

        foreach ($results as $row) {
            $requests[] = [
                'request_id' => $row['request_id'],
                'status' => $row['status']
            ];
        }

    } catch (Exception $e) {
        error_log("Error getting disposal requests: " . $e->getMessage());
    }

    return $requests;
}
?>