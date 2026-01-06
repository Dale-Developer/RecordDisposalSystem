<?php
require_once '../session.php';
require_once '../db_connect.php';

// ========== AUTO-UPDATE EXPIRED RECORDS ==========
$current_date = date('Y-m-d');

try {
    $update_archive_sql = "UPDATE records 
                           SET status = 'Archived' 
                           WHERE status IN ('Active', 'Inactive') 
                           AND period_to IS NOT NULL 
                           AND period_to < :current_date";
    
    $update_stmt = $pdo->prepare($update_archive_sql);
    $update_stmt->execute(['current_date' => $current_date]);
    $archived_count = $update_stmt->rowCount();
    
    $update_disposal_sql = "UPDATE records 
                            SET status = 'Scheduled for Disposal' 
                            WHERE status IN ('Active', 'Inactive')
                            AND total_years > 0
                            AND period_from IS NOT NULL
                            AND DATE_ADD(period_from, INTERVAL total_years YEAR) < :current_date";
    
    $update_disposal_stmt = $pdo->prepare($update_disposal_sql);
    $update_disposal_stmt->execute(['current_date' => $current_date]);
    $disposal_count = $update_stmt->rowCount();
    
    $total_updated = $archived_count + $disposal_count;
    
    if ($total_updated > 0) {
        $_SESSION['records_updated'] = $total_updated;
        $_SESSION['update_time'] = date('H:i:s');
    }
    
} catch (Exception $e) {
    error_log("Auto-update error: " . $e->getMessage());
}
// ========== END AUTO-UPDATE ==========

// Initialize variables
$records = [];
$offices = [];
$department_counts = [];

// Check if database connection is established
if (!isset($pdo)) {
  die("Database connection failed. Please check your database configuration.");
}

