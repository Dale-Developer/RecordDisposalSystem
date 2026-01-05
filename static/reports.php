<?php
require_once '../session.php';
require_once '../db_connect.php';

// Initialize variables
$logs = [];
$total_logs = 0;
$error = '';
$search_filter = '';
$date_from = '';
$date_to = '';
$action_type = '';

// Check for filter parameters
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search_filter = isset($_POST['search_filter']) ? trim($_POST['search_filter']) : '';
    $date_from = isset($_POST['date_from']) ? trim($_POST['date_from']) : '';
    $date_to = isset($_POST['date_to']) ? trim($_POST['date_to']) : '';
    $action_type = isset($_POST['action_type']) ? trim($_POST['action_type']) : '';
}

try {
    // ========== QUERY 1: RECORD CREATION LOGS ==========
    $creation_logs_sql = "SELECT 
                            r.record_id,
                            r.record_series_code,
                            r.record_series_title,
                            r.date_created as action_date,
                            r.created_by,
                            r.office_id,
                            r.status,
                            'RECORD_CREATED' as action_type,
                            NULL as status_from,
                            r.status as status_to,
                            'Record was created' as notes,
                            NULL as request_id,
                            NULL as schedule_id,
                            
                            -- User who created the record
                            u.first_name,
                            u.last_name,
                            u.email as user_email,
                            
                            -- User's role
                            ur.role_name,
                            
                            -- User's office
                            uo.office_name as user_office_name,
                            
                            -- Record's office
                            ro.office_name as record_office_name,
                            
                            -- Record classification
                            rc.class_name
                            
                        FROM records r
                        
                        LEFT JOIN users u ON r.created_by = u.user_id
                        LEFT JOIN roles ur ON u.role_id = ur.role_id
                        LEFT JOIN offices uo ON u.office_id = uo.office_id
                        LEFT JOIN offices ro ON r.office_id = ro.office_id
                        LEFT JOIN record_classification rc ON r.class_id = rc.class_id
                        
                        WHERE 1=1";

    $creation_params = [];

    // Apply filters for creation logs
    if ($search_filter) {
        $creation_logs_sql .= " AND (
                    r.record_series_title LIKE ? OR 
                    r.record_series_code LIKE ? OR
                    u.first_name LIKE ? OR
                    u.last_name LIKE ? OR
                    u.email LIKE ? OR
                    ro.office_name LIKE ? OR
                    rc.class_name LIKE ?
                )";
        $search_param = "%$search_filter%";
        $creation_params = array_merge($creation_params, array_fill(0, 7, $search_param));
    }

    if ($date_from) {
        $creation_logs_sql .= " AND DATE(r.date_created) >= ?";
        $creation_params[] = $date_from;
    }

    if ($date_to) {
        $creation_logs_sql .= " AND DATE(r.date_created) <= ?";
        $creation_params[] = $date_to;
    }

    if ($action_type && $action_type === 'RECORD_CREATED') {
        $creation_logs_sql .= " AND 1=1"; // Always true for creation filter
    }

    // ========== QUERY 2: DISPOSAL ACTION LOGS ==========
    $disposal_logs_sql = "SELECT 
                            dal.action_id,
                            dal.request_id,
                            dal.record_id,
                            dal.schedule_id,
                            dal.action_type,
                            dal.performed_by,
                            dal.performed_at as action_date,
                            dal.status_from,
                            dal.status_to,
                            dal.notes,
                            dal.office_id,
                            dal.role_id,
                            
                            -- User who performed the action
                            u.first_name,
                            u.last_name,
                            u.email as user_email,
                            
                            -- User's role
                            r.role_name,
                            
                            -- User's office
                            o.office_name as user_office_name,
                            
                            -- Record information (if available)
                            rec.record_series_code,
                            rec.record_series_title,
                            rec.office_id as record_office_id,
                            rec_office.office_name as record_office_name,
                            
                            -- Record classification
                            rc.class_name,
                            
                            -- Request information (if available)
                            dr.agency_name,
                            dr.request_date,
                            dr.status as request_status
                            
                        FROM disposal_action_log dal
                        
                        LEFT JOIN users u ON dal.performed_by = u.user_id
                        LEFT JOIN roles r ON dal.role_id = r.role_id
                        LEFT JOIN offices o ON dal.office_id = o.office_id
                        LEFT JOIN records rec ON dal.record_id = rec.record_id
                        LEFT JOIN offices rec_office ON rec.office_id = rec_office.office_id
                        LEFT JOIN record_classification rc ON rec.class_id = rc.class_id
                        LEFT JOIN disposal_requests dr ON dal.request_id = dr.request_id
                        
                        WHERE 1=1";

    $disposal_params = [];

    // Apply filters for disposal logs
    if ($search_filter) {
        $disposal_logs_sql .= " AND (
                    dal.action_type LIKE ? OR 
                    dal.notes LIKE ? OR
                    u.first_name LIKE ? OR
                    u.last_name LIKE ? OR
                    u.email LIKE ? OR
                    rec.record_series_title LIKE ? OR
                    rec.record_series_code LIKE ? OR
                    dr.agency_name LIKE ? OR
                    rec_office.office_name LIKE ? OR
                    rc.class_name LIKE ?
                )";
        $search_param = "%$search_filter%";
        $disposal_params = array_merge($disposal_params, array_fill(0, 10, $search_param));
    }

    if ($date_from) {
        $disposal_logs_sql .= " AND DATE(dal.performed_at) >= ?";
        $disposal_params[] = $date_from;
    }

    if ($date_to) {
        $disposal_logs_sql .= " AND DATE(dal.performed_at) <= ?";
        $disposal_params[] = $date_to;
    }

    if ($action_type && $action_type !== 'all' && $action_type !== 'field_change' && $action_type !== 'RECORD_CREATED') {
        $disposal_logs_sql .= " AND dal.action_type = ?";
        $disposal_params[] = $action_type;
    }

    // ========== QUERY 3: RECORD CHANGE LOGS ==========
    $change_logs_sql = "SELECT 
                            rcl.change_id,
                            rcl.record_id,
                            rcl.user_id,
                            rcl.field_name,
                            rcl.field_type,
                            rcl.old_value_text,
                            rcl.new_value_text,
                            rcl.old_value_int,
                            rcl.new_value_int,
                            rcl.old_value_date,
                            rcl.new_value_date,
                            rcl.old_value_enum,
                            rcl.new_value_enum,
                            rcl.old_reference_id,
                            rcl.new_reference_id,
                            rcl.change_reason as notes,
                            rcl.created_at as action_date,
                            
                            -- Action type for display
                            CASE 
                                WHEN rcl.field_name = 'status' THEN 'STATUS_CHANGED'
                                ELSE 'FIELD_CHANGED'
                            END as action_type,
                            
                            -- For status changes, map old and new values
                            CASE 
                                WHEN rcl.field_name = 'status' THEN rcl.old_value_enum
                                ELSE NULL
                            END as status_from,
                            
                            CASE 
                                WHEN rcl.field_name = 'status' THEN rcl.new_value_enum
                                ELSE NULL
                            END as status_to,
                            
                            -- User who made the change
                            u.first_name,
                            u.last_name,
                            u.email as user_email,
                            
                            -- User's role
                            ur.role_name,
                            
                            -- User's office
                            uo.office_name as user_office_name,
                            
                            -- Record information
                            rec.record_series_code,
                            rec.record_series_title,
                            rec.office_id as record_office_id,
                            rec_office.office_name as record_office_name,
                            
                            -- Record classification
                            rc.class_name,
                            
                            -- For reference fields, get the names
                            CASE 
                                WHEN rcl.field_name = 'office_id' THEN old_office.office_name
                                WHEN rcl.field_name = 'class_id' THEN old_class.class_name
                                ELSE NULL
                            END as old_reference_name,
                            
                            CASE 
                                WHEN rcl.field_name = 'office_id' THEN new_office.office_name
                                WHEN rcl.field_name = 'class_id' THEN new_class.class_name
                                ELSE NULL
                            END as new_reference_name
                            
                        FROM record_change_log rcl
                        
                        LEFT JOIN users u ON rcl.user_id = u.user_id
                        LEFT JOIN roles ur ON u.role_id = ur.role_id
                        LEFT JOIN offices uo ON u.office_id = uo.office_id
                        LEFT JOIN records rec ON rcl.record_id = rec.record_id
                        LEFT JOIN offices rec_office ON rec.office_id = rec_office.office_id
                        LEFT JOIN record_classification rc ON rec.class_id = rc.class_id
                        LEFT JOIN offices old_office ON rcl.old_reference_id = old_office.office_id AND rcl.field_name = 'office_id'
                        LEFT JOIN offices new_office ON rcl.new_reference_id = new_office.office_id AND rcl.field_name = 'office_id'
                        LEFT JOIN record_classification old_class ON rcl.old_reference_id = old_class.class_id AND rcl.field_name = 'class_id'
                        LEFT JOIN record_classification new_class ON rcl.new_reference_id = new_class.class_id AND rcl.field_name = 'class_id'
                        
                        WHERE 1=1";

    $change_params = [];

    // Apply filters for change logs
    if ($search_filter) {
        $change_logs_sql .= " AND (
                    rcl.field_name LIKE ? OR 
                    rcl.change_reason LIKE ? OR
                    u.first_name LIKE ? OR
                    u.last_name LIKE ? OR
                    u.email LIKE ? OR
                    rec.record_series_title LIKE ? OR
                    rec.record_series_code LIKE ? OR
                    rec_office.office_name LIKE ? OR
                    rc.class_name LIKE ?
                )";
        $search_param = "%$search_filter%";
        $change_params = array_merge($change_params, array_fill(0, 9, $search_param));
    }

    if ($date_from) {
        $change_logs_sql .= " AND DATE(rcl.created_at) >= ?";
        $change_params[] = $date_from;
    }

    if ($date_to) {
        $change_logs_sql .= " AND DATE(rcl.created_at) <= ?";
        $change_params[] = $date_to;
    }

    if ($action_type && $action_type !== 'all' && $action_type !== 'RECORD_CREATED') {
        if ($action_type === 'field_change') {
            $change_logs_sql .= " AND rcl.field_name != 'status'";
        } elseif ($action_type === 'STATUS_CHANGED') {
            $change_logs_sql .= " AND rcl.field_name = 'status'";
        } elseif ($action_type === 'FIELD_CHANGED') {
            $change_logs_sql .= " AND rcl.field_name != 'status'";
        } else {
            $change_logs_sql .= " AND rcl.field_name = ?";
            $change_params[] = $action_type;
        }
    }

    // ========== QUERY 4: DISPOSAL REQUEST CREATION ==========
    $request_logs_sql = "SELECT 
                            dr.request_id,
                            NULL as record_id,
                            dr.request_date as action_date,
                            dr.requested_by,
                            u.office_id as office_id,
                            'REQUEST_CREATED' as action_type,
                            NULL as status_from,
                            dr.status as status_to,
                            CONCAT('Disposal request created for agency: ', dr.agency_name) as notes,
                            dr.request_id as original_request_id,
                            NULL as schedule_id,
                            
                            -- User who created the request
                            u.first_name,
                            u.last_name,
                            u.email as user_email,
                            
                            -- User's role
                            ur.role_name,
                            
                            -- User's office
                            uo.office_name as user_office_name,
                            
                            -- Request agency
                            dr.agency_name,
                            
                            -- Request details
                            dr.remarks,
                            
                            -- Additional info
                            NULL as record_series_code,
                            NULL as record_series_title,
                            NULL as record_office_name,
                            NULL as class_name
                            
                        FROM disposal_requests dr
                        
                        LEFT JOIN users u ON dr.requested_by = u.user_id
                        LEFT JOIN roles ur ON u.role_id = ur.role_id
                        LEFT JOIN offices uo ON u.office_id = uo.office_id
                        
                        WHERE 1=1";

    $request_params = [];

    // Apply filters for request logs
    if ($search_filter) {
        $request_logs_sql .= " AND (
                    dr.agency_name LIKE ? OR 
                    dr.remarks LIKE ? OR
                    u.first_name LIKE ? OR
                    u.last_name LIKE ? OR
                    u.email LIKE ? OR
                    uo.office_name LIKE ?
                )";
        $search_param = "%$search_filter%";
        $request_params = array_merge($request_params, array_fill(0, 6, $search_param));
    }

    if ($date_from) {
        $request_logs_sql .= " AND DATE(dr.request_date) >= ?";
        $request_params[] = $date_from;
    }

    if ($date_to) {
        $request_logs_sql .= " AND DATE(dr.request_date) <= ?";
        $request_params[] = $date_to;
    }

    if ($action_type && $action_type === 'REQUEST_CREATED') {
        $request_logs_sql .= " AND 1=1"; // Always true for request creation filter
    }

    // Execute all queries
    $all_logs = [];

    // Get creation logs
    $creation_stmt = $pdo->prepare($creation_logs_sql . " ORDER BY r.date_created DESC");
    $creation_stmt->execute($creation_params);
    $creation_logs = $creation_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($creation_logs as $log) {
        $log['log_type'] = 'creation';
        $log['log_id'] = 'CR-' . $log['record_id'];
        $log['entity_id'] = $log['record_id'];
        $all_logs[] = $log;
    }

    // Get disposal action logs
    $disposal_stmt = $pdo->prepare($disposal_logs_sql . " ORDER BY dal.performed_at DESC");
    $disposal_stmt->execute($disposal_params);
    $disposal_logs = $disposal_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($disposal_logs as $log) {
        $log['log_type'] = 'disposal_action';
        $log['log_id'] = 'DA-' . $log['action_id'];
        $log['entity_id'] = $log['record_id'] ?: $log['request_id'] ?: $log['action_id'];
        $all_logs[] = $log;
    }

    // Get record change logs
    $change_stmt = $pdo->prepare($change_logs_sql . " ORDER BY rcl.created_at DESC");
    $change_stmt->execute($change_params);
    $change_logs = $change_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($change_logs as $log) {
        $log['log_type'] = 'record_change';
        $log['log_id'] = 'RC-' . $log['change_id'];
        $log['entity_id'] = $log['record_id'];
        $all_logs[] = $log;
    }

    // Get disposal request creation logs
    $request_stmt = $pdo->prepare($request_logs_sql . " ORDER BY dr.request_date DESC");
    $request_stmt->execute($request_params);
    $request_logs = $request_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($request_logs as $log) {
        $log['log_type'] = 'request_creation';
        $log['log_id'] = 'DR-' . $log['request_id'];
        $log['entity_id'] = $log['request_id'];
        $all_logs[] = $log;
    }

    // Sort all logs by timestamp (newest first)
    usort($all_logs, function ($a, $b) {
        $dateA = strtotime($a['action_date'] ?? '1970-01-01');
        $dateB = strtotime($b['action_date'] ?? '1970-01-01');
        return $dateB - $dateA;
    });

    // Assign sequential IDs for display
    $total_logs = count($all_logs);
    foreach ($all_logs as $index => $log) {
        $all_logs[$index]['display_id'] = $total_logs - $index;
    }

    $logs = $all_logs;
    $total_logs = count($logs);

    // Get unique action types for filter dropdown
    $action_types_sql = "SELECT DISTINCT action_type FROM disposal_action_log 
                        UNION 
                        SELECT 'RECORD_CREATED' 
                        UNION 
                        SELECT 'REQUEST_CREATED' 
                        UNION 
                        SELECT 'FIELD_CHANGED' 
                        UNION 
                        SELECT 'STATUS_CHANGED' 
                        ORDER BY action_type";
    $action_types_stmt = $pdo->query($action_types_sql);
    $action_types = $action_types_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get unique field names for filter dropdown
    $field_names_sql = "SELECT DISTINCT field_name FROM record_change_log ORDER BY field_name";
    $field_names_stmt = $pdo->query($field_names_sql);
    $field_names = $field_names_stmt->fetchAll(PDO::FETCH_COLUMN);

    $available_action_types = array_merge(
        ['all' => 'All Actions'],
        $action_types
    );

    // Group logs by month for sidebar
    $logs_by_month = [];
    foreach ($logs as $log) {
        $timestamp = $log['action_date'] ?? null;
        if (!$timestamp || $timestamp === '0000-00-00 00:00:00') continue;

        $year_month = date('Y-m', strtotime($timestamp));
        $month_name = date('F Y', strtotime($timestamp));

        if (!isset($logs_by_month[$year_month])) {
            $logs_by_month[$year_month] = [
                'month_year' => $month_name,
                'logs' => []
            ];
        }
        $logs_by_month[$year_month]['logs'][] = $log;
    }

    // Group logs by type for sidebar
    $logs_by_type = [];
    foreach ($logs as $log) {
        $type_key = $log['log_type'] ?? 'other';

        $type_names = [
            'creation' => 'Record Creation',
            'disposal_action' => 'Disposal Actions',
            'record_change' => 'Record Changes',
            'request_creation' => 'Request Creation',
            'other' => 'Other Actions'
        ];

        $type_name = $type_names[$type_key] ?? ucfirst(str_replace('_', ' ', $type_key));

        if (!isset($logs_by_type[$type_key])) {
            $logs_by_type[$type_key] = [
                'type' => $type_name,
                'logs' => []
            ];
        }
        $logs_by_type[$type_key]['logs'][] = $log;
    }

    // Group logs by user for sidebar
    $logs_by_user = [];
    foreach ($logs as $log) {
        $user_name = '';

        if (isset($log['first_name']) && isset($log['last_name'])) {
            $user_name = trim($log['first_name'] . ' ' . $log['last_name']);
        }

        if ($user_name && $user_name !== '' && $user_name !== 'Unknown' && $user_name !== 'System') {
            if (!isset($logs_by_user[$user_name])) {
                $logs_by_user[$user_name] = [
                    'user' => $user_name,
                    'logs' => []
                ];
            }
            $logs_by_user[$user_name]['logs'][] = $log;
        }
    }
} catch (PDOException $e) {
    error_log("Database error in reports.php: " . $e->getMessage());
    $error = "Database error occurred: " . $e->getMessage();
}

