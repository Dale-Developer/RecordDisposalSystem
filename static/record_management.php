<?php
require_once '../session.php';
require_once '../db_connect.php';

// Initialize variables
$records = [];
$classifications = [];
$offices = [];

// Check if database connection is established
if (!isset($pdo)) {
  die("Database connection failed. Please check your database configuration.");
}

try {
  // Fetch records from database
  $sql = "SELECT 
                r.record_id,
                r.record_series_code,
                r.record_title,
                o.office_name,
                r.inclusive_date_from,
                r.inclusive_date_to,
                r.retention_period,
                r.disposition_type,
                r.status,
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

  // Fetch NAP classifications with retention periods
  $classifications = $pdo->query("
        SELECT 
            class_id,
            class_name,
            description,
            functional_category,
            retention_period,
            disposition_action,
            nap_authority
        FROM record_classification 
        ORDER BY functional_category, class_name
    ")->fetchAll(PDO::FETCH_ASSOC);

  // Fetch offices
  $offices = $pdo->query("SELECT * FROM offices ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
  error_log("Database error: " . $e->getMessage());
  die("Database error occurred. Please check the error logs.");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/x-icon" href="../imgs/fav.png">
  <link rel="stylesheet" href="../styles/record_management.css">
  <!-- Add Select2 CSS for searchable dropdown -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <title>Record Management</title>
  <style>
    /* Additional styles for Select2 */
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

    /* FIX FOR TABLE TEXT TRUNCATION - ADD THIS */
    .table-wrapper {
      overflow-x: auto;
    }
    
    table {
      width: 100%;
      table-layout: auto !important; /* Allow columns to expand based on content */
    }
    
    td {
      white-space: normal !important;
      overflow: visible !important;
      text-overflow: unset !important;
      max-width: none !important;
      word-wrap: break-word !important;
      word-break: break-word !important;
    }
    
    /* Remove all truncation from specific columns */
    td:nth-child(1), /* RECORD SERIES CODE */
    td:nth-child(2), /* RECORD TITLE */
    td:nth-child(3), /* DEPARTMENT */
    td:nth-child(4), /* INCLUSIVE DATE */
    td:nth-child(5), /* RETENTION */
    td:nth-child(6), /* DISPOSITION */
    td:nth-child(7), /* STATUS */
    td:nth-child(8)  /* ACTION */ {
      white-space: normal !important;
      overflow: visible !important;
      text-overflow: unset !important;
      max-width: none !important;
      word-wrap: break-word !important;
      word-break: break-word !important;
    }

    /* CENTER THE RECORD TITLE COLUMN */
    th:nth-child(2), td:nth-child(2) { /* RECORD TITLE */
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

    /* Enhanced file upload styles */
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
      color: #4caf50;
      font-weight: 500;
    }

    /* Loading spinner */
    .button-action:disabled {
      opacity: 0.7;
      cursor: not-allowed;
    }

    /* Filter button active state */
    .filter-button.active {
      background: #1f366c !important;
      color: white !important;
    }

    /* Ensure table rows can be hidden */
    #recordsTableBody tr {
      transition: all 0.3s ease;
    }
  </style>
</head>

<body>
  <nav class="sidebar">
    <?php include 'sidebar.php'; ?>
  </nav>
  <main>
    <!-- Main Content Container (Dashboard Card) -->
    <div class="dashboard-card">

      <!-- Header and Action Bar -->
      <header class="header">
        <div class="header-title-block">
          <h1>RECORDS MANAGEMENT</h1>
        </div>

        <div class="actions">

          <!-- Filter Button -->
          <button class="button filter-button" onclick="showFilterModal()">
            Filter
            <i class='bx bx-edit'></i>
          </button>

          <!-- Primary Button (Add New Record) -->
          <button class="button primary-action-btn" onclick="openRecordModal('add')">
            <i class='bx bx-edit'></i>
            Add New Record
          </button>

          <!-- Edit/Pencil Button -->
          <!-- <button class="button icon-button" onclick="console.log('Bulk edit mode toggled')">
            <i class='bx bx-edit'></i>
          </button> -->

        </div>
      </header>

      <!-- Responsive Table Container -->
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>RECORD SERIES CODE</th>
              <th>RECORD TITLE</th>
              <th>DEPARTMENT</th>
              <th>INCLUSIVE DATE</th>
              <th>RETENTION</th>
              <th>DISPOSITION</th>
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
                <tr data-record-id="<?= $record['record_id'] ?>" data-office-name="<?= htmlspecialchars($record['office_name']) ?>">
                  <td><?= htmlspecialchars($record['record_series_code'] ?? 'N/A') ?></td>
                  <td><?= htmlspecialchars($record['record_title']) ?></td>
                  <td><?= htmlspecialchars($record['office_name']) ?></td>
                  <td>
                    <?= $record['inclusive_date_from'] ? date('m/d/Y', strtotime($record['inclusive_date_from'])) : 'N/A' ?>
                    -
                    <?= $record['inclusive_date_to'] ? date('m/d/Y', strtotime($record['inclusive_date_to'])) : 'N/A' ?>
                  </td>
                  <td>
                    <span class="retention-badge"><?= htmlspecialchars($record['retention_period']) ?></span>
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

        <!-- Basic Filter Row -->
        <div class="basic-filter-row">
          <div class="search-input-wrapper">
            <input type="text" id="searchInput" placeholder="Type in file name or name">
            <i class="fas fa-search search-icon"></i>
          </div>

          <!-- Department Dropdown -->
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

        <!-- Advance Filter Toggle -->
        <a href="#" class="advance-filter-toggle" id="filterToggle" onclick="toggleAdvancedFilters(event)"
          aria-expanded="false">
          Show Advance Filter <i class="fas fa-caret-down"></i>
        </a>

        <!-- Advanced Filters Row -->
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
            <select id="retentionSelect">
              <option value="">Retention Period</option>
              <?php
              $retentionOptions = ['1 Year', '2 Years', '3 Years', '4 Years', '5 Years', '6 Years', '7 Years', '8 Years', '9 Years', '10 Years', '11 Years', '12 Years', '13 Years', '14 Years', '15 Years', 'Permanent'];
              foreach ($retentionOptions as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>"><?= htmlspecialchars($option) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-select-group">
            <input type="date" id="dateFromInput" placeholder="Inclusive Date (From)">
          </div>
          <div class="filter-select-group">
            <input type="date" id="dateToInput" placeholder="Inclusive Date (To)">
          </div>
        </div>

        <!-- Clear Filter Link -->
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

          <!-- Top Section: Record Information Inputs -->
          <div class="form-fields-grid">
            <div class="form-group full-width">
              <label for="recordTitle">Record Title:</label>
              <input type="text" id="recordTitle" name="record_title" placeholder="Enter record title" required>
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
              <label for="recordSeries">Record Series Code:</label>
              <input type="text" id="recordSeries" name="record_series_code"
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
                      data-authority="<?= htmlspecialchars($class['nap_authority']) ?>">
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
                <div class="nap-details"><strong>NAP Authority:</strong> <span id="infoAuthority"></span></div>
              </div>
            </div>

            <div class="form-group">
              <label for="inclusiveDate">Inclusive Dates:</label>
              <div class="inclusive-date-group">
                <input type="date" id="inclusiveDateFrom" name="inclusive_date_from" title="Start Date" required
                  onchange="calculateEndDate()">
                <input type="date" id="inclusiveDateTo" name="inclusive_date_to" title="End Date" readonly
                  class="view-field">
              </div>
            </div>

            <!-- Auto-populated fields based on classification -->
            <div class="form-group">
              <label for="retentionPeriod">Retention Period:</label>
              <input type="text" id="retentionPeriod" name="retention_period" readonly class="view-field">
              <input type="hidden" id="retentionYears" name="retention_years">
            </div>

            <div class="form-group">
              <label for="disposition">Disposition Type:</label>
              <select id="disposition" name="disposition_type" class="view-field" style="background-color: #f8f9fa;"
                readonly>
                <option value="">Select Disposition Type...</option>
                <option value="Archive">Archive</option>
                <option value="Dispose">Dispose</option>
                <option value="Active">Active</option>
                <option value="Permanent">Permanent</option>
              </select>
            </div>
          </div>

          <!-- Upload Files Section -->
          <h2 class="upload-title">Record Attachments</h2>
          <div class="upload-area" id="uploadArea">
            <i class="fas fa-cloud-upload-alt cloud-icon"></i>
            <p>Drag and Drop files here<br>-OR-</p>
            <button type="button" class="browse-btn" onclick="document.getElementById('fileUpload').click();">Browse
              Files</button>
            <input type="file" id="fileUpload" name="attachments[]" multiple style="display: none;"
              onchange="handleFileSelect(this.files)" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif">
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
            <button type="button" class="button-action save-draft" id="saveDraftBtn" onclick="saveAsDraft()">SAVE AS
              DRAFT</button>
            <button type="submit" class="button-action save" id="saveRecordBtn">SAVE</button>
          </div>
        </form>
      </div>
    </div>

    <!-- VIEW RECORD MODAL (For Double Click) -->
    <div id="viewRecordModal" class="modal-backdrop">
      <div class="modal-card record-form-card">
        <button class="modal-close-btn" onclick="hideViewRecordModal()">&times;</button>
        <h1 class="form-title" id="viewRecordModalTitle">VIEW RECORD</h1>

        <!-- Top Section: Record Information Display -->
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
            <label>Inclusive Date:</label>
            <div class="view-field" id="viewInclusiveDate"></div>
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
            <label>Status:</label>
            <div class="view-field" id="viewStatus"></div>
          </div>
        </div>

        <!-- Description Section -->
        <div class="form-group full-width" style="margin-top: 20px;">
          <label>Detailed Description / Notes:</label>
          <div class="view-field view-description" id="viewDescription"></div>
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

    // --- Filter Functions ---
    function applyFilters() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const department = document.getElementById('departmentSelect').value;
        const classification = document.getElementById('classificationSelect').value;
        const disposition = document.getElementById('dispositionSelect').value;
        const status = document.getElementById('statusSelect').value;
        const retention = document.getElementById('retentionSelect').value;
        const dateFrom = document.getElementById('dateFromInput').value;
        const dateTo = document.getElementById('dateToInput').value;

        const rows = document.querySelectorAll('#recordsTableBody tr');
        let visibleCount = 0;

        rows.forEach(row => {
            if (row.querySelector('td[colspan="8"]')) {
                // This is the "no records" row, skip it
                return;
            }

            let showRow = true;

            // Search filter
            if (searchTerm) {
                const recordTitle = row.cells[1].textContent.toLowerCase();
                const recordCode = row.cells[0].textContent.toLowerCase();
                if (!recordTitle.includes(searchTerm) && !recordCode.includes(searchTerm)) {
                    showRow = false;
                }
            }

            // Department filter
            if (department && showRow) {
                const officeName = row.getAttribute('data-office-name');
                const departmentText = document.getElementById('departmentSelect').options[document.getElementById('departmentSelect').selectedIndex].text;
                if (officeName !== departmentText) {
                    showRow = false;
                }
            }

            // Status filter
            if (status && showRow) {
                const statusBadge = row.cells[6].querySelector('.badge');
                if (statusBadge && statusBadge.textContent.toLowerCase() !== status) {
                    showRow = false;
                }
            }

            // Retention filter
            if (retention && showRow) {
                const retentionBadge = row.cells[4].querySelector('.retention-badge');
                if (retentionBadge && retentionBadge.textContent !== retention) {
                    showRow = false;
                }
            }

            // Disposition filter
            if (disposition && showRow) {
                const dispositionCell = row.cells[5].textContent;
                if (dispositionCell !== disposition) {
                    showRow = false;
                }
            }

            // Show/hide row
            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleCount++;
        });

        // Update filter button appearance
        const filterButton = document.querySelector('.filter-button');
        const hasActiveFilters = searchTerm || department || classification || disposition || status || retention || dateFrom || dateTo;
        
        if (hasActiveFilters) {
            filterButton.innerHTML = `Filter <i class='bx bx-edit'></i>`;
            filterButton.style.background = '#1f366c';
            filterButton.style.color = 'white';
        } else {
            filterButton.innerHTML = `Filter <i class='bx bx-edit'></i>`;
            filterButton.style.background = '';
            filterButton.style.color = '';
        }

        // Show message if no records found
        const noRecordsRow = document.querySelector('#recordsTableBody tr td[colspan="8"]');
        if (visibleCount === 0) {
            if (!noRecordsRow) {
                const tbody = document.getElementById('recordsTableBody');
                tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 2rem; color: #78909c;">No records match your filters.</td></tr>`;
            }
        } else if (noRecordsRow && noRecordsRow.textContent.includes('match your filters')) {
            // Reload the original content if filters are cleared and we had a "no match" message
            location.reload();
        }

        hideFilterModal();
        showToast('Filters applied successfully');
    }

    function clearFilters() {
        // Reset all filter inputs
        document.getElementById('searchInput').value = '';
        document.getElementById('departmentSelect').value = '';
        document.getElementById('classificationSelect').value = '';
        document.getElementById('dispositionSelect').value = '';
        document.getElementById('statusSelect').value = '';
        document.getElementById('retentionSelect').value = '';
        document.getElementById('dateFromInput').value = '';
        document.getElementById('dateToInput').value = '';

        // Show all rows
        const rows = document.querySelectorAll('#recordsTableBody tr');
        rows.forEach(row => {
            row.style.display = '';
        });

        // Reset filter button
        const filterButton = document.querySelector('.filter-button');
        filterButton.innerHTML = `Filter <i class='bx bx-edit'></i>`;
        filterButton.style.background = '';
        filterButton.style.color = '';

        hideFilterModal();
        showToast('Filters cleared');
    }

    // --- Date Calculation Functions ---
    function parseRetentionPeriod(retentionPeriod) {
      if (!retentionPeriod) return 0;

      if (retentionPeriod === 'Permanent') {
        return 'permanent';
      }

      const match = retentionPeriod.match(/(\d+)\s*Year/);
      if (match && match[1]) {
        return parseInt(match[1]);
      }

      return 0;
    }

    function calculateEndDate() {
      const startDate = document.getElementById('inclusiveDateFrom').value;
      const retentionPeriod = document.getElementById('retentionPeriod').value;
      const retentionYears = parseRetentionPeriod(retentionPeriod);

      if (!startDate || !retentionYears) {
        document.getElementById('inclusiveDateTo').value = '';
        return;
      }

      if (retentionYears === 'permanent') {
        document.getElementById('inclusiveDateTo').value = '';
        return;
      }

      const start = new Date(startDate);
      const years = parseInt(retentionYears);

      if (!isNaN(years)) {
        const endDate = new Date(start);
        endDate.setFullYear(start.getFullYear() + years);
        const formattedEndDate = endDate.toISOString().split('T')[0];
        document.getElementById('inclusiveDateTo').value = formattedEndDate;
      }
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

          $('#classification').on('change', function () {
            const selectedOption = $(this).find('option:selected');
            const retention = selectedOption.data('retention');
            const disposition = selectedOption.data('disposition');
            const category = selectedOption.data('category');
            const authority = selectedOption.data('authority');

            if (retention) {
              $('#retentionPeriod').val(retention);
              $('#disposition').val(disposition);
              calculateEndDate();

              $('#infoRetention').text(retention);
              $('#infoDisposition').text(disposition);
              $('#infoCategory').text(category);
              $('#infoAuthority').text(authority);
              $('#classificationInfo').addClass('show');
            } else {
              $('#retentionPeriod').val('');
              $('#disposition').val('');
              $('#classificationInfo').removeClass('show');
            }
          });

          select2Initialized = true;
          console.log('Select2 initialized successfully');
        } catch (error) {
          console.error('Select2 initialization error:', error);
          $('#classification').show();
        }
      }
    }

    // --- File Upload Functions ---
    function initializeFileUpload() {
      const uploadArea = document.getElementById('uploadArea');
      const fileInput = document.getElementById('fileUpload');

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
        handleFileSelect(files);
      });
    }

    function handleFileSelect(files) {
      const newFiles = Array.from(files);
      selectedFiles = [...selectedFiles, ...newFiles];
      updateFileList();
    }

    function updateFileList() {
      fileCount.textContent = selectedFiles.length;

      if (selectedFiles.length === 0) {
        uploadedFilesList.innerHTML = '<div style="padding: 10px 15px; color: #78909c; font-style: italic; font-size: 14px;">No files currently attached.</div>';
        return;
      }

      uploadedFilesList.innerHTML = selectedFiles.map((file, index) => `
        <div class="file-item">
            <div class="file-info">
                <div class="file-name">${file.name}</div>
                <div class="file-size">${formatFileSize(file.size)}</div>
            </div>
            <input type="text" class="file-tag-input" placeholder="Add tag (optional)" name="file_tags[]">
            <span class="file-status">Ready</span>
            <button type="button" class="remove-file-btn" onclick="removeFile(${index})">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `).join('');
    }

    function formatFileSize(bytes) {
      if (bytes === 0) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function removeFile(index) {
      selectedFiles.splice(index, 1);
      updateFileList();
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
      updateFileList();

      $('#retentionPeriod').val('');
      $('#disposition').val('');
      $('#classificationInfo').removeClass('show');

      if (select2Initialized) {
        $('#classification').val(null).trigger('change');
      }
    }

    // --- Enhanced Record Data Fetching with 500 Error Handling ---
    function fetchRecordData(recordId) {
      console.log('🔄 Fetching record data for ID:', recordId);

      return fetch(`../get_record.php?id=${recordId}`)
        .then(response => {
          console.log('📡 Response status:', response.status, response.statusText);

          if (response.status === 500) {
            return response.text().then(errorText => {
              console.error('❌ PHP 500 Error Detected');

              let errorMessage = 'Server Error (500) - Check PHP configuration';

              try {
                const errorData = JSON.parse(errorText);
                errorMessage = errorData.message || 'Server Error';
                if (errorData.debug) {
                  console.error('Debug info:', errorData.debug);
                }
              } catch (e) {
                if (errorText.includes('Exception') || errorText.includes('Error') || errorText.includes('Fatal')) {
                  const lines = errorText.split('\n');
                  const errorLine = lines.find(line =>
                    line.includes('Exception') ||
                    line.includes('Error') ||
                    line.includes('Fatal')
                  );
                  if (errorLine) {
                    errorMessage = 'Server Error: ' + errorLine.replace(/<[^>]*>/g, '').substring(0, 150);
                  }
                }
              }

              throw new Error(errorMessage);
            });
          }

          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }

          return response.text().then(text => {
            console.log('📄 Response received');

            try {
              const data = JSON.parse(text);

              if (data.success && data.record) {
                console.log('✅ Record data loaded successfully');
                return data.record;
              } else {
                throw new Error(data.message || 'Failed to load record data');
              }
            } catch (e) {
              console.error('❌ JSON parse failed');
              throw new Error('Invalid server response');
            }
          });
        })
        .catch(error => {
          console.error('❌ fetchRecordData error:', error);
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

        fetchRecordData(recordId)
          .then(record => {
            populateFormWithRecordData(record);
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

    function populateFormWithRecordData(record) {
      console.log('Populating form with record data:', record);

      document.getElementById('recordTitle').value = record.record_title || '';
      document.getElementById('officeDepartment').value = record.office_id || '';
      document.getElementById('recordSeries').value = record.record_series_code || '';
      document.getElementById('disposition').value = record.disposition_type || '';

      if (record.inclusive_date_from) {
        const fromDate = new Date(record.inclusive_date_from);
        document.getElementById('inclusiveDateFrom').value = fromDate.toISOString().split('T')[0];
      } else {
        document.getElementById('inclusiveDateFrom').value = '';
      }

      if (record.inclusive_date_to) {
        const toDate = new Date(record.inclusive_date_to);
        document.getElementById('inclusiveDateTo').value = toDate.toISOString().split('T')[0];
      } else {
        document.getElementById('inclusiveDateTo').value = '';
      }

      document.getElementById('retentionPeriod').value = record.retention_period || '';
      document.getElementById('description').value = record.description || '';

      if (record.class_id) {
        setTimeout(() => {
          if (select2Initialized) {
            $('#classification').val(record.class_id).trigger('change');
          } else {
            document.getElementById('classification').value = record.class_id;
            setTimeout(() => {
              if (select2Initialized) {
                $('#classification').val(record.class_id).trigger('change');
              }
            }, 500);
          }
        }, 300);
      }
    }

    function handleEditClick(id) {
      console.log('Edit clicked for record ID:', id);
      openRecordModal('edit', id);
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

      const recordTitle = document.getElementById('recordTitle').value.trim();
      if (!recordTitle) {
        showToast('Record title is required even for drafts', true);
        document.getElementById('recordTitle').focus();
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

      // Set loading state
      document.getElementById('viewRecordTitle').textContent = 'Loading...';
      document.getElementById('viewOfficeDepartment').textContent = 'Loading...';
      document.getElementById('viewRecordSeries').textContent = 'Loading...';
      document.getElementById('viewDisposition').textContent = 'Loading...';
      document.getElementById('viewInclusiveDate').textContent = 'Loading...';
      document.getElementById('viewRetentionPeriod').textContent = 'Loading...';
      document.getElementById('viewStatus').textContent = 'Loading...';
      document.getElementById('viewClassification').textContent = 'Loading...';
      document.getElementById('viewDescription').textContent = 'Loading...';

      fetchRecordData(recordId)
        .then(record => {
          populateViewRecordModal(record);
        })
        .catch(error => {
          console.error('Error loading record for view:', error);

          document.getElementById('viewRecordTitle').textContent = 'Error Loading Record';
          document.getElementById('viewOfficeDepartment').textContent = 'N/A';
          document.getElementById('viewRecordSeries').textContent = 'N/A';
          document.getElementById('viewDisposition').textContent = 'N/A';
          document.getElementById('viewInclusiveDate').textContent = 'N/A';
          document.getElementById('viewRetentionPeriod').textContent = 'N/A';
          document.getElementById('viewStatus').textContent = 'N/A';
          document.getElementById('viewClassification').textContent = 'N/A';
          document.getElementById('viewDescription').textContent = 'Error: ' + error.message;

          showToast('Error loading record data: ' + error.message, true);
        });
    }

    function populateViewRecordModal(record) {
      console.log('Populating view modal with record:', record);

      document.getElementById('viewRecordTitle').textContent = record.record_title || 'N/A';
      document.getElementById('viewOfficeDepartment').textContent = record.office_name || 'N/A';
      document.getElementById('viewRecordSeries').textContent = record.record_series_code || 'N/A';
      document.getElementById('viewDisposition').textContent = record.disposition_type || 'N/A';

      const fromDate = record.inclusive_date_from ? formatDateForDisplay(record.inclusive_date_from) : 'N/A';
      const toDate = record.inclusive_date_to ? formatDateForDisplay(record.inclusive_date_to) : 'N/A';
      document.getElementById('viewInclusiveDate').textContent = `${fromDate} - ${toDate}`;

      document.getElementById('viewRetentionPeriod').textContent = record.retention_period || 'N/A';
      document.getElementById('viewStatus').textContent = record.status || 'N/A';
      document.getElementById('viewClassification').textContent = record.class_name || 'N/A';
      document.getElementById('viewDescription').textContent = record.description || 'No description available.';

      const viewModalTitle = document.getElementById('viewRecordModalTitle');
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
      const recordTitle = document.getElementById('recordTitle').value.trim();
      const officeId = document.getElementById('officeDepartment').value;
      const recordSeries = document.getElementById('recordSeries').value.trim();
      const classId = document.getElementById('classification').value;
      const retentionPeriod = document.getElementById('retentionPeriod').value.trim();
      const startDate = document.getElementById('inclusiveDateFrom').value;

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
      } else if (!retentionPeriod) {
        errorMessage = 'Please select a classification to auto-populate retention period';
        isValid = false;
      } else if (!startDate) {
        errorMessage = 'Please select a start date';
        isValid = false;
      }

      if (!isValid) {
        showToast(errorMessage, true);
        if (!recordTitle) document.getElementById('recordTitle').focus();
        else if (!officeId) document.getElementById('officeDepartment').focus();
        else if (!recordSeries) document.getElementById('recordSeries').focus();
        else if (!classId) document.getElementById('classification').focus();
        else if (!startDate) document.getElementById('inclusiveDateFrom').focus();
      }

      return isValid;
    }

    // --- Form Submission Handler ---
    recordForm.addEventListener('submit', function (e) {
      e.preventDefault();

      if (!validateForm()) {
        return;
      }

      const formData = new FormData(this);
      const isEdit = document.getElementById('recordId').value !== '';

      selectedFiles.forEach((file, index) => {
        formData.append('attachments[]', file, file.name);
      });

      const fileTagInputs = document.querySelectorAll('.file-tag-input');
      fileTagInputs.forEach((input, index) => {
        formData.append(`file_tags[${index}]`, input.value);
      });

      if (!formData.has('status')) {
        formData.append('status', 'Active');
      }

      console.log('Form data being submitted:');
      for (let [key, value] of formData.entries()) {
        if (value instanceof File) {
          console.log(key + ': ' + value.name + ' (' + value.size + ' bytes)');
        } else {
          console.log(key + ': ' + value);
        }
      }

      saveRecordBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (isEdit ? 'UPDATING...' : 'SAVING...');
      saveRecordBtn.disabled = true;

      const actionUrl = this.action;
      console.log('Submitting to:', actionUrl);

      fetch(actionUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      })
        .then(response => {
          console.log('Response status:', response.status);

          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          return response.text().then(text => {
            console.log('Raw response text:', text);
            try {
              return JSON.parse(text);
            } catch (e) {
              console.error('JSON parse error:', e);
              const errorMatch = text.match(/Error: ([^<]+)/) || text.match(/Exception: ([^<]+)/);
              if (errorMatch) {
                throw new Error(errorMatch[1]);
              }
              throw new Error('Invalid JSON response from server');
            }
          });
        })
        .then(data => {
          console.log('Parsed response data:', data);
          if (data.success) {
            showToast(data.message);
            setTimeout(() => {
              hideRecordFormModal();
              window.location.reload();
            }, 1500);
          } else {
            showToast('Error: ' + (data.message || 'Unknown error'), true);
            console.error('Server error:', data.message);
          }
        })
        .catch(error => {
          console.error('Fetch error:', error);
          showToast('Error saving record: ' + error.message, true);
        })
        .finally(() => {
          saveRecordBtn.innerHTML = isEdit ? 'UPDATE RECORD' : 'SAVE RECORD';
          saveRecordBtn.disabled = false;
        });
    });

    // --- Global Event Listeners ---
    recordFormModal.addEventListener('click', (event) => {
      if (event.target === recordFormModal) {
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

    // --- Initialize Application ---
    document.addEventListener('DOMContentLoaded', function () {
      console.log('🔧 Record Management System Loaded');

      // Initialize components
      initializeFileUpload();
      document.getElementById('inclusiveDateFrom').addEventListener('change', calculateEndDate);
    });
  </script>
</body>
</html>