try {
  // Fetch all departments/offices
  $offices = $pdo->query("SELECT * FROM offices ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);
  
  // Fetch archived records
  $sql = "SELECT 
            r.record_id,
            r.record_series_code,
            r.record_series_title,
            o.office_id,
            o.office_name,
            r.period_from,
            r.period_to,
            r.active_years,
            r.storage_years,
            r.total_years,
            r.disposition_type,
            r.status,
            r.time_value,
            rc.class_name,
            rc.functional_category,
            u.first_name,
            u.last_name,
            r.created_at,
            r.updated_at
          FROM records r
          LEFT JOIN offices o ON r.office_id = o.office_id
          LEFT JOIN record_classification rc ON r.class_id = rc.class_id
          LEFT JOIN users u ON r.created_by = u.user_id
          WHERE r.status = 'Archived'  
          ORDER BY r.updated_at DESC, r.record_id DESC";

  $stmt = $pdo->query($sql);
  $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Get archived record counts per department
  foreach ($offices as $office) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM records WHERE office_id = ? AND status = 'Archived'");
    $stmt->execute([$office['office_id']]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $department_counts[$office['office_id']] = $count;
  }
  
  // Group records by department for folder display
  $records_by_department = [];
  foreach ($records as $record) {
    $dept_id = $record['office_id'];
    if (!isset($records_by_department[$dept_id])) {
      $records_by_department[$dept_id] = [
        'department_name' => $record['office_name'],
        'records' => []
      ];
    }
    $records_by_department[$dept_id]['records'][] = $record;
  }
  
  // Group records by year for year folders
  $records_by_year = [];
  foreach ($records as $record) {
    $year = $record['period_to'] ? date('Y', strtotime($record['period_to'])) : 
            ($record['period_from'] ? date('Y', strtotime($record['period_from'])) : 
            date('Y', strtotime($record['updated_at'])));
    
    if (!isset($records_by_year[$year])) {
      $records_by_year[$year] = [
        'year' => $year,
        'records' => []
      ];
    }
    $records_by_year[$year]['records'][] = $record;
  }
  
  // Group records by month-year for month folders
  $records_by_month = [];
  foreach ($records as $record) {
    $month_year = $record['period_to'] ? date('F Y', strtotime($record['period_to'])) : 
                 ($record['period_from'] ? date('F Y', strtotime($record['period_from'])) : 
                 date('F Y', strtotime($record['updated_at'])));
    
    $month_key = $record['period_to'] ? date('Y-m', strtotime($record['period_to'])) : 
                ($record['period_from'] ? date('Y-m', strtotime($record['period_from'])) : 
                date('Y-m', strtotime($record['updated_at'])));
    
    if (!isset($records_by_month[$month_key])) {
      $records_by_month[$month_key] = [
        'month_year' => $month_year,
        'records' => []
      ];
    }
    $records_by_month[$month_key]['records'][] = $record;
  }

} catch (PDOException $e) {
  error_log("Database error: " . $e->getMessage());
  error_log("Error trace: " . $e->getTraceAsString());
  die("Database error occurred. Please check the error logs. Error: " . $e->getMessage());
}

// Check for update notification
$show_update_notification = false;
$update_message = '';
if (isset($_SESSION['records_updated']) && $_SESSION['records_updated'] > 0) {
    $show_update_notification = true;
    $update_message = "Updated " . $_SESSION['records_updated'] . " expired records to 'Archived' status at " . $_SESSION['update_time'];
    unset($_SESSION['records_updated']);
    unset($_SESSION['update_time']);
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
  <title>Archived Records</title>
  <style>
    /* Main container */
    .archive-container {
      display: grid;
      grid-template-columns: 280px 1fr;
      gap: 25px;
      height: calc(100vh - 120px);
      transition: grid-template-columns 0.3s ease;
    }
    
    .archive-container.sidebar-collapsed {
      grid-template-columns: 60px 1fr;
    }
    
    /* Folder Sidebar */
    .folders-sidebar {
      background: white;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      padding: 20px;
      overflow-y: auto;
      position: relative;
      transition: all 0.3s ease;
    }
    
    .archive-container.sidebar-collapsed .folders-sidebar {
      padding: 20px 10px;
      overflow: visible;
    }
    
    .folders-header {
      margin-bottom: 25px;
      padding-bottom: 15px;
      border-bottom: 2px solid #e0e0e0;
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: relative;
    }
    
    .archive-container.sidebar-collapsed .folders-header {
      flex-direction: column;
      gap: 10px;
      border-bottom: none;
      margin-bottom: 15px;
    }
    
    .folders-header h3 {
      margin: 0;
      color: #1f366c;
      font-size: 18px;
      transition: opacity 0.3s ease;
    }
    
    .archive-container.sidebar-collapsed .folders-header h3 {
      display: none;
    }
    
    .sidebar-toggle-btn {
      background: #f5f5f5;
      border: 1px solid #e0e0e0;
      border-radius: 6px;
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      color: #1f366c;
      transition: all 0.3s ease;
      flex-shrink: 0;
    }
    
    .sidebar-toggle-btn:hover {
      background: #1f366c;
      color: white;
      border-color: #1f366c;
    }
    
    .archive-container.sidebar-collapsed .sidebar-toggle-btn {
      transform: rotate(180deg);
      width: 40px;
      height: 40px;
    }
    
    /* Folder Groups */
    .folder-group {
      margin-bottom: 25px;
      transition: all 0.3s ease;
    }
    
    .archive-container.sidebar-collapsed .folder-group {
      margin-bottom: 20px;
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
      transition: opacity 0.3s ease;
    }
    
    .archive-container.sidebar-collapsed .folder-group-title {
      justify-content: center;
      margin-bottom: 10px;
    }
    
    .archive-container.sidebar-collapsed .folder-group-title span {
      display: none;
    }
    
    .folder-group-title i {
      color: #1f366c;
      flex-shrink: 0;
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
      position: relative;
      overflow: hidden;
    }
    
    .archive-container.sidebar-collapsed .folder-item {
      padding: 12px;
      justify-content: center;
    }
    
    .folder-item:hover {
      background-color: #f5f5f5;
      border-color: #1f366c;
      transform: translateX(5px);
    }
    
    .archive-container.sidebar-collapsed .folder-item:hover {
      transform: translateX(0);
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
      flex-shrink: 0;
    }
    
    .archive-container.sidebar-collapsed .folder-icon {
      margin-right: 0;
    }
    
    .department-folder .folder-icon {
      color: #1976d2;
    }
    
    .year-folder .folder-icon {
      color: #4caf50;
    }
    
    .month-folder .folder-icon {
      color: #ff9800;
    }
    
    .folder-item.active .folder-icon {
      color: white;
    }
    
    .folder-info {
      flex: 1;
      transition: opacity 0.3s ease, max-width 0.3s ease;
      overflow: hidden;
      max-width: 100%;
    }
    
    .archive-container.sidebar-collapsed .folder-info {
      opacity: 0;
      max-width: 0;
      position: absolute;
    }
    
    .folder-name {
      font-weight: 600;
      font-size: 14px;
      margin-bottom: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    .folder-count {
      font-size: 12px;
      opacity: 0.7;
    }
    
    .folder-item.active .folder-count {
      color: rgba(255,255,255,0.9);
    }
    
    /* Tooltip for collapsed state */
    .folder-item .folder-tooltip {
      position: absolute;
      left: calc(100% + 10px);
      top: 50%;
      transform: translateY(-50%);
      background: #1f366c;
      color: white;
      padding: 8px 12px;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 500;
      white-space: nowrap;
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.3s ease, visibility 0.3s ease;
      pointer-events: none;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .folder-item .folder-tooltip::before {
      content: '';
      position: absolute;
      left: -5px;
      top: 50%;
      transform: translateY(-50%);
      border-width: 5px 5px 5px 0;
      border-style: solid;
      border-color: transparent #1f366c transparent transparent;
    }
    
    .archive-container.sidebar-collapsed .folder-item:hover .folder-tooltip {
      opacity: 1;
      visibility: visible;
    }
    
    /* Collapsed sidebar icons */
    .folder-item .collapsed-icon {
      display: none;
      font-size: 18px;
    }
    
    .archive-container.sidebar-collapsed .folder-item .collapsed-icon {
      display: block;
    }
    
    .archive-container.sidebar-collapsed .folder-item .folder-icon:not(.collapsed-icon) {
      display: none;
    }
    
    /* All Records Folder in collapsed state */
    .archive-container.sidebar-collapsed #allRecordsFolder {
      padding: 12px;
    }
    
    .archive-container.sidebar-collapsed #allRecordsFolder .collapsed-icon {
      font-size: 20px;
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
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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
    
    /* Records Grid View (Folder-style) */
    .records-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 20px;
    }
    
    .record-card {
      background: white;
      border-radius: 8px;
      padding: 20px;
      border: 1px solid #e0e0e0;
      transition: all 0.3s ease;
      cursor: pointer;
      position: relative;
    }
    
    .record-card:hover {
      border-color: #1f366c;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      transform: translateY(-2px);
    }
    
    .record-card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 15px;
    }
    
    .record-icon {
      font-size: 24px;
      color: #1f366c;
      background: #e3f2fd;
      width: 48px;
      height: 48px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .record-badge {
      background: #e8f5e9;
      color: #2e7d32;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 600;
    }
    
    .record-title {
      font-weight: 600;
      color: #1f366c;
      margin-bottom: 8px;
      font-size: 16px;
      line-height: 1.4;
    }
    
    .record-details {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-bottom: 15px;
    }
    
    .record-detail {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      color: #666;
    }
    
    .record-detail i {
      width: 16px;
      color: #78909c;
    }
    
    .record-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-top: 15px;
      border-top: 1px solid #f0f0f0;
    }
    
    .record-date {
      font-size: 12px;
      color: #78909c;
    }
    
    .record-actions {
      display: flex;
      gap: 8px;
    }
    
    .record-action-btn {
      background: none;
      border: none;
      color: #1f366c;
      cursor: pointer;
      padding: 6px;
      border-radius: 4px;
      transition: background 0.3s ease;
    }
    
    .record-action-btn:hover {
      background: #f0f4ff;
    }
    
    /* Table View (Alternative) */
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
    
    /* View Toggle */
    .view-toggle {
      display: flex;
      gap: 10px;
      margin-bottom: 20px;
    }
    
    .view-toggle-btn {
      background: #f5f5f5;
      border: 1px solid #e0e0e0;
      border-radius: 6px;
      padding: 8px 16px;
      cursor: pointer;
      font-size: 13px;
      color: #666;
      display: flex;
      align-items: center;
      gap: 8px;
      transition: all 0.3s ease;
    }
    
    .view-toggle-btn.active {
      background: #1f366c;
      color: white;
      border-color: #1f366c;
    }
    
    .view-toggle-btn:hover {
      border-color: #1f366c;
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
    
    /* Active Folder Indicator */
    .active-folder-indicator {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 15px;
      background: #e3f2fd;
      border-radius: 8px;
      margin-bottom: 20px;
    }
    
    .active-folder-indicator i {
      color: #1976d2;
      font-size: 20px;
    }
    
    .active-folder-indicator span {
      font-weight: 600;
      color: #1f366c;
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
    
    /* Badge Styles */
    .department-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      background: #e8f5e9;
      color: #2e7d32;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
    }
    
    .time-value-badge {
      background: #e8f5e9;
      color: #2e7d32;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 0.8em;
      font-weight: bold;
      display: inline-block;
    }

    .time-value-badge.permanent {
      background: #e3f2fd;
      color: #1976d2;
    }

    .time-value-badge.temporary {
      background: #fff3e0;
      color: #ef6c00;
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
        <h1>ARCHIVED RECORDS</h1>
      </div>

      <div class="actions">
        <!-- <button class="button filter-button" onclick="showFilterModal()">
          Filter
          <i class='bx bx-filter'></i>
        </button> -->
      </div>
    </header>
    
    <div class="archive-container">
      <!-- Folders Sidebar -->
      <div class="folders-sidebar">
        <div class="folders-header">
          <h3>Archive Folders</h3>
          <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Collapse/Expand Sidebar">
            <i class="fas fa-chevron-left"></i>
          </button>
        </div>
        
        <!-- All Records Folder -->
        <div class="folder-group">
          <div class="folder-group-title">
            <i class="fas fa-archive"></i>
            <span>All Records</span>
          </div>
          <div class="folders-list">
            <div class="folder-item active" onclick="showAllRecords()" id="allRecordsFolder">
              <i class="fas fa-boxes folder-icon"></i>
              <i class="fas fa-boxes collapsed-icon"></i>
              <div class="folder-info">
                <div class="folder-name">All Archived Records</div>
                <div class="folder-count"><?= count($records) ?> records</div>
              </div>
              <div class="folder-tooltip">
                All Archived Records<br>
                <small><?= count($records) ?> records</small>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Department Folders -->
        <?php if (!empty($records_by_department)): ?>
        <div class="folder-group">
          <div class="folder-group-title">
            <i class="fas fa-building"></i>
            <span>Departments</span>
          </div>
          <div class="folders-list">
            <?php foreach ($records_by_department as $dept_id => $dept_data): 
              $record_count = count($dept_data['records']);
              if ($record_count > 0): ?>
              <div class="folder-item department-folder" 
                   onclick="showDepartmentRecords(<?= $dept_id ?>, '<?= htmlspecialchars($dept_data['department_name']) ?>')"
                   data-department-id="<?= $dept_id ?>">
                <i class="fas fa-folder folder-icon"></i>
                <i class="fas fa-building collapsed-icon"></i>
                <div class="folder-info">
                  <div class="folder-name"><?= htmlspecialchars($dept_data['department_name']) ?></div>
                  <div class="folder-count"><?= $record_count ?> records</div>
                </div>
                <div class="folder-tooltip">
                  <?= htmlspecialchars($dept_data['department_name']) ?><br>
                  <small><?= $record_count ?> records</small>
                </div>
              </div>
            <?php endif; endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        
        <!-- Year Folders -->
        <?php if (!empty($records_by_year)): ?>
        <div class="folder-group">
          <div class="folder-group-title">
            <i class="fas fa-calendar-alt"></i>
            <span>By Year</span>
          </div>
          <div class="folders-list">
            <?php 
            // Sort years in descending order
            krsort($records_by_year);
            foreach ($records_by_year as $year_data): 
              $record_count = count($year_data['records']);
              if ($record_count > 0): ?>
              <div class="folder-item year-folder" 
                   onclick="showYearRecords(<?= $year_data['year'] ?>)"
                   data-year="<?= $year_data['year'] ?>">
                <i class="fas fa-folder folder-icon"></i>
                <i class="fas fa-calendar-alt collapsed-icon"></i>
                <div class="folder-info">
                  <div class="folder-name"><?= $year_data['year'] ?></div>
                  <div class="folder-count"><?= $record_count ?> records</div>
                </div>
                <div class="folder-tooltip">
                  <?= $year_data['year'] ?> Archives<br>
                  <small><?= $record_count ?> records</small>
                </div>
              </div>
            <?php endif; endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        
        <!-- Recent Month Folders -->
        <?php if (!empty($records_by_month)): ?>
        <div class="folder-group">
          <div class="folder-group-title">
            <i class="fas fa-calendar"></i>
            <span>By Month</span>
          </div>
          <div class="folders-list">
            <?php 
            // Sort months in descending order
            krsort($records_by_month);
            $month_count = 0;
            foreach ($records_by_month as $month_key => $month_data): 
              $record_count = count($month_data['records']);
              if ($record_count > 0 && $month_count < 6): // Show only last 6 months
                $month_count++;
            ?>
              <div class="folder-item month-folder" 
                   onclick="showMonthRecords('<?= $month_key ?>', '<?= htmlspecialchars($month_data['month_year']) ?>')"
                   data-month="<?= $month_key ?>">
                <i class="fas fa-folder folder-icon"></i>
                <i class="fas fa-calendar collapsed-icon"></i>
                <div class="folder-info">
                  <div class="folder-name"><?= $month_data['month_year'] ?></div>
                  <div class="folder-count"><?= $record_count ?> records</div>
                </div>
                <div class="folder-tooltip">
                  <?= $month_data['month_year'] ?><br>
                  <small><?= $record_count ?> records</small>
                </div>
              </div>
            <?php endif; endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      
      <!-- Records Main Area -->
      <div class="records-main">
        <div class="header-actions">
          <div class="current-folder-info">
            <h2 id="currentFolderTitle">All Archived Records</h2>
            <p id="currentFolderSubtitle">Browse all archived records from all departments</p>
          </div>
          <div class="view-toggle">
            <button class="view-toggle-btn active" onclick="switchView('grid')">
              <i class="fas fa-th-large"></i> Grid View
            </button>
            <button class="view-toggle-btn" onclick="switchView('table')">
              <i class="fas fa-table"></i> Table View
            </button>
          </div>
        </div>
        
        <div class="folder-content">
          <div class="folder-content-header">
            <h3 id="folderContentTitle">All Records</h3>
            <p id="folderContentSubtitle"><?= count($records) ?> archived records found</p>
          </div>
          
          <div class="folder-records-container">
            <!-- Grid View -->
            <div class="records-grid" id="recordsGridView">
              <?php if (empty($records)): ?>
                <div class="empty-state">
                  <div class="empty-state-icon">
                    <i class="fas fa-box-open"></i>
                  </div>
                  <h3>No Archived Records</h3>
                  <p>There are no archived records in the system yet.</p>
                </div>
              <?php else: ?>
                <?php foreach ($records as $record): ?>
                  <div class="record-card" onclick="openViewRecordModal(<?= $record['record_id'] ?>)">
                    <div class="record-card-header">
                      <div class="record-icon">
                        <i class="fas fa-file-alt"></i>
                      </div>
                      <span class="record-badge">
                        <?= htmlspecialchars($record['time_value'] ?? 'Archived') ?>
                      </span>
                    </div>
                    <div class="record-title"><?= htmlspecialchars($record['record_series_title']) ?></div>
                    <div class="record-details">
                      <div class="record-detail">
                        <i class="fas fa-hashtag"></i>
                        <span><?= htmlspecialchars($record['record_series_code'] ?? 'N/A') ?></span>
                      </div>
                      <div class="record-detail">
                        <i class="fas fa-building"></i>
                        <span><?= htmlspecialchars($record['office_name']) ?></span>
                      </div>
                      <div class="record-detail">
                        <i class="fas fa-calendar"></i>
                        <span>
                          <?= $record['period_from'] ? date('m/d/Y', strtotime($record['period_from'])) : 'N/A' ?> - 
                          <?= $record['period_to'] ? date('m/d/Y', strtotime($record['period_to'])) : 'N/A' ?>
                        </span>
                      </div>
                    </div>
                    <div class="record-footer">
                      <div class="record-date">
                        Archived: <?= date('m/d/Y', strtotime($record['updated_at'] ?? $record['created_at'])) ?>
                      </div>
                      <div class="record-actions">
                        <button class="record-action-btn" onclick="event.stopPropagation(); openViewRecordModal(<?= $record['record_id'] ?>)">
                          <i class="fas fa-eye"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            
            <!-- Table View -->
            <div class="records-table-view" id="recordsTableView" style="display: none;">
              <table>
                <thead>
                  <tr>
                    <th>Record Title</th>
                    <th>Department</th>
                    <th>Series Code</th>
                    <th>Period</th>
                    <th>Time Value</th>
                    <th>Archived Date</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($records)): ?>
                    <tr>
                      <td colspan="7" style="text-align: center; padding: 40px; color: #78909c;">
                        No archived records found.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($records as $record): ?>
                      <tr>
                        <td>
                          <strong><?= htmlspecialchars($record['record_series_title']) ?></strong>
                        </td>
                        <td>
                          <span class="department-badge">
                            <i class="fas fa-building"></i> <?= htmlspecialchars($record['office_name']) ?>
                          </span>
                        </td>
                        <td><?= htmlspecialchars($record['record_series_code'] ?? 'N/A') ?></td>
                        <td>
                          <?= $record['period_from'] ? date('m/d/Y', strtotime($record['period_from'])) : 'N/A' ?> - 
                          <?= $record['period_to'] ? date('m/d/Y', strtotime($record['period_to'])) : 'N/A' ?>
                        </td>
                        <td>
                          <span class="time-value-badge <?= strtolower($record['time_value'] ?? '') ?>">
                            <?= htmlspecialchars($record['time_value'] ?? 'Archived') ?>
                          </span>
                        </td>
                        <td><?= date('m/d/Y', strtotime($record['updated_at'] ?? $record['created_at'])) ?></td>
                        <td>
                          <button class="record-action-btn" onclick="openViewRecordModal(<?= $record['record_id'] ?>)">
                            <i class="fas fa-eye"></i> View
                          </button>
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
    </div>

    <!-- VIEW RECORD MODAL -->
    <div id="viewRecordModal" class="modal-backdrop">
      <div class="modal-card record-form-card">
        <button class="modal-close-btn" onclick="hideViewRecordModal()">&times;</button>
        <h1 class="form-title" id="viewRecordModalTitle">VIEW ARCHIVED RECORD</h1>

        <!-- Record Information Display -->
        <div class="form-fields-grid">
          <div class="form-group full-width">
            <label>Record Title:</label>
            <div class="view-field" id="viewRecordTitle"></div>
          </div>

          <div class="form-group">
            <label>Office/ Department:</label>
            <div class="view-field" id="viewOfficeDepartment"></div>
          </div>

          <div class="form-group">
            <label>Record Series:</label>
            <div class="view-field" id="viewRecordSeries"></div>
          </div>

          <!-- <div class="form-group">
            <label>Disposition:</label>
            <div class="view-field" id="viewDisposition"></div>
          </div> -->

          <div class="form-group">
            <label>Period Covered:</label>
            <div class="view-field" id="viewPeriodCovered"></div>
          </div>

          <div class="form-group">
            <label>Classification:</label>
            <div class="view-field" id="viewClassification"></div>
          </div>

          <div class="form-group">
            <label>Retention Period:</label>
            <div class="view-field" id="viewRetentionPeriod"></div>
          </div>

          <div class="form-group">
            <label>Time Value:</label>
            <div class="view-field" id="viewTimeValue"></div>
          </div>

          <div class="form-group">
            <label>Status:</label>
            <div class="view-field" id="viewStatus"></div>
          </div>

          <div class="form-group">
            <label>Archive Date:</label>
            <div class="view-field" id="viewArchiveDate"></div>
          </div>
        </div>

        <!-- Additional Details -->
        <div class="form-fields-grid" style="margin-top: 20px;">
          <div class="form-group">
            <label>Volume:</label>
            <div class="view-field" id="viewVolume"></div>
          </div>

          <div class="form-group">
            <label>Medium:</label>
            <div class="view-field" id="viewMedium"></div>
          </div>

          <div class="form-group">
            <label>Restrictions:</label>
            <div class="view-field" id="viewRestrictions"></div>
          </div>

          <div class="form-group">
            <label>Location:</label>
            <div class="view-field" id="viewLocation"></div>
          </div>

          <div class="form-group">
            <label>Frequency of Use:</label>
            <div class="view-field" id="viewFrequency"></div>
          </div>

          <div class="form-group">
            <label>Utility Value:</label>
            <div class="view-field" id="viewUtilityValue"></div>
          </div>
        </div>

        <!-- Description Section -->
        <div class="form-group full-width" style="margin-top: 20px;">
          <label>Detailed Description / Notes:</label>
            <div class="view-field view-description" id="viewDescription"></div>
        </div>

        <!-- Disposition Instructions -->
        <!-- <div class="form-group full-width" style="margin-top: 20px;">
          <label>Disposition Instructions:</label>
            <div class="view-field view-description" id="viewDispositionProvision"></div>
        </div> -->

        <!-- Files Section -->
        <div class="uploaded-files-section" style="margin-top: 20px;">
          <h2 class="section-header">ATTACHED FILES (<span id="viewFileCount">0</span>)</h2>
          <div class="uploaded-files-list" id="viewUploadedFilesList">
            <div style="padding: 10px 15px; color: #78909c; font-style: italic; font-size: 14px;">
              No files attached to this record.
            </div>
          </div>
        </div>

        <!-- Close Button -->
        <div class="action-buttons">
          <button class="button-action cancel" onclick="hideViewRecordModal()">CLOSE</button>
        </div>
      </div>
    </div>
  </main>

  <!-- Add Select2 JS -->
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

  <script>
    // Global variables
    let currentView = 'grid'; // 'grid' or 'table'
    let currentFilter = 'all'; // 'all', 'department', 'year', 'month'
    let currentFilterId = null;
    let allRecords = <?= json_encode($records) ?>;
    let recordsByDepartment = <?= json_encode($records_by_department) ?>;
    let recordsByYear = <?= json_encode($records_by_year) ?>;
    let recordsByMonth = <?= json_encode($records_by_month) ?>;
    let isSidebarCollapsed = false;

    // --- Toggle Sidebar ---
    function toggleSidebar() {
      const container = document.querySelector('.archive-container');
      isSidebarCollapsed = !isSidebarCollapsed;
      
      if (isSidebarCollapsed) {
        container.classList.add('sidebar-collapsed');
        localStorage.setItem('archiveSidebarCollapsed', 'true');
      } else {
        container.classList.remove('sidebar-collapsed');
        localStorage.setItem('archiveSidebarCollapsed', 'false');
      }
    }

    // --- Restore Sidebar State ---
    function restoreSidebarState() {
      const savedState = localStorage.getItem('archiveSidebarCollapsed');
      const container = document.querySelector('.archive-container');
      
      if (savedState === 'true') {
        container.classList.add('sidebar-collapsed');
        isSidebarCollapsed = true;
      }
    }

    // --- View Switching ---
    function switchView(view) {
      currentView = view;
      
      // Update active view button
      document.querySelectorAll('.view-toggle-btn').forEach(btn => {
        btn.classList.remove('active');
      });
      event.target.classList.add('active');
      
      // Show/hide views
      if (view === 'grid') {
        document.getElementById('recordsGridView').style.display = 'grid';
        document.getElementById('recordsTableView').style.display = 'none';
      } else {
        document.getElementById('recordsGridView').style.display = 'none';
        document.getElementById('recordsTableView').style.display = 'table';
      }
    }

    // --- Folder Filtering ---
    function showAllRecords() {
      currentFilter = 'all';
      currentFilterId = null;
      
      // Update active folder
      updateActiveFolder('allRecordsFolder');
      
      // Update header
      document.getElementById('currentFolderTitle').textContent = 'All Archived Records';
      document.getElementById('currentFolderSubtitle').textContent = 'Browse all archived records from all departments';
      document.getElementById('folderContentTitle').textContent = 'All Records';
      document.getElementById('folderContentSubtitle').textContent = allRecords.length + ' archived records found';
      
      // Display all records
      displayRecords(allRecords);
    }

    function showDepartmentRecords(departmentId, departmentName) {
      currentFilter = 'department';
      currentFilterId = departmentId;
      
      // Update active folder
      updateActiveFolder(null);
      document.querySelector(`.folder-item[data-department-id="${departmentId}"]`).classList.add('active');
      
      // Update header
      document.getElementById('currentFolderTitle').textContent = departmentName;
      document.getElementById('currentFolderSubtitle').textContent = 'Department Archives';
      document.getElementById('folderContentTitle').textContent = departmentName + ' Archives';
      
      // Get and display department records
      const deptRecords = recordsByDepartment[departmentId]?.records || [];
      document.getElementById('folderContentSubtitle').textContent = deptRecords.length + ' archived records found';
      displayRecords(deptRecords);
    }

    function showYearRecords(year) {
      currentFilter = 'year';
      currentFilterId = year;
      
      // Update active folder
      updateActiveFolder(null);
      document.querySelector(`.folder-item[data-year="${year}"]`).classList.add('active');
      
      // Update header
      document.getElementById('currentFolderTitle').textContent = year + ' Archives';
      document.getElementById('currentFolderSubtitle').textContent = 'Records archived in ' + year;
      document.getElementById('folderContentTitle').textContent = year + ' Archives';
      
      // Get and display year records
      const yearRecords = recordsByYear[year]?.records || [];
      document.getElementById('folderContentSubtitle').textContent = yearRecords.length + ' archived records found';
      displayRecords(yearRecords);
    }

    function showMonthRecords(monthKey, monthYear) {
      currentFilter = 'month';
      currentFilterId = monthKey;
      
      // Update active folder
      updateActiveFolder(null);
      document.querySelector(`.folder-item[data-month="${monthKey}"]`).classList.add('active');
      
      // Update header
      document.getElementById('currentFolderTitle').textContent = monthYear + ' Archives';
      document.getElementById('currentFolderSubtitle').textContent = 'Records archived in ' + monthYear;
      document.getElementById('folderContentTitle').textContent = monthYear + ' Archives';
      
      // Get and display month records
      const monthRecords = recordsByMonth[monthKey]?.records || [];
      document.getElementById('folderContentSubtitle').textContent = monthRecords.length + ' archived records found';
      displayRecords(monthRecords);
    }

    function updateActiveFolder(activeId) {
      // Remove active class from all folders
      document.querySelectorAll('.folder-item').forEach(item => {
        item.classList.remove('active');
      });
      
      // Add active class to specified folder
      if (activeId) {
        document.getElementById(activeId).classList.add('active');
      }
    }

    function displayRecords(records) {
      const gridView = document.getElementById('recordsGridView');
      const tableView = document.getElementById('recordsTableView').querySelector('tbody');
      
      if (records.length === 0) {
        // Empty state for grid view
        gridView.innerHTML = `
          <div class="empty-state">
            <div class="empty-state-icon">
              <i class="fas fa-box-open"></i>
            </div>
            <h3>No Records Found</h3>
            <p>There are no archived records in this category.</p>
          </div>
        `;
        
        // Empty state for table view
        tableView.innerHTML = `
          <tr>
            <td colspan="7" style="text-align: center; padding: 40px; color: #78909c;">
              No archived records found in this category.
            </td>
          </tr>
        `;
        return;
      }
      
      // Update grid view
      gridView.innerHTML = records.map(record => `
        <div class="record-card" onclick="openViewRecordModal(${record.record_id})">
          <div class="record-card-header">
            <div class="record-icon">
              <i class="fas fa-file-alt"></i>
            </div>
            <span class="record-badge">
              ${record.time_value || 'Archived'}
            </span>
          </div>
          <div class="record-title">${escapeHtml(record.record_series_title)}</div>
          <div class="record-details">
            <div class="record-detail">
              <i class="fas fa-hashtag"></i>
              <span>${escapeHtml(record.record_series_code || 'N/A')}</span>
            </div>
            <div class="record-detail">
              <i class="fas fa-building"></i>
              <span>${escapeHtml(record.office_name)}</span>
            </div>
            <div class="record-detail">
              <i class="fas fa-calendar"></i>
              <span>
                ${record.period_from ? formatDate(record.period_from) : 'N/A'} - 
                ${record.period_to ? formatDate(record.period_to) : 'N/A'}
              </span>
            </div>
          </div>
          <div class="record-footer">
            <div class="record-date">
              Archived: ${formatDate(record.updated_at || record.created_at)}
            </div>
            <div class="record-actions">
              <button class="record-action-btn" onclick="event.stopPropagation(); openViewRecordModal(${record.record_id})">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>
        </div>
      `).join('');
      
      // Update table view
      tableView.innerHTML = records.map(record => `
        <tr>
          <td>
            <strong>${escapeHtml(record.record_series_title)}</strong>
          </td>
          <td>
            <span class="department-badge">
              <i class="fas fa-building"></i> ${escapeHtml(record.office_name)}
            </span>
          </td>
          <td>${escapeHtml(record.record_series_code || 'N/A')}</td>
          <td>
            ${record.period_from ? formatDate(record.period_from) : 'N/A'} - 
            ${record.period_to ? formatDate(record.period_to) : 'N/A'}
          </td>
          <td>
            <span class="time-value-badge ${(record.time_value || '').toLowerCase()}">
              ${record.time_value || 'Archived'}
            </span>
          </td>
          <td>${formatDate(record.updated_at || record.created_at)}</td>
          <td>
            <button class="record-action-btn" onclick="openViewRecordModal(${record.record_id})">
              <i class="fas fa-eye"></i> View
            </button>
          </td>
        </tr>
      `).join('');
    }

    // --- AJAX Functions for View Record Modal ---
    function fetchRecordFiles(recordId) {
      return fetch(`../get_record_files.php?id=${recordId}`)
        .then(response => {
          if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
          return response.json();
        })
        .then(data => {
          if (data.success) return data.files || [];
          throw new Error(data.message || 'Failed to load files');
        })
        .catch(error => {
          console.error('Error fetching files:', error);
          return [];
        });
    }

    function fetchRecordData(recordId) {
      return fetch(`../get_record.php?id=${recordId}`)
        .then(response => {
          if (response.status === 500) {
            return response.text().then(errorText => {
              throw new Error('Server Error (500) - Check PHP configuration');
            });
          }
          if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
          return response.json();
        })
        .then(data => {
          if (data.success && data.record) return data.record;
          throw new Error(data.message || 'Failed to load record data');
        })
        .catch(error => {
          console.error('fetchRecordData error:', error);
          throw error;
        });
    }

    function openViewRecordModal(recordId) {
      const viewRecordModal = document.getElementById('viewRecordModal');
      viewRecordModal.classList.add('show');
      document.body.style.overflow = 'hidden';

      // Set loading states
      document.querySelectorAll('.view-field').forEach(field => {
        field.textContent = 'Loading...';
      });

      Promise.all([
        fetchRecordData(recordId),
        fetchRecordFiles(recordId)
      ])
      .then(([record, files]) => {
        populateViewRecordModal(record);
        displayViewRecordFiles(files);
      })
      .catch(error => {
        console.error('Error loading record:', error);
        alert('Error loading record: ' + error.message);
      });
    }

    function hideViewRecordModal() {
      document.getElementById('viewRecordModal').classList.remove('show');
      document.body.style.overflow = '';
    }

    function populateViewRecordModal(record) {
      document.getElementById('viewRecordTitle').textContent = record.record_series_title || 'N/A';
      document.getElementById('viewOfficeDepartment').textContent = record.office_name || 'N/A';
      document.getElementById('viewRecordSeries').textContent = record.record_series_code || 'N/A';
      // document.getElementById('viewDisposition').textContent = record.disposition_type || 'N/A';
      document.getElementById('viewClassification').textContent = record.class_name || 'N/A';
      
      const fromDate = record.period_from ? formatDateForDisplay(record.period_from) : 'N/A';
      const toDate = record.period_to ? formatDateForDisplay(record.period_to) : 'N/A';
      document.getElementById('viewPeriodCovered').textContent = `${fromDate} - ${toDate}`;
      
      let retentionText = '';
      if (record.active_years) retentionText += `Active: ${record.active_years} yrs`;
      if (record.storage_years) retentionText += retentionText ? `, Storage: ${record.storage_years} yrs` : `Storage: ${record.storage_years} yrs`;
      if (record.total_years) retentionText += retentionText ? `, Total: ${record.total_years} yrs` : `Total: ${record.total_years} yrs`;
      document.getElementById('viewRetentionPeriod').textContent = retentionText || 'N/A';
      
      document.getElementById('viewTimeValue').innerHTML = record.time_value ?
        `<span class="time-value-badge ${record.time_value.toLowerCase()}">${record.time_value}</span>` : 'N/A';
      
      document.getElementById('viewStatus').textContent = record.status || 'N/A';
      
      const archiveDate = record.archive_date || record.updated_at || record.created_at;
      document.getElementById('viewArchiveDate').textContent = archiveDate ? 
        formatDateForDisplay(archiveDate) + ' ' + formatTimeForDisplay(archiveDate) : 'N/A';
      
      document.getElementById('viewVolume').textContent = record.volume ? `${record.volume} ${record.volume_unit || ''}` : 'N/A';
      document.getElementById('viewMedium').textContent = record.records_medium || 'N/A';
      document.getElementById('viewRestrictions').textContent = record.restrictions || 'N/A';
      document.getElementById('viewLocation').textContent = record.location_of_records || 'N/A';
      document.getElementById('viewFrequency').textContent = record.frequency_of_use || 'N/A';
      document.getElementById('viewUtilityValue').textContent = record.utility_value || 'N/A';
      document.getElementById('viewDescription').textContent = record.description || 'No description available.';
      // document.getElementById('viewDispositionProvision').textContent = record.disposition_provision || 'No disposition instructions.';
      
      document.getElementById('viewRecordModalTitle').textContent = `VIEW ARCHIVED RECORD - ${record.record_series_code || 'ID: ' + record.record_id}`;
    }

    function displayViewRecordFiles(files) {
      const filesContainer = document.getElementById('viewUploadedFilesList');
      const fileCountElement = document.getElementById('viewFileCount');

      if (!files || files.length === 0) {
        filesContainer.innerHTML = '<div style="padding: 10px 15px; color: #78909c; font-style: italic; font-size: 14px;">No files attached to this record.</div>';
        fileCountElement.textContent = '0';
        return;
      }

      filesContainer.innerHTML = files.map(file => {
        return `
          <div class="file-item">
            <div class="file-info">
              <div class="file-name">
                <i class="fas fa-paperclip"></i> ${file.file_name}
              </div>
              <div class="file-size">${formatFileSize(file.file_size)}</div>
              ${file.file_tag ? `<div class="file-tag">Tag: ${file.file_tag}</div>` : ''}
            </div>
          </div>
        `;
      }).join('');

      fileCountElement.textContent = files.length;
    }

    function formatFileSize(bytes) {
      if (bytes === 0) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function formatDateForDisplay(dateString) {
      if (!dateString) return 'N/A';
      try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return 'Invalid Date';
        return date.toLocaleDateString('en-US', {
          year: 'numeric',
          month: '2-digit',
          day: '2-digit'
        });
      } catch (e) {
        console.error('Error formatting date:', e);
        return 'Invalid Date';
      }
    }

    function formatTimeForDisplay(dateString) {
      if (!dateString) return '';
      try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return '';
        return date.toLocaleTimeString('en-US', {
          hour: '2-digit',
          minute: '2-digit'
        });
      } catch (e) {
        console.error('Error formatting time:', e);
        return '';
      }
    }

    // Helper functions
    function formatDate(dateString) {
      if (!dateString) return 'N/A';
      try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US');
      } catch (e) {
        return 'Invalid Date';
      }
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
      console.log('Archived Records Folder View Loaded');
      restoreSidebarState();
    });
  </script>
</body>
</html>