// Function to get CSS class for action type
function getActionClass($action_type)
{
    if (!$action_type) return 'status-pending';

    $lowerAction = strtolower($action_type);

    // Record creation
    if (strpos($lowerAction, 'create') !== false) return 'status-info';

    // Approval actions
    if (strpos($lowerAction, 'approve') !== false) return 'status-success';

    // Rejection actions
    if (strpos($lowerAction, 'reject') !== false) return 'status-failed';

    // Update/change actions
    if (
        strpos($lowerAction, 'update') !== false ||
        strpos($lowerAction, 'change') !== false ||
        strpos($lowerAction, 'edit') !== false
    ) return 'status-warning';

    // Completion actions
    if (strpos($lowerAction, 'complete') !== false) return 'status-success';

    // Archive actions
    if (strpos($lowerAction, 'archive') !== false) return 'status-archive';

    // Disposal actions
    if (strpos($lowerAction, 'disposal') !== false) return 'status-disposed';

    // Submission actions
    if (strpos($lowerAction, 'submit') !== false) return 'status-info';

    // Default
    return 'status-pending';
}

// Function to format date time display
function formatDateTimeDisplay($datetime)
{
    if (!$datetime || $datetime === '0000-00-00' || $datetime === '0000-00-00 00:00:00') return '-';
    return date('M d, Y h:i A', strtotime($datetime));
}

