<?php
require_once '../session.php';
require_once '../db_connect.php';
require_once '../db_logger.php'; // ADD THIS LINE

// Create logger instance
$logger = new SystemLogger($pdo); // ADD THIS LINE


// ========== AUTO-UPDATE EXPIRED RECORDS ==========
// This runs EVERY TIME the page loads - SIMPLEST & FASTEST
// ========== AUTO-UPDATE EXPIRED RECORDS ==========
$current_date = date('Y-m-d');

try {
  // CORRECT LOGIC: Archive records where period_to has passed (is overdue)
  // Only update records that are currently Active or Inactive
  // Update to Archived status when period_to is in the past
  $update_sql = "UPDATE records 
                   SET status = 'Archived' 
                   WHERE status IN ('Active', 'Inactive') 
                   AND period_to IS NOT NULL 
                   AND DATE(period_to) <= :current_date";

  $update_stmt = $pdo->prepare($update_sql);
  $update_stmt->execute(['current_date' => $current_date]);
  $archived_count = $update_stmt->rowCount();

  $total_updated = $archived_count;

  // Store in session to show notification (optional)
  if ($total_updated > 0) {
    $_SESSION['records_updated'] = $total_updated;
    $_SESSION['update_time'] = date('H:i:s');
    
    // ========== ADD LOGGING FOR AUTO-ARCHIVE ==========
    // Get the IDs of records that were auto-archived
    $getArchivedSql = "SELECT record_id, status FROM records 
                       WHERE status = 'Archived' 
                       AND DATE(updated_at) = :current_date
                       AND record_id IN (
                         SELECT record_id FROM records 
                         WHERE status IN ('Active', 'Inactive') 
                         AND period_to IS NOT NULL 
                         AND DATE(period_to) <= :current_date2
                       )";
    
    $getArchivedStmt = $pdo->prepare($getArchivedSql);
    $getArchivedStmt->execute([
      'current_date' => $current_date,
      'current_date2' => $current_date
    ]);
    $archivedRecords = $getArchivedStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Log each auto-archived record
    foreach ($archivedRecords as $record) {
      // Log disposal action for system auto-archive
      $logger->logDisposalAction([
        'record_id' => $record['record_id'],
        'action_type' => 'ARCHIVE_COMPLETE',
        'performed_by' => 0, // System/user_id 0 indicates system action
        'status_from' => 'Active',
        'status_to' => 'Archived',
        'notes' => 'Auto-archived: Retention period expired',
        'office_id' => null,
        'role_id' => null
      ]);
      
      // Log record status change
      $logger->logRecordStatusChange(
        $record['record_id'], 
        0, // System action
        $record['status'], 
        'Archived', 
        'Auto-archived: Retention period expired on ' . $current_date
      );
    }
    // ========== END LOGGING ==========
  }
} catch (Exception $e) {
  // Silently fail - don't break the page if update fails
  error_log("Auto-update error: " . $e->getMessage());
}
// ========== END AUTO-UPDATE ==========
// ========== END AUTO-UPDATE ==========

// Initialize variables
$records = [];
$classifications = [];
$offices = [];

// Check if database connection is established
if (!isset($pdo)) {
  die("Database connection failed. Please check your database configuration.");
}

