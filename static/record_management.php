<?php
require_once '../session.php';
require_once '../db_connect.php';


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
    'disposition_action',
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
            r.disposition_type,
            r.status,
            r.time_value,
            rc.class_id,
            rc.class_name,
            rc.functional_category,
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
    }

    table {
      width: 100%;
      table-layout: auto !important;
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
    }

    .file-info {
      flex: 1;
    }

    .file-name {
      font-weight: 500;
      color: #37474f;
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
    }

    .medium-option input[type="checkbox"] {
      width: 18px;
      height: 18px;
    }

    .radio-group {
      display: flex;
      gap: 20px;
      margin-top: 10px;
    }

    .radio-option {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .radio-option input[type="radio"] {
      width: 18px;
      height: 18px;
    }

    .volume-input {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .volume-input input {
      flex: 1;
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
    /* Add to your existing CSS */
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
              <th>DISPOSITION</th>
              <th>STATUS</th>
              <th>ACTION</th>
            </tr>
          </thead>
          <tbody id="recordsTableBody">
            <?php if (empty($records)): ?>
              <tr>
                <td colspan="9" style="text-align: center; padding: 2rem; color: #78909c;">
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
                  <td><?= htmlspecialchars($record['disposition_type']) ?></td>
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
            <select id="dispositionSelect">
              <option value="">Disposition</option>
              <option value="Archive">Archive</option>
              <option value="Dispose">Dispose</option>
              <option value="Active">Active</option>
              <option value="Permanent">Permanent</option>
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
          <div class="filter-select-group">
            <input type="date" id="dateFromInput" placeholder="Period From">
          </div>
          <div class="filter-select-group">
            <input type="date" id="dateToInput" placeholder="Period To">
          </div>
        </div>

        <a href="#" class="clear-filter-link" onclick="clearFilters()">Clear Filter</a>
      </div>
    </div>

    <!-- RECORD FORM MODAL -->
    <div id="recordModal" class="modal-backdrop">
      <div class="modal-card record-form-card">
        <button class="modal-close-btn" onclick="hideRecordFormModal()">&times;</button>
        <h1 class="form-title" id="recordModalTitle">RECORD FORM</h1>

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
                      data-disposition="<?= htmlspecialchars($class['disposition_action']) ?>"
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
                <div><strong>Disposition Action:</strong> <span id="infoDisposition"></span></div>
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

            <div class="form-group">
              <label for="disposition">Disposition Type:</label>
              <select id="disposition" name="disposition_type" class="view-field" style="background-color: #f8f9fa;" readonly>
                <option value="">Select Disposition Type...</option>
                <option value="Archive">Archive</option>
                <option value="Dispose">Dispose</option>
                <option value="Active">Active</option>
                <option value="Permanent">Permanent</option>
                <option value="Review">Review</option>
                <option value="Transfer">Transfer</option>
              </select>
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

          <!-- Disposition Provision -->
          <div class="form-group full-width" style="margin-top: 20px;">
            <label for="dispositionProvision">Disposition Instructions:</label>
            <textarea id="dispositionProvision" name="disposition_provision" class="description-textarea" rows="3"
              placeholder="Specific disposition instructions..."></textarea>
          </div>

          <!-- Bottom Action Buttons -->
          <div class="action-buttons">
            <button type="button" class="button-action cancel" onclick="hideRecordFormModal()">CANCEL</button>
            <button type="button" class="button-action save-draft" id="saveDraftBtn" onclick="saveAsDraft()">SAVE AS
              DRAFT</button>
            <button type="submit" class="button-action save" id="saveRecordBtn">SAVE</button>
          </div>
        </form>
      </div>
    </div>

    <!-- VIEW RECORD MODAL -->
    <div id="viewRecordModal" class="modal-backdrop">
      <div class="modal-card record-form-card">
        <button class="modal-close-btn" onclick="hideViewRecordModal()">&times;</button>
        <h1 class="form-title" id="viewRecordModalTitle">VIEW RECORD</h1>

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

          <div class="form-group">
            <label>Disposition:</label>
            <div class="view-field" id="viewDisposition"></div>
          </div>

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
        <div class="form-group full-width" style="margin-top: 20px;">
          <label>Disposition Instructions:</label>
          <div class="view-field view-description" id="viewDispositionProvision"></div>
        </div>

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
    const saveDraftBtn = document.getElementById('saveDraftBtn');
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

      console.log("Parsing retention period:", retentionPeriod);

      // Try to match "X years active + Y years storage" pattern
      const fullPattern = /(\d+)\s*years?\s*active\s*\+\s*(\d+)\s*years?\s*storage/i;
      const fullMatch = text.match(fullPattern);

      if (fullMatch) {
        active = parseInt(fullMatch[1]);
        storage = parseInt(fullMatch[2]);
        console.log("Matched full pattern - Active:", active, "Storage:", storage);
      } else {
        // Try to match "X years active" (active only)
        const activePattern = /(\d+)\s*years?\s*active/i;
        const activeMatch = text.match(activePattern);

        if (activeMatch) {
          active = parseInt(activeMatch[1]);
          console.log("Matched active only pattern - Active:", active);
        }

        // Try to match "X years storage" (storage only)
        const storagePattern = /(\d+)\s*years?\s*storage/i;
        const storageMatch = text.match(storagePattern);

        if (storageMatch) {
          storage = parseInt(storageMatch[1]);
          console.log("Matched storage only pattern - Storage:", storage);
        }

        // If no "active" or "storage" keyword found, but contains "years"
        if (active === 0 && storage === 0) {
          const yearsPattern = /(\d+)\s*years?/i;
          const yearsMatch = text.match(yearsPattern);

          if (yearsMatch) {
            // Assume it's all active if not specified
            active = parseInt(yearsMatch[1]);
            console.log("Matched years only pattern - Active:", active);
          }
        }
      }

      const total = active + storage;

      console.log("Final result - Active:", active, "Storage:", storage, "Total:", total);

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

      // Only add Active Retention to Period To (not Total Retention)
      if (!startDate || activeYears === 0) {
        document.getElementById('periodTo').value = '';
        return;
      }

      const start = new Date(startDate);
      const endDate = new Date(start);
      endDate.setFullYear(start.getFullYear() + activeYears); // Only add active years

      document.getElementById('periodTo').value = endDate.toISOString().split('T')[0];
    }

    // --- Calculate Total Retention ---
    function calculateTotalRetention() {
      const activeYears = parseInt(document.getElementById('activeYears').value) || 0;
      const storageYears = parseInt(document.getElementById('storageYears').value) || 0;
      const totalYears = activeYears + storageYears;

      document.getElementById('totalYears').value = totalYears;
      
      // Also recalculate end date when storage changes
      calculateEndDate();
    }

    // --- Initialize Select2 with enhanced functionality ---
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
            const disposition = selectedOption.data('disposition');
            const category = selectedOption.data('category');
            const authority = selectedOption.data('authority');
            const isPermanent = selectedOption.data('permanent') === 'yes';

            if (retentionText) {
              console.log("Selected retention text:", retentionText);

              // Parse retention period
              const retention = parseRetentionPeriod(retentionText);

              // Update active retention (readonly, based on classification)
              document.getElementById('activeYears').value = retention.active;

              // Clear storage years for user input
              document.getElementById('storageYears').value = '';
              document.getElementById('storageYears').readOnly = false;
              document.getElementById('storageYears').classList.remove('view-field');
              document.getElementById('storageYears').style.backgroundColor = 'white';
              document.getElementById('storageYears').placeholder = 'Enter storage years';

              // Calculate total retention (will be 0 since storage is empty)
              calculateTotalRetention();

              // Recalculate end date based on active years
              calculateEndDate();

              // Update disposition
              if (disposition) {
                document.getElementById('disposition').value = disposition;
              }

              // Set Time Value based on permanent indicator
              const timeValue = retention.permanent || isPermanent ? 'Permanent' : 'Temporary';
              document.getElementById('timeValue').value = timeValue;

              // Show classification info
              $('#infoRetention').text(retentionText);
              $('#infoDisposition').text(disposition || 'Not specified');
              $('#infoCategory').text(category || 'Not specified');
              $('#infoTimeValue').text(timeValue).removeClass('permanent temporary').addClass(timeValue.toLowerCase());
              $('#infoAuthority').text(authority || 'Not specified');
              $('#classificationInfo').addClass('show');

              console.log("Set active years to:", retention.active);
            } else {
              document.getElementById('activeYears').value = '';
              document.getElementById('storageYears').value = '';
              document.getElementById('storageYears').readOnly = false;
              document.getElementById('totalYears').value = '';
              document.getElementById('disposition').value = '';
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

    // --- Update the storage years field to recalculate when changed ---
    function setupRetentionCalculation() {
      const storageYearsInput = document.getElementById('storageYears');
      if (storageYearsInput) {
        storageYearsInput.addEventListener('input', calculateTotalRetention);
        storageYearsInput.addEventListener('change', calculateTotalRetention);
      }

      const activeYearsInput = document.getElementById('activeYears');
      if (activeYearsInput) {
        activeYearsInput.addEventListener('input', calculateEndDate);
        activeYearsInput.addEventListener('change', calculateEndDate);
      }

      const periodFromInput = document.getElementById('periodFrom');
      if (periodFromInput) {
        periodFromInput.addEventListener('change', calculateEndDate);
      }
    }

    // --- File Management Functions ---
    function displayExistingFiles(files) {
      console.log('Displaying existing files:', files);
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
                <input type="text" class="file-tag-input" placeholder="Add tag (optional)" 
                       name="existing_file_tags[${file.file_id}]" value="${file.file_tag || ''}">
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

      console.log('Removing existing file ID:', fileId);

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
      console.log('Displaying files in view modal:', files);

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

    // Store the original event handler if it exists
    const originalOnChange = fileInput.onchange;

    // Clear all existing event listeners by replacing the element
    const newFileInput = fileInput.cloneNode(true);
    newFileInput.value = ''; // Clear the value
    fileInput.parentNode.replaceChild(newFileInput, fileInput);

    const currentFileInput = document.getElementById('fileUpload');

    // Add single event listener for file selection
    currentFileInput.addEventListener('change', function(e) {
        console.log('File input changed, files selected:', e.target.files.length);
        if (e.target.files.length > 0) {
            handleFileSelect(e.target.files);
            // Reset the input value to allow selecting the same file again
            e.target.value = '';
        }
    }, { once: false }); // Make sure it's not a one-time listener

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
        const files = e.dataTransfer.files;
        console.log('Files dropped:', files.length);
        handleFileSelect(files);
    });

    // Click handler for the upload area
    uploadArea.addEventListener('click', (e) => {
        // Prevent multiple clicks from bubbling
        e.stopPropagation();
        currentFileInput.click();
    });

    // Also fix the "Browse Files" button
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
        // Check for duplicates
        const isDuplicate = selectedFiles.some(existingFile =>
            existingFile.name === newFile.name &&
            existingFile.size === newFile.size &&
            existingFile.lastModified === newFile.lastModified
        );

        if (!isDuplicate) {
            selectedFiles.push(newFile);
            addedCount++;
            console.log('Added file:', newFile.name);
        } else {
            console.log('Skipping duplicate file:', newFile.name);
        }
    });

    if (addedCount > 0) {
        console.log('Added', addedCount, 'new files. Total files:', selectedFiles.length);
        updateFileList();
        showToast(`Added ${addedCount} file(s)`);
    } else {
        console.log('No new files to add - all were duplicates');
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
        console.log('Removed file at index', index, 'Remaining files:', selectedFiles.length);
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

      updateFileList();

      // Reset calculated fields
      document.getElementById('timeValue').value = '';
      document.getElementById('periodTo').value = '';
      document.getElementById('activeYears').value = '';
      document.getElementById('storageYears').value = '';
      document.getElementById('storageYears').readOnly = false;
      document.getElementById('storageYears').classList.remove('view-field');
      document.getElementById('storageYears').style.backgroundColor = 'white';
      document.getElementById('totalYears').value = '';
      document.getElementById('disposition').value = '';
      $('#classificationInfo').removeClass('show');

      // Reset checkboxes and radio buttons
      document.querySelectorAll('input[name="records_medium[]"]').forEach(cb => cb.checked = false);
      document.querySelector('input[name="restrictions"][value="Open Access"]').checked = true;

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
            console.log(`Successfully loaded ${data.files ? data.files.length : 0} files`);
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
          console.log('Response status:', response.status);

          if (response.status === 500) {
            return response.text().then(errorText => {
              console.error('PHP 500 Error Detected');
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
            console.log('Record data loaded successfully');
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

    // --- Record Modal Functions ---
    function openRecordModal(mode, recordId = null) {
      clearForm();

      setTimeout(() => {
        initializeSelect2();
      }, 100);

      if (mode === 'add') {
        modalTitle.textContent = 'ADD NEW RECORD';
        saveRecordBtn.textContent = 'SAVE RECORD';
        saveDraftBtn.style.display = 'block';
        recordForm.action = '../process_record.php?action=add';

        recordFormModal.classList.add('show');
        document.body.style.overflow = 'hidden';
      } else if (mode === 'edit' && recordId !== null) {
        modalTitle.textContent = 'EDIT RECORD';
        saveRecordBtn.textContent = 'UPDATE RECORD';
        saveDraftBtn.style.display = 'none';
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

    // --- EDIT FUNCTION ---
    function handleEditClick(id) {
      console.log('Edit clicked for record ID:', id);
      openRecordModal('edit', id);
    }

    function populateFormWithRecordData(record) {
      console.log('Populating form with record data:', record);

      document.getElementById('recordSeriesTitle').value = record.record_series_title || '';
      document.getElementById('officeDepartment').value = record.office_id || '';
      document.getElementById('recordSeriesCode').value = record.record_series_code || '';
      document.getElementById('disposition').value = record.disposition_type || '';
      document.getElementById('timeValue').value = record.time_value || '';

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

      document.getElementById('activeYears').value = record.active_years || '';
      document.getElementById('storageYears').value = record.storage_years || '';
      document.getElementById('totalYears').value = record.total_years || '';
      document.getElementById('volume').value = record.volume || '';

      // Handle records_medium for ENUM field - split combined values
      if (record.records_medium) {
        console.log('Setting medium from:', record.records_medium);
        
        // Reset all checkboxes first
        document.querySelectorAll('input[name="records_medium[]"]').forEach(cb => {
          cb.checked = false;
        });
        
        // Split by slash to handle combined ENUM values like "Paper/Electronic Files"
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

      if (record.restrictions) {
        const radio = document.querySelector(`input[name="restrictions"][value="${record.restrictions}"]`);
        if (radio) {
          radio.checked = true;
        } else {
          // Default to Open Access if value not found
          document.querySelector('input[name="restrictions"][value="Open Access"]').checked = true;
        }
      } else {
        document.querySelector('input[name="restrictions"][value="Open Access"]').checked = true;
      }

      document.getElementById('location').value = record.location_of_records || '';
      document.getElementById('frequency').value = record.frequency_of_use || '';
      document.getElementById('duplication').value = record.duplication || '';
      document.getElementById('utilityValue').value = record.utility_value || '';
      document.getElementById('description').value = record.description || '';
      document.getElementById('dispositionProvision').value = record.disposition_provision || '';

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

    function saveAsDraft() {
      console.log('Saving as draft...');

      let statusInput = document.querySelector('input[name="status"]');
      if (!statusInput) {
        statusInput = document.createElement('input');
        statusInput.type = 'hidden';
        statusInput.name = 'status';
        recordForm.appendChild(statusInput);
      }
      statusInput.value = 'Inactive';

      const recordTitle = document.getElementById('recordSeriesTitle').value.trim();
      if (!recordTitle) {
        showToast('Record title is required even for drafts', true);
        document.getElementById('recordSeriesTitle').focus();
        return;
      }

      recordForm.dispatchEvent(new Event('submit'));
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

      viewRecordModal.classList.add('show');
      document.body.style.overflow = 'hidden';

      document.getElementById('viewRecordTitle').textContent = 'Loading...';
      document.getElementById('viewOfficeDepartment').textContent = 'Loading...';
      document.getElementById('viewRecordSeries').textContent = 'Loading...';
      document.getElementById('viewDisposition').textContent = 'Loading...';
      document.getElementById('viewPeriodCovered').textContent = 'Loading...';
      document.getElementById('viewRetentionPeriod').textContent = 'Loading...';
      document.getElementById('viewTimeValue').textContent = 'Loading...';
      document.getElementById('viewStatus').textContent = 'Loading...';
      document.getElementById('viewClassification').textContent = 'Loading...';
      document.getElementById('viewDescription').textContent = 'Loading...';

      document.getElementById('viewUploadedFilesList').innerHTML = '<div class="loading-files">Loading files...</div>';

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
          document.getElementById('viewRecordTitle').textContent = 'Error Loading Record';
          document.getElementById('viewOfficeDepartment').textContent = 'N/A';
          document.getElementById('viewRecordSeries').textContent = 'N/A';
          document.getElementById('viewDisposition').textContent = 'N/A';
          document.getElementById('viewPeriodCovered').textContent = 'N/A';
          document.getElementById('viewRetentionPeriod').textContent = 'N/A';
          document.getElementById('viewTimeValue').textContent = 'N/A';
          document.getElementById('viewStatus').textContent = 'N/A';
          document.getElementById('viewClassification').textContent = 'N/A';
          document.getElementById('viewDescription').textContent = 'Error: ' + error.message;
          document.getElementById('viewUploadedFilesList').innerHTML = '<div style="padding: 10px 15px; color: #78909c; font-style: italic; font-size: 14px;">Error loading files.</div>';

          showToast('Error loading record data: ' + error.message, true);
        });
    }

    function populateViewRecordModal(record) {
      console.log('Populating view modal with record:', record);

      document.getElementById('viewRecordTitle').textContent = record.record_series_title || 'N/A';
      document.getElementById('viewOfficeDepartment').textContent = record.office_name || 'N/A';
      document.getElementById('viewRecordSeries').textContent = record.record_series_code || 'N/A';
      document.getElementById('viewDisposition').textContent = record.disposition_type || 'N/A';

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
      document.getElementById('viewClassification').textContent = record.class_name || 'N/A';

      document.getElementById('viewVolume').textContent = record.volume ? `${record.volume} ${record.volume_unit || ''}` : 'N/A';
      document.getElementById('viewMedium').textContent = record.records_medium || 'N/A';
      document.getElementById('viewRestrictions').textContent = record.restrictions || 'N/A';
      document.getElementById('viewLocation').textContent = record.location_of_records || 'N/A';
      document.getElementById('viewFrequency').textContent = record.frequency_of_use || 'N/A';
      document.getElementById('viewUtilityValue').textContent = record.utility_value || 'N/A';
      document.getElementById('viewDescription').textContent = record.description || 'No description available.';
      document.getElementById('viewDispositionProvision').textContent = record.disposition_provision || 'No disposition instructions.';

      viewModalTitle.textContent = `VIEW RECORD - ${record.record_series_code || 'ID: ' + record.record_id}`;
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
        // For non-permanent records, we need at least active OR storage years
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
      
      // Add each checkbox value separately - using the array format
      mediumValues.forEach(value => {
        formData.append('records_medium[]', value);
      });

      // Add files to FormData
      selectedFiles.forEach((file, index) => {
        formData.append('attachments[]', file);
      });

      // Get file tags from inputs (both new and existing)
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
        // Log form data for debugging
        console.log('Form data being sent:');
        for (let pair of formData.entries()) {
          if (pair[0] === 'attachments[]') {
            console.log(pair[0] + ': [File] ' + pair[1].name);
          } else {
            console.log(pair[0] + ': ' + pair[1]);
          }
        }

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
      const searchTerm = document.getElementById('searchInput').value.toLowerCase();
      const department = document.getElementById('departmentSelect').value;
      const classification = document.getElementById('classificationSelect').value;
      const disposition = document.getElementById('dispositionSelect').value;
      const status = document.getElementById('statusSelect').value;
      const timeValue = document.getElementById('timeValueSelect').value;
      const dateFrom = document.getElementById('dateFromInput').value;
      const dateTo = document.getElementById('dateToInput').value;

      const rows = document.querySelectorAll('#recordsTableBody tr');
      let visibleCount = 0;

      rows.forEach(row => {
        // Skip the "no records" row if it exists
        if (row.querySelector('td[colspan="9"]')) {
          return;
        }

        let showRow = true;

        // Search filter (record title, series code, and office name)
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

        // Department filter
        if (department && showRow) {
          const departmentOption = document.getElementById('departmentSelect').options[document.getElementById('departmentSelect').selectedIndex];
          const officeCellText = row.cells[2].textContent.trim();
          if (departmentOption.text !== officeCellText) {
            showRow = false;
          }
        }

        // Status filter
        if (status && showRow) {
          const statusBadge = row.cells[7].querySelector('.badge');
          if (statusBadge) {
            const rowStatus = statusBadge.textContent.toLowerCase();
            if (rowStatus !== status.toLowerCase()) {
              showRow = false;
            }
          } else {
            showRow = false;
          }
        }

        // Time Value filter
        if (timeValue && showRow) {
          const timeValueBadge = row.cells[5].querySelector('.time-value-badge');
          if (timeValueBadge) {
            if (timeValueBadge.textContent !== timeValue) {
              showRow = false;
            }
          } else if (timeValue !== '') {
            // If timeValue filter is set but cell has "N/A"
            showRow = false;
          }
        }

        // Disposition filter
        if (disposition && showRow) {
          const dispositionCell = row.cells[6].textContent.trim();
          if (dispositionCell !== disposition) {
            showRow = false;
          }
        }

        // Classification filter (using data attribute on rows)
        if (classification && showRow) {
          const classId = row.getAttribute('data-class-id');
          if (classId !== classification) {
            showRow = false;
          }
        }

        // Date range filters
        if ((dateFrom || dateTo) && showRow) {
          const dateRangeText = row.cells[3].textContent.trim();
          const dates = dateRangeText.split(' - ');
          
          if (dates.length === 2) {
            try {
              // Parse dates from display format (MM/DD/YYYY)
              const fromDate = dates[0] !== 'N/A' ? parseDateString(dates[0]) : null;
              const toDate = dates[1] !== 'N/A' ? parseDateString(dates[1]) : null;
              
              if (dateFrom) {
                const filterFromDate = new Date(dateFrom);
                if (toDate && toDate < filterFromDate) {
                  showRow = false;
                }
              }
              
              if (dateTo && showRow) {
                const filterToDate = new Date(dateTo);
                if (fromDate && fromDate > filterToDate) {
                  showRow = false;
                }
              }
            } catch (e) {
              console.error('Error parsing dates:', e);
            }
          }
        }

        row.style.display = showRow ? '' : 'none';
        if (showRow) visibleCount++;
      });

      // Update filter button appearance
      const filterButton = document.querySelector('.filter-button');
      const hasActiveFilters = searchTerm || department || classification || disposition || status || timeValue || dateFrom || dateTo;

      if (hasActiveFilters) {
        filterButton.classList.add('active');
        filterButton.innerHTML = `Filter <i class='bx bx-filter'></i> (${visibleCount})`;
      } else {
        filterButton.classList.remove('active');
        filterButton.innerHTML = `Filter <i class='bx bx-filter'></i>`;
      }

      // Handle no results
      const tbody = document.getElementById('recordsTableBody');
      const noRecordsRow = tbody.querySelector('tr td[colspan="9"]');
      
      if (visibleCount === 0) {
        if (!noRecordsRow || !noRecordsRow.textContent.includes('match your filters')) {
          tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; padding: 2rem; color: #78909c;">No records match your filters.</td></tr>`;
        }
      } else if (noRecordsRow && noRecordsRow.textContent.includes('match your filters')) {
        // Remove the no records message if there are results
        location.reload();
      }

      hideFilterModal();
      showToast(`Filters applied. Showing ${visibleCount} record(s).`);
    }

    // Helper function to parse date strings
    function parseDateString(dateStr) {
      // Handle MM/DD/YYYY format
      const parts = dateStr.split('/');
      if (parts.length === 3) {
        return new Date(parts[2], parts[0] - 1, parts[1]);
      }
      return null;
    }

    function clearFilters() {
      // Clear all filter inputs
      document.getElementById('searchInput').value = '';
      document.getElementById('departmentSelect').value = '';
      document.getElementById('classificationSelect').value = '';
      document.getElementById('dispositionSelect').value = '';
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
        // Remove any existing inline onclick
        addRecordBtn.removeAttribute('onclick');

        // Add event listener
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

      // Also set up event listeners for file input
      // document.getElementById('fileUpload').addEventListener('change', function(e) {
      //   handleFileSelect(e.target.files);
      // });
    });
  </script>
</body>

</html>