// Function to get user display name
function getUserDisplayName($log)
{
    $name = '';
    if (isset($log['first_name']) && isset($log['last_name'])) {
        $name = trim($log['first_name'] . ' ' . $log['last_name']);
    }

    if ($name && trim($name) !== '') {
        $display_name = htmlspecialchars($name);

        // Add role badge if admin
        if (isset($log['role_name']) && stripos($log['role_name'], 'admin') !== false) {
            $display_name .= ' <span class="role-badge admin">(Admin)</span>';
        }

        return $display_name;
    } else {
        return 'System';
    }
}

// Function to get user office display
function getUserOfficeDisplay($log)
{
    if (isset($log['user_office_name']) && $log['user_office_name']) {
        // Check if user is admin
        if (isset($log['role_name']) && stripos($log['role_name'], 'admin') !== false) {
            return 'Admin';
        }
        return htmlspecialchars($log['user_office_name']);
    }

    return 'N/A';
}

// Function to get action description
function getActionDescription($log)
{
    $action_type = $log['action_type'] ?? '';
    $record_title = $log['record_series_title'] ?? '';
    $agency_name = $log['agency_name'] ?? '';
    $field_name = $log['field_name'] ?? '';

    if ($log['log_type'] === 'creation') {
        return 'Record Created: ' . htmlspecialchars($record_title);
    } elseif ($log['log_type'] === 'disposal_action') {
        $desc = htmlspecialchars($action_type);
        if ($record_title) {
            $desc .= ' - ' . htmlspecialchars($record_title);
        } elseif ($agency_name) {
            $desc .= ' - ' . htmlspecialchars($agency_name);
        }
        return $desc;
    } elseif ($log['log_type'] === 'record_change') {
        $desc = 'Field Changed: ' . htmlspecialchars($field_name);
        if ($record_title) {
            $desc .= ' in ' . htmlspecialchars($record_title);
        }
        return $desc;
    } elseif ($log['log_type'] === 'request_creation') {
        return 'Disposal Request Created: ' . htmlspecialchars($agency_name);
    }

    return 'System Activity';
}

// Function to get icon for action type
function getActionIcon($log)
{
    $action_type = strtolower($log['action_type'] ?? '');

    if ($log['log_type'] === 'creation') {
        return 'fas fa-plus-circle';
    } elseif ($log['log_type'] === 'disposal_action') {
        if (strpos($action_type, 'approve') !== false) return 'fas fa-check-circle';
        if (strpos($action_type, 'reject') !== false) return 'fas fa-times-circle';
        if (strpos($action_type, 'create') !== false) return 'fas fa-plus-circle';
        if (strpos($action_type, 'update') !== false) return 'fas fa-edit';
        if (strpos($action_type, 'complete') !== false) return 'fas fa-check-circle';
        if (strpos($action_type, 'archive') !== false) return 'fas fa-archive';
        if (strpos($action_type, 'disposal') !== false) return 'fas fa-trash';
        if (strpos($action_type, 'submit') !== false) return 'fas fa-paper-plane';
        return 'fas fa-history';
    } elseif ($log['log_type'] === 'record_change') {
        return 'fas fa-exchange-alt';
    } elseif ($log['log_type'] === 'request_creation') {
        return 'fas fa-file-alt';
    }

    return 'fas fa-history';
}

// Function to get record link if available
function getRecordLink($log)
{
    if (isset($log['record_id']) && $log['record_id']) {
        return 'record_view.php?id=' . $log['record_id'];
    }
    return '#';
}

