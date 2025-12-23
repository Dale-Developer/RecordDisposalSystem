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
    $sql = "SELECT 
                -- Record Information
                r.record_id,
                r.record_series_code,
                r.record_series_title,
                r.office_id,
                o.office_name,
                r.class_id,
                rc.class_name,
                r.period_from,
                r.period_to,
                r.active_years,
                r.storage_years,
                r.total_years,
                r.retention_period_id,
                rp.period_name,
                r.disposition_type,
                r.status as record_status,
                r.date_created as record_created_date,
                r.created_at as record_db_created_at,
                r.updated_at as record_last_updated,
                r.created_by as created_by_user_id,
                
                -- Basic Creator Information
                creator.user_id as creator_user_id,
                CONCAT(creator.first_name, ' ', creator.last_name) as creator_name,
                creator.email as creator_email,
                creator_role.role_name as creator_role,
                creator_office.office_name as creator_office_name,
                
                -- Disposal Request Information
                dr.request_id as disposal_request_id,
                dr.agency_name as disposal_agency_name,
                dr.request_date as disposal_request_date,
                dr.status as disposal_request_status,
                dr.created_at as disposal_created_at,
                dr.requested_by as disposal_created_by,
                dr.approved_by as disposal_approved_by,
                dr.approved_at as disposal_approved_at,
                dr.rejected_by as disposal_rejected_by,
                dr.rejected_at as disposal_rejected_at,
                dr.remarks as disposal_remarks,
                
                -- Basic Disposal Requester Information
                disposal_requester.user_id as disposal_requester_id,
                CONCAT(disposal_requester.first_name, ' ', disposal_requester.last_name) as disposal_creator_name,
                disposal_requester.email as disposal_creator_email,
                disposal_requester_role.role_name as disposal_creator_role,
                disposal_requester_office.office_name as disposal_creator_office,
                
                -- Disposal Approver Information (from disposal_requests table)
                approver.user_id as approver_user_id,
                CONCAT(approver.first_name, ' ', approver.last_name) as approver_name,
                approver.email as approver_email,
                approver_role.role_name as approver_role,
                approver_office.office_name as approver_office,
                
                -- Disposal Rejector Information (from disposal_requests table)
                rejector.user_id as rejector_user_id,
                CONCAT(rejector.first_name, ' ', rejector.last_name) as rejector_name,
                rejector.email as rejector_email,
                rejector_role.role_name as rejector_role,
                rejector_office.office_name as rejector_office,
                
                -- Retention Calculation
                CASE 
                    WHEN r.period_to IS NOT NULL AND r.period_to <= CURDATE() THEN 'Retention Period Reached'
                    WHEN r.status = 'Archived' THEN 'Archived'
                    WHEN r.status = 'Disposed' THEN 'Disposed'
                    ELSE 'Active'
                END as retention_status,
                
                -- Retention End Date
                DATE_ADD(r.period_to, INTERVAL r.total_years YEAR) as retention_end_date
                
            FROM records r
            
            -- Record joins
            LEFT JOIN offices o ON r.office_id = o.office_id
            LEFT JOIN record_classification rc ON r.class_id = rc.class_id
            LEFT JOIN retention_periods rp ON r.retention_period_id = rp.period_id
            
            -- Creator information
            LEFT JOIN users creator ON r.created_by = creator.user_id
            LEFT JOIN roles creator_role ON creator.role_id = creator_role.role_id
            LEFT JOIN offices creator_office ON creator.office_id = creator_office.office_id
            
            -- Disposal request joins
            LEFT JOIN disposal_request_details drd ON r.record_id = drd.record_id
            LEFT JOIN disposal_requests dr ON drd.request_id = dr.request_id
            LEFT JOIN users disposal_requester ON dr.requested_by = disposal_requester.user_id
            LEFT JOIN roles disposal_requester_role ON disposal_requester.role_id = disposal_requester_role.role_id
            LEFT JOIN offices disposal_requester_office ON disposal_requester.office_id = disposal_requester_office.office_id
            
            -- Approver information (from disposal_requests)
            LEFT JOIN users approver ON dr.approved_by = approver.user_id
            LEFT JOIN roles approver_role ON approver.role_id = approver_role.role_id
            LEFT JOIN offices approver_office ON approver.office_id = approver_office.office_id
            
            -- Rejector information (from disposal_requests)
            LEFT JOIN users rejector ON dr.rejected_by = rejector.user_id
            LEFT JOIN roles rejector_role ON rejector.role_id = rejector_role.role_id
            LEFT JOIN offices rejector_office ON rejector.office_id = rejector_office.office_id
            
            WHERE 1=1";

    $params = [];

    // Apply filters
    if ($search_filter) {
        $sql .= " AND (
                    r.record_series_title LIKE ? OR 
                    r.record_series_code LIKE ? OR
                    rc.class_name LIKE ? OR
                    o.office_name LIKE ? OR
                    creator.first_name LIKE ? OR
                    creator.last_name LIKE ? OR
                    creator.email LIKE ? OR
                    disposal_requester.first_name LIKE ? OR
                    disposal_requester.last_name LIKE ? OR
                    approver.first_name LIKE ? OR
                    approver.last_name LIKE ? OR
                    rejector.first_name LIKE ? OR
                    rejector.last_name LIKE ?
                )";
        $search_param = "%$search_filter%";
        $params = array_merge($params, array_fill(0, 13, $search_param));
    }

    if ($date_from) {
        $sql .= " AND (
                    DATE(r.date_created) >= ? OR 
                    DATE(dr.request_date) >= ? OR
                    DATE(dr.approved_at) >= ? OR
                    DATE(dr.rejected_at) >= ?
                )";
        $params[] = $date_from;
        $params[] = $date_from;
        $params[] = $date_from;
        $params[] = $date_from;
    }

    if ($date_to) {
        $sql .= " AND (
                    DATE(r.date_created) <= ? OR 
                    DATE(dr.request_date) <= ? OR
                    DATE(dr.approved_at) <= ? OR
                    DATE(dr.rejected_at) <= ?
                )";
        $params[] = $date_to;
        $params[] = $date_to;
        $params[] = $date_to;
        $params[] = $date_to;
    }

    if ($action_type) {
        // Filter by record status or request status
        if ($action_type === 'Active') {
            $sql .= " AND r.status = 'Active'";
        } elseif ($action_type === 'Retention Reached') {
            $sql .= " AND (r.period_to IS NOT NULL AND r.period_to <= CURDATE())";
        } elseif ($action_type === 'Archived') {
            $sql .= " AND r.status = 'Archived'";
        } elseif ($action_type === 'Disposed') {
            $sql .= " AND r.status = 'Disposed'";
        } elseif (in_array($action_type, ['Pending', 'Approved', 'Rejected'])) {
            $sql .= " AND dr.status = ?";
            $params[] = $action_type;
        }
    }

    // Group by to avoid duplicate rows
    $sql .= " GROUP BY r.record_id";
    $sql .= " ORDER BY r.record_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_logs = count($logs);

    // Get unique action types for filter dropdown
    $available_action_types = [
        'Active',
        'Retention Reached',
        'Archived',
        'Disposed',
        'Pending',
        'Approved',
        'Rejected'
    ];

    // Group logs by month for sidebar
    $logs_by_month = [];
    foreach ($logs as $log) {
        $year_month = date('Y-m', strtotime($log['record_created_date']));
        if (!isset($logs_by_month[$year_month])) {
            $logs_by_month[$year_month] = [
                'month_year' => date('F Y', strtotime($log['record_created_date'])),
                'logs' => []
            ];
        }
        $logs_by_month[$year_month]['logs'][] = $log;
    }

    // Group logs by type for sidebar
    $logs_by_type = [];
    foreach ($logs as $log) {
        $log_type = $log['retention_status'];
        if ($log_type) {
            if (!isset($logs_by_type[$log_type])) {
                $logs_by_type[$log_type] = [
                    'type' => $log_type,
                    'logs' => []
                ];
            }
            $logs_by_type[$log_type]['logs'][] = $log;
        }
    }

    // Group logs by user for sidebar
    $logs_by_user = [];
    foreach ($logs as $log) {
        $user_name = $log['creator_name'] ?? 'Unknown';
        if ($user_name && trim($user_name) !== '' && $user_name !== 'Unknown') {
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

// Function to get CSS class for status
function getStatusClass($status)
{
    if (!$status) return 'status-pending';

    $lowerStatus = strtolower($status);
    if ($lowerStatus === 'active' || $lowerStatus === 'approved') return 'status-success';
    if ($lowerStatus === 'inactive' || $lowerStatus === 'pending' || $lowerStatus === 'scheduled for disposal') return 'status-pending';
    if ($lowerStatus === 'rejected') return 'status-failed';
    if ($lowerStatus === 'archived') return 'status-archive';
    if ($lowerStatus === 'disposed') return 'status-disposed';
    if ($lowerStatus === 'retention period reached') return 'status-warning';
    return 'status-pending';
}

// Function to format date display
function formatDateDisplay($date)
{
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') return '-';
    return date('Y-m-d', strtotime($date));
}

// Function to get user office display (shows "Admin" for admin users)
function getUserOfficeDisplay($log, $user_type = 'creator')
{
    $role_field = '';
    $office_field = '';

    // Map user type to correct field names
    switch ($user_type) {
        case 'creator':
            $role_field = 'creator_role';
            $office_field = 'creator_office_name';
            break;
        case 'disposal_creator':
            $role_field = 'disposal_creator_role';
            $office_field = 'disposal_creator_office';
            break;
        case 'approver':
            $role_field = 'approver_role';
            $office_field = 'approver_office';
            break;
        case 'rejector':
            $role_field = 'rejector_role';
            $office_field = 'rejector_office';
            break;
        default:
            $role_field = 'creator_role';
            $office_field = 'creator_office_name';
    }

    // Check if role contains "admin"
    if (isset($log[$role_field]) && stripos($log[$role_field], 'admin') !== false) {
        return 'Admin';
    }

    // Return office name or 'N/A'
    return $log[$office_field] ?? 'N/A';
}

// Function to get display name with role indicator
function getUserDisplayName($log, $user_type = 'creator')
{
    $name_field = '';
    $role_field = '';

    // Map user type to correct field names
    switch ($user_type) {
        case 'creator':
            $name_field = 'creator_name';
            $role_field = 'creator_role';
            break;
        case 'disposal_creator':
            $name_field = 'disposal_creator_name';
            $role_field = 'disposal_creator_role';
            break;
        case 'approver':
            $name_field = 'approver_name';
            $role_field = 'approver_role';
            break;
        case 'rejector':
            $name_field = 'rejector_name';
            $role_field = 'rejector_role';
            break;
        default:
            $name_field = 'creator_name';
            $role_field = 'creator_role';
    }

    $name = $log[$name_field] ?? '';
    $role = $log[$role_field] ?? null;

    if (!$name || trim($name) === '') {
        return 'Unknown';
    }

    // Add role indicator if admin
    if ($role && stripos($role, 'admin') !== false) {
        return htmlspecialchars($name) . ' <span class="role-badge admin">(Admin)</span>';
    }

    return htmlspecialchars($name);
}

// Function to get action user display name (for approver/rejector)
function getActionUserDisplayName($log)
{
    $action_type = $log['disposal_request_status'] ?? '';
    
    if ($action_type === 'Approved') {
        $name = $log['approver_name'] ?? '';
        $role = $log['approver_role'] ?? null;

        if (!$name || trim($name) === '') {
            return 'N/A';
        }

        // Add role indicator if admin
        if ($role && stripos($role, 'admin') !== false) {
            return htmlspecialchars($name) . ' <span class="role-badge admin">(Admin)</span>';
        }

        return htmlspecialchars($name);
    } elseif ($action_type === 'Rejected') {
        $name = $log['rejector_name'] ?? '';
        $role = $log['rejector_role'] ?? null;

        if (!$name || trim($name) === '') {
            return 'N/A';
        }

        // Add role indicator if admin
        if ($role && stripos($role, 'admin') !== false) {
            return htmlspecialchars($name) . ' <span class="role-badge admin">(Admin)</span>';
        }

        return htmlspecialchars($name);
    }
    
    return 'N/A';
}

// Function to get action user office display
function getActionUserOfficeDisplay($log)
{
    $action_type = $log['disposal_request_status'] ?? '';
    
    if ($action_type === 'Approved') {
        $role_field = 'approver_role';
        $office_field = 'approver_office';

        // Check if role contains "admin"
        if (isset($log[$role_field]) && stripos($log[$role_field], 'admin') !== false) {
            return 'Admin';
        }

        // Return office name or 'N/A'
        return $log[$office_field] ?? 'N/A';
    } elseif ($action_type === 'Rejected') {
        $role_field = 'rejector_role';
        $office_field = 'rejector_office';

        // Check if role contains "admin"
        if (isset($log[$role_field]) && stripos($log[$role_field], 'admin') !== false) {
            return 'Admin';
        }

        // Return office name or 'N/A'
        return $log[$office_field] ?? 'N/A';
    }
    
    return 'N/A';
}

// Function to get action date
function getActionDate($log)
{
    $action_type = $log['disposal_request_status'] ?? '';
    
    if ($action_type === 'Approved') {
        return $log['disposal_approved_at'] ?? null;
    } elseif ($action_type === 'Rejected') {
        return $log['disposal_rejected_at'] ?? null;
    }
    
    return null;
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
    <title>Report & Logs</title>
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

        .retention-warning,
        .retention-info {
            margin-top: 5px;
        }

        .disposal-badge {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #ddd;
        }

        /* Action buttons */
        .view-details-btn,
        .request-details-btn,
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

        .request-details-btn {
            background: #f8f9fa;
            color: #1f366c;
            border: 1px solid #ddd;
            margin-left: 5px;
        }

        .request-details-btn:hover {
            background: #e9ecef;
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
                <h1>RECORDS & REQUESTS REPORT</h1>
                <p>Showing records with creator information</p>
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
                    <h3>Report Categories</h3>
                </div>

                <!-- All Logs Folder -->
                <div class="folder-group">
                    <div class="folder-group-title">
                        <i class="fas fa-archive"></i>
                        <span>All Records</span>
                    </div>
                    <div class="folders-list">
                        <div class="folder-item active" onclick="showAllLogs()" id="allLogsFolder">
                            <i class="fas fa-boxes folder-icon"></i>
                            <div class="folder-info">
                                <div class="folder-name">All Records & Requests</div>
                                <div class="folder-count"><?= $total_logs ?> records</div>
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
                                $record_count = count($month_data['logs']);
                                if ($record_count > 0):
                            ?>
                                    <div class="folder-item month-folder"
                                        onclick="showMonthLogs('<?= $month_key ?>', '<?= htmlspecialchars($month_data['month_year']) ?>')"
                                        data-month="<?= $month_key ?>">
                                        <i class="fas fa-folder folder-icon" style="color: #1976d2;"></i>
                                        <div class="folder-info">
                                            <div class="folder-name"><?= $month_data['month_year'] ?></div>
                                            <div class="folder-count"><?= $record_count ?> records</div>
                                        </div>
                                    </div>
                            <?php endif;
                            endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Status Folders -->
                <?php if (!empty($logs_by_type)): ?>
                    <div class="folder-group">
                        <div class="folder-group-title">
                            <i class="fas fa-tags"></i>
                            <span>By Status</span>
                        </div>
                        <div class="folders-list">
                            <?php foreach ($logs_by_type as $type => $type_data):
                                $record_count = count($type_data['logs']);
                                if ($record_count > 0):
                                    $display_name = ucwords(strtolower($type));
                            ?>
                                    <div class="folder-item type-folder" onclick="showTypeLogs('<?= htmlspecialchars($type) ?>')"
                                        data-type="<?= htmlspecialchars($type) ?>">
                                        <i class="fas fa-folder folder-icon" style="color: #28a745;"></i>
                                        <div class="folder-info">
                                            <div class="folder-name"><?= htmlspecialchars($display_name) ?></div>
                                            <div class="folder-count"><?= $record_count ?> records</div>
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
                            <span>By Creator</span>
                        </div>
                        <div class="folders-list">
                            <?php foreach ($logs_by_user as $user_name => $user_data):
                                $record_count = count($user_data['logs']);
                                if ($record_count > 0): ?>
                                    <div class="folder-item user-folder" onclick="showUserLogs('<?= htmlspecialchars($user_name) ?>')"
                                        data-user="<?= htmlspecialchars($user_name) ?>">
                                        <i class="fas fa-folder folder-icon" style="color: #ff9800;"></i>
                                        <div class="folder-info">
                                            <div class="folder-name"><?= htmlspecialchars($user_name) ?></div>
                                            <div class="folder-count"><?= $record_count ?> records</div>
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
                        <h2 id="currentFolderTitle">All Records & Requests</h2>
                        <p id="currentFolderSubtitle">Browse records with creator info, disposal requests, and retention status</p>
                    </div>
                </div>

                <!-- Search and Filter Bar -->
                <div class="search-filter-bar">
                    <form method="POST" class="filter-form" id="filterForm">
                        <!-- Search Field -->
                        <div class="filter-group">
                            <label for="search_filter">SEARCH</label>
                            <input type="text" id="search_filter" name="search_filter" class="filter-input"
                                placeholder="Search records, creators, agencies..."
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

                        <!-- Status Filter Field -->
                        <div class="filter-group">
                            <label for="action_type">STATUS FILTER</label>
                            <select id="action_type" name="action_type" class="filter-input">
                                <option value="">All Status</option>
                                <?php foreach ($available_action_types as $type):
                                    if (!empty($type)): ?>
                                        <option value="<?= htmlspecialchars($type) ?>" <?= $action_type == $type ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($type) ?>
                                        </option>
                                <?php endif;
                                endforeach; ?>
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
                        <h3 id="folderContentTitle">Records & Requests</h3>
                        <p id="folderContentSubtitle"><?= $total_logs ?> records found</p>
                    </div>

                    <div class="folder-records-container">
                        <!-- Simplified Table -->
                        <table class="records-table-view">
                            <thead>
                                <tr>
                                    <th width="100">RECORD</th>
                                    <th width="150">BASIC INFO</th>
                                    <th width="120">CREATOR</th>
                                    <th width="120">STATUS</th>
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
                                                <h3>No Records Found</h3>
                                                <p><?= $search_filter || $date_from || $date_to || $action_type ?
                                                        'No records found matching your filters.' :
                                                        'No records have been created yet.' ?></p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log):
                                        $record_status_class = getStatusClass($log['record_status']);
                                        $retention_status_class = getStatusClass($log['retention_status']);
                                        $record_id_padded = str_pad($log['record_id'], 5, '0', STR_PAD_LEFT);
                                        $creator_name = getUserDisplayName($log, 'creator');
                                        $creator_office = getUserOfficeDisplay($log, 'creator');
                                        
                                        // Determine main status display
                                        if (!empty($log['disposal_request_status'])) {
                                            $main_status = $log['disposal_request_status'];
                                            $main_status_class = getStatusClass($main_status);
                                        } else {
                                            $main_status = $log['retention_status'];
                                            $main_status_class = $retention_status_class;
                                        }
                                    ?>
                                        <tr id="log-row-<?= $log['record_id'] ?>">
                                            <!-- Record Column -->
                                            <td>
                                                <div class="record-badge">
                                                    <span class="record-id">R<?= $record_id_padded ?></span>
                                                </div>
                                                <div class="record-code">
                                                    <?= htmlspecialchars($log['record_series_code']) ?>
                                                </div>
                                            </td>
                                            
                                            <!-- Basic Info Column -->
                                            <td>
                                                <div class="record-title">
                                                    <strong><?= htmlspecialchars($log['record_series_title']) ?></strong>
                                                </div>
                                                <div class="record-meta">
                                                    <span class="meta-item">
                                                        <i class="fas fa-building"></i> <?= htmlspecialchars($log['office_name']) ?>
                                                    </span>
                                                    <span class="meta-item">
                                                        <i class="fas fa-calendar"></i> <?= formatDateDisplay($log['record_created_date']) ?>
                                                    </span>
                                                    <span class="meta-item">
                                                        <i class="fas fa-tag"></i> <?= htmlspecialchars($log['class_name']) ?>
                                                    </span>
                                                </div>
                                            </td>
                                            
                                            <!-- Creator Column -->
                                            <td>
                                                <div class="creator-info">
                                                    <div class="creator-name">
                                                        <?= $creator_name ?>
                                                    </div>
                                                    <div class="creator-office">
                                                        <small><?= htmlspecialchars($creator_office) ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            
                                            <!-- Status Column -->
                                            <td>
                                                <!-- Main Status Badge -->
                                                <div class="main-status">
                                                    <span class="status-badge <?= $main_status_class ?>">
                                                        <?= htmlspecialchars($main_status) ?>
                                                    </span>
                                                </div>
                                                
                                                <!-- Record Status -->
                                                <div class="sub-status">
                                                    <small>
                                                        Record: 
                                                        <span class="status-indicator <?= $record_status_class ?>">
                                                            <?= htmlspecialchars($log['record_status']) ?>
                                                        </span>
                                                    </small>
                                                </div>
                                                
                                                <!-- Retention Info -->
                                                <?php if (!empty($log['retention_end_date'])): 
                                                    $end_date = formatDateDisplay($log['retention_end_date']);
                                                    $today = date('Y-m-d');
                                                    if ($end_date < $today): ?>
                                                        <div class="retention-warning">
                                                            <small><i class="fas fa-exclamation-triangle text-danger"></i> Ended: <?= $end_date ?></small>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="retention-info">
                                                            <small><i class="fas fa-clock"></i> Ends: <?= $end_date ?></small>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <!-- Disposal Request Badge -->
                                                <?php if (!empty($log['disposal_agency_name'])): ?>
                                                    <div class="disposal-badge">
                                                        <small>
                                                            <i class="fas fa-trash"></i> 
                                                            <span class="text-primary">Disposal Requested</span>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <!-- Actions Column -->
                                            <td>
                                                <button type="button" class="view-details-btn" onclick="toggleLogDetails(<?= $log['record_id'] ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                        
                                        <!-- Details row -->
                                        <tr class="log-details-row" id="log-details-<?= $log['record_id'] ?>" style="display: none;">
                                            <td colspan="5">
                                                <div class="log-details-content">
                                                    <div class="details-header">
                                                        <h4>Record Details: R<?= $record_id_padded ?></h4>
                                                    </div>
                                                    
                                                    <div class="details-grid">
                                                        <!-- Left Column -->
                                                        <div class="details-column">
                                                            <div class="details-section">
                                                                <h5><i class="fas fa-info-circle"></i> Record Information</h5>
                                                                <div class="details-item">
                                                                    <label>Title:</label>
                                                                    <span><?= htmlspecialchars($log['record_series_title']) ?></span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Code:</label>
                                                                    <span><?= htmlspecialchars($log['record_series_code']) ?></span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Office:</label>
                                                                    <span><?= htmlspecialchars($log['office_name']) ?></span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Classification:</label>
                                                                    <span><?= htmlspecialchars($log['class_name']) ?></span>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="details-section">
                                                                <h5><i class="fas fa-user"></i> Creator Information</h5>
                                                                <div class="details-item">
                                                                    <label>Name:</label>
                                                                    <span><?= $creator_name ?></span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Office:</label>
                                                                    <span><?= htmlspecialchars($creator_office) ?></span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Created:</label>
                                                                    <span><?= formatDateDisplay($log['record_created_date']) ?></span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <!-- Right Column -->
                                                        <div class="details-column">
                                                            <div class="details-section">
                                                                <h5><i class="fas fa-history"></i> Retention Information</h5>
                                                                <div class="details-item">
                                                                    <label>Period:</label>
                                                                    <span><?= formatDateDisplay($log['period_from']) ?> - <?= formatDateDisplay($log['period_to']) ?></span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Total Years:</label>
                                                                    <span><?= $log['total_years'] ?> years</span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Retention Period:</label>
                                                                    <span><?= htmlspecialchars($log['period_name'] ?? 'N/A') ?></span>
                                                                </div>
                                                                <div class="details-item">
                                                                    <label>Retention Status:</label>
                                                                    <span class="status-badge <?= $retention_status_class ?>">
                                                                        <?= htmlspecialchars($log['retention_status']) ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            
                                                            <?php if (!empty($log['disposal_agency_name'])): 
                                                                $disposal_creator_name = getUserDisplayName($log, 'disposal_creator');
                                                                $disposal_creator_office = getUserOfficeDisplay($log, 'disposal_creator');
                                                                $action_user_name = getActionUserDisplayName($log);
                                                                $action_user_office = getActionUserOfficeDisplay($log);
                                                                $action_date = getActionDate($log);
                                                            ?>
                                                                <div class="details-section">
                                                                    <h5><i class="fas fa-trash-alt"></i> Disposal Request</h5>
                                                                    <div class="details-item">
                                                                        <label>Agency:</label>
                                                                        <span><?= htmlspecialchars($log['disposal_agency_name']) ?></span>
                                                                    </div>
                                                                    <div class="details-item">
                                                                        <label>Request Date:</label>
                                                                        <span><?= formatDateDisplay($log['disposal_request_date']) ?></span>
                                                                    </div>
                                                                    <div class="details-item">
                                                                        <label>Requested By:</label>
                                                                        <span><?= $disposal_creator_name ?> (<?= htmlspecialchars($disposal_creator_office) ?>)</span>
                                                                    </div>
                                                                    <div class="details-item">
                                                                        <label>Status:</label>
                                                                        <span class="status-badge <?= getStatusClass($log['disposal_request_status']) ?>">
                                                                            <?= htmlspecialchars($log['disposal_request_status']) ?>
                                                                        </span>
                                                                    </div>
                                                                    
                                                                    <?php if (!empty($log['disposal_remarks'])): ?>
                                                                        <div class="details-item">
                                                                            <label>Remarks:</label>
                                                                            <span><?= htmlspecialchars($log['disposal_remarks']) ?></span>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    
                                                                    <!-- Show approver/rejector information -->
                                                                    <?php if (in_array($log['disposal_request_status'], ['Approved', 'Rejected'])): ?>
                                                                        <?php if ($action_date): ?>
                                                                            <div class="details-item">
                                                                                <label><?= $log['disposal_request_status'] ?> Date:</label>
                                                                                <span><?= formatDateDisplay($action_date) ?></span>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                        <div class="details-item">
                                                                            <label><?= $log['disposal_request_status'] ?> By:</label>
                                                                            <span>
                                                                                <?= $action_user_name ?>
                                                                                <?php if ($action_user_office && $action_user_office !== 'N/A'): ?>
                                                                                    (<?= htmlspecialchars($action_user_office) ?>)
                                                                                <?php endif; ?>
                                                                            </span>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="details-footer">
                                                        <button type="button" class="close-details-btn" onclick="toggleLogDetails(<?= $log['record_id'] ?>)">
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
        // Global variables
        let currentFilter = 'all';
        let currentFilterId = null;
        let allLogs = <?= json_encode($logs) ?>;
        let logsByMonth = <?= json_encode($logs_by_month) ?>;
        let logsByType = <?= json_encode($logs_by_type) ?>;
        let logsByUser = <?= json_encode($logs_by_user) ?>;

        // Helper functions for JavaScript
        function getUserOfficeDisplay(log, userType = 'creator') {
            const roleField = userType + '_role';
            const officeField = userType + '_office';

            // Check if role contains "admin"
            if (log[roleField] && log[roleField].toLowerCase().includes('admin')) {
                return 'Admin';
            }

            // Return office name or 'N/A'
            return log[officeField] || 'N/A';
        }

        function getUserDisplayName(log, userType = 'creator') {
            const nameField = userType + '_name';
            const roleField = userType + '_role';

            const name = log[nameField] || '';
            const role = log[roleField] || null;

            if (!name) {
                return 'System';
            }

            const escapedName = escapeHtml(name).trim();

            // Add role indicator if admin
            if (role && role.toLowerCase().includes('admin')) {
                return escapedName + ' <span class="role-badge admin">(Admin)</span>';
            }

            return escapedName;
        }

        // New helper functions for action user
        function getActionUserDisplayName(log) {
            const actionType = log.disposal_request_status || '';
            
            if (actionType === 'Approved') {
                const name = log.approver_name || '';
                const role = log.approver_role || null;

                if (!name) {
                    return 'N/A';
                }

                const escapedName = escapeHtml(name).trim();

                // Add role indicator if admin
                if (role && role.toLowerCase().includes('admin')) {
                    return escapedName + ' <span class="role-badge admin">(Admin)</span>';
                }

                return escapedName;
            } else if (actionType === 'Rejected') {
                const name = log.rejector_name || '';
                const role = log.rejector_role || null;

                if (!name) {
                    return 'N/A';
                }

                const escapedName = escapeHtml(name).trim();

                // Add role indicator if admin
                if (role && role.toLowerCase().includes('admin')) {
                    return escapedName + ' <span class="role-badge admin">(Admin)</span>';
                }

                return escapedName;
            }
            
            return 'N/A';
        }

        function getActionUserOfficeDisplay(log) {
            const actionType = log.disposal_request_status || '';
            
            if (actionType === 'Approved') {
                const roleField = 'approver_role';
                const officeField = 'approver_office';

                // Check if role contains "admin"
                if (log[roleField] && log[roleField].toLowerCase().includes('admin')) {
                    return 'Admin';
                }

                // Return office name or 'N/A'
                return log[officeField] || 'N/A';
            } else if (actionType === 'Rejected') {
                const roleField = 'rejector_role';
                const officeField = 'rejector_office';

                // Check if role contains "admin"
                if (log[roleField] && log[roleField].toLowerCase().includes('admin')) {
                    return 'Admin';
                }

                // Return office name or 'N/A'
                return log[officeField] || 'N/A';
            }
            
            return 'N/A';
        }

        function getActionDate(log) {
            const actionType = log.disposal_request_status || '';
            
            if (actionType === 'Approved') {
                return log.disposal_approved_at || null;
            } else if (actionType === 'Rejected') {
                return log.disposal_rejected_at || null;
            }
            
            return null;
        }

        // Folder Filtering functions
        function showAllLogs() {
            currentFilter = 'all';
            currentFilterId = null;

            updateActiveFolder('allLogsFolder');
            document.getElementById('currentFolderTitle').textContent = 'All Records & Requests';
            document.getElementById('currentFolderSubtitle').textContent = 'Browse records with creator info, disposal requests, and retention status';
            document.getElementById('folderContentTitle').textContent = 'Records & Requests';
            document.getElementById('folderContentSubtitle').textContent = allLogs.length + ' records found';

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

            document.getElementById('currentFolderTitle').textContent = monthYear + ' Records';
            document.getElementById('currentFolderSubtitle').textContent = 'Records created in ' + monthYear;
            document.getElementById('folderContentTitle').textContent = monthYear + ' Records';

            const monthLogs = logsByMonth[monthKey]?.logs || [];
            document.getElementById('folderContentSubtitle').textContent = monthLogs.length + ' records found';
            displayLogs(monthLogs);
        }

        function showTypeLogs(type) {
            currentFilter = 'type';
            currentFilterId = type;

            updateActiveFolder(null);
            const typeFolder = document.querySelector(`.folder-item[data-type="${type}"]`);
            if (typeFolder) {
                typeFolder.classList.add('active');
            }

            const typeName = type;

            document.getElementById('currentFolderTitle').textContent = typeName + ' Records';
            document.getElementById('currentFolderSubtitle').textContent = 'Records with status: ' + typeName;
            document.getElementById('folderContentTitle').textContent = typeName + ' Records';

            const typeLogs = logsByType[type]?.logs || [];
            document.getElementById('folderContentSubtitle').textContent = typeLogs.length + ' records found';
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

            document.getElementById('currentFolderTitle').textContent = username + "'s Records";
            document.getElementById('currentFolderSubtitle').textContent = 'Records created by: ' + username;
            document.getElementById('folderContentTitle').textContent = username + "'s Records";

            const userLogs = logsByUser[username]?.logs || [];
            document.getElementById('folderContentSubtitle').textContent = userLogs.length + ' records found';
            displayLogs(userLogs);
        }

        function updateActiveFolder(activeId) {
            document.querySelectorAll('.folder-item').forEach(item => {
                item.classList.remove('active');
            });

            if (activeId) {
                document.getElementById(activeId).classList.add('active');
            }
        }

        function displayLogs(logs) {
            const tbody = document.getElementById('logsTableBody');

            if (logs.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-clipboard-list"></i>
                                </div>
                                <h3>No Records Found</h3>
                                <p>No records found in this category.</p>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }

            let html = '';
            logs.forEach(log => {
                const recordStatusClass = getStatusClass(log.record_status);
                const retentionStatusClass = getStatusClass(log.retention_status);
                const recordIdPadded = String(log.record_id).padStart(5, '0');
                const creatorName = getUserDisplayName(log, 'creator');
                const creatorOffice = getUserOfficeDisplay(log, 'creator');
                
                // Determine main status
                let mainStatus, mainStatusClass;
                if (log.disposal_request_status) {
                    mainStatus = log.disposal_request_status;
                    mainStatusClass = getStatusClass(mainStatus);
                } else {
                    mainStatus = log.retention_status;
                    mainStatusClass = retentionStatusClass;
                }

                html += `
                    <tr id="log-row-${log.record_id}">
                        <td>
                            <div class="record-badge">
                                <span class="record-id">R${recordIdPadded}</span>
                            </div>
                            <div class="record-code">
                                ${escapeHtml(log.record_series_code)}
                            </div>
                        </td>
                        <td>
                            <div class="record-title">
                                <strong>${escapeHtml(log.record_series_title)}</strong>
                            </div>
                            <div class="record-meta">
                                <span class="meta-item">
                                    <i class="fas fa-building"></i> ${escapeHtml(log.office_name)}
                                </span>
                                <span class="meta-item">
                                    <i class="fas fa-calendar"></i> ${formatDateDisplay(log.record_created_date)}
                                </span>
                                <span class="meta-item">
                                    <i class="fas fa-tag"></i> ${escapeHtml(log.class_name)}
                                </span>
                            </div>
                        </td>
                        <td>
                            <div class="creator-info">
                                <div class="creator-name">
                                    ${creatorName}
                                </div>
                                <div class="creator-office">
                                    <small>${escapeHtml(creatorOffice)}</small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="main-status">
                                <span class="status-badge ${mainStatusClass}">
                                    ${escapeHtml(mainStatus)}
                                </span>
                            </div>
                            <div class="sub-status">
                                <small>
                                    Record: 
                                    <span class="status-indicator ${recordStatusClass}">
                                        ${escapeHtml(log.record_status)}
                                    </span>
                                </small>
                            </div>
                            ${log.retention_end_date ? 
                                (() => {
                                    const endDate = formatDateDisplay(log.retention_end_date);
                                    const today = new Date().toISOString().split('T')[0];
                                    if (endDate < today) {
                                        return `
                                        <div class="retention-warning">
                                            <small><i class="fas fa-exclamation-triangle text-danger"></i> Ended: ${endDate}</small>
                                        </div>
                                        `;
                                    } else {
                                        return `
                                        <div class="retention-info">
                                            <small><i class="fas fa-clock"></i> Ends: ${endDate}</small>
                                        </div>
                                        `;
                                    }
                                })() : ''
                            }
                            ${log.disposal_agency_name ? `
                                <div class="disposal-badge">
                                    <small>
                                        <i class="fas fa-trash"></i> 
                                        <span class="text-primary">Disposal Requested</span>
                                    </small>
                                </div>
                            ` : ''}
                        </td>
                        <td>
                            <button type="button" class="view-details-btn" onclick="toggleLogDetails(${log.record_id})">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                    <tr class="log-details-row" id="log-details-${log.record_id}" style="display: none;">
                        <td colspan="5">
                            <div class="log-details-content">
                                <div class="details-header">
                                    <h4>Record Details: R${recordIdPadded}</h4>
                                </div>
                                <div class="details-grid">
                                    <div class="details-column">
                                        <div class="details-section">
                                            <h5><i class="fas fa-info-circle"></i> Record Information</h5>
                                            <div class="details-item">
                                                <label>Title:</label>
                                                <span>${escapeHtml(log.record_series_title)}</span>
                                            </div>
                                            <div class="details-item">
                                                <label>Code:</label>
                                                <span>${escapeHtml(log.record_series_code)}</span>
                                            </div>
                                            <div class="details-item">
                                                <label>Office:</label>
                                                <span>${escapeHtml(log.office_name)}</span>
                                            </div>
                                            <div class="details-item">
                                                <label>Classification:</label>
                                                <span>${escapeHtml(log.class_name)}</span>
                                            </div>
                                        </div>
                                        <div class="details-section">
                                            <h5><i class="fas fa-user"></i> Creator Information</h5>
                                            <div class="details-item">
                                                <label>Name:</label>
                                                <span>${creatorName}</span>
                                            </div>
                                            <div class="details-item">
                                                <label>Office:</label>
                                                <span>${escapeHtml(creatorOffice)}</span>
                                            </div>
                                            <div class="details-item">
                                                <label>Created:</label>
                                                <span>${formatDateDisplay(log.record_created_date)}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="details-column">
                                        <div class="details-section">
                                            <h5><i class="fas fa-history"></i> Retention Information</h5>
                                            <div class="details-item">
                                                <label>Period:</label>
                                                <span>${formatDateDisplay(log.period_from)} - ${formatDateDisplay(log.period_to)}</span>
                                            </div>
                                            <div class="details-item">
                                                <label>Total Years:</label>
                                                <span>${log.total_years} years</span>
                                            </div>
                                            <div class="details-item">
                                                <label>Retention Period:</label>
                                                <span>${escapeHtml(log.period_name || 'N/A')}</span>
                                            </div>
                                            <div class="details-item">
                                                <label>Retention Status:</label>
                                                <span class="status-badge ${retentionStatusClass}">
                                                    ${escapeHtml(log.retention_status)}
                                                </span>
                                            </div>
                                        </div>
                                        ${log.disposal_agency_name ? 
                                            (() => {
                                                const disposalCreatorName = getUserDisplayName(log, 'disposal_creator');
                                                const disposalCreatorOffice = getUserOfficeDisplay(log, 'disposal_creator');
                                                const actionUserName = getActionUserDisplayName(log);
                                                const actionUserOffice = getActionUserOfficeDisplay(log);
                                                const actionDate = getActionDate(log);
                                                
                                                let actionUserHtml = '';
                                                if (log.disposal_request_status === 'Approved' || log.disposal_request_status === 'Rejected') {
                                                    if (actionDate) {
                                                        actionUserHtml += `
                                                            <div class="details-item">
                                                                <label>${log.disposal_request_status} Date:</label>
                                                                <span>${formatDateDisplay(actionDate)}</span>
                                                            </div>
                                                        `;
                                                    }
                                                    actionUserHtml += `
                                                        <div class="details-item">
                                                            <label>${log.disposal_request_status} By:</label>
                                                            <span>
                                                                ${actionUserName}
                                                                ${actionUserOffice && actionUserOffice !== 'N/A' ? 
                                                                    `(${escapeHtml(actionUserOffice)})` : ''}
                                                            </span>
                                                        </div>
                                                    `;
                                                }
                                                
                                                let remarksHtml = '';
                                                if (log.disposal_remarks) {
                                                    remarksHtml = `
                                                        <div class="details-item">
                                                            <label>Remarks:</label>
                                                            <span>${escapeHtml(log.disposal_remarks)}</span>
                                                        </div>
                                                    `;
                                                }
                                                
                                                return `
                                                <div class="details-section">
                                                    <h5><i class="fas fa-trash-alt"></i> Disposal Request</h5>
                                                    <div class="details-item">
                                                        <label>Agency:</label>
                                                        <span>${escapeHtml(log.disposal_agency_name)}</span>
                                                    </div>
                                                    <div class="details-item">
                                                        <label>Request Date:</label>
                                                        <span>${formatDateDisplay(log.disposal_request_date)}</span>
                                                    </div>
                                                    <div class="details-item">
                                                        <label>Requested By:</label>
                                                        <span>${disposalCreatorName} (${escapeHtml(disposalCreatorOffice)})</span>
                                                    </div>
                                                    <div class="details-item">
                                                        <label>Status:</label>
                                                        <span class="status-badge ${getStatusClass(log.disposal_request_status)}">
                                                            ${escapeHtml(log.disposal_request_status)}
                                                        </span>
                                                    </div>
                                                    ${remarksHtml}
                                                    ${actionUserHtml}
                                                </div>
                                                `;
                                            })() : ''
                                        }
                                    </div>
                                </div>
                                <div class="details-footer">
                                    <button type="button" class="close-details-btn" onclick="toggleLogDetails(${log.record_id})">
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
        function toggleLogDetails(recordId) {
            const detailsRow = document.getElementById(`log-details-${recordId}`);
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
                    detailsRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }, 100);
            }
        }

        // Helper functions
        function getStatusClass(status) {
            if (!status) return 'status-pending';

            const lowerStatus = status.toLowerCase();
            if (lowerStatus === 'active' || lowerStatus === 'approved')
                return 'status-success';
            if (lowerStatus === 'inactive' || lowerStatus === 'pending' || lowerStatus === 'scheduled for disposal')
                return 'status-pending';
            if (lowerStatus === 'rejected')
                return 'status-failed';
            if (lowerStatus === 'archived')
                return 'status-archive';
            if (lowerStatus === 'disposed')
                return 'status-disposed';
            if (lowerStatus === 'retention period reached')
                return 'status-warning';
            return 'status-pending';
        }

        function formatDateDisplay(dateString) {
            if (!dateString || dateString === '0000-00-00' || dateString === '0000-00-00 00:00:00')
                return '-';
            return new Date(dateString).toLocaleDateString('en-US');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Reset all filters
        function resetFilters() {
            document.getElementById('filterForm').reset();
            window.location.href = 'reports.php';
        }

        // Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Select2 for status filter
            $('#action_type').select2({
                placeholder: "All Status",
                allowClear: true,
                width: '100%',
                minimumResultsForSearch: 10,
                dropdownParent: $('#filterForm')
            });

            // Set minimum and maximum dates for date inputs
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('date_to').max = today;
            document.getElementById('date_from').max = today;

            // Set default date range (last 30 days)
            if (!document.getElementById('date_from').value) {
                const thirtyDaysAgo = new Date();
                thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                document.getElementById('date_from').value = thirtyDaysAgo.toISOString().split('T')[0];
            }

            if (!document.getElementById('date_to').value) {
                document.getElementById('date_to').value = today;
            }

            // Handle date validation
            document.getElementById('date_from').addEventListener('change', function() {
                const dateTo = document.getElementById('date_to');
                if (this.value > dateTo.value) {
                    dateTo.value = this.value;
                }
            });

            document.getElementById('date_to').addEventListener('change', function() {
                const dateFrom = document.getElementById('date_from');
                if (this.value < dateFrom.value) {
                    dateFrom.value = this.value;
                }
            });

            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl + F to focus search
                if (e.ctrlKey && e.key === 'f') {
                    e.preventDefault();
                    document.getElementById('search_filter').focus();
                }

                // Esc to clear search
                if (e.key === 'Escape') {
                    const searchInput = document.getElementById('search_filter');
                    if (document.activeElement === searchInput && searchInput.value) {
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
        });
    </script>
</body>

</html>