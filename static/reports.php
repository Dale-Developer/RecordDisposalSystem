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
    // Build the query to show record creators, disposal approvers, and retention status
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
                  creator.first_name as creator_first_name,
                  creator.last_name as creator_last_name,
                  creator.email as creator_email,
                  creator.role_id as creator_role_id,
                  r.creator_office_id,
                  creator_office.office_name as creator_office_name,
                  
                  -- Disposal Request Information
                  dr.request_id as disposal_request_id,
                  dr.agency_name as disposal_agency_name,
                  dr.request_date as disposal_request_date,
                  dr.status as disposal_request_status,
                  dr.created_at as disposal_created_at,
                  dr.requested_by as disposal_created_by,
                  disposal_creator.first_name as disposal_creator_first_name,
                  disposal_creator.last_name as disposal_creator_last_name,
                  disposal_creator.email as disposal_creator_email,
                  disposal_creator.role_id as disposal_creator_role_id,
                  
                  -- Disposal Approval Information (from activity logs)
                  disposal_approver.first_name as disposal_approver_first_name,
                  disposal_approver.last_name as disposal_approver_last_name,
                  disposal_approver.role_id as disposal_approver_role_id,
                  disposal_approval.created_at as disposal_approval_date,
                  
                  -- Archive Request Information
                  ar.request_id as archive_request_id,
                  ar.request_date as archive_request_date,
                  ar.status as archive_request_status,
                  ar_requester.first_name as archive_requester_first_name,
                  ar_requester.last_name as archive_requester_last_name,
                  ar_requester.email as archive_requester_email,
                  ar_requester.role_id as archive_requester_role_id,
                  
                  -- Archive Approval Information (from activity logs)
                  archive_approver.first_name as archive_approver_first_name,
                  archive_approver.last_name as archive_approver_last_name,
                  archive_approver.role_id as archive_approver_role_id,
                  archive_approval.created_at as archive_approval_date,
                  
                  -- Retention Calculation
                  CASE 
                      WHEN r.period_to IS NOT NULL AND r.period_to <= CURDATE() THEN 'Retention Period Reached'
                      WHEN r.status = 'Archived' THEN 'Archived'
                      WHEN r.status = 'Disposed' THEN 'Disposed'
                      ELSE 'Active'
                  END as retention_status,
                  
                  -- Retention End Date
                  DATE_ADD(r.period_to, INTERVAL r.total_years YEAR) as retention_end_date,
                  
                  -- Log Type for display
                  'RECORD_INFO' as log_type
                  
              FROM records r
              LEFT JOIN offices o ON r.office_id = o.office_id
              LEFT JOIN record_classification rc ON r.class_id = rc.class_id
              LEFT JOIN retention_periods rp ON r.retention_period_id = rp.period_id
              LEFT JOIN users creator ON r.created_by = creator.user_id
              LEFT JOIN offices creator_office ON r.creator_office_id = creator_office.office_id
              
              -- Join for disposal requests related to this record
              LEFT JOIN disposal_request_details drd ON r.record_id = drd.record_id
              LEFT JOIN disposal_requests dr ON drd.request_id = dr.request_id
              LEFT JOIN users disposal_creator ON dr.requested_by = disposal_creator.user_id
              
              -- Join for disposal approval (from activity logs)
              LEFT JOIN activity_logs disposal_approval ON dr.request_id = disposal_approval.disposal_request_id 
                  AND disposal_approval.action_type = 'DISPOSAL_REQUEST_APPROVE'
              LEFT JOIN users disposal_approver ON disposal_approval.user_id = disposal_approver.user_id
              
              -- Join for archive requests related to this record
              LEFT JOIN archive_requests ar ON r.record_id = ar.record_id
              LEFT JOIN users ar_requester ON ar.requested_by = ar_requester.user_id
              
              -- Join for archive approval (from activity logs)
              LEFT JOIN activity_logs archive_approval ON ar.request_id = archive_approval.archive_request_id 
                  AND archive_approval.action_type = 'ARCHIVE_REQUEST_APPROVE'
              LEFT JOIN users archive_approver ON archive_approval.user_id = archive_approver.user_id
              
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
              dr.agency_name LIKE ? OR
              disposal_creator.first_name LIKE ? OR
              disposal_creator.last_name LIKE ?
          )";
      $search_param = "%$search_filter%";
      $params = array_merge($params, array_fill(0, 9, $search_param));
    }

    if ($date_from) {
      $sql .= " AND (DATE(r.date_created) >= ? OR DATE(dr.created_at) >= ? OR DATE(ar.request_date) >= ?)";
      $params[] = $date_from;
      $params[] = $date_from;
      $params[] = $date_from;
    }

    if ($date_to) {
      $sql .= " AND (DATE(r.date_created) <= ? OR DATE(dr.created_at) <= ? OR DATE(ar.request_date) <= ?)";
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
        $sql .= " AND (dr.status = ? OR ar.status = ?)";
        $params[] = $action_type;
        $params[] = $action_type;
      }
    }

    $sql .= " ORDER BY r.record_id DESC, dr.request_id DESC, ar.request_id DESC";

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
      $user_name = ($log['creator_first_name'] && $log['creator_last_name']) ? 
        $log['creator_first_name'] . ' ' . $log['creator_last_name'] : 'System';
      if ($user_name && trim($user_name) !== '' && $user_name !== 'System') {
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
    if (!$status)
      return 'status-pending';

    $lowerStatus = strtolower($status);
    if ($lowerStatus === 'active' || $lowerStatus === 'approved')
      return 'status-success';
    if ($lowerStatus === 'inactive' || $lowerStatus === 'pending' || $lowerStatus === 'scheduled for disposal')
      return 'status-pending';
    if ($lowerStatus === 'rejected')
      return 'status-failed';
    if ($lowerStatus === 'archived')
      return 'status-archive';
    if ($lowerStatus === 'disposed')
      return 'status-disposed';
    if ($lowerStatus === 'retention period reached')
      return 'status-warning';
    return 'status-pending';
  }

  // Function to format date display
  function formatDateDisplay($date)
  {
    if (!$date || $date === '0000-00-00')
      return '-';
    return date('Y-m-d', strtotime($date));
  }

  // Function to format retention info
  function formatRetentionInfo($log)
  {
    $info = [];
    
    if (!empty($log['period_from']) && !empty($log['period_to'])) {
      $period_from = formatDateDisplay($log['period_from']);
      $period_to = formatDateDisplay($log['period_to']);
      $info[] = "Period: {$period_from} to {$period_to}";
    }
    
    if (!empty($log['total_years'])) {
      $info[] = "Retention: {$log['total_years']} years";
    }
    
    if (!empty($log['retention_end_date'])) {
      $end_date = formatDateDisplay($log['retention_end_date']);
      $today = date('Y-m-d');
      if ($end_date < $today && $log['retention_status'] === 'Retention Period Reached') {
        $info[] = "<span class='text-danger'><strong>Ended: {$end_date}</strong></span>";
      } else {
        $info[] = "Ends: {$end_date}";
      }
    }
    
    return implode('<br>', $info);
  }

  // Function to get user office display (shows "Admin" for admin users)
  function getUserOfficeDisplay($log, $user_type = 'creator')
  {
    $role_id_field = $user_type . '_role_id';
    $office_field = $user_type . '_office_name';
    
    // Check if role_id is 1 (Admin)
    if (isset($log[$role_id_field]) && $log[$role_id_field] == 1) {
      return 'Admin';
    }
    
    // Return office name or 'N/A'
    return $log[$office_field] ?? 'N/A';
  }

  // Function to get display name with role indicator
  function getUserDisplayName($log, $user_type = 'creator')
  {
    $first_name_field = $user_type . '_first_name';
    $last_name_field = $user_type . '_last_name';
    $role_id_field = $user_type . '_role_id';
    
    $first_name = $log[$first_name_field] ?? '';
    $last_name = $log[$last_name_field] ?? '';
    $role_id = $log[$role_id_field] ?? null;
    
    if (!$first_name && !$last_name) {
      return 'System';
    }
    
    $name = trim("{$first_name} {$last_name}");
    
    // Add role indicator if admin
    if ($role_id == 1) {
      return $name . ' <span class="role-badge admin">(Admin)</span>';
    }
    
    return $name;
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
      /* All your original CSS styles remain exactly the same */
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
      .record-id-badge {
        display: inline-block;
        padding: 4px 8px;
        background-color: #f0f2f5;
        border-radius: 4px;
        font-family: 'Courier New', monospace;
        font-weight: 600;
        color: #2c3e50;
      }

      .status-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 500;
      }

      /* Badge colors */
      .status-success {
        background-color: #d4edda;
        color: #155724;
      }

      .status-pending {
        background-color: #fff3cd;
        color: #856404;
      }

      .status-failed {
        background-color: #f8d7da;
        color: #721c24;
      }

      .status-archive {
        background-color: #d6dbdf;
        color: #424949;
      }

      .status-disposed {
        background-color: #e8daef;
        color: #512e5f;
      }

      .status-warning {
        background-color: #fff3cd;
        color: #856404;
        border: 1px solid #ffeaa7;
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

      .role-badge.staff {
        background-color: #6c757d;
        color: white;
      }

      /* Responsive */
      @media (max-width: 1400px) {
        .records-table-view {
          min-width: 1400px;
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

      .text-danger {
        color: #dc3545 !important;
      }

      .text-success {
        color: #28a745 !important;
      }

      .text-warning {
        color: #ffc107 !important;
      }

      .user-info {
        font-size: 13px;
      }

      .user-info small {
        color: #666;
        display: block;
      }

      .admin-office {
        color: #1f366c;
        font-weight: 600;
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
                      <i class="fas fa-folder folder-icon" style="    color: #1976d2;"></i>
                      <div class="folder-info">
                        <div class="folder-name"><?= $month_data['month_year'] ?></div>
                        <div class="folder-count"><?= $record_count ?> records</div>
                      </div>
                    </div>
                  <?php endif; endforeach; ?>
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
                  <?php endif; endforeach; ?>
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
                      <i class="fas fa-folder folder-icon" style="color: #ff9800;;"></i>
                      <div class="folder-info">
                        <div class="folder-name"><?= htmlspecialchars($user_name) ?></div>
                        <div class="folder-count"><?= $record_count ?> records</div>
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
              <h2 id="currentFolderTitle">All Records & Requests</h2>
              <p id="currentFolderSubtitle">Browse records with creator info, disposal requests, and retention status</p>
            </div>
          </div>

          <!-- Search and Filter Bar -->
          <div class="search-filter-bar">
            <form method="POST" class="filter-form" id="filterForm">
              <div class="filter-group">
                <label for="search_filter">Search</label>
                <input type="text" id="search_filter" name="search_filter" class="filter-input" placeholder="Search records, creators, agencies..."
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
                <label for="action_type">Status Filter</label>
                <select id="action_type" name="action_type" class="filter-input">
                  <option value="">All Status</option>
                  <?php foreach ($available_action_types as $type):
                    if (!empty($type)): ?>
                      <option value="<?= htmlspecialchars($type) ?>" <?= $action_type == $type ? 'selected' : '' ?>>
                        <?= htmlspecialchars($type) ?>
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
              <h3 id="folderContentTitle">Records & Requests</h3>
              <p id="folderContentSubtitle"><?= $total_logs ?> records found</p>
            </div>

            <div class="folder-records-container">
              <table class="records-table-view">
                <thead>
                  <tr>
                    <th width="80">Record ID</th>
                    <th width="120">Record Info</th>
                    <th width="100">Office</th>
                    <th width="150">Creator Information</th>
                    <th width="150">Disposal Request</th>
                    <th width="150">Archive Request</th>
                    <th width="150">Retention Status</th>
                    <th width="80">Details</th>
                  </tr>
                </thead>
                <tbody id="logsTableBody">
                  <?php if (empty($logs)): ?>
                    <tr>
                      <td colspan="8">
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
                      ?>
                      <tr id="log-row-<?= $log['record_id'] ?>">
                        <td>
                          <span class="record-id-badge">R<?= $record_id_padded ?></span>
                        </td>
                        <td>
                          <div><strong><?= htmlspecialchars($log['record_series_title']) ?></strong></div>
                          <small>Code: <?= htmlspecialchars($log['record_series_code']) ?></small><br>
                          <small>Class: <?= htmlspecialchars($log['class_name']) ?></small><br>
                          <small>Created: <?= formatDateDisplay($log['record_created_date']) ?></small>
                        </td>
                        <td>
                          <?= htmlspecialchars($log['office_name']) ?>
                        </td>
                        <td class="user-info">
                          <div><?= $creator_name ?></div>
                          <small><?= htmlspecialchars($log['creator_email'] ?? '') ?></small><br>
                          <small class="<?= $log['creator_role_id'] == 1 ? 'admin-office' : '' ?>">
                            <?= htmlspecialchars($creator_office) ?>
                          </small><br>
                          <small>Created: <?= formatDateDisplay($log['record_created_date']) ?></small><br>
                          <small>Last Updated: <?= date('Y-m-d', strtotime($log['record_last_updated'])) ?></small>
                        </td>
                        <td class="user-info">
                          <?php if (!empty($log['disposal_agency_name'])): 
                            $disposal_creator_name = getUserDisplayName($log, 'disposal_creator');
                            $disposal_approver_name = !empty($log['disposal_approver_first_name']) ? 
                              getUserDisplayName($log, 'disposal_approver') : '';
                            ?>
                            <div><strong><?= htmlspecialchars($log['disposal_agency_name']) ?></strong></div>
                            <small>Requested: <?= formatDateDisplay($log['disposal_request_date']) ?></small><br>
                            <small>By: <?= $disposal_creator_name ?></small><br>
                            <?php if (!empty($log['disposal_approver_first_name'])): ?>
                              <small>Approved by: <?= $disposal_approver_name ?></small><br>
                              <small>On: <?= date('Y-m-d', strtotime($log['disposal_approval_date'])) ?></small>
                            <?php endif; ?>
                            <div style="margin-top: 5px;">
                              <span class="status-badge <?= getStatusClass($log['disposal_request_status']) ?>">
                                <?= htmlspecialchars($log['disposal_request_status'] ?? 'N/A') ?>
                              </span>
                            </div>
                          <?php else: ?>
                            <span class="text-warning">No disposal request</span>
                          <?php endif; ?>
                        </td>
                        <td class="user-info">
                          <?php if (!empty($log['archive_request_id'])): 
                            $archive_requester_name = getUserDisplayName($log, 'archive_requester');
                            $archive_approver_name = !empty($log['archive_approver_first_name']) ? 
                              getUserDisplayName($log, 'archive_approver') : '';
                            ?>
                            <div><strong>Archive Request</strong></div>
                            <small>Requested: <?= formatDateDisplay($log['archive_request_date']) ?></small><br>
                            <small>By: <?= $archive_requester_name ?></small><br>
                            <?php if (!empty($log['archive_approver_first_name'])): ?>
                              <small>Approved by: <?= $archive_approver_name ?></small><br>
                              <small>On: <?= date('Y-m-d', strtotime($log['archive_approval_date'])) ?></small>
                            <?php endif; ?>
                            <div style="margin-top: 5px;">
                              <span class="status-badge <?= getStatusClass($log['archive_request_status']) ?>">
                                <?= htmlspecialchars($log['archive_request_status'] ?? 'N/A') ?>
                              </span>
                            </div>
                          <?php else: ?>
                            <span class="text-warning">No archive request</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <div style="margin-bottom: 5px;">
                            <span class="status-badge <?= $retention_status_class ?>">
                              <?= htmlspecialchars($log['retention_status']) ?>
                            </span>
                          </div>
                          <div>
                            <small><?= formatRetentionInfo($log) ?></small>
                          </div>
                          <div style="margin-top: 5px;">
                            <span class="status-badge <?= $record_status_class ?>">
                              Record: <?= htmlspecialchars($log['record_status']) ?>
                            </span>
                          </div>
                        </td>
                        <td>
                          <button type="button" class="view-details-btn" onclick="toggleLogDetails(<?= $log['record_id'] ?>)">
                            View
                          </button>
                        </td>
                      </tr>
                      <tr class="log-details-row" id="log-details-<?= $log['record_id'] ?>" style="display: none;">
                        <td colspan="8">
                          <div class="log-details-content">
                            <div class="log-details-grid">
                              <!-- Record Information -->
                              <div class="log-detail-item full-width">
                                <div class="log-detail-label">Record Information</div>
                                <div class="log-detail-value">
                                  <strong>ID:</strong> R<?= $record_id_padded ?><br>
                                  <strong>Title:</strong> <?= htmlspecialchars($log['record_series_title']) ?><br>
                                  <strong>Code:</strong> <?= htmlspecialchars($log['record_series_code']) ?><br>
                                  <strong>Office:</strong> <?= htmlspecialchars($log['office_name']) ?><br>
                                  <strong>Classification:</strong> <?= htmlspecialchars($log['class_name']) ?>
                                </div>
                              </div>

                              <!-- Creator Information -->
                              <div class="log-detail-item">
                                <div class="log-detail-label">Created By</div>
                                <div class="log-detail-value">
                                  <?= $creator_name ?><br>
                                  Email: <?= htmlspecialchars($log['creator_email'] ?? 'N/A') ?><br>
                                  Office: <span class="<?= $log['creator_role_id'] == 1 ? 'admin-office' : '' ?>">
                                    <?= htmlspecialchars($creator_office) ?>
                                  </span><br>
                                  Created Date: <?= formatDateDisplay($log['record_created_date']) ?><br>
                                  Last Updated: <?= date('Y-m-d H:i:s', strtotime($log['record_last_updated'])) ?>
                                </div>
                              </div>

                              <!-- Period Information -->
                              <div class="log-detail-item">
                                <div class="log-detail-label">Period Covered</div>
                                <div class="log-detail-value">
                                  From: <?= formatDateDisplay($log['period_from']) ?><br>
                                  To: <?= formatDateDisplay($log['period_to']) ?><br>
                                  Active Years: <?= $log['active_years'] ?><br>
                                  Storage Years: <?= $log['storage_years'] ?><br>
                                  Total Years: <?= $log['total_years'] ?>
                                </div>
                              </div>

                              <!-- Retention Information -->
                              <div class="log-detail-item">
                                <div class="log-detail-label">Retention Information</div>
                                <div class="log-detail-value">
                                  Retention Period: <?= htmlspecialchars($log['period_name'] ?? 'N/A') ?><br>
                                  Retention Status: <span class="status-badge <?= $retention_status_class ?>">
                                    <?= htmlspecialchars($log['retention_status']) ?>
                                  </span><br>
                                  <?php if (!empty($log['retention_end_date'])): 
                                    $end_date = formatDateDisplay($log['retention_end_date']);
                                    $today = date('Y-m-d');
                                    if ($end_date < $today): ?>
                                      <span class="text-danger"><strong>Retention Ended: <?= $end_date ?></strong></span>
                                    <?php else: ?>
                                      Retention Ends: <?= $end_date ?>
                                    <?php endif; ?>
                                  <?php endif; ?>
                                </div>
                              </div>

                              <!-- Record Status -->
                              <div class="log-detail-item">
                                <div class="log-detail-label">Record Status</div>
                                <div class="log-detail-value">
                                  <span class="status-badge <?= $record_status_class ?>">
                                    <?= htmlspecialchars($log['record_status']) ?>
                                  </span><br>
                                  Disposition Type: <?= htmlspecialchars($log['disposition_type']) ?>
                                </div>
                              </div>

                              <!-- Disposal Request Details -->
                              <?php if (!empty($log['disposal_agency_name'])): 
                                $disposal_creator_name = getUserDisplayName($log, 'disposal_creator');
                                $disposal_approver_name = !empty($log['disposal_approver_first_name']) ? 
                                  getUserDisplayName($log, 'disposal_approver') : '';
                                $disposal_creator_office = getUserOfficeDisplay($log, 'disposal_creator');
                                $disposal_approver_office = !empty($log['disposal_approver_first_name']) ? 
                                  getUserOfficeDisplay($log, 'disposal_approver') : '';
                                ?>
                                <div class="log-detail-item">
                                  <div class="log-detail-label">Disposal Request</div>
                                  <div class="log-detail-value">
                                    <strong>ID:</strong> DR<?= str_pad($log['disposal_request_id'], 5, '0', STR_PAD_LEFT) ?><br>
                                    <strong>Agency:</strong> <?= htmlspecialchars($log['disposal_agency_name']) ?><br>
                                    <strong>Request Date:</strong> <?= formatDateDisplay($log['disposal_request_date']) ?><br>
                                    <strong>Created By:</strong> <?= $disposal_creator_name ?><br>
                                    <small>Office: <?= htmlspecialchars($disposal_creator_office) ?></small><br>
                                    <?php if (!empty($log['disposal_approver_first_name'])): ?>
                                      <strong>Approved By:</strong> <?= $disposal_approver_name ?><br>
                                      <small>Office: <?= htmlspecialchars($disposal_approver_office) ?></small><br>
                                      <strong>Approval Date:</strong> <?= date('Y-m-d H:i:s', strtotime($log['disposal_approval_date'])) ?>
                                    <?php endif; ?>
                                    <div style="margin-top: 5px;">
                                      Status: <span class="status-badge <?= getStatusClass($log['disposal_request_status']) ?>">
                                        <?= htmlspecialchars($log['disposal_request_status']) ?>
                                      </span>
                                    </div>
                                  </div>
                                </div>
                              <?php endif; ?>

                              <!-- Archive Request Details -->
                              <?php if (!empty($log['archive_request_id'])): 
                                $archive_requester_name = getUserDisplayName($log, 'archive_requester');
                                $archive_approver_name = !empty($log['archive_approver_first_name']) ? 
                                  getUserDisplayName($log, 'archive_approver') : '';
                                $archive_requester_office = getUserOfficeDisplay($log, 'archive_requester');
                                $archive_approver_office = !empty($log['archive_approver_first_name']) ? 
                                  getUserOfficeDisplay($log, 'archive_approver') : '';
                                ?>
                                <div class="log-detail-item">
                                  <div class="log-detail-label">Archive Request</div>
                                  <div class="log-detail-value">
                                    <strong>ID:</strong> AR<?= str_pad($log['archive_request_id'], 5, '0', STR_PAD_LEFT) ?><br>
                                    <strong>Request Date:</strong> <?= formatDateDisplay($log['archive_request_date']) ?><br>
                                    <strong>Requested By:</strong> <?= $archive_requester_name ?><br>
                                    <small>Office: <?= htmlspecialchars($archive_requester_office) ?></small><br>
                                    <?php if (!empty($log['archive_approver_first_name'])): ?>
                                      <strong>Approved By:</strong> <?= $archive_approver_name ?><br>
                                      <small>Office: <?= htmlspecialchars($archive_approver_office) ?></small><br>
                                      <strong>Approval Date:</strong> <?= date('Y-m-d H:i:s', strtotime($log['archive_approval_date'])) ?>
                                    <?php endif; ?>
                                    <div style="margin-top: 5px;">
                                      Status: <span class="status-badge <?= getStatusClass($log['archive_request_status']) ?>">
                                        <?= htmlspecialchars($log['archive_request_status']) ?>
                                      </span>
                                    </div>
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

      // Helper functions for JavaScript
      function getUserOfficeDisplay(log, userType = 'creator') {
        const roleIdField = userType + '_role_id';
        const officeField = userType + '_office_name';
        
        // Check if role_id is 1 (Admin)
        if (log[roleIdField] == 1) {
          return 'Admin';
        }
        
        // Return office name or 'N/A'
        return log[officeField] || 'N/A';
      }

      function getUserDisplayName(log, userType = 'creator') {
        const firstNameField = userType + '_first_name';
        const lastNameField = userType + '_last_name';
        const roleIdField = userType + '_role_id';
        
        const firstName = log[firstNameField] || '';
        const lastName = log[lastNameField] || '';
        const roleId = log[roleIdField] || null;
        
        if (!firstName && !lastName) {
          return 'System';
        }
        
        const name = escapeHtml(firstName + ' ' + lastName).trim();
        
        // Add role indicator if admin
        if (roleId == 1) {
          return name + ' <span class="role-badge admin">(Admin)</span>';
        }
        
        return name;
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
                      <td colspan="8">
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

          html += `
                  <tr id="log-row-${log.record_id}">
                      <td>
                          <span class="record-id-badge">R${recordIdPadded}</span>
                      </td>
                      <td>
                          <div><strong>${escapeHtml(log.record_series_title)}</strong></div>
                          <small>Code: ${escapeHtml(log.record_series_code)}</small><br>
                          <small>Class: ${escapeHtml(log.class_name)}</small><br>
                          <small>Created: ${formatDateDisplay(log.record_created_date)}</small>
                      </td>
                      <td>
                          ${escapeHtml(log.office_name)}
                      </td>
                      <td class="user-info">
                          <div>${creatorName}</div>
                          <small>${escapeHtml(log.creator_email || '')}</small><br>
                          <small class="${log.creator_role_id == 1 ? 'admin-office' : ''}">
                            ${escapeHtml(creatorOffice)}
                          </small><br>
                          <small>Created: ${formatDateDisplay(log.record_created_date)}</small><br>
                          <small>Last Updated: ${formatDateTime(log.record_last_updated)}</small>
                      </td>
                      <td class="user-info">
                          ${log.disposal_agency_name ? 
                            (() => {
                              const disposalCreatorName = getUserDisplayName(log, 'disposal_creator');
                              const disposalApproverName = log.disposal_approver_first_name ? 
                                getUserDisplayName(log, 'disposal_approver') : '';
                              return `
                              <div><strong>${escapeHtml(log.disposal_agency_name)}</strong></div>
                              <small>Requested: ${formatDateDisplay(log.disposal_request_date)}</small><br>
                              <small>By: ${disposalCreatorName}</small><br>
                              ${log.disposal_approver_first_name ? `
                              <small>Approved by: ${disposalApproverName}</small><br>
                              <small>On: ${formatDateDisplay(log.disposal_approval_date)}</small>
                              ` : ''}
                              <div style="margin-top: 5px;">
                                  <span class="status-badge ${getStatusClass(log.disposal_request_status)}">
                                      ${escapeHtml(log.disposal_request_status || 'N/A')}
                                  </span>
                              </div>
                              `;
                            })() : `
                          <span class="text-warning">No disposal request</span>
                          `}
                      </td>
                      <td class="user-info">
                          ${log.archive_request_id ? 
                            (() => {
                              const archiveRequesterName = getUserDisplayName(log, 'archive_requester');
                              const archiveApproverName = log.archive_approver_first_name ? 
                                getUserDisplayName(log, 'archive_approver') : '';
                              return `
                              <div><strong>Archive Request</strong></div>
                              <small>Requested: ${formatDateDisplay(log.archive_request_date)}</small><br>
                              <small>By: ${archiveRequesterName}</small><br>
                              ${log.archive_approver_first_name ? `
                              <small>Approved by: ${archiveApproverName}</small><br>
                              <small>On: ${formatDateDisplay(log.archive_approval_date)}</small>
                              ` : ''}
                              <div style="margin-top: 5px;">
                                  <span class="status-badge ${getStatusClass(log.archive_request_status)}">
                                      ${escapeHtml(log.archive_request_status || 'N/A')}
                                  </span>
                              </div>
                              `;
                            })() : `
                          <span class="text-warning">No archive request</span>
                          `}
                      </td>
                      <td>
                          <div style="margin-bottom: 5px;">
                              <span class="status-badge ${retentionStatusClass}">
                                  ${escapeHtml(log.retention_status)}
                              </span>
                          </div>
                          <div>
                              <small>${formatRetentionInfo(log)}</small>
                          </div>
                          <div style="margin-top: 5px;">
                              <span class="status-badge ${recordStatusClass}">
                                  Record: ${escapeHtml(log.record_status)}
                              </span>
                          </div>
                      </td>
                      <td>
                          <button type="button" class="view-details-btn" onclick="toggleLogDetails(${log.record_id})">
                              View
                          </button>
                      </td>
                  </tr>
                  <tr class="log-details-row" id="log-details-${log.record_id}" style="display: none;">
                      <td colspan="8">
                          <div class="log-details-content">
                              <div class="log-details-grid">
                                  <div class="log-detail-item full-width">
                                      <div class="log-detail-label">Record Information</div>
                                      <div class="log-detail-value">
                                          <strong>ID:</strong> R${recordIdPadded}<br>
                                          <strong>Title:</strong> ${escapeHtml(log.record_series_title)}<br>
                                          <strong>Code:</strong> ${escapeHtml(log.record_series_code)}<br>
                                          <strong>Office:</strong> ${escapeHtml(log.office_name)}<br>
                                          <strong>Classification:</strong> ${escapeHtml(log.class_name)}
                                      </div>
                                  </div>
                                  <div class="log-detail-item">
                                      <div class="log-detail-label">Created By</div>
                                      <div class="log-detail-value">
                                          ${creatorName}<br>
                                          Email: ${escapeHtml(log.creator_email || 'N/A')}<br>
                                          Office: <span class="${log.creator_role_id == 1 ? 'admin-office' : ''}">
                                            ${escapeHtml(creatorOffice)}
                                          </span><br>
                                          Created Date: ${formatDateDisplay(log.record_created_date)}<br>
                                          Last Updated: ${formatDateTimeFull(log.record_last_updated)}
                                      </div>
                                  </div>
                                  <div class="log-detail-item">
                                      <div class="log-detail-label">Period Covered</div>
                                      <div class="log-detail-value">
                                          From: ${formatDateDisplay(log.period_from)}<br>
                                          To: ${formatDateDisplay(log.period_to)}<br>
                                          Active Years: ${log.active_years}<br>
                                          Storage Years: ${log.storage_years}<br>
                                          Total Years: ${log.total_years}
                                      </div>
                                  </div>
                                  <div class="log-detail-item">
                                      <div class="log-detail-label">Retention Information</div>
                                      <div class="log-detail-value">
                                          Retention Period: ${escapeHtml(log.period_name || 'N/A')}<br>
                                          Retention Status: <span class="status-badge ${retentionStatusClass}">
                                              ${escapeHtml(log.retention_status)}
                                          </span><br>
                                          ${log.retention_end_date ? `
                                          ${formatDateDisplay(log.retention_end_date) < new Date().toISOString().split('T')[0] ? 
                                              `<span class="text-danger"><strong>Retention Ended: ${formatDateDisplay(log.retention_end_date)}</strong></span>` :
                                              `Retention Ends: ${formatDateDisplay(log.retention_end_date)}`
                                          }
                                          ` : ''}
                                      </div>
                                  </div>
                                  <div class="log-detail-item">
                                      <div class="log-detail-label">Record Status</div>
                                      <div class="log-detail-value">
                                          <span class="status-badge ${recordStatusClass}">
                                              ${escapeHtml(log.record_status)}
                                          </span><br>
                                          Disposition Type: ${escapeHtml(log.disposition_type)}
                                      </div>
                                  </div>
                                  ${log.disposal_agency_name ? 
                                    (() => {
                                      const disposalCreatorName = getUserDisplayName(log, 'disposal_creator');
                                      const disposalApproverName = log.disposal_approver_first_name ? 
                                        getUserDisplayName(log, 'disposal_approver') : '';
                                      const disposalCreatorOffice = getUserOfficeDisplay(log, 'disposal_creator');
                                      const disposalApproverOffice = log.disposal_approver_first_name ? 
                                        getUserOfficeDisplay(log, 'disposal_approver') : '';
                                      return `
                                      <div class="log-detail-item">
                                          <div class="log-detail-label">Disposal Request</div>
                                          <div class="log-detail-value">
                                              <strong>ID:</strong> DR${String(log.disposal_request_id).padStart(5, '0')}<br>
                                              <strong>Agency:</strong> ${escapeHtml(log.disposal_agency_name)}<br>
                                              <strong>Request Date:</strong> ${formatDateDisplay(log.disposal_request_date)}<br>
                                              <strong>Created By:</strong> ${disposalCreatorName}<br>
                                              <small>Office: ${escapeHtml(disposalCreatorOffice)}</small><br>
                                              ${log.disposal_approver_first_name ? `
                                              <strong>Approved By:</strong> ${disposalApproverName}<br>
                                              <small>Office: ${escapeHtml(disposalApproverOffice)}</small><br>
                                              <strong>Approval Date:</strong> ${formatDateTimeFull(log.disposal_approval_date)}
                                              ` : ''}
                                              <div style="margin-top: 5px;">
                                                  Status: <span class="status-badge ${getStatusClass(log.disposal_request_status)}">
                                                      ${escapeHtml(log.disposal_request_status)}
                                                  </span>
                                              </div>
                                          </div>
                                      </div>
                                      `;
                                    })() : ''}
                                  ${log.archive_request_id ? 
                                    (() => {
                                      const archiveRequesterName = getUserDisplayName(log, 'archive_requester');
                                      const archiveApproverName = log.archive_approver_first_name ? 
                                        getUserDisplayName(log, 'archive_approver') : '';
                                      const archiveRequesterOffice = getUserOfficeDisplay(log, 'archive_requester');
                                      const archiveApproverOffice = log.archive_approver_first_name ? 
                                        getUserOfficeDisplay(log, 'archive_approver') : '';
                                      return `
                                      <div class="log-detail-item">
                                          <div class="log-detail-label">Archive Request</div>
                                          <div class="log-detail-value">
                                              <strong>ID:</strong> AR${String(log.archive_request_id).padStart(5, '0')}<br>
                                              <strong>Request Date:</strong> ${formatDateDisplay(log.archive_request_date)}<br>
                                              <strong>Requested By:</strong> ${archiveRequesterName}<br>
                                              <small>Office: ${escapeHtml(archiveRequesterOffice)}</small><br>
                                              ${log.archive_approver_first_name ? `
                                              <strong>Approved By:</strong> ${archiveApproverName}<br>
                                              <small>Office: ${escapeHtml(archiveApproverOffice)}</small><br>
                                              <strong>Approval Date:</strong> ${formatDateTimeFull(log.archive_approval_date)}
                                              ` : ''}
                                              <div style="margin-top: 5px;">
                                                  Status: <span class="status-badge ${getStatusClass(log.archive_request_status)}">
                                                      ${escapeHtml(log.archive_request_status)}
                                                  </span>
                                              </div>
                                          </div>
                                      </div>
                                      `;
                                    })() : ''}
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
        if (detailsRow.style.display === 'none') {
          detailsRow.style.display = 'table-row';
        } else {
          detailsRow.style.display = 'none';
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

      function formatDateTime(dateTimeString) {
        if (!dateTimeString) return '-';
        const date = new Date(dateTimeString);
        return date.toLocaleDateString('en-US');
      }

      function formatDateTimeFull(dateTimeString) {
        if (!dateTimeString) return '-';
        return new Date(dateTimeString).toLocaleString('en-US');
      }

      function formatRetentionInfo(log) {
        let info = '';
        
        if (log.period_from && log.period_to) {
          const periodFrom = formatDateDisplay(log.period_from);
          const periodTo = formatDateDisplay(log.period_to);
          info += `Period: ${periodFrom} to ${periodTo}<br>`;
        }
        
        if (log.total_years) {
          info += `Retention: ${log.total_years} years<br>`;
        }
        
        if (log.retention_end_date) {
          const endDate = formatDateDisplay(log.retention_end_date);
          const today = new Date().toISOString().split('T')[0];
          if (endDate < today && log.retention_status === 'Retention Period Reached') {
            info += `<span class="text-danger"><strong>Ended: ${endDate}</strong></span>`;
          } else {
            info += `Ends: ${endDate}`;
          }
        }
        
        return info;
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
      document.addEventListener('DOMContentLoaded', function () {
        // Initialize Select2 for status filter
        $('#action_type').select2({
          placeholder: "Select status",
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
      });
    </script>
  </body>

  </html>