try {
  // First, let's check what columns exist in record_classification table
  $checkColumns = $pdo->query("DESCRIBE record_classification")->fetchAll(PDO::FETCH_ASSOC);
  $columnNames = array_column($checkColumns, 'Field');

  // Build dynamic SELECT based on available columns
  $selectFields = [
    'class_id',
    'class_name',
    'description',
    'functional_category',
    'retention_period',
    'nap_authority'
  ];

  // Check if permanent_indicator column exists
  if (in_array('permanent_indicator', $columnNames)) {
    $selectFields[] = 'permanent_indicator';
  }

  // Fetch records from database
  $sql = "SELECT 
            r.record_id,
            r.record_series_code,
            r.record_series_title,
            o.office_name,
            r.period_from,
            r.period_to,
            r.active_years,
            r.storage_years,
            r.total_years,
            r.status,
            r.time_value,
            rc.class_id,
            rc.class_name,
            rc.functional_category,
            rc.retention_period,
            rc.nap_authority,
            u.first_name,
            u.last_name
          FROM records r
          LEFT JOIN offices o ON r.office_id = o.office_id
          LEFT JOIN record_classification rc ON r.class_id = rc.class_id
          LEFT JOIN users u ON r.created_by = u.user_id
          ORDER BY r.record_id DESC";

  $stmt = $pdo->query($sql);
  $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Fetch record classifications - use dynamic field selection
  $selectQuery = "SELECT " . implode(', ', $selectFields) . " FROM record_classification ORDER BY functional_category, class_name";

  $classifications = $pdo->query($selectQuery)->fetchAll(PDO::FETCH_ASSOC);

  // Fetch offices
  $offices = $pdo->query("SELECT * FROM offices ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("Database error: " . $e->getMessage());
  error_log("Error trace: " . $e->getTraceAsString());
  die("Database error occurred. Please check the error logs.");
}

// Check for update notification
$show_update_notification = false;
$update_message = '';
if (isset($_SESSION['records_updated']) && $_SESSION['records_updated'] > 0) {
  $show_update_notification = true;
  $update_message = "Updated " . $_SESSION['records_updated'] . " expired records to 'Archived' status at " . $_SESSION['update_time'];
  // Clear the notification after showing
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
  <title>Record Management</title>
  <style>
    /* Keep all your existing CSS styles */
    .select2-container--default .select2-selection--single {
      border: 1px solid #cfd8dc;
      border-radius: 8px;
      height: 48px;
      padding: 10px 15px;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
      line-height: 26px;
      padding-left: 0;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
      height: 46px;
    }

    .table-wrapper {
      overflow-x: auto;
      width: 100%;
      margin: 0 -15px;
      padding: 0 15px;
    }

    table {
      width: 100%;
      table-layout: auto !important;
      min-width: 800px;
    }

    td {
      white-space: normal !important;
      overflow: visible !important;
      text-overflow: unset !important;
      max-width: none !important;
      word-wrap: break-word !important;
      word-break: break-word !important;
    }

    td:nth-child(1),
    td:nth-child(2),
    td:nth-child(3),
    td:nth-child(4),
    td:nth-child(5),
    td:nth-child(6),
    td:nth-child(7),
    td:nth-child(8),
    td:nth-child(9) {
      white-space: normal !important;
      overflow: visible !important;
      text-overflow: unset !important;
      max-width: none !important;
      word-wrap: break-word !important;
      word-break: break-word !important;
    }

    th:nth-child(1),
    td:nth-child(1) {
      text-align: center !important;
    }

    th:nth-child(2),
    td:nth-child(2) {
      text-align: center !important;
    }

    .classification-info {
      background: #f8f9fa;
      border: 1px solid #e9ecef;
      border-radius: 8px;
      padding: 15px;
      margin-top: 10px;
      display: none;
    }

    .classification-info.show {
      display: block;
    }

    .nap-details {
      font-size: 0.9em;
      color: #6c757d;
      margin-top: 8px;
    }

    .retention-badge {
      background: #e3f2fd;
      color: #1976d2;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 0.8em;
      font-weight: bold;
    }

    .view-field {
      padding: 12px 15px;
      border: 1px solid #e0e0e0;
      border-radius: 8px;
      background-color: #f8f9fa;
      font-size: 15px;
      color: #37474f;
      min-height: 48px;
      display: flex;
      align-items: center;
    }

    .view-description {
      min-height: 120px;
      align-items: flex-start;
      white-space: pre-wrap;
    }

    #viewRecordModal .form-group label {
      font-weight: 600;
      color: #1f366c;
      margin-bottom: 8px;
      display: block;
    }

    #viewRecordModal .action-buttons {
      justify-content: center;
    }

    .upload-area.drag-over {
      border-color: #1f366c;
      background-color: #f0f4ff;
    }

    .file-item {
      display: flex;
      align-items: center;
      padding: 10px 15px;
      border-bottom: 1px solid #e0e0e0;
      gap: 10px;
      flex-wrap: wrap;
    }

    .file-info {
      flex: 1;
      min-width: 200px;
    }

    .file-name {
      font-weight: 500;
      color: #37474f;
      word-break: break-word;
    }

    .file-size {
      font-size: 12px;
      color: #78909c;
    }

    .file-tag-input {
      padding: 5px 8px;
      border: 1px solid #cfd8dc;
      border-radius: 4px;
      width: 120px;
      font-size: 12px;
    }

    .remove-file-btn {
      background: none;
      border: none;
      color: #f44336;
      cursor: pointer;
      padding: 5px;
    }

    .remove-file-btn:hover {
      color: #d32f2f;
    }

    .file-status {
      font-size: 12px;
      background-color: #e7f7ef;
      color: #1ea97c;
      font-weight: 500;
    }

    .button-action:disabled {
      opacity: 0.7;
      cursor: not-allowed;
    }

    .filter-button.active {
      background: #1f366c !important;
      color: white !important;
      border-color: #1f366c !important;
    }

    .filter-button {
      transition: all 0.3s ease;
    }

    #recordsTableBody tr {
      transition: all 0.3s ease;
    }

    .retention-display {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .retention-line {
      font-size: 0.85em;
    }

    .retention-total {
      font-weight: bold;
      color: #1f366c;
    }

    .form-section-title {
      font-size: 18px;
      font-weight: 600;
      color: #1f366c;
      margin: 25px 0 15px 0;
      padding-bottom: 8px;
      border-bottom: 2px solid #e0e0e0;
    }

    .medium-checkboxes {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      margin-top: 10px;
    }

    .medium-option {
      display: flex;
      align-items: center;
      gap: 8px;
      flex: 0 0 auto;
    }

    .medium-option input[type="checkbox"] {
      width: 18px;
      height: 18px;
      flex-shrink: 0;
    }

    /* Responsive Radio Group with 2-1 layout */
    .radio-group {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 15px 20px;
      margin-top: 10px;
    }

    /* Third radio option on second row, spanning both columns */
    .radio-option:nth-child(3) {
      grid-column: 1 / span 2;
    }

    .radio-option {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .radio-option input[type="radio"] {
      width: 18px;
      height: 18px;
      flex-shrink: 0;
    }

    .volume-input {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .volume-input input {
      flex: 1;
      min-width: 150px;
    }

    .volume-unit {
      min-width: 100px;
      height: 20px;
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

    /* Style for editable storage years field */
    #activeYears {
      background-color: #f8f9fa !important;
      cursor: not-allowed !important;
      color: #6c757d !important;
    }

    #storageYears {
      background-color: white !important;
      border: 2px solid #1f366c !important;
      cursor: text !important;
    }

    #storageYears:focus {
      border-color: #4caf50 !important;
      box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1) !important;
    }

    /* Tooltip style for clarification */
    .field-hint {
      font-size: 12px;
      color: #78909c;
      margin-top: 4px;
      font-style: italic;
    }

    /* Fix for modal display */
    .modal-backdrop.show {
      display: flex !important;
      opacity: 1 !important;
      visibility: visible !important;
    }

    .modal-backdrop {
      display: none;
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.3s ease, visibility 0.3s ease;
    }

    /* Make total years field readonly */
    #totalYears {
      background-color: #f8f9fa !important;
      cursor: not-allowed !important;
      color: #6c757d !important;
    }

    /* ========== VIEW MODAL SPECIFIC STYLES TO MATCH EDIT MODAL ========== */
    #viewRecordModal .modal-card {
      background-color: white;
      border-radius: 16px;
      width: 100%;
      max-width: 1400px;
      max-height: 90vh;
      overflow: hidden;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      animation: slideUp 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
      display: flex;
      flex-direction: column;
      margin: 20px auto;
      padding: 0;
    }

    #viewRecordModal .modal-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 5px;
      background: linear-gradient(90deg, #1f366c 0%, #1e3a8a 100%);
      z-index: 2;
    }

    #viewRecordModal .modal-header {
      background: linear-gradient(90deg, #1f366c 0%, #1e3a8a 100%);
      color: white;
      /* padding: clamp(20px, 2.5vw, 30px); */
      position: relative;
      text-align: center;
      margin: 0;
      border-radius: 0;
    }

    #viewRecordModal .form-title {
      color: white;
      margin: 0;
      border-radius: 0;
      letter-spacing: 0.1em;
      font-size: clamp(1.3rem, 1.8vw, 1.8rem);
      font-weight: 700;
      border: none;
      text-align: center;
    }

    #viewRecordModal .action-buttons {
      background: #f9fafb;
      padding: clamp(16px, 2vw, 20px) clamp(20px, 2.5vw, 30px);
      border-top: 1px solid #e5e7eb;
      display: flex;
      justify-content: center;
      gap: 12px;
      margin-top: auto;
    }

    #viewRecordModal .view-field {
      background: #f8fafc;
      border: 2px solid #e2e8f0;
      border-radius: 10px;
      padding: clamp(12px, 1.2vw, 14px) clamp(15px, 1.5vw, 18px);
      font-size: clamp(0.9rem, 1vw, 1rem);
      color: #37474f;
      min-height: 48px;
      display: flex;
      align-items: center;
      width: 100%;
      box-sizing: border-box;
      font-family: "Poppins", sans-serif;
      cursor: default;
    }

    #viewRecordModal .view-field.view-description {
      min-height: 120px;
      align-items: flex-start;
      white-space: pre-wrap;
      line-height: 1.5;
    }

    #viewRecordModal .form-section-title {
      background: #f8fafc;
      border-radius: 12px;
      padding: clamp(16px, 2vw, 20px);
      margin: 0 0 clamp(20px, 2.5vw, 30px) 0;
      border: 1px solid #e2e8f0;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
      display: flex;
      align-items: center;
      border-bottom: none;
      font-size: 18px;
      color: #1f366c;
      font-weight: 600;
    }

    #viewRecordModal .form-section-title::before {
      content: "";
      width: 4px;
      height: 24px;
      background: #1e3a8a;
      border-radius: 2px;
      margin-right: 12px;
    }

    #viewRecordModal .upload-title {
      color: #1f366c;
      font-weight: 700;
      margin-bottom: 15px;
      font-size: clamp(15px, 1vw, 16px);
      padding-top: 10px;
      border-top: 1px solid #f0f0f0;
    }

    #viewRecordModal .button-action.cancel {
      background: #6b7280;
      color: white;
      padding: 10px 20px;
      border-radius: 8px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      min-width: 140px;
      border: none;
    }

    #viewRecordModal .button-action.cancel:hover {
      background: #4b5563;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
    }

    /* Animations */
    @keyframes slideUp {
      from {
        transform: translateY(30px);
        opacity: 0;
      }

      to {
        transform: translateY(0);
        opacity: 1;
      }
    }

    /* ========== RESPONSIVE STYLES ========== */

    /* Large Desktop (1200px and above) */
    @media (min-width: 1200px) {
      .form-fields-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 20px 30px;
      }

      .radio-group {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px 25px;
      }

      .medium-checkboxes {
        gap: 20px;
      }
    }

    /* Desktop (992px to 1199px) */
    @media (min-width: 992px) and (max-width: 1199px) {
      .form-fields-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 18px 25px;
      }

      .radio-group {
        gap: 15px 20px;
      }

      .medium-checkboxes {
        gap: 18px;
      }

      .file-info {
        min-width: 180px;
      }
    }

    /* Tablet Landscape (768px to 991px) */
    @media (min-width: 768px) and (max-width: 991px) {
      .form-fields-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px 20px;
      }

      .radio-group {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px 15px;
      }

      .medium-checkboxes {
        gap: 15px;
      }

      .file-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
      }

      .file-info {
        min-width: 100%;
      }

      .file-tag-input {
        width: 100%;
      }

      .view-field {
        padding: 10px 12px;
        font-size: 14px;
      }
    }

    /* Tablet Portrait (576px to 767px) */
    @media (min-width: 576px) and (max-width: 767px) {
      .form-fields-grid {
        grid-template-columns: 1fr;
        gap: 15px;
      }

      .radio-group {
        grid-template-columns: 1fr;
        gap: 12px;
      }

      /* Stack all radio options vertically on mobile */
      .radio-option:nth-child(3) {
        grid-column: 1;
      }

      .medium-checkboxes {
        gap: 12px;
        flex-direction: column;
      }

      .medium-option {
        width: 100%;
      }

      .volume-input {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
      }

      .volume-input input {
        width: 100%;
        min-width: auto;
      }

      .file-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
      }

      .file-info {
        min-width: 100%;
      }

      .file-tag-input {
        width: 100%;
        order: 2;
      }

      .file-status {
        order: 3;
      }

      .remove-file-btn {
        align-self: flex-end;
        margin-top: -40px;
        order: 4;
      }

      .table-wrapper {
        margin: 0 -10px;
        padding: 0 10px;
      }

      table {
        min-width: 700px;
      }

      .view-field {
        padding: 10px 12px;
        font-size: 14px;
        min-height: 44px;
      }

      .view-description {
        min-height: 100px;
      }

      #viewRecordModal .form-fields-grid {
        grid-template-columns: 1fr;
      }
    }

    /* Mobile (below 576px) */
    @media (max-width: 575px) {
      .form-fields-grid {
        grid-template-columns: 1fr;
        gap: 12px;
      }

      .radio-group {
        grid-template-columns: 1fr;
        gap: 10px;
      }

      /* Stack all radio options vertically on mobile */
      .radio-option:nth-child(3) {
        grid-column: 1;
      }

      .medium-checkboxes {
        gap: 10px;
        flex-direction: column;
      }

      .medium-option {
        width: 100%;
      }

      .radio-option,
      .medium-option {
        font-size: 14px;
      }

      .volume-input {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
      }

      .volume-input input {
        width: 100%;
        min-width: auto;
      }

      .volume-unit {
        min-width: auto;
        width: 100%;
      }

      .form-section-title {
        font-size: 16px;
        margin: 20px 0 12px 0;
      }

      .file-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
        padding: 8px 12px;
      }

      .file-info {
        min-width: 100%;
      }

      .file-name {
        font-size: 14px;
      }

      .file-tag-input {
        width: 100%;
        order: 2;
        padding: 6px 8px;
      }

      .file-status {
        order: 3;
        font-size: 11px;
      }

      .remove-file-btn {
        align-self: flex-end;
        margin-top: -40px;
        order: 4;
        font-size: 16px;
      }

      .table-wrapper {
        margin: 0 -8px;
        padding: 0 8px;
      }

      table {
        min-width: 600px;
        font-size: 13px;
      }

      th,
      td {
        padding: 8px 6px;
      }

      .view-field {
        padding: 8px 10px;
        font-size: 13px;
        min-height: 40px;
      }

      .view-description {
        min-height: 80px;
        font-size: 13px;
      }

      .action-buttons {
        flex-direction: column;
        gap: 10px;
      }

      .button-action {
        width: 100%;
        justify-content: center;
        padding: 10px 15px;
        font-size: 14px;
      }

      .field-hint {
        font-size: 11px;
      }

      .time-value-badge {
        font-size: 0.75em;
        padding: 3px 6px;
      }

      .retention-line {
        font-size: 0.8em;
      }

      .upload-area {
        padding: 20px 15px;
      }

      .cloud-icon {
        font-size: 30px;
      }

      .browse-btn {
        padding: 8px 16px;
        font-size: 14px;
      }

      #viewRecordModal .modal-card {
        padding: 0;
        margin: 10px;
        width: calc(100% - 20px);
      }

      #viewRecordModal .action-buttons {
        flex-direction: column;
      }

      #viewRecordModal .button-action.cancel {
        width: 100%;
      }
    }

    /* Very Small Mobile (below 375px) */
    @media (max-width: 374px) {
      .form-fields-grid {
        gap: 10px;
      }

      .radio-group {
        gap: 8px;
      }

      .radio-option,
      .medium-option {
        font-size: 13px;
        gap: 6px;
      }

      .radio-option input[type="radio"],
      .medium-option input[type="checkbox"] {
        width: 16px;
        height: 16px;
      }

      .medium-checkboxes {
        gap: 8px;
      }

      .form-section-title {
        font-size: 15px;
        margin: 15px 0 10px 0;
      }

      .file-item {
        padding: 6px 10px;
        gap: 6px;
      }

      .file-name {
        font-size: 13px;
      }

      .file-tag-input {
        padding: 5px 6px;
        font-size: 11px;
      }

      .table-wrapper {
        margin: 0 -5px;
        padding: 0 5px;
      }

      table {
        min-width: 550px;
        font-size: 12px;
      }

      th,
      td {
        padding: 6px 4px;
      }

      .view-field {
        padding: 6px 8px;
        font-size: 12px;
        min-height: 36px;
      }

      .button-action {
        padding: 8px 12px;
        font-size: 13px;
      }

      .upload-area {
        padding: 15px 10px;
      }

      .cloud-icon {
        font-size: 25px;
      }

      .browse-btn {
        padding: 6px 12px;
        font-size: 13px;
      }
    }

    /* Modal Responsiveness */
    @media (max-width: 767px) {
      .modal-card {
        width: 95%;
        max-width: 95vw;
        margin: 20px auto;
        padding: 0;
      }

      .modal-close-btn {
        top: 10px;
        right: 10px;
        font-size: 20px;
        width: 30px;
        height: 30px;
      }

      .form-group.full-width {
        grid-column: 1;
      }
    }

    /* Landscape Mode for Mobile */
    @media (max-height: 600px) and (orientation: landscape) {
      .modal-card {
        max-height: 85vh;
        overflow-y: auto;
      }

      .uploaded-files-list {
        max-height: 150px;
      }

      .view-description {
        min-height: 80px;
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
        <h1>RECORDS MANAGEMENT</h1>
      </div>

      <div class="actions">
        <button class="button filter-button" onclick="showFilterModal()">
          Filter
          <i class='bx bx-filter'></i>
        </button>

        <button class="button primary-action-btn" id="addRecordBtn">
          <i class='bx bxs-folder-plus'></i>
          Add New Record
        </button>
      </div>
    </header>
    <div class="card dashboard-card">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>RECORD SERIES CODE</th>
              <th>RECORD TITLE</th>
              <th>DEPARTMENT</th>
              <th>INCLUSIVE DATE</th>
              <th>RETENTION PERIOD</th>
              <th>TIME VALUE</th>
              <th>STATUS</th>
              <th>ACTION</th>
            </tr>
          </thead>
          <tbody id="recordsTableBody">
            <?php if (empty($records)): ?>
              <tr>
                <td colspan="8" style="text-align: center; padding: 2rem; color: #78909c;">
                  No records found. Click "Add New Record" to create one.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($records as $record): ?>
                <tr data-record-id="<?= $record['record_id'] ?>"
                  data-office-name="<?= htmlspecialchars($record['office_name']) ?>"
                  data-class-id="<?= $record['class_id'] ?>">
                  <td><?= htmlspecialchars($record['record_series_code'] ?? 'N/A') ?></td>
                  <td><?= htmlspecialchars($record['record_series_title']) ?></td>
                  <td><?= htmlspecialchars($record['office_name']) ?></td>
                  <td>
                    <?= $record['period_from'] ? date('m/d/Y', strtotime($record['period_from'])) : 'N/A' ?>
                    -
                    <?= $record['period_to'] ? date('m/d/Y', strtotime($record['period_to'])) : 'N/A' ?>
                  </td>
                  <td>
                    <div class="retention-display">
                      <?php if ($record['active_years']): ?>
                        <div class="retention-line">Active: <?= $record['active_years'] ?> years</div>
                      <?php endif; ?>
                      <?php if ($record['storage_years']): ?>
                        <div class="retention-line">Storage: <?= $record['storage_years'] ?> years</div>
                      <?php endif; ?>
                      <?php if ($record['total_years']): ?>
                        <div class="retention-line retention-total">Total: <?= $record['total_years'] ?> years</div>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <?php if ($record['time_value']): ?>
                      <span class="time-value-badge <?= strtolower($record['time_value']) ?>">
                        <?= htmlspecialchars($record['time_value']) ?>
                      </span>
                    <?php else: ?>
                      N/A
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge <?= strtolower($record['status']) ?>">
                      <?= htmlspecialchars($record['status']) ?>
                    </span>
                  </td>
                  <td>
                    <button class="edit-action-btn" onclick="handleEditClick(<?= $record['record_id'] ?>)">
                      <i class='bx bx-edit'></i>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- FILTER MODAL -->
    <div id="filterModal" class="modal-backdrop">
      <div class="modal-card filter-card">
        <button class="modal-close-btn" onclick="hideFilterModal()">&times;</button>

        <div class="filter-header">
          <h2>FILTER RECORDS</h2>
        </div>

        <div class="basic-filter-row">
          <div class="search-input-wrapper">
            <input type="text" id="searchInput" placeholder="Type in file name or name">
            <i class="fas fa-search search-icon"></i>
          </div>

          <div class="department-dropdown select-wrapper">
            <select id="departmentSelect">
              <option value="">Department</option>
              <?php foreach ($offices as $office): ?>
                <option value="<?= $office['office_id'] ?>"><?= htmlspecialchars($office['office_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <button class="filter-apply-btn" onclick="applyFilters()">APPLY</button>
        </div>

        <a href="#" class="advance-filter-toggle" id="filterToggle" onclick="toggleAdvancedFilters(event)"
          aria-expanded="false">
          Show Advance Filter <i class="fas fa-caret-down"></i>
        </a>

        <div id="advancedFiltersRow" class="advanced-filters-row">
          <div class="filter-select-group select-wrapper">
            <select id="classificationSelect">
              <option value="">Classification</option>
              <?php foreach ($classifications as $class): ?>
                <option value="<?= $class['class_id'] ?>"><?= htmlspecialchars($class['class_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-select-group select-wrapper">
            <select id="statusSelect">
              <option value="">Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="archived">Archived</option>
              <option value="disposed">Disposed</option>
            </select>
          </div>
          <div class="filter-select-group select-wrapper">
            <select id="timeValueSelect">
              <option value="">Time Value</option>
              <option value="Permanent">Permanent</option>
              <option value="Temporary">Temporary</option>
            </select>
          </div>
          <!-- <div class="filter-select-group">
            <input type="date" id="dateFromInput" placeholder="Period From">
          </div>
          <div class="filter-select-group">
            <input type="date" id="dateToInput" placeholder="Period To">
          </div> -->
        </div>

        <a href="#" class="clear-filter-link" onclick="clearFilters()">Clear Filter</a>
      </div>
    </div>

    <!-- RECORD FORM MODAL -->
    <div id="recordModal" class="modal-backdrop">
      <div class="modal-card record-form-card">
        <div class="modal-header">
          <button class="modal-close-btn" onclick="hideRecordFormModal()" style="color: #ffffffff;">&times;</button>
          <h1 class="form-title" id="recordModalTitle">RECORD FORM</h1>
        </div>

        <form id="recordForm" method="POST" action="../process_record.php" enctype="multipart/form-data">
          <input type="hidden" id="recordId" name="record_id" value="">

          <!-- Basic Record Information -->
          <div class="form-fields-grid">
            <div class="form-group full-width">
              <label for="recordSeriesTitle">Record Title:</label>
              <input type="text" id="recordSeriesTitle" name="record_series_title" placeholder="Enter record title" required>
            </div>

            <div class="form-group select-wrapper">
              <label for="officeDepartment">Office/ Department:</label>
              <select id="officeDepartment" name="office_id" required>
                <option value="">Select Department</option>
                <?php if (!empty($offices)): ?>
                  <?php foreach ($offices as $office): ?>
                    <option value="<?= $office['office_id'] ?>"><?= htmlspecialchars($office['office_name']) ?></option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <option value="">No departments found</option>
                <?php endif; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="recordSeriesCode">Record Series Code:</label>
              <input type="text" id="recordSeriesCode" name="record_series_code"
                placeholder="Enter series code (e.g., HR-FSR-001)" required>
            </div>

            <!-- NAP Classification with Search -->
            <div class="form-group full-width">
              <label for="classification">Classification (NAP Standards):</label>
              <select id="classification" name="class_id" required style="width: 100%">
                <option value="">Search and select classification...</option>
                <?php if (!empty($classifications)): ?>
                  <?php foreach ($classifications as $class): ?>
                    <option value="<?= $class['class_id'] ?>"
                      data-retention="<?= htmlspecialchars($class['retention_period']) ?>"
                      data-category="<?= htmlspecialchars($class['functional_category']) ?>"
                      data-authority="<?= htmlspecialchars($class['nap_authority']) ?>"
                      data-permanent="<?= isset($class['permanent_indicator']) && $class['permanent_indicator'] ? 'yes' : 'no' ?>">
                      <?= htmlspecialchars($class['class_name']) ?>
                      (<?= htmlspecialchars($class['functional_category']) ?> -
                      <?= htmlspecialchars($class['retention_period']) ?>)
                    </option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <option value="">No classifications found</option>
                <?php endif; ?>
              </select>
              <div id="classificationInfo" class="classification-info">
                <div><strong>Retention Period:</strong> <span id="infoRetention" class="retention-badge"></span></div>
                <div><strong>Category:</strong> <span id="infoCategory"></span></div>
                <div><strong>Time Value:</strong> <span id="infoTimeValue" class="time-value-badge"></span></div>
                <div class="nap-details"><strong>NAP Authority:</strong> <span id="infoAuthority"></span></div>
              </div>
            </div>

            <div class="form-group">
              <label for="periodFrom">Period From:</label>
              <input type="date" id="periodFrom" name="period_from" title="Start Date" required>
            </div>

            <div class="form-group">
              <label for="periodTo">Period To:</label>
              <input type="date" id="periodTo" name="period_to" title="End Date" readonly class="view-field">
            </div>

            <!-- Retention Period Fields -->
            <div class="form-group">
              <label for="activeYears">Active Retention (years):</label>
              <input type="number" id="activeYears" name="active_years" min="0" max="100" class="view-field" readonly>
              <div class="field-hint">
                <i class="fas fa-info-circle"></i>
                Active retention is set automatically from classification.
                Add storage years if applicable.
              </div>
            </div>

            <div class="form-group">
              <label for="storageYears">Storage Retention (years):</label>
              <input type="number" id="storageYears" name="storage_years" min="0" max="100" placeholder="Enter storage years"
                style="background-color: white; border: 1px solid #cfd8dc;">
            </div>

            <div class="form-group">
              <label for="totalYears">Total Retention (years):</label>
              <input type="number" id="totalYears" name="total_years" min="0" max="200" class="view-field" readonly>
            </div>
          </div>

          <!-- Record Details Section -->
          <h2 class="form-section-title">Record Details</h2>

          <div class="form-fields-grid">
            <div class="form-group">
              <label for="volume">Volume (in cubic meters):</label>
              <input type="number" id="volume" name="volume" step="0.01" min="0" placeholder="0.00" style="width: 100%;">
            </div>

            <div class="form-group">
              <label>Records Medium:</label>
              <div class="medium-checkboxes">
                <label class="medium-option">
                  <input type="checkbox" name="records_medium[]" value="Paper"> Paper
                </label>
                <label class="medium-option">
                  <input type="checkbox" name="records_medium[]" value="Electronic Files"> Electronic Files
                </label>
                <label class="medium-option">
                  <input type="checkbox" name="records_medium[]" value="Computer Printouts"> Computer Printouts
                </label>
              </div>
            </div>

            <div class="form-group">
              <label>Access Restrictions:</label>
              <div class="radio-group">
                <label class="radio-option">
                  <input type="radio" name="restrictions" value="Open Access" checked> Open Access
                </label>
                <label class="radio-option">
                  <input type="radio" name="restrictions" value="Restricted Access"> Restricted
                </label>
                <br>
                <label class="radio-option">
                  <input type="radio" name="restrictions" value="Confidential"> Confidential
                </label>
              </div>
            </div>

            <div class="form-group">
              <label for="location">Location of Records:</label>
              <input type="text" id="location" name="location_of_records" placeholder="Physical or digital location">
            </div>

            <div class="form-group">
              <label for="frequency">Frequency of Use:</label>
              <select id="frequency" name="frequency_of_use">
                <option value="">Select frequency...</option>
                <option value="Daily">Daily</option>
                <option value="Weekly">Weekly</option>
                <option value="Monthly">Monthly</option>
                <option value="Quarterly">Quarterly</option>
                <option value="Annually">Annually</option>
                <option value="Rarely">Rarely</option>
              </select>
            </div>

            <div class="form-group">
              <label for="duplication">Duplication:</label>
              <input type="text" id="duplication" name="duplication" placeholder="Duplication information">
            </div>

            <div class="form-group">
              <label for="timeValue">Time Value:</label>
              <input type="text" id="timeValue" name="time_value" class="view-field" readonly>
            </div>

            <div class="form-group">
              <label for="utilityValue">Utility Value:</label>
              <select id="utilityValue" name="utility_value">
                <option value="">Select value...</option>
                <option value="Administrative">Administrative</option>
                <option value="Fiscal">Fiscal</option>
                <option value="Legal">Legal</option>
                <option value="Archival">Archival</option>
              </select>
            </div>
          </div>

          <!-- Upload Files Section -->
          <h2 class="upload-title">Record Attachments</h2>
          <div class="upload-area" id="uploadArea">
            <i class="fas fa-cloud-upload-alt cloud-icon"></i>
            <p>Drag and Drop files here<br>-OR-</p>
            <button type="button" class="browse-btn" onclick="event.preventDefault(); event.stopPropagation();">Browse Files</button>
            <input type="file" id="fileUpload" name="attachments[]" multiple style="display: none;"
              accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif">
          </div>

          <!-- UPLOADED FILES LIST -->
          <div class="uploaded-files-section">
            <h2 class="section-header">UPLOADED FILES (<span id="fileCount">0</span>)</h2>
            <div class="uploaded-files-list" id="uploadedFilesList">
              <div style="padding: 10px 15px; color: #78909c; font-style: italic; font-size: 14px;">No files currently
                attached.</div>
            </div>
          </div>

          <!-- Description Section -->
          <div class="form-group full-width" style="margin-top: 20px;">
            <label for="description">Detailed Description / Notes:</label>
            <textarea id="description" name="description" class="description-textarea" rows="5"
              placeholder="Add detailed notes or contextual information about the record series..."></textarea>
          </div>

          <!-- Bottom Action Buttons -->
          <div class="action-buttons">
            <button type="button" class="button-action cancel" onclick="hideRecordFormModal()">CANCEL</button>
            <!-- <button type="button" class="button-action save-draft" id="saveDraftBtn" onclick="saveAsDraft()">SAVE AS
              DRAFT</button> -->
            <button type="submit" class="button-action save" id="saveRecordBtn">SAVE</button>
          </div>
        </form>
      </div>
    </div>

    <!-- VIEW RECORD MODAL -->
    <div id="viewRecordModal" class="modal-backdrop">
      <div class="modal-card record-form-card">
        <div class="modal-header">
          <button class="modal-close-btn" onclick="hideViewRecordModal()" style="color: #ffffff;">&times;</button>
          <h1 class="form-title" id="viewRecordModalTitle">VIEW RECORD</h1>
        </div>

        <div id="viewRecordForm" style="padding: clamp(20px, 2.5vw, 30px); overflow-y: auto; flex: 1;">
          <!-- Basic Record Information -->
          <div class="form-fields-grid">
            <div class="form-group full-width">
              <label>Record Title:</label>
              <div class="view-field" id="viewRecordTitle"></div>
            </div>

            <div class="form-group select-wrapper">
              <label>Office/ Department:</label>
              <div class="view-field" id="viewOfficeDepartment"></div>
            </div>

            <div class="form-group">
              <label>Record Series Code:</label>
              <div class="view-field" id="viewRecordSeriesCode"></div>
            </div>

            <!-- NAP Classification with Search -->
            <div class="form-group full-width">
              <label>Classification (NAP Standards):</label>
              <div class="view-field" id="viewClassification"></div>
              <div id="viewClassificationInfo" class="classification-info" style="display: none;">
                <div><strong>Retention Period:</strong> <span id="viewInfoRetention" class="retention-badge"></span></div>
                <div><strong>Category:</strong> <span id="viewInfoCategory"></span></div>
                <div><strong>Time Value:</strong> <span id="viewInfoTimeValue" class="time-value-badge"></span></div>
                <div class="nap-details"><strong>NAP Authority:</strong> <span id="viewInfoAuthority"></span></div>
              </div>
            </div>

            <div class="form-group">
              <label>Period From:</label>
              <div class="view-field" id="viewPeriodFrom"></div>
            </div>

            <div class="form-group">
              <label>Period To:</label>
              <div class="view-field" id="viewPeriodTo"></div>
            </div>

            <!-- Retention Period Fields -->
            <div class="form-group">
              <label>Active Retention (years):</label>
              <div class="view-field" id="viewActiveYears"></div>
              <div class="field-hint">
                <i class="fas fa-info-circle"></i>
                Active retention is set automatically from classification.
              </div>
            </div>

            <div class="form-group">
              <label>Storage Retention (years):</label>
              <div class="view-field" id="viewStorageYears"></div>
            </div>

            <div class="form-group">
              <label>Total Retention (years):</label>
              <div class="view-field" id="viewTotalYears"></div>
            </div>
          </div>

          <!-- Record Details Section -->
          <h2 class="form-section-title">Record Details</h2>

          <div class="form-fields-grid">
            <div class="form-group">
              <label>Volume (in cubic meters):</label>
              <div class="view-field" id="viewVolume"></div>
            </div>

            <div class="form-group">
              <label>Records Medium:</label>
              <div class="view-field" id="viewMedium"></div>
            </div>

            <div class="form-group">
              <label>Access Restrictions:</label>
              <div class="view-field" id="viewRestrictions"></div>
            </div>

            <div class="form-group">
              <label>Location of Records:</label>
              <div class="view-field" id="viewLocation"></div>
            </div>

            <div class="form-group">
              <label>Frequency of Use:</label>
              <div class="view-field" id="viewFrequency"></div>
            </div>

            <div class="form-group">
              <label>Duplication:</label>
              <div class="view-field" id="viewDuplication"></div>
            </div>

            <div class="form-group">
              <label>Time Value:</label>
              <div class="view-field" id="viewTimeValue"></div>
            </div>

            <div class="form-group">
              <label>Utility Value:</label>
              <div class="view-field" id="viewUtilityValue"></div>
            </div>
          </div>

          <!-- Upload Files Section -->
          <h2 class="upload-title">Record Attachments</h2>
          <div class="uploaded-files-section" style="margin-top: 20px;">
            <h2 class="section-header">ATTACHED FILES (<span id="viewFileCount">0</span>)</h2>
            <div class="uploaded-files-list" id="viewUploadedFilesList">
              <div style="padding: 10px 15px; color: #78909c; font-style: italic; font-size: 14px;">
                No files attached to this record.
              </div>
            </div>
          </div>

          <!-- Description Section -->
          <div class="form-group full-width" style="margin-top: 20px;">
            <label>Detailed Description / Notes:</label>
            <div class="view-field view-description" id="viewDescription"></div>
          </div>

          <!-- Status Field -->
          <div class="form-group" style="margin-top: 20px;">
            <label>Status:</label>
            <div class="view-field" id="viewStatus"></div>
          </div>
        </div>

        <!-- Bottom Action Buttons -->
        <div class="action-buttons">
          <button type="button" class="button-action cancel" onclick="hideViewRecordModal()">CLOSE</button>
        </div>
      </div>
    </div>
  </main>

  <!-- Success Toast -->
  <div id="successToast" class="success-toast"></div>

  <!-- Add Select2 JS -->
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

  <!-- COMPLETE JAVASCRIPT CODE WITH FIXED VALIDATION AND FILTERS -->
<script>
    // --- Element References ---
    const recordFormModal = document.getElementById('recordModal');
    const viewRecordModal = document.getElementById('viewRecordModal');
    const filterModal = document.getElementById('filterModal');
    const advancedFiltersRow = document.getElementById('advancedFiltersRow');
    const filterToggle = document.getElementById('filterToggle');
    const recordForm = document.getElementById('recordForm');

    const modalTitle = document.getElementById('recordModalTitle');
    const viewModalTitle = document.getElementById('viewRecordModalTitle');
    const saveRecordBtn = document.getElementById('saveRecordBtn');
    const uploadedFilesList = document.getElementById('uploadedFilesList');
    const tableBody = document.getElementById('recordsTableBody');
    const fileCount = document.getElementById('fileCount');

    let selectedFiles = [];
    let select2Initialized = false;
    let removedFiles = [];

    // --- Parse Retention Period from Classification ---
    function parseRetentionPeriod(retentionPeriod) {
        if (!retentionPeriod) return {
            active: 0,
            storage: 0,
            total: 0,
            permanent: false
        };

        // Handle "Permanent" or "permanent" cases
        const lowerRetention = retentionPeriod.toLowerCase();
        if (lowerRetention.includes('permanent')) {
            return {
                active: 0,
                storage: 0,
                total: 0,
                permanent: true
            };
        }

        // Initialize values
        let active = 0;
        let storage = 0;

        // Convert to lowercase for easier matching
        const text = retentionPeriod.toLowerCase();

        // Try to match "X years active + Y years storage" pattern
        const fullPattern = /(\d+)\s*years?\s*active\s*\+\s*(\d+)\s*years?\s*storage/i;
        const fullMatch = text.match(fullPattern);

        if (fullMatch) {
            active = parseInt(fullMatch[1]);
            storage = parseInt(fullMatch[2]);
        } else {
            // Try to match "X years active" (active only)
            const activePattern = /(\d+)\s*years?\s*active/i;
            const activeMatch = text.match(activePattern);

            if (activeMatch) {
                active = parseInt(activeMatch[1]);
            }

            // Try to match "X years storage" (storage only)
            const storagePattern = /(\d+)\s*years?\s*storage/i;
            const storageMatch = text.match(storagePattern);

            if (storageMatch) {
                storage = parseInt(storageMatch[1]);
            }

            // If no "active" or "storage" keyword found, but contains "years"
            if (active === 0 && storage === 0) {
                const yearsPattern = /(\d+)\s*years?/i;
                const yearsMatch = text.match(yearsPattern);

                if (yearsMatch) {
                    // Assume it's all active if not specified
                    active = parseInt(yearsMatch[1]);
                }
            }
        }

        const total = active + storage;
        return {
            active,
            storage,
            total,
            permanent: false
        };
    }

    // --- Date Calculation Function ---
    function calculateEndDate() {
        const startDate = document.getElementById('periodFrom').value;
        const activeYears = parseInt(document.getElementById('activeYears').value) || 0;

        if (!startDate || activeYears === 0) {
            document.getElementById('periodTo').value = '';
            return;
        }

        const start = new Date(startDate);
        const endDate = new Date(start);
        endDate.setFullYear(start.getFullYear() + activeYears);
        document.getElementById('periodTo').value = endDate.toISOString().split('T')[0];
    }

    // --- Calculate Total Retention ---
    function calculateTotalRetention() {
        const activeYears = parseInt(document.getElementById('activeYears').value) || 0;
        const storageYears = parseInt(document.getElementById('storageYears').value) || 0;
        const totalYears = activeYears + storageYears;
        document.getElementById('totalYears').value = totalYears;
        calculateEndDate();
    }

    // --- Initialize Select2 ---
    function initializeSelect2() {
        if (!select2Initialized) {
            try {
                $('#classification').select2({
                    placeholder: 'Search and select classification...',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $('#recordModal')
                });

                $('#classification').on('change', function() {
                    const selectedOption = $(this).find('option:selected');
                    const retentionText = selectedOption.data('retention');
                    const category = selectedOption.data('category');
                    const authority = selectedOption.data('authority');
                    const isPermanent = selectedOption.data('permanent') === 'yes';

                    if (retentionText) {
                        const retention = parseRetentionPeriod(retentionText);
                        document.getElementById('activeYears').value = retention.active;
                        document.getElementById('storageYears').value = '';
                        document.getElementById('storageYears').readOnly = false;
                        document.getElementById('storageYears').classList.remove('view-field');
                        document.getElementById('storageYears').style.backgroundColor = 'white';
                        document.getElementById('storageYears').placeholder = 'Enter storage years';
                        
                        calculateTotalRetention();
                        calculateEndDate();

                        const timeValue = retention.permanent || isPermanent ? 'Permanent' : 'Temporary';
                        document.getElementById('timeValue').value = timeValue;

                        $('#infoRetention').text(retentionText);
                        $('#infoCategory').text(category || 'Not specified');
                        $('#infoTimeValue').text(timeValue).removeClass('permanent temporary').addClass(timeValue.toLowerCase());
                        $('#infoAuthority').text(authority || 'Not specified');
                        $('#classificationInfo').addClass('show');
                    } else {
                        document.getElementById('activeYears').value = '';
                        document.getElementById('storageYears').value = '';
                        document.getElementById('storageYears').readOnly = false;
                        document.getElementById('totalYears').value = '';
                        document.getElementById('timeValue').value = '';
                        document.getElementById('periodTo').value = '';
                        $('#classificationInfo').removeClass('show');
                    }
                });

                select2Initialized = true;
            } catch (error) {
                console.error('Select2 initialization error:', error);
                $('#classification').show();
            }
        }
    }

    // --- Setup Retention Calculation ---
    function setupRetentionCalculation() {
        const storageYearsInput = document.getElementById('storageYears');
        const activeYearsInput = document.getElementById('activeYears');
        const periodFromInput = document.getElementById('periodFrom');

        if (storageYearsInput) {
            storageYearsInput.addEventListener('input', calculateTotalRetention);
            storageYearsInput.addEventListener('change', calculateTotalRetention);
        }

        if (activeYearsInput) {
            activeYearsInput.addEventListener('input', calculateEndDate);
            activeYearsInput.addEventListener('change', calculateEndDate);
        }

        if (periodFromInput) {
            periodFromInput.addEventListener('change', calculateEndDate);
        }
    }

    // --- File Management Functions ---
    function displayExistingFiles(files) {
        if (!files || files.length === 0) {
            uploadedFilesList.innerHTML = '<div style="padding: 10px 15px; color: #78909c; font-style: italic; font-size: 14px;">No files currently attached.</div>';
            updateFileCount();
            return;
        }

        const existingFilesHTML = files.map((file, index) => `
            <div class="file-item existing-file" data-file-id="${file.file_id}">
                <div class="file-info">
                    <div class="file-name">
                        <i class="fas fa-paperclip"></i> ${file.file_name}
                    </div>
                    <div class="file-size">${formatFileSize(file.file_size)}</div>
                </div>
                <span class="file-status">Uploaded</span>
                <button type="button" class="remove-file-btn" onclick="removeExistingFile(${file.file_id}, '${file.file_name.replace(/'/g, "\\'")}')">
                    <i class="fas fa-times"></i>
                </button>
                <input type="hidden" name="existing_files[]" value="${file.file_id}">
            </div>
        `).join('');

        uploadedFilesList.innerHTML = existingFilesHTML;
        updateFileCount();
    }

    function removeExistingFile(fileId, fileName) {
        if (!confirm(`Are you sure you want to delete "${fileName}"? This action cannot be undone.`)) {
            return;
        }

        if (!removedFiles.includes(fileId)) {
            removedFiles.push(fileId);
        }

        let removalInput = document.querySelector(`input[name="removed_files[]"][value="${fileId}"]`);
        if (!removalInput) {
            removalInput = document.createElement('input');
            removalInput.type = 'hidden';
            removalInput.name = 'removed_files[]';
            removalInput.value = fileId;
            recordForm.appendChild(removalInput);
        }

        const fileElement = document.querySelector(`.existing-file[data-file-id="${fileId}"]`);
        if (fileElement) {
            fileElement.style.opacity = '0.5';
            fileElement.style.textDecoration = 'line-through';
            fileElement.querySelector('.file-status').textContent = 'Marked for deletion';
            fileElement.querySelector('.remove-file-btn').disabled = true;

            setTimeout(() => {
                fileElement.remove();
                updateFileCount();
            }, 1000);
        }

        updateFileCount();
        showToast(`File "${fileName}" marked for deletion`);
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
            const fileSize = file.file_size ? formatFileSize(file.file_size) : 'Unknown size';
            const downloadLink = file.file_path ?
                `<a href="../${file.file_path}" target="_blank" style="margin-left: 10px; color: #1f366c; text-decoration: underline; font-size: 12px;">
                    <i class="fas fa-download"></i> Download
                </a>` : '';

            return `
                <div class="file-item" style="display: flex; align-items: center; padding: 10px 15px; border-bottom: 1px solid #e0e0e0;">
                    <div style="flex: 1; min-width: 200px;">
                        <div class="file-name" style="font-weight: 500; color: #37474f; word-break: break-word;">
                            <i class="fas fa-paperclip"></i> ${file.file_name || 'Unnamed file'}
                        </div>
                        <div class="file-size" style="font-size: 12px; color: #78909c;">${fileSize}</div>
                        ${file.file_tag ? `<div class="file-tag" style="font-size: 11px; color: #666; margin-top: 2px;">Tag: ${file.file_tag}</div>` : ''}
                    </div>
                    ${downloadLink}
                </div>
            `;
        }).join('');

        fileCountElement.textContent = files.length;
    }

    function updateFileList() {
        const existingFileElements = document.querySelectorAll('.existing-file');
        const totalCount = existingFileElements.length + selectedFiles.length;
        fileCount.textContent = totalCount;

        let newFilesHTML = '';

        if (selectedFiles.length > 0) {
            newFilesHTML = selectedFiles.map((file, index) => `
                <div class="file-item new-file" data-file-index="${index}">
                    <div class="file-info">
                        <div class="file-name">
                            <i class="fas fa-upload"></i> ${file.name}
                        </div>
                        <div class="file-size">${formatFileSize(file.size)}</div>
                    </div>
                    <input type="text" class="file-tag-input" placeholder="Add tag (optional)" name="file_tags[]">
                    <span class="file-status">Ready to upload</span>
                    <button type="button" class="remove-file-btn" onclick="removeFile(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `).join('');
        }

        if (totalCount === 0) {
            uploadedFilesList.innerHTML = '<div style="padding: 10px 15px; color: #78909c; font-style: italic; font-size: 14px;">No files currently attached.</div>';
        } else {
            const existingFilesHTML = Array.from(existingFileElements).map(el => el.outerHTML).join('');
            uploadedFilesList.innerHTML = existingFilesHTML + newFilesHTML;
        }
    }

    function updateFileCount() {
        const existingFileElements = document.querySelectorAll('.existing-file');
        const totalCount = existingFileElements.length + selectedFiles.length;
        fileCount.textContent = totalCount;
    }

    function initializeFileUpload() {
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileUpload');

        // Clear existing event listeners
        const newFileInput = fileInput.cloneNode(true);
        newFileInput.value = '';
        fileInput.parentNode.replaceChild(newFileInput, fileInput);

        const currentFileInput = document.getElementById('fileUpload');

        // Add event listener for file selection
        currentFileInput.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                handleFileSelect(e.target.files);
                e.target.value = '';
            }
        }, { once: false });

        // Drag and drop handlers
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('drag-over');
        });

        uploadArea.addEventListener('dragleave', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('drag-over');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('drag-over');
            handleFileSelect(e.dataTransfer.files);
        });

        // Click handler
        uploadArea.addEventListener('click', (e) => {
            e.stopPropagation();
            currentFileInput.click();
        });

        // Browse button
        const browseBtn = uploadArea.querySelector('.browse-btn');
        if (browseBtn) {
            browseBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                currentFileInput.click();
            });
        }
    }

    function handleFileSelect(files) {
        if (!files || files.length === 0) return;

        const newFiles = Array.from(files);
        let addedCount = 0;

        newFiles.forEach(newFile => {
            const isDuplicate = selectedFiles.some(existingFile =>
                existingFile.name === newFile.name &&
                existingFile.size === newFile.size &&
                existingFile.lastModified === newFile.lastModified
            );

            if (!isDuplicate) {
                selectedFiles.push(newFile);
                addedCount++;
            }
        });

        if (addedCount > 0) {
            updateFileList();
            showToast(`Added ${addedCount} file(s)`);
        } else {
            showToast('File already added', true);
        }
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function removeFile(index) {
        if (index >= 0 && index < selectedFiles.length) {
            const removedFile = selectedFiles[index];
            selectedFiles.splice(index, 1);
            updateFileList();
            showToast(`Removed ${removedFile.name}`);
        }
    }

    // --- UI Feedback Functions ---
    function showToast(message, isError = false) {
        const toast = document.getElementById('successToast');
        if (isError) {
            toast.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${message}`;
            toast.style.background = '#f44336';
        } else {
            toast.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
            toast.style.background = '#4caf50';
        }
        toast.classList.add('show');

        setTimeout(() => {
            toast.classList.remove('show');
        }, 4000);
    }

    // --- Modal Control Functions ---
    function hideRecordFormModal() {
        recordFormModal.classList.remove('show');
        document.body.style.overflow = '';

        // Clear files
        selectedFiles = [];
        uploadedFilesList.innerHTML = '<div style="padding: 10px 15px; color: #78909c; font-style: italic; font-size: 14px;">No files currently attached.</div>';
        updateFileCount();

        if (select2Initialized) {
            $('#classification').select2('destroy');
            select2Initialized = false;
        }
    }

    function hideViewRecordModal() {
        viewRecordModal.classList.remove('show');
        document.body.style.overflow = '';
    }

    function showFilterModal() {
        filterModal.classList.add('show');
        document.body.style.overflow = 'hidden';

        advancedFiltersRow.classList.remove('show');
        filterToggle.innerHTML = 'Show Advance Filter <i class="fas fa-caret-down"></i>';
        filterToggle.setAttribute('aria-expanded', 'false');
    }

    function hideFilterModal() {
        filterModal.classList.remove('show');
        document.body.style.overflow = '';
    }

    function toggleAdvancedFilters(event) {
        event.preventDefault();
        const isVisible = advancedFiltersRow.classList.toggle('show');

        if (isVisible) {
            filterToggle.innerHTML = 'Hide Advance Filter <i class="fas fa-caret-up"></i>';
            filterToggle.setAttribute('aria-expanded', 'true');
        } else {
            filterToggle.innerHTML = 'Show Advance Filter <i class="fas fa-caret-down"></i>';
            filterToggle.setAttribute('aria-expanded', 'false');
        }
    }

    // --- Form Utility ---
    function clearForm() {
        document.getElementById('recordForm').reset();
        document.getElementById('recordId').value = '';
        selectedFiles = [];
        removedFiles = [];

        document.querySelectorAll('input[name="removed_files[]"]').forEach(input => input.remove());

        uploadedFilesList.innerHTML = '<div style="padding: 10px 15px; color: #78909c; font-style: italic; font-size: 14px;">No files currently attached.</div>';
        updateFileCount();

        // Reset calculated fields
        document.getElementById('timeValue').value = '';
        document.getElementById('periodTo').value = '';
        document.getElementById('activeYears').value = '';
        document.getElementById('storageYears').value = '';
        document.getElementById('storageYears').readOnly = false;
        document.getElementById('storageYears').classList.remove('view-field');
        document.getElementById('storageYears').style.backgroundColor = 'white';
        document.getElementById('storageYears').placeholder = 'Enter storage years';
        document.getElementById('totalYears').value = '';
        $('#classificationInfo').removeClass('show');

        // Reset checkboxes and radio buttons
        document.querySelectorAll('input[name="records_medium[]"]').forEach(cb => cb.checked = false);
        document.querySelector('input[name="restrictions"][value="Open Access"]').checked = true;

        // Clear file input
        const fileInput = document.getElementById('fileUpload');
        if (fileInput) {
            fileInput.value = '';
        }

        // Reset Select2
        if (select2Initialized) {
            $('#classification').val(null).trigger('change');
        }
    }

    // --- AJAX Functions for Data Retrieval ---
    function fetchRecordFiles(recordId) {
        console.log('Fetching files for record:', recordId);
        return fetch(`../get_record_files.php?id=${recordId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    return data.files || [];
                } else {
                    throw new Error(data.message || 'Failed to load files');
                }
            })
            .catch(error => {
                console.error('Error fetching files:', error);
                return [];
            });
    }

    function fetchRecordData(recordId) {
        console.log('Fetching record data for ID:', recordId);
        return fetch(`../get_record.php?id=${recordId}`)
            .then(response => {
                if (response.status === 500) {
                    return response.text().then(errorText => {
                        let errorMessage = 'Server Error (500) - Check PHP configuration';
                        throw new Error(errorMessage);
                    });
                }

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                return response.json();
            })
            .then(data => {
                if (data.success && data.record) {
                    return data.record;
                } else {
                    throw new Error(data.message || 'Failed to load record data');
                }
            })
            .catch(error => {
                console.error('fetchRecordData error:', error);
                throw error;
            });
    }

    // --- EDIT FUNCTION (from first file) ---
    function handleEditClick(id) {
        console.log('Edit clicked for record ID:', id);
        openRecordModal('edit', id);
    }

    // --- Record Modal Functions (from first file) ---
    function openRecordModal(mode, recordId = null) {
        clearForm();

        setTimeout(() => {
            initializeSelect2();
        }, 100);

        if (mode === 'add') {
            modalTitle.textContent = 'ADD NEW RECORD';
            saveRecordBtn.textContent = 'SAVE RECORD';
            recordForm.action = '../process_record.php?action=add';

            recordFormModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        } else if (mode === 'edit' && recordId !== null) {
            modalTitle.textContent = 'EDIT RECORD';
            saveRecordBtn.textContent = 'UPDATE RECORD';
            recordForm.action = '../process_record.php?action=edit';
            document.getElementById('recordId').value = recordId;

            recordFormModal.classList.add('show');
            document.body.style.overflow = 'hidden';

            saveRecordBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> LOADING...';
            saveRecordBtn.disabled = true;

            Promise.all([
                    fetchRecordData(recordId),
                    fetchRecordFiles(recordId)
                ])
                .then(([record, files]) => {
                    populateFormWithRecordData(record);
                    displayExistingFiles(files);
                    saveRecordBtn.innerHTML = 'UPDATE RECORD';
                    saveRecordBtn.disabled = false;
                })
                .catch(error => {
                    console.error('Error loading record:', error);
                    showToast('Error loading record data: ' + error.message, true);
                    saveRecordBtn.innerHTML = 'UPDATE RECORD';
                    saveRecordBtn.disabled = false;
                });
        }
    }

    // --- Populate Form with Record Data (from first file) ---
    function populateFormWithRecordData(record) {
        console.log('Populating form with record data:', record);

        // Basic Information
        document.getElementById('recordSeriesTitle').value = record.record_series_title || '';
        document.getElementById('officeDepartment').value = record.office_id || '';
        document.getElementById('recordSeriesCode').value = record.record_series_code || '';
        document.getElementById('timeValue').value = record.time_value || '';

        // Dates
        if (record.period_from) {
            const fromDate = new Date(record.period_from);
            document.getElementById('periodFrom').value = fromDate.toISOString().split('T')[0];
        } else {
            document.getElementById('periodFrom').value = '';
        }

        if (record.period_to) {
            const toDate = new Date(record.period_to);
            document.getElementById('periodTo').value = toDate.toISOString().split('T')[0];
        } else {
            document.getElementById('periodTo').value = '';
        }

        // Retention Periods
        document.getElementById('activeYears').value = record.active_years || '';
        document.getElementById('storageYears').value = record.storage_years || '';
        document.getElementById('totalYears').value = record.total_years || '';
        document.getElementById('volume').value = record.volume || '';

        // Handle records_medium for ENUM field
        if (record.records_medium) {
            console.log('Setting medium from:', record.records_medium);

            // Reset all checkboxes first
            document.querySelectorAll('input[name="records_medium[]"]').forEach(cb => {
                cb.checked = false;
            });

            // Split by slash to handle combined ENUM values
            const mediums = record.records_medium.split('/').map(m => m.trim());
            console.log('Split mediums:', mediums);

            // Check each individual medium
            mediums.forEach(medium => {
                const checkbox = document.querySelector(`input[name="records_medium[]"][value="${medium}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                    console.log('Checked checkbox for:', medium);
                }
            });
        } else {
            // Reset all checkboxes if no medium specified
            document.querySelectorAll('input[name="records_medium[]"]').forEach(cb => {
                cb.checked = false;
            });
        }

        // Handle radio buttons for restrictions
        if (record.restrictions) {
            const radio = document.querySelector(`input[name="restrictions"][value="${record.restrictions}"]`);
            if (radio) {
                radio.checked = true;
            } else {
                document.querySelector('input[name="restrictions"][value="Open Access"]').checked = true;
            }
        } else {
            document.querySelector('input[name="restrictions"][value="Open Access"]').checked = true;
        }

        // Other fields
        document.getElementById('location').value = record.location_of_records || '';
        document.getElementById('frequency').value = record.frequency_of_use || '';
        document.getElementById('duplication').value = record.duplication || '';
        document.getElementById('utilityValue').value = record.utility_value || '';
        document.getElementById('description').value = record.description || '';

        // Set classification
        if (record.class_id) {
            setTimeout(() => {
                if (select2Initialized) {
                    $('#classification').val(record.class_id).trigger('change');
                } else {
                    document.getElementById('classification').value = record.class_id;
                }
            }, 300);
        }
    }

    // --- View Record Modal Functions ---
    function handleRowDoubleClick(event) {
        const row = event.target.closest('tr');
        if (row && row.hasAttribute('data-record-id')) {
            const recordId = row.getAttribute('data-record-id');
            console.log('Double-clicked record ID:', recordId);
            openViewRecordModal(parseInt(recordId));
        }
    }

    function openViewRecordModal(recordId) {
        const viewRecordModal = document.getElementById('viewRecordModal');

        // Reset fields
        document.getElementById('viewRecordTitle').textContent = 'Loading...';
        document.getElementById('viewOfficeDepartment').textContent = 'Loading...';
        document.getElementById('viewRecordSeriesCode').textContent = 'Loading...';
        document.getElementById('viewClassification').textContent = 'Loading...';
        document.getElementById('viewPeriodFrom').textContent = 'Loading...';
        document.getElementById('viewPeriodTo').textContent = 'Loading...';
        document.getElementById('viewActiveYears').textContent = 'Loading...';
        document.getElementById('viewStorageYears').textContent = 'Loading...';
        document.getElementById('viewTotalYears').textContent = 'Loading...';
        document.getElementById('viewTimeValue').textContent = 'Loading...';
        document.getElementById('viewStatus').textContent = 'Loading...';
        document.getElementById('viewVolume').textContent = 'Loading...';
        document.getElementById('viewMedium').textContent = 'Loading...';
        document.getElementById('viewRestrictions').textContent = 'Loading...';
        document.getElementById('viewLocation').textContent = 'Loading...';
        document.getElementById('viewFrequency').textContent = 'Loading...';
        document.getElementById('viewDuplication').textContent = 'Loading...';
        document.getElementById('viewUtilityValue').textContent = 'Loading...';
        document.getElementById('viewDescription').textContent = 'Loading...';

        // Hide classification info
        document.getElementById('viewClassificationInfo').style.display = 'none';

        // Clear files list
        document.getElementById('viewUploadedFilesList').innerHTML = '<div class="loading-files">Loading files...</div>';
        document.getElementById('viewFileCount').textContent = '0';

        viewRecordModal.classList.add('show');
        document.body.style.overflow = 'hidden';

        // Fetch and populate data
        Promise.all([
                fetchRecordData(recordId),
                fetchRecordFiles(recordId)
            ])
            .then(([record, files]) => {
                populateViewRecordModal(record);
                displayViewRecordFiles(files);
            })
            .catch(error => {
                console.error('Error loading record for view:', error);
                showToast('Error loading record data: ' + error.message, true);

                // Show error in fields
                document.querySelectorAll('#viewRecordForm .view-field').forEach(field => {
                    field.textContent = 'Error loading data';
                });
            });
    }

    function populateViewRecordModal(record) {
        console.log('Populating view modal with record:', record);

        // Basic Information
        document.getElementById('viewRecordTitle').textContent = record.record_series_title || 'N/A';
        document.getElementById('viewOfficeDepartment').textContent = record.office_name || 'N/A';
        document.getElementById('viewRecordSeriesCode').textContent = record.record_series_code || 'N/A';
        document.getElementById('viewClassification').textContent = record.class_name || 'N/A';

        // Dates
        document.getElementById('viewPeriodFrom').textContent = record.period_from ? formatDateForDisplay(record.period_from) : 'N/A';
        document.getElementById('viewPeriodTo').textContent = record.period_to ? formatDateForDisplay(record.period_to) : 'N/A';

        // Retention Periods
        document.getElementById('viewActiveYears').textContent = record.active_years || '0';
        document.getElementById('viewStorageYears').textContent = record.storage_years || '0';
        document.getElementById('viewTotalYears').textContent = record.total_years || '0';

        // Time Value with badge
        const timeValueElement = document.getElementById('viewTimeValue');
        if (record.time_value) {
            const badgeClass = record.time_value.toLowerCase();
            timeValueElement.innerHTML = `<span class="time-value-badge ${badgeClass}">${record.time_value}</span>`;
        } else {
            timeValueElement.textContent = 'N/A';
        }

        // Status with badge
        const statusElement = document.getElementById('viewStatus');
        if (record.status) {
            const statusClass = record.status.toLowerCase();
            statusElement.innerHTML = `<span class="badge ${statusClass}">${record.status}</span>`;
        } else {
            statusElement.textContent = 'N/A';
        }

        // Record Details
        document.getElementById('viewVolume').textContent = record.volume ? `${record.volume}` : '0';

        // Medium
        if (record.records_medium) {
            const mediums = record.records_medium.split('/').map(m => m.trim());
            document.getElementById('viewMedium').textContent = mediums.join(', ');
        } else {
            document.getElementById('viewMedium').textContent = 'N/A';
        }

        document.getElementById('viewRestrictions').textContent = record.restrictions || 'N/A';
        document.getElementById('viewLocation').textContent = record.location_of_records || 'N/A';
        document.getElementById('viewFrequency').textContent = record.frequency_of_use || 'N/A';
        document.getElementById('viewDuplication').textContent = record.duplication || 'N/A';
        document.getElementById('viewUtilityValue').textContent = record.utility_value || 'N/A';
        document.getElementById('viewDescription').textContent = record.description || 'No description available.';

        // Set modal title
        viewModalTitle.textContent = `VIEW RECORD - ${record.record_series_code || record.record_series_title || 'ID: ' + record.record_id}`;

        // Try to populate classification info if available
        if (record.retention_period || record.functional_category || record.nap_authority) {
            const classificationInfo = document.getElementById('viewClassificationInfo');
            if (classificationInfo) {
                document.getElementById('viewInfoRetention').textContent = record.retention_period || 'Not specified';
                document.getElementById('viewInfoCategory').textContent = record.functional_category || 'Not specified';

                const timeValueBadge = document.getElementById('viewInfoTimeValue');
                if (record.time_value) {
                    timeValueBadge.textContent = record.time_value;
                    timeValueBadge.className = `time-value-badge ${record.time_value.toLowerCase()}`;
                } else {
                    timeValueBadge.textContent = 'Not specified';
                    timeValueBadge.className = 'time-value-badge';
                }

                document.getElementById('viewInfoAuthority').textContent = record.nap_authority || 'Not specified';
                classificationInfo.style.display = 'block';
            }
        }
    }

    function formatDateForDisplay(dateString) {
        if (!dateString) return 'N/A';
        try {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) {
                return 'Invalid Date';
            }
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

    // --- Form Validation ---
    function validateForm() {
        const recordTitle = document.getElementById('recordSeriesTitle').value.trim();
        const officeId = document.getElementById('officeDepartment').value;
        const recordSeries = document.getElementById('recordSeriesCode').value.trim();
        const classId = document.getElementById('classification').value;
        const startDate = document.getElementById('periodFrom').value;
        const activeYears = parseInt(document.getElementById('activeYears').value) || 0;
        const storageYears = parseInt(document.getElementById('storageYears').value) || 0;
        const timeValue = document.getElementById('timeValue').value;

        let isValid = true;
        let errorMessage = '';

        if (!recordTitle) {
            errorMessage = 'Please enter a record title';
            isValid = false;
        } else if (!officeId) {
            errorMessage = 'Please select an office/department';
            isValid = false;
        } else if (!recordSeries) {
            errorMessage = 'Please enter a record series code';
            isValid = false;
        } else if (!classId) {
            errorMessage = 'Please select a classification';
            isValid = false;
        } else if (!startDate) {
            errorMessage = 'Please select a start date';
            isValid = false;
        } else if (timeValue !== 'Permanent') {
            if (activeYears === 0 && storageYears === 0) {
                errorMessage = 'Retention period is required. Please enter either Active or Storage retention years';
                isValid = false;
            }
        }

        if (!isValid) {
            showToast(errorMessage, true);
            if (!recordTitle) document.getElementById('recordSeriesTitle').focus();
            else if (!officeId) document.getElementById('officeDepartment').focus();
            else if (!recordSeries) document.getElementById('recordSeriesCode').focus();
            else if (!classId) document.getElementById('classification').focus();
            else if (!startDate) document.getElementById('periodFrom').focus();
            else if (activeYears === 0 && storageYears === 0) document.getElementById('storageYears').focus();
        }

        return isValid;
    }

    // --- Form Submission Handler ---
    recordForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        if (!validateForm()) {
            return;
        }

        // Process medium checkboxes
        const mediumCheckboxes = document.querySelectorAll('input[name="records_medium[]"]:checked');
        const mediumValues = Array.from(mediumCheckboxes).map(cb => cb.value);

        const formData = new FormData(this);
        const isEdit = document.getElementById('recordId').value !== '';

        // FIX: Remove any existing records_medium data
        formData.delete('records_medium');
        formData.delete('records_medium[]');

        // Add each checkbox value separately
        mediumValues.forEach(value => {
            formData.append('records_medium[]', value);
        });

        // Add files to FormData
        selectedFiles.forEach((file, index) => {
            formData.append('attachments[]', file);
        });

        // Add removed files
        document.querySelectorAll('input[name="removed_files[]"]').forEach(input => {
            formData.append('removed_files[]', input.value);
        });

        // Get file tags from inputs
        const fileTagInputs = document.querySelectorAll('.file-tag-input');
        fileTagInputs.forEach((input, index) => {
            if (input.value.trim()) {
                formData.append(`file_tags[]`, input.value.trim());
            }
        });

        if (!formData.has('status')) {
            formData.append('status', 'Active');
        }

        // Add action parameter
        formData.append('action', isEdit ? 'edit' : 'add');

        // Show loading state
        saveRecordBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (isEdit ? 'UPDATING...' : 'SAVING...');
        saveRecordBtn.disabled = true;

        try {
            // Use AJAX to submit the form data
            const response = await fetch(this.action, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            console.log('Server response:', result);

            if (result.success) {
                showToast(result.message + (result.files_uploaded > 0 ? ` (${result.files_uploaded} file(s) uploaded)` : ''));

                // Reset form and close modal after a delay
                setTimeout(() => {
                    hideRecordFormModal();
                    clearForm();

                    // Reload the page to show updated records
                    location.reload();
                }, 1500);
            } else {
                throw new Error(result.message || 'Failed to save record');
            }
        } catch (error) {
            console.error('Form submission error:', error);
            showToast('Error: ' + error.message, true);
        } finally {
            saveRecordBtn.innerHTML = isEdit ? 'UPDATE RECORD' : 'SAVE RECORD';
            saveRecordBtn.disabled = false;
        }
    });

    // --- Filter Functions ---
    function applyFilters() {
        const searchTerm = document.getElementById('searchInput').value.trim().toLowerCase();
        const departmentSelect = document.getElementById('departmentSelect');
        const department = departmentSelect.value;
        const classification = document.getElementById('classificationSelect').value;
        const status = document.getElementById('statusSelect').value;
        const timeValue = document.getElementById('timeValueSelect').value;

        const rows = document.querySelectorAll('#recordsTableBody tr');
        let visibleCount = 0;

        rows.forEach(row => {
            // Skip the "no records" row if it exists
            if (row.querySelector('td[colspan="8"]')) {
                row.style.display = 'none';
                return;
            }

            let showRow = true;

            // 1. Search filter
            if (searchTerm && showRow) {
                const recordTitle = row.cells[1].textContent.toLowerCase();
                const recordCode = row.cells[0].textContent.toLowerCase();
                const officeName = row.cells[2].textContent.toLowerCase();

                if (!recordTitle.includes(searchTerm) &&
                    !recordCode.includes(searchTerm) &&
                    !officeName.includes(searchTerm)) {
                    showRow = false;
                }
            }

            // 2. Department filter
            if (department && showRow) {
                const rowOfficeId = row.getAttribute('data-office-name');
                const selectedOption = departmentSelect.options[departmentSelect.selectedIndex];
                const selectedOfficeName = selectedOption.textContent.trim();

                if (rowOfficeId !== selectedOfficeName) {
                    showRow = false;
                }
            }

            // 3. Classification filter
            if (classification && showRow) {
                const rowClassId = row.getAttribute('data-class-id');
                if (classification !== rowClassId) {
                    showRow = false;
                }
            }

            // 4. Status filter
            if (status && showRow) {
                const statusCell = row.cells[6];
                const statusBadge = statusCell.querySelector('.badge');

                if (statusBadge) {
                    const rowStatus = statusBadge.textContent.trim().toLowerCase();
                    if (rowStatus !== status.toLowerCase()) {
                        showRow = false;
                    }
                } else {
                    const cellText = statusCell.textContent.trim().toLowerCase();
                    if (cellText !== status.toLowerCase()) {
                        showRow = false;
                    }
                }
            }

            // 5. Time Value filter
            if (timeValue && showRow) {
                const timeValueCell = row.cells[5];
                const timeValueBadge = timeValueCell.querySelector('.time-value-badge');

                if (timeValueBadge) {
                    const rowTimeValue = timeValueBadge.textContent.trim();
                    if (rowTimeValue !== timeValue) {
                        showRow = false;
                    }
                } else {
                    const cellText = timeValueCell.textContent.trim();
                    if (cellText !== timeValue) {
                        showRow = false;
                    }
                }
            }

            // Apply the final display state
            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleCount++;
        });

        // Update filter button appearance
        const filterButton = document.querySelector('.filter-button');
        const hasActiveFilters = searchTerm || department || classification || status || timeValue;

        if (hasActiveFilters) {
            filterButton.classList.add('active');
            filterButton.innerHTML = `Filter <i class='bx bx-filter'></i> (${visibleCount})`;
        } else {
            filterButton.classList.remove('active');
            filterButton.innerHTML = `Filter <i class='bx bx-filter'></i>`;
        }

        // Handle no results
        const tbody = document.getElementById('recordsTableBody');
        let noRecordsRow = tbody.querySelector('tr td[colspan="8"]');

        if (visibleCount === 0) {
            if (!noRecordsRow || !noRecordsRow.textContent.includes('match your filters')) {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 2rem; color: #78909c;">No records match your filters.</td></tr>`;
            }
        } else {
            if (noRecordsRow && noRecordsRow.textContent.includes('match your filters')) {
                noRecordsRow.closest('tr').remove();
            }
        }

        hideFilterModal();
        showToast(`Filters applied. Showing ${visibleCount} record(s).`);
    }

    function clearFilters() {
        // Clear all filter inputs
        document.getElementById('searchInput').value = '';
        document.getElementById('departmentSelect').value = '';
        document.getElementById('classificationSelect').value = '';
        document.getElementById('statusSelect').value = '';
        document.getElementById('timeValueSelect').value = '';
        document.getElementById('dateFromInput').value = '';
        document.getElementById('dateToInput').value = '';

        // Show all rows
        const rows = document.querySelectorAll('#recordsTableBody tr');
        rows.forEach(row => {
            row.style.display = '';
        });

        // Reset filter button
        const filterButton = document.querySelector('.filter-button');
        filterButton.classList.remove('active');
        filterButton.innerHTML = `Filter <i class='bx bx-filter'></i>`;

        // Reload table to get original data
        location.reload();

        hideFilterModal();
        showToast('Filters cleared');
    }

    function parseDateString(dateStr) {
        const parts = dateStr.split('/');
        if (parts.length === 3) {
            return new Date(parseInt(parts[2]), parseInt(parts[0]) - 1, parseInt(parts[1]));
        }
        return null;
    }

    // --- Global Event Listeners ---
    recordFormModal.addEventListener('click', (event) => {
        const isSelect2Click = event.target.closest('.select2-container') !== null;
        if (event.target === recordFormModal && !isSelect2Click) {
            hideRecordFormModal();
        }
    });

    viewRecordModal.addEventListener('click', (event) => {
        if (event.target === viewRecordModal) {
            hideViewRecordModal();
        }
    });

    filterModal.addEventListener('click', (event) => {
        if (event.target === filterModal) {
            hideFilterModal();
        }
    });

    tableBody.addEventListener('dblclick', handleRowDoubleClick);

    tableBody.addEventListener('dblclick', (event) => {
        if (event.target.closest('.edit-action-btn')) {
            event.stopPropagation();
        }
    });

    // --- Setup Add Record Button ---
    function setupAddRecordButton() {
        const addRecordBtn = document.getElementById('addRecordBtn');
        if (addRecordBtn) {
            addRecordBtn.removeAttribute('onclick');
            addRecordBtn.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('Add New Record button clicked');
                openRecordModal('add');
            });
        }
    }

    // --- Initialize Application ---
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Record Management System Loaded');
        initializeFileUpload();
        setupRetentionCalculation();
        setupAddRecordButton();

        // Test if edit button click works
        console.log('Testing edit buttons...');
        const editButtons = document.querySelectorAll('.edit-action-btn');
        console.log('Found', editButtons.length, 'edit buttons');
        
        // Add click listeners to edit buttons
        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                const recordId = this.closest('tr').getAttribute('data-record-id');
                handleEditClick(recordId);
            });
        });
    });
</script>
</body>

</html>