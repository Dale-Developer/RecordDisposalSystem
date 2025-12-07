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
  // Build the base query with joins to all relevant tables
  $sql = "SELECT 
                al.log_id,
                COALESCE(al.log_type, al.action_type) as log_type,
                al.created_at,
                COALESCE(al.entity_name, al.entity_type) as entity_type,
                al.entity_id,
                al.entity_name,
                COALESCE(al.action_description, al.description) as action_description,
                COALESCE(al.status, 
                    CASE 
                        WHEN al.action_type IN ('USER_LOGIN', 'USER_LOGOUT', 'RECORD_CREATE', 'RECORD_UPDATE', 'DISPOSAL_REQUEST_APPROVE', 'DISPOSAL_SCHEDULE_COMPLETE', 'ARCHIVE_REQUEST_APPROVE') THEN 'Success'
                        WHEN al.action_type IN ('DISPOSAL_REQUEST_REJECT', 'ARCHIVE_REQUEST_REJECT') THEN 'Failed'
                        ELSE 'Completed'
                    END
                ) as status,
                al.ip_address,
                al.old_values,
                al.new_values,
                al.record_status_from,
                al.record_status_to,
                al.request_status_from,
                al.request_status_to,
                u.user_id,
                u.first_name,
                u.last_name,
                u.email,
                r.role_name,
                o.office_name,
                rec.record_series_title,
                rec.record_series_code,
                rec.status as record_current_status,
                dr.agency_name as disposal_agency_name,
                dr.request_date as disposal_request_date,
                dr.status as disposal_request_status,
                ds.schedule_date as disposal_schedule_date,
                ds.status as disposal_schedule_status,
                ar.status as archive_request_status,
                ar.request_date as archive_request_date,
                rc.class_name as classification_name,
                rp.period_name as retention_period_name,
                rf.file_name,
                rf.file_type,
                rf.file_path,
                us.login_time as session_start_time,
                us.logout_time as session_end_time
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.user_id
            LEFT JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN offices o ON u.office_id = o.office_id
            LEFT JOIN records rec ON al.record_id = rec.record_id
            LEFT JOIN disposal_requests dr ON al.disposal_request_id = dr.request_id
            LEFT JOIN disposal_schedule ds ON al.disposal_schedule_id = ds.schedule_id
            LEFT JOIN archive_requests ar ON al.archive_request_id = ar.request_id
            LEFT JOIN record_classification rc ON al.classification_id = rc.class_id
            LEFT JOIN retention_periods rp ON al.retention_period_id = rp.period_id
            LEFT JOIN record_files rf ON al.record_file_id = rf.file_id
            LEFT JOIN user_sessions us ON al.user_session_id = us.session_id
            WHERE 1=1";

  $params = [];

  // Apply filters
  if ($search_filter) {
    $sql .= " AND (
            COALESCE(al.action_description, al.description) LIKE ? OR 
            COALESCE(al.log_type, al.action_type) LIKE ? OR
            u.first_name LIKE ? OR 
            u.last_name LIKE ? OR 
            u.email LIKE ? OR
            al.ip_address LIKE ? OR
            rec.record_series_title LIKE ? OR
            rec.record_series_code LIKE ? OR
            dr.agency_name LIKE ? OR
            rc.class_name LIKE ?
        )";
    $search_param = "%$search_filter%";
    $params = array_merge($params, array_fill(0, 10, $search_param));
  }

  if ($date_from) {
    $sql .= " AND DATE(al.created_at) >= ?";
    $params[] = $date_from;
  }

  if ($date_to) {
    $sql .= " AND DATE(al.created_at) <= ?";
    $params[] = $date_to;
  }

  if ($action_type) {
    $sql .= " AND (al.log_type = ? OR al.action_type = ?)";
    $params[] = $action_type;
    $params[] = $action_type;
  }

  $sql .= " ORDER BY al.created_at DESC";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $total_logs = count($logs);

  // Get unique action types for filter dropdown
  $action_types_sql = "SELECT DISTINCT COALESCE(log_type, action_type) as action_type 
                        FROM activity_logs 
                        WHERE COALESCE(log_type, action_type) IS NOT NULL 
                        AND COALESCE(log_type, action_type) != ''
                        ORDER BY COALESCE(log_type, action_type)";
  $action_types_stmt = $pdo->query($action_types_sql);
  $available_action_types = $action_types_stmt->fetchAll(PDO::FETCH_COLUMN);

  // Group logs by month
  $logs_by_month = [];
  foreach ($logs as $log) {
    $year_month = date('Y-m', strtotime($log['created_at']));
    if (!isset($logs_by_month[$year_month])) {
      $logs_by_month[$year_month] = [
        'month_year' => date('F Y', strtotime($log['created_at'])),
        'logs' => []
      ];
    }
    $logs_by_month[$year_month]['logs'][] = $log;
  }

  // Group logs by type
  $logs_by_type = [];
  foreach ($logs as $log) {
    $log_type = $log['log_type'];
    if ($log_type) {
      if (!isset($logs_by_type[$log_type])) {
        $logs_by_type[$log_type] = [
          'log_type' => $log_type,
          'logs' => []
        ];
      }
      $logs_by_type[$log_type]['logs'][] = $log;
    }
  }

  // Group logs by user
  $logs_by_user = [];
  foreach ($logs as $log) {
    $user_name = ($log['first_name'] && $log['last_name']) ? $log['first_name'] . ' ' . $log['last_name'] : 'System';
    if ($user_name && trim($user_name) !== '' && $user_name !== 'System') {
      if (!isset($logs_by_user[$user_name])) {
        $logs_by_user[$user_name] = [
          'user' => $user_name,
          'role' => $log['role_name'] ?? 'Unknown',
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

// Function to get display name for action type
function getDisplayActionName($logType)
{
  if (!$logType)
    return 'System Action';

  $actionMap = [
    'USER_LOGIN' => 'User Login',
    'USER_LOGOUT' => 'User Logout',
    'USER_CREATE' => 'Create User',
    'USER_UPDATE' => 'Update User',
    'USER_DELETE' => 'Delete User',
    'RECORD_CREATE' => 'Create Record',
    'RECORD_UPDATE' => 'Update Record',
    'RECORD_DELETE' => 'Delete Record',
    'RECORD_STATUS_CHANGE' => 'Record Status Change',
    'DISPOSAL_REQUEST_CREATE' => 'Create Disposal Request',
    'DISPOSAL_REQUEST_UPDATE' => 'Update Disposal Request',
    'DISPOSAL_REQUEST_APPROVE' => 'Approve Disposal Request',
    'DISPOSAL_REQUEST_REJECT' => 'Reject Disposal Request',
    'DISPOSAL_REQUEST_CANCEL' => 'Cancel Disposal Request',
    'DISPOSAL_SCHEDULE_CREATE' => 'Create Disposal Schedule',
    'DISPOSAL_SCHEDULE_UPDATE' => 'Update Disposal Schedule',
    'DISPOSAL_SCHEDULE_COMPLETE' => 'Complete Disposal Schedule',
    'FILE_UPLOAD' => 'File Upload',
    'FILE_DELETE' => 'File Delete',
    'FILE_DOWNLOAD' => 'File Download',
    'OFFICE_CREATE' => 'Create Office',
    'OFFICE_UPDATE' => 'Update Office',
    'OFFICE_DELETE' => 'Delete Office',
    'CLASSIFICATION_CREATE' => 'Create Classification',
    'CLASSIFICATION_UPDATE' => 'Update Classification',
    'CLASSIFICATION_DELETE' => 'Delete Classification',
    'SYSTEM_SETTINGS_CHANGE' => 'System Settings Change',
    'BULK_OPERATION' => 'Bulk Operation',
    'DATA_EXPORT' => 'Data Export',
    'RETENTION_PERIOD_CREATE' => 'Create Retention Period',
    'RETENTION_PERIOD_UPDATE' => 'Update Retention Period',
    'RETENTION_PERIOD_DELETE' => 'Delete Retention Period',
    'ARCHIVE_REQUEST_CREATE' => 'Create Archive Request',
    'ARCHIVE_REQUEST_APPROVE' => 'Approve Archive Request',
    'ARCHIVE_REQUEST_REJECT' => 'Reject Archive Request',
    'SESSION_START' => 'Session Started',
    'SESSION_END' => 'Session Ended'
  ];

  return $actionMap[$logType] ?? $logType;
}

// Function to get CSS class for action type
function getActionClass($logType)
{
  if (!$logType)
    return 'action-system';

  $lowerType = strtolower($logType);
  if (strpos($lowerType, 'login') !== false || strpos($lowerType, 'session_start') !== false)
    return 'action-login';
  if (strpos($lowerType, 'logout') !== false || strpos($lowerType, 'session_end') !== false)
    return 'action-logout';
  if (strpos($lowerType, 'create') !== false || strpos($lowerType, 'add') !== false)
    return 'action-create';
  if (strpos($lowerType, 'edit') !== false || strpos($lowerType, 'update') !== false)
    return 'action-edit';
  if (strpos($lowerType, 'delete') !== false || strpos($lowerType, 'remove') !== false)
    return 'action-delete';
  if (strpos($lowerType, 'disposal') !== false)
    return 'action-disposal';
  if (strpos($lowerType, 'archive') !== false)
    return 'action-archive';
  if (strpos($lowerType, 'approve') !== false)
    return 'action-success';
  if (strpos($lowerType, 'reject') !== false || strpos($lowerType, 'cancel') !== false)
    return 'action-reject';
  if (strpos($lowerType, 'upload') !== false || strpos($lowerType, 'download') !== false)
    return 'action-file';
  if (strpos($lowerType, 'status') !== false)
    return 'action-status';
  if (strpos($lowerType, 'classification') !== false)
    return 'action-classification';
  if (strpos($lowerType, 'retention') !== false)
    return 'action-retention';
  return 'action-system';
}

// Function to get CSS class for status
function getStatusClass($status)
{
  if (!$status)
    return 'status-pending';

  $lowerStatus = strtolower($status);
  if (strpos($lowerStatus, 'success') !== false || strpos($lowerStatus, 'complete') !== false || strpos($lowerStatus, 'approved') !== false)
    return 'status-success';
  if (strpos($lowerStatus, 'fail') !== false || strpos($lowerStatus, 'reject') !== false || strpos($lowerStatus, 'error') !== false)
    return 'status-failed';
  return 'status-pending';
}

// Function to format JSON for display
function formatJSON($jsonString)
{
  if (!$jsonString)
    return '';

  try {
    $obj = json_decode($jsonString, true);
    if (json_last_error() === JSON_ERROR_NONE) {
      return json_encode($obj, JSON_PRETTY_PRINT);
    }
  } catch (Exception $e) {
    // Do nothing
  }

  return $jsonString;
}

// Function to format related entity info
function formatRelatedEntity($log)
{
  $info = [];

  // Record information
  if (!empty($log['record_series_title'])) {
    $info[] = "Record: {$log['record_series_title']} ({$log['record_series_code']})";
  }

  // Disposal request information
  if (!empty($log['disposal_agency_name'])) {
    $date = date('Y-m-d', strtotime($log['disposal_request_date']));
    $info[] = "Disposal Request: {$log['disposal_agency_name']} ({$date})";
  }

  // Disposal schedule information
  if (!empty($log['disposal_schedule_date'])) {
    $date = date('Y-m-d', strtotime($log['disposal_schedule_date']));
    $info[] = "Disposal Schedule: {$date}";
  }

  // Archive request information
  if (!empty($log['archive_request_status'])) {
    $info[] = "Archive Request: " . ucfirst($log['archive_request_status']);
  }

  // Classification information
  if (!empty($log['classification_name'])) {
    $info[] = "Classification: {$log['classification_name']}";
  }

  // File information
  if (!empty($log['file_name'])) {
    $info[] = "File: {$log['file_name']}";
  }

  // Session information
  if (!empty($log['session_start_time'])) {
    $info[] = "Session: Started at " . date('Y-m-d H:i', strtotime($log['session_start_time']));
  }

  return implode('<br>', $info);
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

    /* Table View */
    .records-table-view {
      width: 100%;
      border-collapse: collapse;
    }

    .records-table-view th {
      text-align: left;
      padding: 12px 15px;
      background: #f8f9fa;
      color: #1f366c;
      font-weight: 600;
      border-bottom: 2px solid #e0e0e0;
      font-size: 13px;
      text-transform: uppercase;
    }

    .records-table-view td {
      padding: 15px;
      border-bottom: 1px solid #f0f0f0;
      font-size: 14px;
    }

    .records-table-view tr:hover {
      background: #f9f9f9;
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

    /* Log specific styling */
    .log-id-badge {
      display: inline-block;
      padding: 4px 8px;
      background-color: #f0f2f5;
      border-radius: 4px;
      font-family: 'Courier New', monospace;
      font-weight: 600;
      color: #2c3e50;
    }

    .action-badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 500;
    }

    .status-badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 500;
    }

    /* Badge colors */
    .action-login {
      background-color: #d1f2eb;
      color: #0e6251;
    }

    .action-logout {
      background-color: #d6eaf8;
      color: #1a5276;
    }

    .action-create {
      background-color: #d5f4e6;
      color: #0b5345;
    }

    .action-edit {
      background-color: #fef9e7;
      color: #7d6608;
    }

    .action-delete {
      background-color: #fadbd8;
      color: #78281f;
    }

    .action-disposal {
      background-color: #e8daef;
      color: #512e5f;
    }

    .action-archive {
      background-color: #d6dbdf;
      color: #424949;
    }

    .action-success {
      background-color: #d4edda;
      color: #155724;
    }

    .action-reject {
      background-color: #f8d7da;
      color: #721c24;
    }

    .action-file {
      background-color: #d6eaf8;
      color: #1a5276;
    }

    .action-status {
      background-color: #e8daef;
      color: #512e5f;
    }

    .action-system {
      background-color: #e8e8e8;
      color: #333;
    }

    .status-success {
      background-color: #d4edda;
      color: #155724;
    }

    .status-failed {
      background-color: #f8d7da;
      color: #721c24;
    }

    .status-pending {
      background-color: #fff3cd;
      color: #856404;
    }

    /* Responsive */
    @media (max-width: 1400px) {
      .records-table-view {
        min-width: 1200px;
      }

      .folder-records-container {
        overflow-x: auto;
      }
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

    .filter-group {
      display: flex;
      flex-direction: column;
    }

    .filter-group label {
      font-size: 12px;
      font-weight: 600;
      color: #666;
      margin-bottom: 5px;
      text-transform: uppercase;
    }

    .filter-input {
      padding: 8px 12px;
      border: 1px solid #ddd;
      border-radius: 6px;
      font-size: 14px;
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
    }

    .filter-button:hover {
      background: #152852;
    }

    .filter-button.reset {
      background: #666;
    }

    .filter-button.reset:hover {
      background: #555;
    }

    .filter-buttons {
      display: flex;
      gap: 10px;
    }

    /* Log details row */
    .log-details-row {
      display: none;
      background: #f9f9f9;
    }

    .log-details-content {
      padding: 20px;
      border-left: 4px solid #1f366c;
      background: #fff;
      margin: 10px;
      border-radius: 6px;
    }

    .log-details-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 15px;
    }

    .log-detail-item {
      margin-bottom: 10px;
    }

    .log-detail-label {
      font-size: 12px;
      font-weight: 600;
      color: #666;
      text-transform: uppercase;
      margin-bottom: 3px;
    }

    .log-detail-value {
      font-size: 14px;
      color: #333;
      word-break: break-word;
    }

    .view-details-btn {
      background: none;
      border: none;
      color: #1f366c;
      cursor: pointer;
      font-size: 12px;
      text-decoration: underline;
      padding: 5px;
    }

    .view-details-btn:hover {
      color: #152852;
    }

    .full-width {
      grid-column: 1 / -1;
    }

    .json-display {
      background: #f5f5f5;
      padding: 10px;
      border-radius: 4px;
      font-family: 'Courier New', monospace;
      font-size: 12px;
      max-height: 150px;
      overflow-y: auto;
      white-space: pre-wrap;
    }

    .status-change {
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    .status-change-arrow {
      color: #666;
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
        <h1>REPORT & LOGS</h1>
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
            <span>All Logs</span>
          </div>
          <div class="folders-list">
            <div class="folder-item active" onclick="showAllLogs()" id="allLogsFolder">
              <i class="fas fa-boxes folder-icon"></i>
              <div class="folder-info">
                <div class="folder-name">All Activity Logs</div>
                <div class="folder-count"><?= $total_logs ?> logs</div>
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
                    <i class="fas fa-folder folder-icon"></i>
                    <div class="folder-info">
                      <div class="folder-name"><?= $month_data['month_year'] ?></div>
                      <div class="folder-count"><?= $record_count ?> logs</div>
                    </div>
                  </div>
                <?php endif; endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Action Type Folders -->
        <?php if (!empty($logs_by_type)): ?>
          <div class="folder-group">
            <div class="folder-group-title">
              <i class="fas fa-cogs"></i>
              <span>By Action Type</span>
            </div>
            <div class="folders-list">
              <?php foreach ($logs_by_type as $type => $type_data):
                $record_count = count($type_data['logs']);
                if ($record_count > 0):
                  $display_name = getDisplayActionName($type);
                  ?>
                  <div class="folder-item type-folder" onclick="showTypeLogs('<?= htmlspecialchars($type) ?>')"
                    data-type="<?= htmlspecialchars($type) ?>">
                    <i class="fas fa-folder folder-icon"></i>
                    <div class="folder-info">
                      <div class="folder-name"><?= htmlspecialchars($display_name) ?></div>
                      <div class="folder-count"><?= $record_count ?> logs</div>
                    </div>
                  </div>
                <?php endif; endforeach; ?>
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
                $record_count = count($user_data['logs']);
                if ($record_count > 0): ?>
                  <div class="folder-item user-folder" onclick="showUserLogs('<?= htmlspecialchars($user_name) ?>')"
                    data-user="<?= htmlspecialchars($user_name) ?>">
                    <i class="fas fa-folder folder-icon"></i>
                    <div class="folder-info">
                      <div class="folder-name"><?= htmlspecialchars($user_name) ?></div>
                      <div class="folder-count"><?= $record_count ?> logs</div>
                    </div>
                  </div>
                <?php endif; endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Logs Main Area -->
      <div class="records-main">
        <div class="header-actions">
          <div class="current-folder-info">
            <h2 id="currentFolderTitle">All Activity Logs</h2>
            <p id="currentFolderSubtitle">Browse all activity logs from the system</p>
          </div>
        </div>

        <!-- Search and Filter Bar -->
        <div class="search-filter-bar">
          <form method="POST" class="filter-form" id="filterForm">
            <div class="filter-group">
              <label for="search_filter">Search</label>
              <input type="text" id="search_filter" name="search_filter" class="filter-input" placeholder="Search..."
                value="<?= htmlspecialchars($search_filter) ?>">
            </div>

            <div class="filter-group">
              <label for="date_from">Date From</label>
              <input type="date" id="date_from" name="date_from" class="filter-input"
                value="<?= htmlspecialchars($date_from) ?>">
            </div>

            <div class="filter-group">
              <label for="date_to">Date To</label>
              <input type="date" id="date_to" name="date_to" class="filter-input"
                value="<?= htmlspecialchars($date_to) ?>">
            </div>

            <div class="filter-group">
              <label for="action_type">Action Type</label>
              <select id="action_type" name="action_type" class="filter-input">
                <option value="">All Actions</option>
                <?php foreach ($available_action_types as $type):
                  if (!empty($type)): ?>
                    <option value="<?= htmlspecialchars($type) ?>" <?= $action_type == $type ? 'selected' : '' ?>>
                      <?= htmlspecialchars(getDisplayActionName($type)) ?>
                    </option>
                  <?php endif; endforeach; ?>
              </select>
            </div>

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
            <h3 id="folderContentTitle">All Logs</h3>
            <p id="folderContentSubtitle"><?= $total_logs ?> activity logs found</p>
          </div>

          <div class="folder-records-container">
            <table class="records-table-view">
              <thead>
                <tr>
                  <th width="80">Log ID</th>
                  <th width="150">Timestamp</th>
                  <th width="120">User</th>
                  <th width="120">Action</th>
                  <th width="100">Entity</th>
                  <th width="200">Description</th>
                  <th width="100">Status</th>
                  <th width="100">IP Address</th>
                  <th width="80">Details</th>
                </tr>
              </thead>
              <tbody id="logsTableBody">
                <?php if (empty($logs)): ?>
                  <tr>
                    <td colspan="9">
                      <div class="empty-state">
                        <div class="empty-state-icon">
                          <i class="fas fa-clipboard-list"></i>
                        </div>
                        <h3>No Activity Logs Found</h3>
                        <p><?= $search_filter || $date_from || $date_to || $action_type ?
                          'No logs found matching your filters.' :
                          'No activity logs have been recorded yet.' ?></p>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($logs as $log):
                    $action_class = getActionClass($log['log_type']);
                    $status_class = getStatusClass($log['status']);
                    $log_id_padded = str_pad($log['log_id'], 5, '0', STR_PAD_LEFT);
                    $user_name = ($log['first_name'] && $log['last_name']) ?
                      $log['first_name'] . ' ' . $log['last_name'] :
                      'System';
                    $role_name = $log['role_name'] ?? 'Unknown';
                    $office_name = $log['office_name'] ?? 'N/A';
                    ?>
                    <tr id="log-row-<?= $log['log_id'] ?>">
                      <td>
                        <span class="log-id-badge">L<?= $log_id_padded ?></span>
                      </td>
                      <td>
                        <?= date('Y/m/d | h:i a', strtotime($log['created_at'])) ?>
                      </td>
                      <td>
                        <div><?= htmlspecialchars($user_name) ?></div>
                        <small style="color: #666;"><?= htmlspecialchars($role_name) ?></small>
                      </td>
                      <td>
                        <span class="action-badge <?= $action_class ?>">
                          <?= htmlspecialchars(getDisplayActionName($log['log_type'])) ?>
                        </span>
                      </td>
                      <td>
                        <?= htmlspecialchars($log['entity_type'] ?? 'System') ?>
                        <?php if ($log['entity_id']): ?>
                          <br><small>ID: <?= $log['entity_id'] ?></small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?= htmlspecialchars(substr($log['action_description'] ?? '', 0, 50)) . (strlen($log['action_description'] ?? '') > 50 ? '...' : '') ?>
                      </td>
                      <td>
                        <span class="status-badge <?= $status_class ?>">
                          <?= htmlspecialchars($log['status'] ?? 'Completed') ?>
                        </span>
                      </td>
                      <td>
                        <small><?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?></small>
                      </td>
                      <td>
                        <button type="button" class="view-details-btn" onclick="toggleLogDetails(<?= $log['log_id'] ?>)">
                          View
                        </button>
                      </td>
                    </tr>
                    <tr class="log-details-row" id="log-details-<?= $log['log_id'] ?>" style="display: none;">
                      <td colspan="9">
                        <div class="log-details-content">
                          <div class="log-details-grid">
                            <div class="log-detail-item">
                              <div class="log-detail-label">Log ID</div>
                              <div class="log-detail-value">L<?= $log_id_padded ?></div>
                            </div>
                            <div class="log-detail-item">
                              <div class="log-detail-label">Timestamp</div>
                              <div class="log-detail-value"><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></div>
                            </div>
                            <div class="log-detail-item">
                              <div class="log-detail-label">User</div>
                              <div class="log-detail-value">
                                <?= htmlspecialchars($user_name) ?><br>
                                <small><?= htmlspecialchars($log['email'] ?? 'N/A') ?></small><br>
                                <small>Role: <?= htmlspecialchars($role_name) ?></small><br>
                                <small>Office: <?= htmlspecialchars($office_name) ?></small>
                              </div>
                            </div>
                            <div class="log-detail-item">
                              <div class="log-detail-label">Action Type</div>
                              <div class="log-detail-value"><?= htmlspecialchars($log['log_type']) ?></div>
                            </div>
                            <div class="log-detail-item">
                              <div class="log-detail-label">Entity</div>
                              <div class="log-detail-value">
                                <?= htmlspecialchars($log['entity_type'] ?? 'System') ?>
                                <?php if ($log['entity_id']): ?>
                                  <br><small>ID: <?= $log['entity_id'] ?></small>
                                <?php endif; ?>
                              </div>
                            </div>
                            <div class="log-detail-item">
                              <div class="log-detail-label">IP Address</div>
                              <div class="log-detail-value"><?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?></div>
                            </div>
                            <div class="log-detail-item">
                              <div class="log-detail-label">Status</div>
                              <div class="log-detail-value">
                                <span class="status-badge <?= $status_class ?>">
                                  <?= htmlspecialchars($log['status'] ?? 'Completed') ?>
                                </span>
                              </div>
                            </div>
                            <?php if ($log['record_status_from'] && $log['record_status_to']): ?>
                              <div class="log-detail-item">
                                <div class="log-detail-label">Status Change</div>
                                <div class="log-detail-value">
                                  <div class="status-change">
                                    <span class="status-badge"><?= htmlspecialchars($log['record_status_from']) ?></span>
                                    <i class="fas fa-arrow-right status-change-arrow"></i>
                                    <span
                                      class="status-badge status-success"><?= htmlspecialchars($log['record_status_to']) ?></span>
                                  </div>
                                </div>
                              </div>
                            <?php endif; ?>
                            <div class="log-detail-item full-width">
                              <div class="log-detail-label">Description</div>
                              <div class="log-detail-value"><?= nl2br(htmlspecialchars($log['action_description'])) ?></div>
                            </div>
                            <?php if (!empty($log['old_values']) && $log['old_values'] !== 'null'): ?>
                              <div class="log-detail-item full-width">
                                <div class="log-detail-label">Old Values</div>
                                <div class="log-detail-value">
                                  <div class="json-display"><?= htmlspecialchars(formatJSON($log['old_values'])) ?></div>
                                </div>
                              </div>
                            <?php endif; ?>
                            <?php if (!empty($log['new_values']) && $log['new_values'] !== 'null'): ?>
                              <div class="log-detail-item full-width">
                                <div class="log-detail-label">New Values</div>
                                <div class="log-detail-value">
                                  <div class="json-display"><?= htmlspecialchars(formatJSON($log['new_values'])) ?></div>
                                </div>
                              </div>
                            <?php endif; ?>
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

    // Function to get display name for action type
    function getDisplayActionName(logType) {
      if (!logType) return 'System Action';

      const actionMap = {
        'USER_LOGIN': 'User Login',
        'USER_LOGOUT': 'User Logout',
        'USER_CREATE': 'Create User',
        'USER_UPDATE': 'Update User',
        'USER_DELETE': 'Delete User',
        'RECORD_CREATE': 'Create Record',
        'RECORD_UPDATE': 'Update Record',
        'RECORD_DELETE': 'Delete Record',
        'RECORD_STATUS_CHANGE': 'Record Status Change',
        'DISPOSAL_REQUEST_CREATE': 'Create Disposal Request',
        'DISPOSAL_REQUEST_UPDATE': 'Update Disposal Request',
        'DISPOSAL_REQUEST_APPROVE': 'Approve Disposal Request',
        'DISPOSAL_REQUEST_REJECT': 'Reject Disposal Request',
        'DISPOSAL_REQUEST_CANCEL': 'Cancel Disposal Request',
        'DISPOSAL_SCHEDULE_CREATE': 'Create Disposal Schedule',
        'DISPOSAL_SCHEDULE_UPDATE': 'Update Disposal Schedule',
        'DISPOSAL_SCHEDULE_COMPLETE': 'Complete Disposal Schedule',
        'FILE_UPLOAD': 'File Upload',
        'FILE_DELETE': 'File Delete',
        'FILE_DOWNLOAD': 'File Download',
        'OFFICE_CREATE': 'Create Office',
        'OFFICE_UPDATE': 'Update Office',
        'OFFICE_DELETE': 'Delete Office',
        'CLASSIFICATION_CREATE': 'Create Classification',
        'CLASSIFICATION_UPDATE': 'Update Classification',
        'CLASSIFICATION_DELETE': 'Delete Classification',
        'SYSTEM_SETTINGS_CHANGE': 'System Settings Change',
        'BULK_OPERATION': 'Bulk Operation',
        'DATA_EXPORT': 'Data Export',
        'RETENTION_PERIOD_CREATE': 'Create Retention Period',
        'RETENTION_PERIOD_UPDATE': 'Update Retention Period',
        'RETENTION_PERIOD_DELETE': 'Delete Retention Period',
        'ARCHIVE_REQUEST_CREATE': 'Create Archive Request',
        'ARCHIVE_REQUEST_APPROVE': 'Approve Archive Request',
        'ARCHIVE_REQUEST_REJECT': 'Reject Archive Request',
        'SESSION_START': 'Session Started',
        'SESSION_END': 'Session Ended'
      };

      return actionMap[logType] || logType;
    }

    // Function to get CSS class for action type
    function getActionClass(logType) {
      if (!logType) return 'action-system';

      const lowerType = logType.toLowerCase();
      if (lowerType.includes('login') || lowerType.includes('session_start')) return 'action-login';
      if (lowerType.includes('logout') || lowerType.includes('session_end')) return 'action-logout';
      if (lowerType.includes('create') || lowerType.includes('add')) return 'action-create';
      if (lowerType.includes('edit') || lowerType.includes('update')) return 'action-edit';
      if (lowerType.includes('delete') || lowerType.includes('remove')) return 'action-delete';
      if (lowerType.includes('disposal')) return 'action-disposal';
      if (lowerType.includes('archive')) return 'action-archive';
      if (lowerType.includes('approve')) return 'action-success';
      if (lowerType.includes('reject') || lowerType.includes('cancel')) return 'action-reject';
      if (lowerType.includes('upload') || lowerType.includes('download')) return 'action-file';
      if (lowerType.includes('status')) return 'action-status';
      if (lowerType.includes('classification')) return 'action-classification';
      if (lowerType.includes('retention')) return 'action-retention';
      return 'action-system';
    }

    // Function to get CSS class for status
    function getStatusClass(status) {
      if (!status) return 'status-pending';

      const lowerStatus = status.toLowerCase();
      if (lowerStatus.includes('success') || lowerStatus.includes('complete') || lowerStatus.includes('approved')) return 'status-success';
      if (lowerStatus.includes('fail') || lowerStatus.includes('reject') || lowerStatus.includes('error')) return 'status-failed';
      return 'status-pending';
    }

    // Function to format related entity info
    function formatRelatedEntity(log) {
      const info = [];

      // Record information
      if (log.record_series_title) {
        info.push(`Record: ${log.record_series_title} (${log.record_series_code})`);
      }

      // Disposal request information
      if (log.disposal_agency_name) {
        const date = formatDate(log.disposal_request_date);
        info.push(`Disposal Request: ${log.disposal_agency_name} (${date})`);
      }

      // Disposal schedule information
      if (log.disposal_schedule_date) {
        const date = formatDate(log.disposal_schedule_date);
        info.push(`Disposal Schedule: ${date}`);
      }

      // Archive request information
      if (log.archive_request_status) {
        info.push(`Archive Request: ${log.archive_request_status.charAt(0).toUpperCase() + log.archive_request_status.slice(1)}`);
      }

      // Classification information
      if (log.classification_name) {
        info.push(`Classification: ${log.classification_name}`);
      }

      // File information
      if (log.file_name) {
        info.push(`File: ${log.file_name}`);
      }

      // Session information
      if (log.session_start_time) {
        info.push(`Session: Started at ${formatDateTime(log.session_start_time)}`);
      }

      return info.join('<br>');
    }

    // Folder Filtering functions
    function showAllLogs() {
      currentFilter = 'all';
      currentFilterId = null;

      updateActiveFolder('allLogsFolder');
      document.getElementById('currentFolderTitle').textContent = 'All Activity Logs';
      document.getElementById('currentFolderSubtitle').textContent = 'Browse all activity logs from the system';
      document.getElementById('folderContentTitle').textContent = 'All Logs';
      document.getElementById('folderContentSubtitle').textContent = allLogs.length + ' activity logs found';

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

      document.getElementById('currentFolderTitle').textContent = monthYear + ' Logs';
      document.getElementById('currentFolderSubtitle').textContent = 'Activity logs for ' + monthYear;
      document.getElementById('folderContentTitle').textContent = monthYear + ' Logs';

      const monthLogs = logsByMonth[monthKey]?.logs || [];
      document.getElementById('folderContentSubtitle').textContent = monthLogs.length + ' activity logs found';
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

      const typeName = getDisplayActionName(type);

      document.getElementById('currentFolderTitle').textContent = typeName + ' Logs';
      document.getElementById('currentFolderSubtitle').textContent = typeName + ' activity logs';
      document.getElementById('folderContentTitle').textContent = typeName + ' Logs';

      const typeLogs = logsByType[type]?.logs || [];
      document.getElementById('folderContentSubtitle').textContent = typeLogs.length + ' activity logs found';
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

      document.getElementById('currentFolderTitle').textContent = username + "'s Activity Logs";
      document.getElementById('currentFolderSubtitle').textContent = 'Activity logs for user: ' + username;
      document.getElementById('folderContentTitle').textContent = username + "'s Activity Logs";

      const userLogs = logsByUser[username]?.logs || [];
      document.getElementById('folderContentSubtitle').textContent = userLogs.length + ' activity logs found';
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
                    <td colspan="9">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <h3>No Logs Found</h3>
                            <p>No activity logs found in this category.</p>
                        </div>
                    </td>
                </tr>
            `;
        return;
      }

      let html = '';
      logs.forEach(log => {
        const actionClass = getActionClass(log.log_type);
        const statusClass = getStatusClass(log.status);
        const logIdPadded = String(log.log_id).padStart(5, '0');
        const actionName = getDisplayActionName(log.log_type);
        const userName = (log.first_name && log.last_name) ?
          log.first_name + ' ' + log.last_name :
          'System';
        const roleName = log.role_name || 'Unknown';
        const officeName = log.office_name || 'N/A';
        const relatedEntity = formatRelatedEntity(log);

        html += `
                <tr id="log-row-${log.log_id}">
                    <td>
                        <span class="log-id-badge">L${logIdPadded}</span>
                    </td>
                    <td>
                        ${formatDateTime(log.created_at)}
                    </td>
                    <td>
                        <div>${escapeHtml(userName)}</div>
                        <small style="color: #666;">${escapeHtml(roleName)}</small>
                    </td>
                    <td>
                        <span class="action-badge ${actionClass}">
                            ${escapeHtml(actionName)}
                        </span>
                    </td>
                    <td>
                        ${escapeHtml(log.entity_type || 'System')}
                        ${log.entity_id ? `<br><small>ID: ${log.entity_id}</small>` : ''}
                        ${relatedEntity ? `<div class="related-entity-info">${relatedEntity}</div>` : ''}
                    </td>
                    <td>${escapeHtml(truncateText(log.action_description, 50))}</td>
                    <td>
                        <span class="status-badge ${statusClass}">
                            ${escapeHtml(log.status || 'Completed')}
                        </span>
                    </td>
                    <td>
                        <small>${escapeHtml(log.ip_address || 'N/A')}</small>
                    </td>
                    <td>
                        <button type="button" class="view-details-btn" onclick="toggleLogDetails(${log.log_id})">
                            View
                        </button>
                    </td>
                </tr>
                <tr class="log-details-row" id="log-details-${log.log_id}" style="display: none;">
                    <td colspan="9">
                        <div class="log-details-content">
                            <div class="log-details-grid">
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Log ID</div>
                                    <div class="log-detail-value">L${logIdPadded}</div>
                                </div>
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Timestamp</div>
                                    <div class="log-detail-value">${formatDateTimeFull(log.created_at)}</div>
                                </div>
                                <div class="log-detail-item">
                                    <div class="log-detail-label">User</div>
                                    <div class="log-detail-value">
                                        ${escapeHtml(userName)}<br>
                                        <small>${escapeHtml(log.email || 'N/A')}</small><br>
                                        <small>Role: ${escapeHtml(roleName)}</small><br>
                                        <small>Office: ${escapeHtml(officeName)}</small>
                                    </div>
                                </div>
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Action Type</div>
                                    <div class="log-detail-value">${escapeHtml(log.log_type)}</div>
                                </div>
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Entity</div>
                                    <div class="log-detail-value">
                                        ${escapeHtml(log.entity_type || 'System')}
                                        ${log.entity_id ? `<br><small>ID: ${log.entity_id}</small>` : ''}
                                    </div>
                                </div>
                                <div class="log-detail-item">
                                    <div class="log-detail-label">IP Address</div>
                                    <div class="log-detail-value">${escapeHtml(log.ip_address || 'N/A')}</div>
                                </div>
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Status</div>
                                    <div class="log-detail-value">
                                        <span class="status-badge ${statusClass}">
                                            ${escapeHtml(log.status || 'Completed')}
                                        </span>
                                    </div>
                                </div>
                                ${log.record_status_from && log.record_status_to ? `
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Record Status Change</div>
                                    <div class="log-detail-value">
                                        <div class="status-change">
                                            <span class="status-badge">${escapeHtml(log.record_status_from)}</span>
                                            <i class="fas fa-arrow-right status-change-arrow"></i>
                                            <span class="status-badge status-success">${escapeHtml(log.record_status_to)}</span>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                ${log.request_status_from && log.request_status_to ? `
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Request Status Change</div>
                                    <div class="log-detail-value">
                                        <div class="status-change">
                                            <span class="status-badge">${escapeHtml(log.request_status_from)}</span>
                                            <i class="fas fa-arrow-right status-change-arrow"></i>
                                            <span class="status-badge status-success">${escapeHtml(log.request_status_to)}</span>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                ${log.record_series_title ? `
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Record</div>
                                    <div class="log-detail-value">
                                        <strong>${escapeHtml(log.record_series_title)}</strong><br>
                                        Code: ${escapeHtml(log.record_series_code)}<br>
                                        Status: <span class="entity-status record-status">${escapeHtml(log.record_current_status)}</span>
                                    </div>
                                </div>
                                ` : ''}
                                ${log.disposal_agency_name ? `
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Disposal Request</div>
                                    <div class="log-detail-value">
                                        <strong>${escapeHtml(log.disposal_agency_name)}</strong><br>
                                        Date: ${formatDate(log.disposal_request_date)}<br>
                                        Status: <span class="entity-status disposal-status">${escapeHtml(log.disposal_request_status)}</span>
                                    </div>
                                </div>
                                ` : ''}
                                ${log.disposal_schedule_date ? `
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Disposal Schedule</div>
                                    <div class="log-detail-value">
                                        Date: ${formatDate(log.disposal_schedule_date)}<br>
                                        Status: <span class="entity-status disposal-status">${escapeHtml(log.disposal_schedule_status)}</span>
                                    </div>
                                </div>
                                ` : ''}
                                ${log.archive_request_status ? `
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Archive Request</div>
                                    <div class="log-detail-value">
                                        Date: ${formatDate(log.archive_request_date)}<br>
                                        Status: <span class="entity-status archive-status">${escapeHtml(log.archive_request_status)}</span>
                                    </div>
                                </div>
                                ` : ''}
                                ${log.classification_name ? `
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Classification</div>
                                    <div class="log-detail-value">${escapeHtml(log.classification_name)}</div>
                                </div>
                                ` : ''}
                                ${log.retention_period_name ? `
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Retention Period</div>
                                    <div class="log-detail-value">${escapeHtml(log.retention_period_name)}</div>
                                </div>
                                ` : ''}
                                ${log.file_name ? `
                                <div class="log-detail-item">
                                    <div class="log-detail-label">File</div>
                                    <div class="log-detail-value">
                                        <strong>${escapeHtml(log.file_name)}</strong><br>
                                        Type: ${escapeHtml(log.file_type)}<br>
                                        Path: ${escapeHtml(truncateText(log.file_path, 50))}
                                    </div>
                                </div>
                                ` : ''}
                                ${log.session_start_time ? `
                                <div class="log-detail-item">
                                    <div class="log-detail-label">Session</div>
                                    <div class="log-detail-value">
                                        Started: ${formatDateTimeFull(log.session_start_time)}<br>
                                        ${log.session_end_time ? `Ended: ${formatDateTimeFull(log.session_end_time)}` : ''}
                                    </div>
                                </div>
                                ` : ''}
                                <div class="log-detail-item full-width">
                                    <div class="log-detail-label">Description</div>
                                    <div class="log-detail-value">${escapeHtml(log.action_description).replace(/\n/g, '<br>')}</div>
                                </div>
                                ${log.old_values && log.old_values !== 'null' ? `
                                <div class="log-detail-item full-width">
                                    <div class="log-detail-label">Old Values</div>
                                    <div class="log-detail-value">
                                        <div class="json-display">${escapeHtml(formatJSON(log.old_values))}</div>
                                    </div>
                                </div>
                                ` : ''}
                                ${log.new_values && log.new_values !== 'null' ? `
                                <div class="log-detail-item full-width">
                                    <div class="log-detail-label">New Values</div>
                                    <div class="log-detail-value">
                                        <div class="json-display">${escapeHtml(formatJSON(log.new_values))}</div>
                                    </div>
                                </div>
                                ` : ''}
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
      if (detailsRow.style.display === 'none') {
        detailsRow.style.display = 'table-row';
      } else {
        detailsRow.style.display = 'none';
      }
    }

    // Reset all filters
    function resetFilters() {
      document.getElementById('filterForm').reset();
      window.location.href = 'reports.php';
    }

    // Helper functions
    function formatDateTime(dateTimeString) {
      if (!dateTimeString) return 'N/A';
      const date = new Date(dateTimeString);
      return date.toLocaleDateString('en-US') + ' | ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }

    function formatDateTimeFull(dateTimeString) {
      if (!dateTimeString) return 'N/A';
      return new Date(dateTimeString).toLocaleString('en-US');
    }

    function formatDate(dateString) {
      if (!dateString) return 'N/A';
      return new Date(dateString).toLocaleDateString('en-US');
    }

    function truncateText(text, maxLength) {
      if (!text) return '';
      if (text.length <= maxLength) return text;
      return text.substring(0, maxLength) + '...';
    }

    function formatJSON(jsonString) {
      try {
        const obj = JSON.parse(jsonString);
        return JSON.stringify(obj, null, 2);
      } catch (e) {
        return jsonString;
      }
    }

    function escapeHtml(text) {
      if (!text) return '';
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    // Event Listeners
    document.addEventListener('DOMContentLoaded', function () {
      // Initialize Select2 for action type dropdown
      $('#action_type').select2({
        placeholder: "Select action type",
        allowClear: true,
        width: '100%'
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
      document.getElementById('date_from').addEventListener('change', function () {
        const dateTo = document.getElementById('date_to');
        if (this.value > dateTo.value) {
          dateTo.value = this.value;
        }
      });

      document.getElementById('date_to').addEventListener('change', function () {
        const dateFrom = document.getElementById('date_from');
        if (this.value < dateFrom.value) {
          dateFrom.value = this.value;
        }
      });

      // Add keyboard shortcuts
      document.addEventListener('keydown', function (e) {
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

      // Add export functionality
      const exportButton = document.createElement('button');
      exportButton.type = 'button';
      exportButton.className = 'filter-button';
      exportButton.innerHTML = '<i class="fas fa-download"></i> Export';
      exportButton.style.marginLeft = '10px';
      exportButton.onclick = exportLogs;

      document.querySelector('.filter-buttons').appendChild(exportButton);
    });

    // Export logs function
    function exportLogs() {
      let logsToExport;

      // Determine which logs to export based on current filter
      switch (currentFilter) {
        case 'month':
          logsToExport = logsByMonth[currentFilterId]?.logs || [];
          break;
        case 'type':
          logsToExport = logsByType[currentFilterId]?.logs || [];
          break;
        case 'user':
          logsToExport = logsByUser[currentFilterId]?.logs || [];
          break;
        default:
          logsToExport = allLogs;
      }

      if (logsToExport.length === 0) {
        alert('No logs to export!');
        return;
      }

      // Create CSV content
      let csvContent = "Log ID,Timestamp,User,Action,Entity,Description,Status,IP Address\n";

      logsToExport.forEach(log => {
        const userName = (log.first_name && log.last_name) ?
          log.first_name + ' ' + log.last_name :
          'System';
        const actionName = getDisplayActionName(log.log_type);

        csvContent += `"L${String(log.log_id).padStart(5, '0')}",`;
        csvContent += `"${formatDateTimeFull(log.created_at)}",`;
        csvContent += `"${userName}",`;
        csvContent += `"${actionName}",`;
        csvContent += `"${log.entity_type || 'System'}",`;
        csvContent += `"${log.action_description ? log.action_description.replace(/"/g, '""') : ''}",`;
        csvContent += `"${log.status || 'Completed'}",`;
        csvContent += `"${log.ip_address || 'N/A'}"\n`;
      });

      // Create download link
      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.setAttribute('href', url);
      link.setAttribute('download', `activity_logs_${new Date().toISOString().slice(0, 10)}.csv`);
      link.style.visibility = 'hidden';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    }

    // Add search highlighting
    function highlightSearchText() {
      const searchText = document.getElementById('search_filter').value.toLowerCase();
      if (!searchText) return;

      const tbody = document.getElementById('logsTableBody');
      const rows = tbody.querySelectorAll('tr');

      rows.forEach(row => {
        if (row.classList.contains('log-details-row')) return;

        const text = row.textContent.toLowerCase();
        if (text.includes(searchText)) {
          row.style.backgroundColor = '#fff9c4';
          setTimeout(() => {
            row.style.backgroundColor = '';
          }, 1000);
        }
      });
    }

    // Add event listener for search input
    document.getElementById('search_filter')?.addEventListener('input', function () {
      if (this.value.length >= 3) {
        highlightSearchText();
      }
    });

    // Add bulk actions functionality
    function selectAllLogs(selectAll) {
      const checkboxes = document.querySelectorAll('.log-checkbox');
      checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll;
      });
    }

    // Add print functionality
    function printLogs() {
      const printWindow = window.open('', '_blank');
      printWindow.document.write(`
            <html>
            <head>
                <title>Activity Logs Report</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    h1 { color: #1f366c; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; }
                    .badge { padding: 3px 6px; border-radius: 3px; font-size: 12px; }
                    .status-success { background-color: #d4edda; color: #155724; }
                    .status-failed { background-color: #f8d7da; color: #721c24; }
                    .status-pending { background-color: #fff3cd; color: #856404; }
                </style>
            </head>
            <body>
                <h1>Activity Logs Report</h1>
                <p>Generated: ${new Date().toLocaleString()}</p>
                <p>Total Logs: ${allLogs.length}</p>
                <table>
                    <thead>
                        <tr>
                            <th>Log ID</th>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Description</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${allLogs.map(log => `
                            <tr>
                                <td>L${String(log.log_id).padStart(5, '0')}</td>
                                <td>${formatDateTimeFull(log.created_at)}</td>
                                <td>${log.first_name && log.last_name ? log.first_name + ' ' + log.last_name : 'System'}</td>
                                <td>${getDisplayActionName(log.log_type)}</td>
                                <td>${log.entity_type || 'System'}</td>
                                <td>${log.action_description ? log.action_description.substring(0, 50) + (log.action_description.length > 50 ? '...' : '') : ''}</td>
                                <td><span class="badge ${getStatusClass(log.status)}">${log.status || 'Completed'}</span></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </body>
            </html>
        `);
      printWindow.document.close();
      printWindow.print();
    }

    // Add print button
    document.addEventListener('DOMContentLoaded', function () {
      const printButton = document.createElement('button');
      printButton.type = 'button';
      printButton.className = 'filter-button';
      printButton.innerHTML = '<i class="fas fa-print"></i> Print';
      printButton.style.marginLeft = '10px';
      printButton.onclick = printLogs;

      document.querySelector('.filter-buttons').appendChild(printButton);
    });

    // Add auto-refresh functionality
    let autoRefreshInterval = null;

    function toggleAutoRefresh() {
      if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
        document.getElementById('autoRefreshBtn').innerHTML = '<i class="fas fa-sync-alt"></i> Auto Refresh';
        document.getElementById('autoRefreshBtn').style.backgroundColor = '#1f366c';
      } else {
        autoRefreshInterval = setInterval(() => {
          // Check for new logs
          location.reload();
        }, 30000); // Refresh every 30 seconds
        document.getElementById('autoRefreshBtn').innerHTML = '<i class="fas fa-stop"></i> Stop Refresh';
        document.getElementById('autoRefreshBtn').style.backgroundColor = '#dc3545';
      }
    }

    // Add auto-refresh button
    document.addEventListener('DOMContentLoaded', function () {
      const autoRefreshBtn = document.createElement('button');
      autoRefreshBtn.type = 'button';
      autoRefreshBtn.className = 'filter-button';
      autoRefreshBtn.id = 'autoRefreshBtn';
      autoRefreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Auto Refresh';
      autoRefreshBtn.onclick = toggleAutoRefresh;
      autoRefreshBtn.style.marginLeft = '10px';

      document.querySelector('.filter-buttons').appendChild(autoRefreshBtn);
    });

    // Handle window close to clear interval
    window.addEventListener('beforeunload', function () {
      if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
      }
    });
  </script>
</body>

</html>