// Function to get display value for a field change
function getFieldDisplayValue($log)
{
    if (($log['log_type'] ?? '') !== 'record_change') return '';

    $field_type = $log['field_type'] ?? 'text';

    switch ($field_type) {
        case 'text':
            $old_val = $log['old_value_text'] ?: 'Empty';
            $new_val = $log['new_value_text'] ?: 'Empty';
            break;
        case 'number':
            $old_val = $log['old_value_int'] !== null ? $log['old_value_int'] : 'Empty';
            $new_val = $log['new_value_int'] !== null ? $log['new_value_int'] : 'Empty';
            break;
        case 'date':
            $old_val = $log['old_value_date'] ? date('Y-m-d', strtotime($log['old_value_date'])) : 'Empty';
            $new_val = $log['new_value_date'] ? date('Y-m-d', strtotime($log['new_value_date'])) : 'Empty';
            break;
        case 'enum':
            $old_val = $log['old_value_enum'] ?: 'Empty';
            $new_val = $log['new_value_enum'] ?: 'Empty';
            break;
        case 'foreign_key':
            $old_val = $log['old_reference_name'] ?: $log['old_reference_id'] ?: 'Empty';
            $new_val = $log['new_reference_name'] ?: $log['new_reference_id'] ?: 'Empty';
            break;
        default:
            $old_val = 'N/A';
            $new_val = 'N/A';
    }

    return htmlspecialchars($old_val) . ' <span class="arrow">→</span> ' . htmlspecialchars($new_val);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="../imgs/fav.png">
    <link rel="stylesheet" href="../styles/record_management.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <title>System Audit Logs</title>
    <style>
        /* Main container */
        .archive-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 25px;
            height: calc(100vh - 120px);
        }

        /* Folder Sidebar */
        .folders-sidebar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 20px;
            overflow-y: auto;
        }

        .folders-header {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }

        .folders-header h3 {
            margin: 0;
            color: #1f366c;
            font-size: 18px;
        }

        /* Folder Groups */
        .folder-group {
            margin-bottom: 25px;
        }

        .folder-group-title {
            font-size: 14px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .folder-group-title i {
            color: #1f366c;
        }

        .folders-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        /* Folder Items */
        .folder-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            background: white;
        }

        .folder-item:hover {
            background-color: #f5f5f5;
            border-color: #1f366c;
            transform: translateX(5px);
        }

        .folder-item.active {
            background-color: #1f366c;
            color: white;
            border-color: #1f366c;
            box-shadow: 0 4px 8px rgba(31, 54, 108, 0.2);
        }

        .folder-icon {
            font-size: 18px;
            margin-right: 12px;
            min-width: 24px;
            text-align: center;
        }

        .folder-item.active .folder-icon {
            color: white;
        }

        .folder-info {
            flex: 1;
        }

        .folder-name {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .folder-count {
            font-size: 12px;
            opacity: 0.7;
        }

        .folder-item.active .folder-count {
            color: rgba(255, 255, 255, 0.9);
        }

        /* Records Main Area */
        .records-main {
            display: flex;
            flex-direction: column;
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .current-folder-info h2 {
            margin: 0 0 5px 0;
            color: #1f366c;
            font-size: 24px;
        }

        .current-folder-info p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        /* Folder Content Display */
        .folder-content {
            flex: 1;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .folder-content-header {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
            background: #f8f9fa;
        }

        .folder-content-header h3 {
            margin: 0 0 5px 0;
            color: #1f366c;
        }

        .folder-content-header p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        .folder-records-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }

        /* Simplified table styling */
        .records-table-view {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .records-table-view th {
            background: #f8f9fa;
            color: #1f366c;
            font-weight: 600;
            padding: 12px 15px;
            border-bottom: 2px solid #dee2e6;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .records-table-view tbody tr {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .records-table-view tbody tr:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }

        .records-table-view td {
            padding: 15px;
            border: none;
            vertical-align: top;
            text-align: center;
        }

        /* Record badge */
        .record-badge {
            margin-bottom: 5px;
        }

        .record-id {
            display: inline-block;
            padding: 4px 8px;
            background: #1f366c;
            color: white;
            border-radius: 4px;
            font-weight: 600;
            font-size: 12px;
        }

        .record-code {
            font-size: 12px;
            color: #666;
            margin-top: 3px;
        }

        /* Record title */
        .record-title {
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .record-meta {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .meta-item {
            font-size: 11px;
            color: #666;
        }

        .meta-item i {
            width: 14px;
            margin-right: 4px;
            color: #1f366c;
        }

        /* Creator info */
        .creator-info {
            line-height: 1.4;
        }

        .creator-name {
            font-weight: 500;
            margin-bottom: 3px;
        }

        .creator-office {
            font-size: 12px;
            color: #666;
        }

        /* Status column improvements */
        .main-status {
            margin-bottom: 8px;
        }

        .sub-status {
            margin-bottom: 5px;
        }

        .status-indicator {
            font-weight: 500;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
        }

        .disposal-badge {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #ddd;
        }

        /* Action buttons */
        .view-details-btn,
        .close-details-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .view-details-btn {
            background: #1f366c;
            color: white;
        }

        .view-details-btn:hover {
            background: #152852;
        }

        /* Details panel improvements */
        .log-details-content {
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #1f366c;
        }

        .details-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #dee2e6;
        }

        .details-header h4 {
            margin: 0;
            color: #1f366c;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 20px;
        }

        .details-section {
            margin-bottom: 20px;
        }

        .details-section h5 {
            margin: 0 0 10px 0;
            color: #495057;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .details-item {
            margin-bottom: 8px;
            display: flex;
            align-items: flex-start;
        }

        .details-item label {
            font-weight: 600;
            color: #666;
            min-width: 120px;
            font-size: 12px;
            text-transform: uppercase;
        }

        .details-item span {
            flex: 1;
            font-size: 14px;
            color: #333;
            word-break: break-word;
        }

        .details-footer {
            text-align: right;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
        }

        .close-details-btn {
            background: #6c757d;
            color: white;
        }

        .close-details-btn:hover {
            background: #5a6268;
        }

        /* Status badge colors */
        .status-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .status-failed {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .status-archive {
            background-color: #d6dbdf;
            color: #424949;
            border: 1px solid #c1c7cd;
        }

        .status-disposed {
            background-color: #e8daef;
            color: #512e5f;
            border: 1px solid #dcc6e8;
        }

        .status-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .status-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        /* Status indicator (smaller version) */
        .status-indicator.status-success {
            background-color: rgba(212, 237, 218, 0.3);
            color: #155724;
        }

        .status-indicator.status-pending {
            background-color: rgba(255, 243, 205, 0.3);
            color: #856404;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #78909c;
        }

        .empty-state-icon {
            font-size: 64px;
            color: #cfd8dc;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            margin: 0 0 10px 0;
            color: #546e7a;
            font-size: 18px;
        }

        .empty-state p {
            margin: 0;
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
        }

        /* Scrollbar styling */
        .folders-sidebar::-webkit-scrollbar,
        .folder-records-container::-webkit-scrollbar {
            width: 6px;
        }

        .folders-sidebar::-webkit-scrollbar-track,
        .folder-records-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .folders-sidebar::-webkit-scrollbar-thumb,
        .folder-records-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }

        .folders-sidebar::-webkit-scrollbar-thumb:hover,
        .folder-records-container::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }

        /* Role badge */
        .role-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: 600;
        }

        .role-badge.admin {
            background-color: #1f366c;
            color: white;
        }

        /* Search/filter bar */
        .search-filter-bar {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        @media (min-width: 1200px) {
            .filter-form {
                grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
            }
        }

        @media (max-width: 1199px) and (min-width: 768px) {
            .filter-form {
                grid-template-columns: repeat(3, 1fr);
            }

            .filter-buttons {
                grid-column: span 3;
                justify-content: flex-end;
            }
        }

        @media (max-width: 767px) {
            .filter-form {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #666;
            margin-bottom: 5px;
            text-transform: uppercase;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .filter-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            width: 100%;
            box-sizing: border-box;
            height: 38px;
        }

        .filter-input::placeholder {
            color: #999;
            font-size: 13px;
        }

        .filter-button {
            padding: 8px 20px;
            background: #1f366c;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            white-space: nowrap;
        }

        .filter-button:hover {
            background: #152852;
        }

        .filter-button.reset {
            background: #6c757d;
        }

        .filter-button.reset:hover {
            background: #5a6268;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            height: 38px;
        }

        /* Date input styling */
        input[type="date"].filter-input {
            appearance: none;
            -webkit-appearance: none;
            background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%231f366c' viewBox='0 0 16 16'%3E%3Cpath d='M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z'/%3E%3C/svg%3E") no-repeat right 10px center;
            background-size: 16px;
            padding-right: 35px;
        }

        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            border: 1px solid #ddd;
            border-radius: 6px;
            height: 38px;
            padding: 4px;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 28px;
            font-size: 14px;
            color: #333;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow b {
            border-color: #666 transparent transparent transparent;
        }

        .select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b {
            border-color: transparent transparent #666 transparent;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #1f366c;
        }

        .select2-container--default .select2-results__option[aria-selected=true] {
            background-color: #f8f9fa;
            color: #1f366c;
        }

        /* Ensure all filter inputs have consistent appearance */
        #search_filter:focus,
        #date_from:focus,
        #date_to:focus,
        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #1f366c;
            box-shadow: 0 0 0 2px rgba(31, 54, 108, 0.1);
            outline: none;
        }

        /* Log details row */
        .log-details-row {
            display: none;
            background: #f9f9f9;
        }

        .text-danger {
            color: #dc3545 !important;
        }

        .text-success {
            color: #28a745 !important;
        }

        .text-warning {
            color: #ffc107 !important;
        }

        .text-primary {
            color: #1f366c !important;
        }

        .admin-office {
            color: #1f366c;
            font-weight: 600;
        }

        /* Value change display */
        .value-change {
            background: #f8f9fa;
            padding: 5px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            display: inline-block;
        }

        .arrow {
            color: #1f366c;
            margin: 0 5px;
            font-weight: bold;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .details-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        @media (max-width: 768px) {
            .archive-container {
                grid-template-columns: 1fr;
            }

            .folders-sidebar {
                max-height: 300px;
            }

            .records-table-view {
                display: block;
                overflow-x: auto;
            }

            .records-table-view th,
            .records-table_view td {
                white-space: nowrap;
            }
        }

        @media (max-width: 1400px) {
            .records-table-view {
                min-width: 1000px;
            }
        }
    </style>
</head>

<body>
    <nav class="sidebar">
        <?php include 'sidebar.php'; ?>
    </nav>
    <main>
        <header class="header">
            <div class="header-title-block">
                <h1>SYSTEM AUDIT LOGS</h1>
                <p>Track all system activities - record creation, changes, and disposal actions</p>
            </div>
        </header>

        <?php if (!empty($error)): ?>
            <div class="error-message"
                style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="archive-container">
            <!-- Folders Sidebar -->
            <div class="folders-sidebar">
                <div class="folders-header">
                    <h3>Log Categories</h3>
                </div>

                <!-- All Logs Folder -->
                <div class="folder-group">
                    <div class="folder-group-title">
                        <i class="fas fa-archive"></i>
                        <span>All Activities</span>
                    </div>
                    <div class="folders-list">
                        <div class="folder-item active" onclick="showAllLogs()" id="allLogsFolder">
                            <i class="fas fa-boxes folder-icon"></i>
                            <div class="folder-info">
                                <div class="folder-name">All System Activities</div>
                                <div class="folder-count"><?= $total_logs ?> entries</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Month Folders -->
                <?php if (!empty($logs_by_month)): ?>
                    <div class="folder-group">
                        <div class="folder-group-title">
                            <i class="fas fa-calendar-alt"></i>
                            <span>By Month</span>
                        </div>
                        <div class="folders-list">
                            <?php
                            krsort($logs_by_month);
                            foreach ($logs_by_month as $month_key => $month_data):
                                $log_count = count($month_data['logs']);
                                if ($log_count > 0):
                            ?>
                                    <div class="folder-item month-folder"
                                        onclick="showMonthLogs('<?= $month_key ?>', '<?= htmlspecialchars($month_data['month_year']) ?>')"
                                        data-month="<?= $month_key ?>">
                                        <i class="fas fa-folder folder-icon" style="color: #1976d2;"></i>
                                        <div class="folder-info">
                                            <div class="folder-name"><?= $month_data['month_year'] ?></div>
                                            <div class="folder-count"><?= $log_count ?> entries</div>
                                        </div>
                                    </div>
                            <?php endif;
                            endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Type Folders -->
                <?php if (!empty($logs_by_type)): ?>
                    <div class="folder-group">
                        <div class="folder-group-title">
                            <i class="fas fa-tags"></i>
                            <span>By Activity Type</span>
                        </div>
                        <div class="folders-list">
                            <?php foreach ($logs_by_type as $type_key => $type_data):
                                $log_count = count($type_data['logs']);
                                if ($log_count > 0):
                            ?>
                                    <div class="folder-item type-folder" onclick="showTypeLogs('<?= $type_key ?>')"
                                        data-type="<?= $type_key ?>">
                                        <i class="fas fa-folder folder-icon" style="color: #28a745;"></i>
                                        <div class="folder-info">
                                            <div class="folder-name"><?= htmlspecialchars($type_data['type']) ?></div>
                                            <div class="folder-count"><?= $log_count ?> entries</div>
                                        </div>
                                    </div>
                            <?php endif;
                            endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- User Folders -->
                <?php if (!empty($logs_by_user)): ?>
                    <div class="folder-group">
                        <div class="folder-group-title">
                            <i class="fas fa-users"></i>
                            <span>By User</span>
                        </div>
                        <div class="folders-list">
                            <?php foreach ($logs_by_user as $user_name => $user_data):
                                $log_count = count($user_data['logs']);
                                if ($log_count > 0): ?>
                                    <div class="folder-item user-folder" onclick="showUserLogs('<?= htmlspecialchars($user_name) ?>')"
                                        data-user="<?= htmlspecialchars($user_name) ?>">
                                        <i class="fas fa-folder folder-icon" style="color: #ff9800;"></i>
                                        <div class="folder-info">
                                            <div class="folder-name"><?= htmlspecialchars($user_name) ?></div>
                                            <div class="folder-count"><?= $log_count ?> entries</div>
                                        </div>
                                    </div>
                            <?php endif;
                            endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Logs Main Area -->
            <div class="records-main">
                <div class="header-actions">
                    <div class="current-folder-info">
                        <h2 id="currentFolderTitle">System Activities Timeline</h2>
                        <p id="currentFolderSubtitle">Track all record creation, changes, and disposal actions</p>
                    </div>
                </div>

                <!-- Search and Filter Bar -->
                <div class="search-filter-bar">
                    <form method="POST" class="filter-form" id="filterForm">
                        <!-- Search Field -->
                        <div class="filter-group">
                            <label for="search_filter">SEARCH</label>
                            <input type="text" id="search_filter" name="search_filter" class="filter-input"
                                placeholder="Search actions, users, records, agencies..."
                                value="<?= htmlspecialchars($search_filter) ?>">
                        </div>

                        <!-- Date From Field -->
                        <div class="filter-group">
                            <label for="date_from">DATE FROM</label>
                            <input type="date" id="date_from" name="date_from" class="filter-input"
                                value="<?= htmlspecialchars($date_from) ?>">
                        </div>

                        <!-- Date To Field -->
                        <div class="filter-group">
                            <label for="date_to">DATE TO</label>
                            <input type="date" id="date_to" name="date_to" class="filter-input"
                                value="<?= htmlspecialchars($date_to) ?>">
                        </div>

                        <!-- Action Type Filter Field -->
                        <div class="filter-group">
                            <label for="action_type">ACTION TYPE</label>
                            <select id="action_type" name="action_type" class="filter-input">
                                <option value="all">All Actions</option>
                                <optgroup label="Record Actions">
                                    <option value="RECORD_CREATED" <?= $action_type == 'RECORD_CREATED' ? 'selected' : '' ?>>Record Created</option>
                                    <option value="FIELD_CHANGED" <?= $action_type == 'FIELD_CHANGED' ? 'selected' : '' ?>>Field Changed</option>
                                    <option value="STATUS_CHANGED" <?= $action_type == 'STATUS_CHANGED' ? 'selected' : '' ?>>Status Changed</option>
                                </optgroup>
                                <optgroup label="Disposal Actions">
                                    <option value="REQUEST_CREATED" <?= $action_type == 'REQUEST_CREATED' ? 'selected' : '' ?>>Request Created</option>
                                    <?php
                                    // Display disposal action types
                                    $disposal_actions = ['REQUEST_SUBMIT', 'REQUEST_APPROVE', 'REQUEST_REJECT', 'DISPOSAL_COMPLETE', 'ARCHIVE_COMPLETE'];
                                    foreach ($disposal_actions as $action):
                                        if (in_array($action, $action_types)): ?>
                                            <option value="<?= htmlspecialchars($action) ?>" <?= $action_type == $action ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(str_replace('_', ' ', $action)) ?>
                                            </option>
                                    <?php endif;
                                    endforeach; ?>
                                </optgroup>
                                <?php
                                // Field changes
                                if (!empty($field_names)): ?>
                                    <optgroup label="Specific Field Changes">
                                        <?php foreach ($field_names as $field): ?>
                                            <option value="<?= htmlspecialchars($field) ?>" <?= $action_type == $field ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(str_replace('_', ' ', ucfirst(strtolower($field)))) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- Buttons -->
                        <div class="filter-buttons">
                            <button type="submit" class="filter-button">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <button type="button" class="filter-button reset" onclick="resetFilters()">
                                <i class="fas fa-redo"></i> Reset
                            </button>
                        </div>
                    </form>
                </div>

                <div class="folder-content">
                    <div class="folder-content-header">
                        <h3 id="folderContentTitle">Activities Timeline</h3>
                        <p id="folderContentSubtitle"><?= $total_logs ?> activity entries found</p>
                    </div>

                    <div class="folder-records-container">
                        <!-- Simplified Table -->
                        <table class="records-table-view">
                            <thead>
                                <tr>
                                    <th width="80">LOG #</th>
                                    <th width="150">ACTION</th>
                                    <th width="120">USER</th>
                                    <th width="120">DETAILS</th>
                                    <th width="80">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="logsTableBody">
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="5">
                                            <div class="empty-state">
                                                <div class="empty-state-icon">
                                                    <i class="fas fa-clipboard-list"></i>
                                                </div>
                                                <h3>No Activity Found</h3>
                                                <p><?= $search_filter || $date_from || $date_to || $action_type ?
                                                        'No activities found matching your filters.' :
                                                        'No system activity has been logged yet.' ?></p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log):
                                        $display_id = str_pad($log['display_id'], 5, '0', STR_PAD_LEFT);
                                        $action_class = getActionClass($log['action_type']);
                                        $user_name = getUserDisplayName($log);
                                        $user_office = getUserOfficeDisplay($log);
                                        $action_desc = getActionDescription($log);
                                        $icon = getActionIcon($log);
                                        $record_link = getRecordLink($log);
                                        $record_id = $log['record_id'] ?? null;
                                    ?>
                                        <tr id="log-row-<?= $log['display_id'] ?>">
                                            <!-- Log ID Column -->
                                            <td>
                                                <div class="record-badge">
                                                    <span class="record-id">L<?= $display_id ?></span>
                                                </div>
                                                <div class="record-code">
                                                    <?= htmlspecialchars(date('m/d/Y', strtotime($log['action_date']))) ?>
                                                </div>
                                            </td>

                                            <!-- Action Column -->
                                            <td>
                                                <div class="record-title">
                                                    <strong><?= $action_desc ?></strong>
                                                </div>
                                                <div class="record-meta">
                                                    <span class="meta-item">
                                                        <i class="fas fa-calendar"></i> <?= formatDateTimeDisplay($log['action_date']) ?>
                                                    </span>
                                                    <?php if ($log['record_series_title']): ?>
                                                        <span class="meta-item">
                                                            <i class="fas fa-file-alt"></i>
                                                            <?= $record_link !== '#' ?
                                                                '<a href="' . $record_link . '" target="_blank" style="color: #1f366c;">' .
                                                                htmlspecialchars($log['record_series_title']) . '</a>' :
                                                                htmlspecialchars($log['record_series_title']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($log['record_office_name']): ?>
                                                        <span class="meta-item">
                                                            <i class="fas fa-building"></i> <?= htmlspecialchars($log['record_office_name']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- User Column -->
                                            <td>
                                                <div class="creator-info">
                                                    <div class="creator-name">
                                                        <?= $user_name ?>
                                                    </div>
                                                    <div class="creator-office">
                                                        <small><?= htmlspecialchars($user_office) ?></small>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Details Column -->
                                            <td>
                                                <!-- Main Status Badge -->
                                                <div class="main-status">
                                                    <span class="status-badge <?= $action_class ?>">
                                                        <?= htmlspecialchars($log['action_type']) ?>
                                                    </span>
                                                </div>

                                                <!-- Status Change -->
                                                <?php if ($log['status_from'] && $log['status_to']): ?>
                                                    <div class="sub-status">
                                                        <small>
                                                            Status:
                                                            <span class="status-indicator <?= getActionClass($log['status_from']) ?>">
                                                                <?= htmlspecialchars($log['status_from']) ?>
                                                            </span>
                                                            <span class="arrow">→</span>
                                                            <span class="status-indicator <?= getActionClass($log['status_to']) ?>">
                                                                <?= htmlspecialchars($log['status_to']) ?>
                                                            </span>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Field Change -->
                                                <?php if ($log['log_type'] === 'record_change'): ?>
                                                    <div class="sub-status">
                                                        <small>
                                                            Changed: <?= htmlspecialchars($log['field_name']) ?>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Agency Info -->
                                                <?php if ($log['agency_name']): ?>
                                                    <div class="disposal-badge">
                                                        <small>
                                                            <i class="fas fa-building"></i>
                                                            <span class="text-primary"><?= htmlspecialchars($log['agency_name']) ?></span>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Actions Column -->
                                            <td>
                                                <button type="button" class="view-details-btn" onclick="toggleLogDetails(<?= $log['display_id'] ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </td>
                                        </tr>

                                        <!-- Details row -->
                                        <tr class="log-details-row" id="log-details-<?= $log['display_id'] ?>" style="display: none;">
                                            <td colspan="5">
                                                <div class="log-details-content">
                                                    <div class="details-header">
                                                        <h4>Activity Details: L<?= $display_id ?></h4>
                                                    </div>

                                                    <div class="details-grid">
                                                        <!-- Left Column -->
                                                        <div class="details-column">
                                                            <div class="details-section">
                                                                <h5><i class="fas fa-info-circle"></i> Activity Information</h5>
                                                                <div class="details-item">
                                                                    <label>Type:</label>
                                                                    <span class="status-badge <?= $action_class ?>">
                                                                        <?= htmlspecialchars($log['action_type']) ?>
                                                                    </span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Date & Time:</label>
                                                                    <span><?= formatDateTimeDisplay($log['action_date']) ?></span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Description:</label>
                                                                    <span><?= $action_desc ?></span>
                                                                </div>
                                                                <?php if ($log['log_type'] === 'record_change'): ?>
                                                                    <div class="details-item">
                                                                        <label>Field:</label>
                                                                        <span><?= htmlspecialchars($log['field_name']) ?></span>
                                                                    </div>
                                                                    <div class="details-item">
                                                                        <label>Change:</label>
                                                                        <span class="value-change">
                                                                            <?= getFieldDisplayValue($log) ?>
                                                                        </span>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>

                                                            <?php if ($log['record_series_title'] || $log['class_name']): ?>
                                                                <div class="details-section">
                                                                    <h5><i class="fas fa-file-alt"></i> Record Information</h5>
                                                                    <?php if ($log['record_series_title']): ?>
                                                                        <div class="details-item">
                                                                            <label>Title:</label>
                                                                            <span>
                                                                                <?= $record_link !== '#' ?
                                                                                    '<a href="' . $record_link . '" target="_blank" style="color: #1f366c;">' .
                                                                                    htmlspecialchars($log['record_series_title']) . '</a>' :
                                                                                    htmlspecialchars($log['record_series_title']) ?>
                                                                            </span>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <?php if ($log['record_series_code']): ?>
                                                                        <div class="details-item">
                                                                            <label>Code:</label>
                                                                            <span><?= htmlspecialchars($log['record_series_code']) ?></span>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <?php if ($log['record_office_name']): ?>
                                                                        <div class="details-item">
                                                                            <label>Office:</label>
                                                                            <span><?= htmlspecialchars($log['record_office_name']) ?></span>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <?php if ($log['class_name']): ?>
                                                                        <div class="details-item">
                                                                            <label>Classification:</label>
                                                                            <span><?= htmlspecialchars($log['class_name']) ?></span>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>

                                                        <!-- Right Column -->
                                                        <div class="details-column">
                                                            <div class="details-section">
                                                                <h5><i class="fas fa-user"></i> User Information</h5>
                                                                <div class="details-item">
                                                                    <label>Name:</label>
                                                                    <span><?= $user_name ?></span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Office:</label>
                                                                    <span><?= htmlspecialchars($user_office) ?></span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Email:</label>
                                                                    <span><?= htmlspecialchars($log['user_email'] ?? 'N/A') ?></span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Role:</label>
                                                                    <span><?= htmlspecialchars($log['role_name'] ?? 'N/A') ?></span>
                                                                </div>
                                                            </div>

                                                            <?php if ($log['status_from'] && $log['status_to']): ?>
                                                                <div class="details-section">
                                                                    <h5><i class="fas fa-exchange-alt"></i> Status Change</h5>
                                                                    <div class="details-item">
                                                                        <label>From:</label>
                                                                        <span class="status-badge <?= getActionClass($log['status_from']) ?>">
                                                                            <?= htmlspecialchars($log['status_from']) ?>
                                                                        </span>
                                                                    </div>
                                                                    <div class="details-item">
                                                                        <label>To:</label>
                                                                        <span class="status-badge <?= getActionClass($log['status_to']) ?>">
                                                                            <?= htmlspecialchars($log['status_to']) ?>
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>

                                                            <?php if ($log['agency_name']): ?>
                                                                <div class="details-section">
                                                                    <h5><i class="fas fa-trash-alt"></i> Disposal Request</h5>
                                                                    <div class="details-item">
                                                                        <label>Agency:</label>
                                                                        <span><?= htmlspecialchars($log['agency_name']) ?></span>
                                                                    </div>
                                                                    <?php if ($log['request_date']): ?>
                                                                        <div class="details-item">
                                                                            <label>Request Date:</label>
                                                                            <span><?= formatDateTimeDisplay($log['request_date']) ?></span>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <?php if ($log['request_status']): ?>
                                                                        <div class="details-item">
                                                                            <label>Request Status:</label>
                                                                            <span class="status-badge <?= getActionClass($log['request_status']) ?>">
                                                                                <?= htmlspecialchars($log['request_status']) ?>
                                                                            </span>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>

                                                            <?php if ($log['notes'] && trim($log['notes']) !== ''): ?>
                                                                <div class="details-section">
                                                                    <h5><i class="fas fa-sticky-note"></i> Notes</h5>
                                                                    <div class="details-item" style="flex-direction: column; align-items: flex-start;">
                                                                        <label>Details:</label>
                                                                        <span style="background: #f8f9fa; padding: 10px; border-radius: 4px; width: 100%;">
                                                                            <?= htmlspecialchars($log['notes']) ?>
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>

                                                    <div class="details-footer">
                                                        <button type="button" class="close-details-btn" onclick="toggleLogDetails(<?= $log['display_id'] ?>)">
                                                            <i class="fas fa-times"></i> Close
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Global variables - populated from PHP
        let currentFilter = 'all';
        let currentFilterId = null;
        let allLogs = <?= json_encode($logs) ?>;
        let logsByMonth = <?= json_encode($logs_by_month) ?>;
        let logsByType = <?= json_encode($logs_by_type) ?>;
        let logsByUser = <?= json_encode($logs_by_user) ?>;

        // Helper functions
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDateTimeDisplay(dateString) {
            if (!dateString || dateString === '0000-00-00' || dateString === '0000-00-00 00:00:00') return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            }) + ' ' + date.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function getActionClass(actionType) {
            if (!actionType) return 'status-pending';

            const lowerAction = actionType.toLowerCase();

            if (lowerAction.includes('create')) return 'status-info';
            if (lowerAction.includes('approve')) return 'status-success';
            if (lowerAction.includes('reject')) return 'status-failed';
            if (lowerAction.includes('update') || lowerAction.includes('change') || lowerAction.includes('edit'))
                return 'status-warning';
            if (lowerAction.includes('complete')) return 'status-success';
            if (lowerAction.includes('archive')) return 'status-archive';
            if (lowerAction.includes('disposal')) return 'status-disposed';
            if (lowerAction.includes('submit')) return 'status-info';

            return 'status-pending';
        }

        function getFieldDisplayValue(log) {
            if (log.log_type !== 'record_change') return '';

            const fieldType = log.field_type || 'text';

            let oldValue = '';
            let newValue = '';

            switch (fieldType) {
                case 'text':
                    oldValue = log.old_value_text || 'Empty';
                    newValue = log.new_value_text || 'Empty';
                    break;
                case 'number':
                    oldValue = log.old_value_int !== null ? log.old_value_int : 'Empty';
                    newValue = log.new_value_int !== null ? log.new_value_int : 'Empty';
                    break;
                case 'date':
                    oldValue = log.old_value_date ? formatDateTimeDisplay(log.old_value_date) : 'Empty';
                    newValue = log.new_value_date ? formatDateTimeDisplay(log.new_value_date) : 'Empty';
                    break;
                case 'enum':
                    oldValue = log.old_value_enum || 'Empty';
                    newValue = log.new_value_enum || 'Empty';
                    break;
                case 'foreign_key':
                    oldValue = log.old_reference_name || log.old_reference_id || 'Empty';
                    newValue = log.new_reference_name || log.new_reference_id || 'Empty';
                    break;
                default:
                    oldValue = 'N/A';
                    newValue = 'N/A';
            }

            return escapeHtml(oldValue) + ' <span class="arrow">→</span> ' + escapeHtml(newValue);
        }

        function getUserDisplayName(log) {
            let name = '';
            if (log.first_name && log.last_name) {
                name = log.first_name.trim() + ' ' + log.last_name.trim();
            }

            if (name && name.trim() !== '') {
                let displayName = escapeHtml(name);

                // Add role badge if admin
                if (log.role_name && log.role_name.toLowerCase().includes('admin')) {
                    displayName += ' <span class="role-badge admin">(Admin)</span>';
                }

                return displayName;
            } else {
                return 'System';
            }
        }

        function getUserOfficeDisplay(log) {
            if (log.user_office_name && log.user_office_name.trim() !== '') {
                // Check if user is admin
                if (log.role_name && log.role_name.toLowerCase().includes('admin')) {
                    return 'Admin';
                }
                return escapeHtml(log.user_office_name);
            }

            return 'N/A';
        }

        function getActionDescription(log) {
            const actionType = log.action_type || '';
            const recordTitle = log.record_series_title || '';
            const agencyName = log.agency_name || '';
            const fieldName = log.field_name || '';

            if (log.log_type === 'creation') {
                return 'Record Created: ' + escapeHtml(recordTitle);
            } else if (log.log_type === 'disposal_action') {
                let desc = escapeHtml(actionType);
                if (recordTitle) {
                    desc += ' - ' + escapeHtml(recordTitle);
                } else if (agencyName) {
                    desc += ' - ' + escapeHtml(agencyName);
                }
                return desc;
            } else if (log.log_type === 'record_change') {
                let desc = 'Field Changed: ' + escapeHtml(fieldName);
                if (recordTitle) {
                    desc += ' in ' + escapeHtml(recordTitle);
                }
                return desc;
            } else if (log.log_type === 'request_creation') {
                return 'Disposal Request Created: ' + escapeHtml(agencyName);
            }

            return 'System Activity';
        }

        function getRecordLink(log) {
            if (log.record_id && log.record_id.toString().trim() !== '') {
                return 'record_view.php?id=' + log.record_id;
            }
            return '#';
        }

        // Folder Filtering functions - FIXED VERSION
        function showAllLogs() {
            currentFilter = 'all';
            currentFilterId = null;

            updateActiveFolder('allLogsFolder');
            document.getElementById('currentFolderTitle').textContent = 'System Activities Timeline';
            document.getElementById('currentFolderSubtitle').textContent = 'Track all record creation, changes, and disposal actions';
            document.getElementById('folderContentTitle').textContent = 'Activities Timeline';

            // Use the allLogs array directly
            document.getElementById('folderContentSubtitle').textContent = allLogs.length + ' activity entries found';

            displayLogs(allLogs);
        }

        function showMonthLogs(monthKey, monthYear) {
            currentFilter = 'month';
            currentFilterId = monthKey;

            updateActiveFolder(null);
            const monthFolder = document.querySelector(`.folder-item[data-month="${monthKey}"]`);
            if (monthFolder) {
                monthFolder.classList.add('active');
            }

            document.getElementById('currentFolderTitle').textContent = monthYear + ' Activities';
            document.getElementById('currentFolderSubtitle').textContent = 'Activities from ' + monthYear;
            document.getElementById('folderContentTitle').textContent = monthYear + ' Activities';

            // Get logs for this month - check if month exists in logsByMonth
            const monthData = logsByMonth[monthKey];
            const monthLogs = monthData && monthData.logs ? monthData.logs : [];
            document.getElementById('folderContentSubtitle').textContent = monthLogs.length + ' activity entries found';
            displayLogs(monthLogs);
        }

        function showTypeLogs(typeKey) {
            currentFilter = 'type';
            currentFilterId = typeKey;

            updateActiveFolder(null);
            const typeFolder = document.querySelector(`.folder-item[data-type="${typeKey}"]`);
            if (typeFolder) {
                typeFolder.classList.add('active');
            }

            // Get type name
            const typeData = logsByType[typeKey];
            const typeName = typeData && typeData.type ? typeData.type : typeKey;

            document.getElementById('currentFolderTitle').textContent = typeName;
            document.getElementById('currentFolderSubtitle').textContent = 'Activities of type: ' + typeName;
            document.getElementById('folderContentTitle').textContent = typeName;

            // Get logs for this type
            const typeLogs = typeData && typeData.logs ? typeData.logs : [];
            document.getElementById('folderContentSubtitle').textContent = typeLogs.length + ' activity entries found';
            displayLogs(typeLogs);
        }

        function showUserLogs(username) {
            currentFilter = 'user';
            currentFilterId = username;

            updateActiveFolder(null);
            const userFolder = document.querySelector(`.folder-item[data-user="${username}"]`);
            if (userFolder) {
                userFolder.classList.add('active');
            }

            document.getElementById('currentFolderTitle').textContent = username + "'s Activities";
            document.getElementById('currentFolderSubtitle').textContent = 'Activities by user: ' + username;
            document.getElementById('folderContentTitle').textContent = username + "'s Activities";

            // Get logs for this user
            const userData = logsByUser[username];
            const userLogs = userData && userData.logs ? userData.logs : [];
            document.getElementById('folderContentSubtitle').textContent = userLogs.length + ' activity entries found';
            displayLogs(userLogs);
        }

        function updateActiveFolder(activeId) {
            document.querySelectorAll('.folder-item').forEach(item => {
                item.classList.remove('active');
            });

            if (activeId) {
                const activeElement = document.getElementById(activeId);
                if (activeElement) {
                    activeElement.classList.add('active');
                }
            }
        }

        // Display logs function - FIXED
        function displayLogs(logs) {
            const tbody = document.getElementById('logsTableBody');

            if (!logs || logs.length === 0) {
                tbody.innerHTML = `
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <h3>No Activity Found</h3>
                            <p>No activities found in this category.</p>
                        </div>
                    </td>
                </tr>
            `;
                return;
            }

            let html = '';

            // Use the logs array directly - they already have display_id from PHP
            logs.forEach((log) => {
                const displayId = String(log.display_id).padStart(5, '0');
                const actionClass = getActionClass(log.action_type);
                const userName = getUserDisplayName(log);
                const userOffice = getUserOfficeDisplay(log);
                const actionDesc = getActionDescription(log);
                const recordLink = getRecordLink(log);
                const displayDate = formatDateTimeDisplay(log.action_date);
                const shortDate = new Date(log.action_date).toLocaleDateString('en-US');

                html += `
                <tr id="log-row-${log.display_id}">
                    <td>
                        <div class="record-badge">
                            <span class="record-id">L${displayId}</span>
                        </div>
                        <div class="record-code">
                            ${escapeHtml(shortDate)}
                        </div>
                    </td>
                    <td>
                        <div class="record-title">
                            <strong>${actionDesc}</strong>
                        </div>
                        <div class="record-meta">
                            <span class="meta-item">
                                <i class="fas fa-calendar"></i> ${displayDate}
                            </span>
                            ${log.record_series_title ? `
                                <span class="meta-item">
                                    <i class="fas fa-file-alt"></i> 
                                    ${recordLink !== '#' ? 
                                        '<a href="' + recordLink + '" target="_blank" style="color: #1f366c;">' + 
                                        escapeHtml(log.record_series_title) + '</a>' : 
                                        escapeHtml(log.record_series_title)}
                                </span>
                            ` : ''}
                            ${log.record_office_name ? `
                                <span class="meta-item">
                                    <i class="fas fa-building"></i> ${escapeHtml(log.record_office_name)}
                                </span>
                            ` : ''}
                        </div>
                    </td>
                    <td>
                        <div class="creator-info">
                            <div class="creator-name">
                                ${userName}
                            </div>
                            <div class="creator-office">
                                <small>${escapeHtml(userOffice)}</small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="main-status">
                            <span class="status-badge ${actionClass}">
                                ${escapeHtml(log.action_type)}
                            </span>
                        </div>
                        ${log.status_from && log.status_to ? `
                            <div class="sub-status">
                                <small>
                                    Status: 
                                    <span class="status-indicator ${getActionClass(log.status_from)}">
                                        ${escapeHtml(log.status_from)}
                                    </span>
                                    <span class="arrow">→</span>
                                    <span class="status-indicator ${getActionClass(log.status_to)}">
                                        ${escapeHtml(log.status_to)}
                                    </span>
                                </small>
                            </div>
                        ` : ''}
                        ${log.log_type === 'record_change' ? `
                            <div class="sub-status">
                                <small>
                                    Changed: ${escapeHtml(log.field_name)}
                                </small>
                            </div>
                        ` : ''}
                        ${log.agency_name ? `
                            <div class="disposal-badge">
                                <small>
                                    <i class="fas fa-building"></i> 
                                    <span class="text-primary">${escapeHtml(log.agency_name)}</span>
                                </small>
                            </div>
                        ` : ''}
                    </td>
                    <td>
                        <button type="button" class="view-details-btn" onclick="toggleLogDetails(${log.display_id})">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </td>
                </tr>
                <tr class="log-details-row" id="log-details-${log.display_id}" style="display: none;">
                    <td colspan="5">
                        <div class="log-details-content">
                            <div class="details-header">
                                <h4>Activity Details: L${displayId}</h4>
                            </div>
                            <div class="details-grid">
                                <div class="details-column">
                                    <div class="details-section">
                                        <h5><i class="fas fa-info-circle"></i> Activity Information</h5>
                                        <div class="details-item">
                                            <label>Type:</label>
                                            <span class="status-badge ${actionClass}">
                                                ${escapeHtml(log.action_type)}
                                            </span>
                                        </div>
                                        <div class="details-item">
                                            <label>Date & Time:</label>
                                            <span>${displayDate}</span>
                                        </div>
                                        <div class="details-item">
                                            <label>Description:</label>
                                            <span>${actionDesc}</span>
                                        </div>
                                        ${log.log_type === 'record_change' ? `
                                            <div class="details-item">
                                                <label>Field:</label>
                                                <span>${escapeHtml(log.field_name)}</span>
                                            </div>
                                            <div class="details-item">
                                                <label>Change:</label>
                                                <span class="value-change">
                                                    ${getFieldDisplayValue(log)}
                                                </span>
                                            </div>
                                        ` : ''}
                                    </div>
                                    ${log.record_series_title || log.class_name ? `
                                        <div class="details-section">
                                            <h5><i class="fas fa-file-alt"></i> Record Information</h5>
                                            ${log.record_series_title ? `
                                                <div class="details-item">
                                                    <label>Title:</label>
                                                    <span>
                                                        ${recordLink !== '#' ? 
                                                            '<a href="' + recordLink + '" target="_blank" style="color: #1f366c;">' + 
                                                            escapeHtml(log.record_series_title) + '</a>' : 
                                                            escapeHtml(log.record_series_title)}
                                                    </span>
                                                </div>
                                            ` : ''}
                                            ${log.record_series_code ? `
                                                <div class="details-item">
                                                    <label>Code:</label>
                                                    <span>${escapeHtml(log.record_series_code)}</span>
                                                </div>
                                            ` : ''}
                                            ${log.record_office_name ? `
                                                <div class="details-item">
                                                    <label>Office:</label>
                                                    <span>${escapeHtml(log.record_office_name)}</span>
                                                </div>
                                            ` : ''}
                                            ${log.class_name ? `
                                                <div class="details-item">
                                                    <label>Classification:</label>
                                                    <span>${escapeHtml(log.class_name)}</span>
                                                </div>
                                            ` : ''}
                                        </div>
                                    ` : ''}
                                </div>
                                <div class="details-column">
                                    <div class="details-section">
                                        <h5><i class="fas fa-user"></i> User Information</h5>
                                        <div class="details-item">
                                            <label>Name:</label>
                                            <span>${userName}</span>
                                        </div>
                                        <div class="details-item">
                                            <label>Office:</label>
                                            <span>${escapeHtml(userOffice)}</span>
                                        </div>
                                        <div class="details-item">
                                            <label>Email:</label>
                                            <span>${escapeHtml(log.user_email || 'N/A')}</span>
                                        </div>
                                        <div class="details-item">
                                            <label>Role:</label>
                                            <span>${escapeHtml(log.role_name || 'N/A')}</span>
                                        </div>
                                    </div>
                                    ${log.status_from && log.status_to ? `
                                        <div class="details-section">
                                            <h5><i class="fas fa-exchange-alt"></i> Status Change</h5>
                                            <div class="details-item">
                                                <label>From:</label>
                                                <span class="status-badge ${getActionClass(log.status_from)}">
                                                    ${escapeHtml(log.status_from)}
                                                </span>
                                            </div>
                                            <div class="details-item">
                                                <label>To:</label>
                                                <span class="status-badge ${getActionClass(log.status_to)}">
                                                    ${escapeHtml(log.status_to)}
                                                </span>
                                            </div>
                                        </div>
                                    ` : ''}
                                    ${log.agency_name ? `
                                        <div class="details-section">
                                            <h5><i class="fas fa-trash-alt"></i> Disposal Request</h5>
                                            <div class="details-item">
                                                <label>Agency:</label>
                                                <span>${escapeHtml(log.agency_name)}</span>
                                            </div>
                                            ${log.request_date ? `
                                                <div class="details-item">
                                                    <label>Request Date:</label>
                                                    <span>${formatDateTimeDisplay(log.request_date)}</span>
                                                </div>
                                            ` : ''}
                                            ${log.request_status ? `
                                                <div class="details-item">
                                                    <label>Request Status:</label>
                                                    <span class="status-badge ${getActionClass(log.request_status)}">
                                                        ${escapeHtml(log.request_status)}
                                                    </span>
                                                </div>
                                            ` : ''}
                                        </div>
                                    ` : ''}
                                    ${log.notes && log.notes.trim() !== '' ? `
                                        <div class="details-section">
                                            <h5><i class="fas fa-sticky-note"></i> Notes</h5>
                                            <div class="details-item" style="flex-direction: column; align-items: flex-start;">
                                                <label>Details:</label>
                                                <span style="background: #f8f9fa; padding: 10px; border-radius: 4px; width: 100%;">
                                                    ${escapeHtml(log.notes)}
                                                </span>
                                            </div>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                            <div class="details-footer">
                                <button type="button" class="close-details-btn" onclick="toggleLogDetails(${log.display_id})">
                                    <i class="fas fa-times"></i> Close
                                </button>
                            </div>
                        </div>
                    </td>
                </tr>
            `;
            });

            tbody.innerHTML = html;
        }

        // Toggle log details view
        function toggleLogDetails(logId) {
            const detailsRow = document.getElementById(`log-details-${logId}`);
            if (!detailsRow) return;

            const isVisible = detailsRow.style.display === 'table-row';

            // Close all other detail rows
            document.querySelectorAll('.log-details-row').forEach(row => {
                row.style.display = 'none';
            });

            // Toggle current row
            detailsRow.style.display = isVisible ? 'none' : 'table-row';

            // Smooth scroll to details if opening
            if (!isVisible) {
                setTimeout(() => {
                    detailsRow.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest'
                    });
                }, 100);
            }
        }

        // Reset all filters
        function resetFilters() {
            document.getElementById('filterForm').reset();
            window.location.href = 'reports.php';
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Select2 for action type filter
            $('#action_type').select2({
                placeholder: "All Actions",
                allowClear: true,
                width: '100%',
                minimumResultsForSearch: 10,
                dropdownParent: $('#filterForm')
            });

            // Set date range limits
            const today = new Date().toISOString().split('T')[0];
            const dateToInput = document.getElementById('date_to');
            const dateFromInput = document.getElementById('date_from');

            if (dateToInput) dateToInput.max = today;
            if (dateFromInput) dateFromInput.max = today;

            // Set default date values if empty
            if (!dateFromInput.value) {
                const sevenDaysAgo = new Date();
                sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 30);
                dateFromInput.value = sevenDaysAgo.toISOString().split('T')[0];
            }

            if (!dateToInput.value) {
                dateToInput.value = today;
            }

            // Handle date validation
            if (dateFromInput) {
                dateFromInput.addEventListener('change', function() {
                    if (dateToInput && this.value > dateToInput.value) {
                        dateToInput.value = this.value;
                    }
                });
            }

            if (dateToInput) {
                dateToInput.addEventListener('change', function() {
                    if (dateFromInput && this.value < dateFromInput.value) {
                        dateFromInput.value = this.value;
                    }
                });
            }

            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl + F to focus search
                if (e.ctrlKey && e.key === 'f') {
                    e.preventDefault();
                    const searchInput = document.getElementById('search_filter');
                    if (searchInput) searchInput.focus();
                }

                // Esc to clear search
                if (e.key === 'Escape') {
                    const searchInput = document.getElementById('search_filter');
                    if (document.activeElement === searchInput && searchInput && searchInput.value) {
                        searchInput.value = '';
                        searchInput.focus();
                    }
                }

                // Ctrl + R to reset filters
                if (e.ctrlKey && e.key === 'r') {
                    e.preventDefault();
                    resetFilters();
                }
            });

            // Debug: Check if data is loaded
            console.log('All logs loaded:', allLogs.length);
            console.log('Logs by month:', Object.keys(logsByMonth).length);
            console.log('Logs by type:', Object.keys(logsByType).length);
            console.log('Logs by user:', Object.keys(logsByUser).length);

            // Initialize with "All Logs" selected
            showAllLogs();
        });
    </script>
</body>

</html>