<?php
require_once '../session.php';
require_once '../db_connect.php';

// Check if database connection is established
if (!isset($pdo)) {
    die("Database connection failed");
}

// Function to get records past inclusive_date_to with archive or dispose disposition
function getDisposableRecords($pdo) {
    $currentDate = date('Y-m-d');
    
    $sql = "SELECT r.record_id, r.record_series_code, r.record_title, 
                   r.date_created, r.inclusive_date_from, r.inclusive_date_to, 
                   r.retention_period, r.disposition_type, o.office_name, rc.class_name
            FROM records r
            JOIN offices o ON r.office_id = o.office_id
            JOIN record_classification rc ON r.class_id = rc.class_id
            WHERE r.disposition_type IN ('Archive', 'Dispose') 
            AND r.status = 'Active'
            AND r.inclusive_date_to IS NOT NULL
            AND r.inclusive_date_to <= :currentDate
            AND r.record_id NOT IN (
                SELECT ar.record_id 
                FROM archive_requests ar 
                WHERE ar.status = 'Pending'
            )";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':currentDate', $currentDate, PDO::PARAM_STR);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return [];
    }
}

// Function to get archive requests with user and record information
function getArchiveRequests($pdo) {
    $sql = "SELECT ar.request_id, ar.request_date, ar.status,
                   u.first_name, u.last_name, u.email,
                   r.record_series_code, r.record_title, r.disposition_type,
                   o.office_name, rc.class_name
            FROM archive_requests ar
            JOIN users u ON ar.requested_by = u.user_id
            JOIN records r ON ar.record_id = r.record_id
            JOIN offices o ON r.office_id = o.office_id
            JOIN record_classification rc ON r.class_id = rc.class_id
            ORDER BY ar.request_date DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return [];
    }
}

// Function to get specific request information
function getRequestInfo($pdo, $requestId) {
    $sql = "SELECT ar.request_id, ar.request_date, ar.status,
                   u.first_name, u.last_name, u.user_id,
                   r.record_id, r.record_series_code, r.record_title, r.disposition_type
            FROM archive_requests ar
            JOIN users u ON ar.requested_by = u.user_id
            JOIN records r ON ar.record_id = r.record_id
            WHERE ar.request_id = :request_id";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':request_id', $requestId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return null;
    }
}

// Function to update record status after approval
function updateRecordStatus($pdo, $recordId, $dispositionType) {
    if ($dispositionType === 'Archive') {
        $sql = "UPDATE records SET status = 'Archived' WHERE record_id = :record_id";
    } else if ($dispositionType === 'Dispose') {
        $sql = "UPDATE records SET status = 'Disposed' WHERE record_id = :record_id";
    } else {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

// Get disposable records
$disposableRecords = getDisposableRecords($pdo);

// Get archive requests
$archiveRequests = getArchiveRequests($pdo);

// Handle form submission for new archive request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_records'])) {
    $selectedRecords = $_POST['selected_records'];
    $requestedBy = $_SESSION['user_id'];
    
    if (!empty($selectedRecords)) {
        $successCount = 0;
        $errorCount = 0;
        
        try {
            $pdo->beginTransaction();
            
            // Create individual archive request for each selected record
            foreach ($selectedRecords as $recordId) {
                // Validate record exists and is disposable/archivable
                $checkSql = "SELECT record_id, disposition_type FROM records WHERE record_id = ? AND disposition_type IN ('Archive', 'Dispose') AND status = 'Active'";
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->execute([$recordId]);
                
                if ($checkStmt->rowCount() > 0) {
                    // Insert into archive_requests (one request per record)
                    $insertSql = "INSERT INTO archive_requests (record_id, requested_by, status) VALUES (?, ?, 'Pending')";
                    $insertStmt = $pdo->prepare($insertSql);
                    
                    if ($insertStmt->execute([$recordId, $requestedBy])) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                } else {
                    $errorCount++;
                }
            }
            
            $pdo->commit();
            
            if ($successCount > 0) {
                $_SESSION['message'] = "Successfully created $successCount archive request(s)!";
                $_SESSION['message_type'] = "success";
                
                // Redirect to prevent form resubmission
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $message = "Failed to create archive requests. Please try again.";
                $messageType = "error";
            }
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Database error: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

// Handle request approval/decline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['request_id'])) {
    $requestId = $_POST['request_id'];
    $action = $_POST['action'];
    
    try {
        $pdo->beginTransaction();
        
        // Get the request details first
        $requestInfo = getRequestInfo($pdo, $requestId);
        
        if (!$requestInfo) {
            throw new Exception("Request not found");
        }
        
        if ($action === 'approve') {
            // Update the record status based on disposition
            $updateSuccess = updateRecordStatus($pdo, $requestInfo['record_id'], $requestInfo['disposition_type']);
            
            if ($updateSuccess) {
                // Update request status
                $updateSql = "UPDATE archive_requests SET status = 'Approved' WHERE request_id = ?";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([$requestId]);
                
                $_SESSION['message'] = "Request approved successfully! Record has been " . strtolower($requestInfo['disposition_type']) . "d.";
                $_SESSION['message_type'] = "success";
            } else {
                throw new Exception("Failed to update record status");
            }
            
        } elseif ($action === 'decline') {
            // Update request status to declined
            $updateSql = "UPDATE archive_requests SET status = 'Declined' WHERE request_id = ?";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([$requestId]);
            
            $_SESSION['message'] = "Request declined successfully!";
            $_SESSION['message_type'] = "success";
        }
        
        $pdo->commit();
        
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error: " . $e->getMessage();
        $messageType = "error";
    }
}

// Check for session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Check if we're viewing a specific request
$viewingRequestId = isset($_GET['view_request']) ? (int)$_GET['view_request'] : null;
$currentRequest = null;

if ($viewingRequestId) {
    $currentRequest = getRequestInfo($pdo, $viewingRequestId);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Requests Management</title>
  <link rel="stylesheet" href="../styles/request.css">
  <link rel="icon" type="image/x-icon" href="../imgs/fav.png">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <style>
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      z-index: 1000;
      justify-content: center;
      align-items: center;
    }

    .modal-content {
      background-color: white;
      border-radius: 8px;
      width: 90%;
      max-width: 1200px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
      position: relative;
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 15px 20px;
      border-bottom: 1px solid #e0e0e0;
      background-color: #f8f9fa;
      border-radius: 8px 8px 0 0;
    }

    .modal-title {
      font-size: 1.2rem;
      font-weight: 600;
      color: #333;
    }

    .close-modal {
      background: none;
      border: none;
      font-size: 1.5rem;
      cursor: pointer;
      color: #666;
      padding: 0;
      width: 30px;
      height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
    }

    .close-modal:hover {
      background-color: #e9ecef;
      color: #333;
    }

    .modal-body {
      padding: 20px;
    }

    .search-section {
      margin-bottom: 20px;
    }

    .search-box {
      width: 100%;
      padding: 12px 15px;
      border: 1px solid #cfd8dc;
      border-radius: 8px;
      font-size: 15px;
      color: #37474f;
      background-color: white;
    }

    .search-box:focus {
      border-color: #1e3a8a;
      outline: none;
      box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.2);
    }

    .records-table-container {
      margin: 20px 0;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: white;
      overflow: hidden;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      max-height: 400px;
      overflow-y: auto;
    }

    .records-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }

    .records-table th,
    .records-table td {
      width: calc(100% / 8);
    }

    .records-table th:first-child,
    .records-table td:first-child {
      width: 60px;
      min-width: 60px;
      max-width: 60px;
    }

    .records-table th:not(:first-child),
    .records-table td:not(:first-child) {
      width: calc((100% - 60px) / 7);
    }

    .records-table th {
      background: #f2f4f7;
      padding: 14px 8px;
      text-align: center;
      font-weight: 600;
      border-bottom: 2px solid #34495e;
      font-size: 12px;
      color: #34495e;
      text-transform: uppercase;
      position: sticky;
      top: 0;
      white-space: normal;
      word-wrap: break-word;
      line-height: 1.3;
    }

    .records-table td {
      padding: 12px 8px;
      border-bottom: 1px solid #e2e8f0;
      font-size: 13px;
      color: #475569;
      text-align: center;
      vertical-align: middle;
      word-wrap: break-word;
      overflow-wrap: break-word;
      line-height: 1.4;
    }

    .records-table tr:last-child td {
      border-bottom: none;
    }

    .records-table tr:hover {
      background: #f0f4f9;
    }

    .checkbox-cell {
      text-align: center;
    }

    .checkbox-cell input[type="checkbox"] {
      margin: 0;
      transform: scale(1.1);
    }

    .retention-expired {
      color: #e53935;
      font-weight: 600;
      font-size: 12px;
    }

    .disposition-archive {
      color: #1e3a8a;
      font-weight: 600;
      font-size: 12px;
      background: #e0e7ff;
      padding: 4px 8px;
      border-radius: 4px;
    }

    .disposition-dispose {
      color: #dc2626;
      font-weight: 600;
      font-size: 12px;
      background: #fef2f2;
      padding: 4px 8px;
      border-radius: 4px;
    }

    .form-actions {
      display: flex;
      justify-content: flex-end;
      gap: 15px;
      margin-top: 20px;
      padding-top: 20px;
      border-top: 1px solid #e2e8f0;
    }

    .btn-submit {
      background: #1e3a8a;
      color: white;
      padding: 12px 25px;
      border: none;
      border-radius: 6px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s;
      text-transform: uppercase;
    }

    .btn-submit:hover {
      background: #1f366c;
      transform: translateY(-1px);
    }

    .btn-cancel {
      background: #6c757d;
      color: white;
      padding: 12px 25px;
      border: none;
      border-radius: 6px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s;
      text-transform: uppercase;
    }

    .btn-cancel:hover {
      background: #5a6268;
      transform: translateY(-1px);
    }

    .message {
      padding: 12px 15px;
      margin: 15px 0;
      border-radius: 6px;
      font-weight: 500;
    }

    .message.success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }

    .message.error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }

    .no-records {
      text-align: center;
      padding: 40px 20px;
      color: #6c757d;
      font-style: italic;
    }

    .record-status-archived {
      color: #1e3a8a;
      font-weight: 600;
      background: #e0e7ff;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
    }

    .record-status-disposed {
      color: #dc2626;
      font-weight: 600;
      background: #fef2f2;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
    }

    .record-table-container {
      margin: 20px 0;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: white;
      overflow: hidden;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      max-height: 400px;
      overflow-y: auto;
    }

    .record-table {
      width: 100%;
      border-collapse: collapse;
    }

    .record-table th {
      background: #f2f4f7;
      padding: 14px 8px;
      text-align: center;
      font-weight: 600;
      border-bottom: 2px solid #34495e;
      font-size: 12px;
      color: #34495e;
      text-transform: uppercase;
      position: sticky;
      top: 0;
    }

    .record-table td {
      padding: 12px 8px;
      border-bottom: 1px solid #e2e8f0;
      font-size: 13px;
      color: #475569;
      text-align: center;
      vertical-align: middle;
    }

    .record-table tr:last-child td {
      border-bottom: none;
    }

    .record-table tr:hover {
      background: #f0f4f9;
    }
  </style>
</head>

<body>
  <nav class="sidebar">
    <?php include 'sidebar.php'; ?>
  </nav>

  <main>
    <!-- REQUESTS TABLE VIEW -->
    <div class="header">
      <h1>REQUESTS</h1>
      <div class="actions">
        <button class="button primary-action-btn" onclick="openNewRequestModal()">
          <i class='bx bx-mail-send'></i>
          Add New Request
        </button>
      </div>
    </div>

    <!-- Display messages -->
    <?php if (isset($message)): ?>
      <div class="message <?php echo $messageType; ?>">
        <?php echo $message; ?>
      </div>
    <?php endif; ?>

    <div class="card dashboard-card" id="requests-view">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>REQUEST ID</th>
              <th>RECORD</th>
              <th>REQUESTED BY</th>
              <th>DATE OF REQUEST</th>
              <th>DISPOSITION</th>
              <th>STATUS</th>
              <th>ACTION</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($archiveRequests)): ?>
              <tr>
                <td colspan="7" style="text-align: center; padding: 2rem; color: #78909c;">
                  No archive requests found.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($archiveRequests as $request): ?>
                <tr>
                  <td>AR-<?php echo str_pad($request['request_id'], 3, '0', STR_PAD_LEFT); ?></td>
                  <td>
                    <strong><?php echo htmlspecialchars($request['record_series_code']); ?></strong><br>
                    <small><?php echo htmlspecialchars($request['record_title']); ?></small>
                  </td>
                  <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                  <td><?php echo date('m/d/Y', strtotime($request['request_date'])); ?></td>
                  <td>
                    <span class="<?php echo $request['disposition_type'] === 'Archive' ? 'disposition-archive' : 'disposition-dispose'; ?>">
                      <?php echo htmlspecialchars($request['disposition_type']); ?>
                    </span>
                  </td>
                  <td>
                    <span class="status <?php echo strtolower($request['status']); ?>">
                      <?php echo $request['status']; ?>
                    </span>
                  </td>
                  <td>
                    <div class="view-btn" onclick="window.location.href='?view_request=<?php echo $request['request_id']; ?>'">
                      <i class='bx bx-show'></i>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- NEW REQUEST MODAL -->
    <div id="new-request-modal" class="modal-overlay">
      <div class="modal-content">
        <div class="modal-header">
          <div class="modal-title">Create New Archive Requests</div>
          <button class="close-modal" onclick="closeNewRequestModal()">
            <i class='bx bx-x'></i>
          </button>
        </div>
        <form method="POST" action="">
          <div class="modal-body">
            <div class="search-section">
              <input type="text" class="search-box" id="record-search" placeholder="Search records..." onkeyup="filterRecords()">
            </div>
            
            <div class="records-table-container">
              <table class="records-table">
                <thead>
                  <tr>
                    <th class="checkbox-cell">
                      <input type="checkbox" id="select-all" onchange="toggleSelectAll()">
                    </th>
                    <th>RECORD ID</th>
                    <th>RECORD TITLE</th>
                    <th>OFFICE</th>
                    <th>CLASSIFICATION</th>
                    <th>INCLUSIVE DATE TO</th>
                    <th>DISPOSITION</th>
                    <th>STATUS</th>
                  </tr>
                </thead>
                <tbody id="records-table-body">
                  <?php if (empty($disposableRecords)): ?>
                    <tr>
                      <td colspan="8" class="no-records">
                        No records found that are past their inclusive date and marked for archive or disposal.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($disposableRecords as $record): ?>
                      <tr class="record-row">
                        <td class="checkbox-cell">
                          <input type="checkbox" name="selected_records[]" value="<?php echo $record['record_id']; ?>">
                        </td>
                        <td><?php echo htmlspecialchars($record['record_series_code']); ?></td>
                        <td><?php echo htmlspecialchars($record['record_title']); ?></td>
                        <td><?php echo htmlspecialchars($record['office_name']); ?></td>
                        <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                        <td><?php echo date('m/d/Y', strtotime($record['inclusive_date_to'])); ?></td>
                        <td>
                          <span class="<?php echo $record['disposition_type'] === 'Archive' ? 'disposition-archive' : 'disposition-dispose'; ?>">
                            <?php echo htmlspecialchars($record['disposition_type']); ?>
                          </span>
                        </td>
                        <td><span class="retention-expired">Ready for Processing</span></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
            
            <div class="form-actions">
              <button type="button" class="btn-cancel" onclick="closeNewRequestModal()">Cancel</button>
              <button type="submit" class="btn-submit" <?php echo empty($disposableRecords) ? 'disabled' : ''; ?>>Create Archive Requests</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- DETAILS MODAL -->
    <?php if ($viewingRequestId && $currentRequest): ?>
    <div id="details-modal" class="modal-overlay" style="display: flex;">
      <div class="modal-content">
        <div class="modal-header">
          <div class="modal-title">Request Details</div>
          <button class="close-modal" onclick="window.location.href='?'">
            <i class='bx bx-x'></i>
          </button>
        </div>
        <div class="modal-body">
          <div class="details-container">
            <div class="details-table-container">
              <table class="details-table">
                <tr>
                  <th>REQUEST ID</th>
                  <th>RECORD</th>
                  <th>REQUEST BY</th>
                  <th>REQUEST DATE</th>
                  <th>DISPOSITION</th>
                  <th>STATUS</th>
                </tr>
                <tr>
                  <td>AR-<?php echo str_pad($currentRequest['request_id'], 3, '0', STR_PAD_LEFT); ?></td>
                  <td>
                    <strong><?php echo htmlspecialchars($currentRequest['record_series_code']); ?></strong><br>
                    <small><?php echo htmlspecialchars($currentRequest['record_title']); ?></small>
                  </td>
                  <td><?php echo htmlspecialchars($currentRequest['first_name'] . ' ' . $currentRequest['last_name']); ?></td>
                  <td><?php echo date('m/d/Y', strtotime($currentRequest['request_date'])); ?></td>
                  <td>
                    <span class="<?php echo $currentRequest['disposition_type'] === 'Archive' ? 'disposition-archive' : 'disposition-dispose'; ?>">
                      <?php echo htmlspecialchars($currentRequest['disposition_type']); ?>
                    </span>
                  </td>
                  <td>
                    <span class="status-badge status-<?php echo strtolower($currentRequest['status']); ?>">
                      <?php echo $currentRequest['status']; ?>
                    </span>
                  </td>
                </tr>
              </table>
            </div>

            <div class="record-table-container">
              <table class="record-table">
                <thead>
                  <tr>
                    <th>Record Title</th>
                    <th>Record Series Code</th>
                    <th>Inclusive Date</th>
                    <th>Classification</th>
                    <th>Office</th>
                    <th>Disposition</th>
                    <th>Current Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td><?php echo htmlspecialchars($currentRequest['record_title']); ?></td>
                    <td><?php echo htmlspecialchars($currentRequest['record_series_code']); ?></td>
                    <td>
                      <?php 
                        // Get full record details for dates
                        $recordSql = "SELECT inclusive_date_from, inclusive_date_to FROM records WHERE record_id = ?";
                        $recordStmt = $pdo->prepare($recordSql);
                        $recordStmt->execute([$currentRequest['record_id']]);
                        $recordDetails = $recordStmt->fetch(PDO::FETCH_ASSOC);
                        
                        $fromDate = $recordDetails['inclusive_date_from'] ? date('m/d/Y', strtotime($recordDetails['inclusive_date_from'])) : 'N/A';
                        $toDate = $recordDetails['inclusive_date_to'] ? date('m/d/Y', strtotime($recordDetails['inclusive_date_to'])) : 'N/A';
                        echo $fromDate . ' - ' . $toDate;
                      ?>
                    </td>
                    <td>
                      <?php 
                        // Get classification name
                        $classSql = "SELECT rc.class_name FROM records r JOIN record_classification rc ON r.class_id = rc.class_id WHERE r.record_id = ?";
                        $classStmt = $pdo->prepare($classSql);
                        $classStmt->execute([$currentRequest['record_id']]);
                        $class = $classStmt->fetch(PDO::FETCH_ASSOC);
                        echo htmlspecialchars($class['class_name'] ?? 'N/A');
                      ?>
                    </td>
                    <td>
                      <?php 
                        // Get office name
                        $officeSql = "SELECT o.office_name FROM records r JOIN offices o ON r.office_id = o.office_id WHERE r.record_id = ?";
                        $officeStmt = $pdo->prepare($officeSql);
                        $officeStmt->execute([$currentRequest['record_id']]);
                        $office = $officeStmt->fetch(PDO::FETCH_ASSOC);
                        echo htmlspecialchars($office['office_name'] ?? 'N/A');
                      ?>
                    </td>
                    <td>
                      <span class="<?php echo $currentRequest['disposition_type'] === 'Archive' ? 'disposition-archive' : 'disposition-dispose'; ?>">
                        <?php echo htmlspecialchars($currentRequest['disposition_type']); ?>
                      </span>
                    </td>
                    <td>
                      <?php if ($currentRequest['status'] === 'Approved'): ?>
                        <span class="<?php echo $currentRequest['disposition_type'] === 'Archive' ? 'record-status-archived' : 'record-status-disposed'; ?>">
                          <?php echo $currentRequest['disposition_type'] === 'Archive' ? 'Archived' : 'Disposed'; ?>
                        </span>
                      <?php else: ?>
                        <span class="retention-expired">Pending</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <?php if ($currentRequest['status'] === 'Pending'): ?>
            <div class="button-row">
              <form method="POST" action="" style="display: inline;">
                <input type="hidden" name="request_id" value="<?php echo $currentRequest['request_id']; ?>">
                <input type="hidden" name="action" value="approve">
                <button type="button" class="btn approve" onclick="showConfirmationPopup()">Approve</button>
              </form>
              <form method="POST" action="" style="display: inline;">
                <input type="hidden" name="request_id" value="<?php echo $currentRequest['request_id']; ?>">
                <input type="hidden" name="action" value="decline">
                <button type="submit" class="btn decline" onclick="return confirm('Are you sure you want to decline this request?')">Decline</button>
              </form>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- CONFIRMATION POPUP -->
    <div class="overlay" id="popup" style="display: none;">
      <div class="popup-box">
        <div class="popup-header">
          CONFIRMATION NOTICE
        </div>
        <div class="popup-content">
          <p>Are you sure you want to approve this request?<br>
            Record will be: <strong><?php echo $currentRequest['disposition_type'] ?? 'Processed'; ?></strong><br>
            This action cannot be undone.</p>
        </div>
        <div class="popup-buttons">
          <button class="approve-btn" onclick="approveRequest()">Approve Request</button>
          <button class="cancel-btn" onclick="closePopup()">Cancel</button>
        </div>
      </div>
    </div>
  </main>

  <script>
    function openNewRequestModal() {
      document.getElementById('new-request-modal').style.display = 'flex';
    }

    function closeNewRequestModal() {
      document.getElementById('new-request-modal').style.display = 'none';
    }

    function showConfirmationPopup() {
      document.getElementById('popup').style.display = 'flex';
    }

    function closePopup() {
      document.getElementById('popup').style.display = 'none';
    }

    function approveRequest() {
      const approveForm = document.querySelector('form input[name="action"][value="approve"]').closest('form');
      approveForm.submit();
    }

    function toggleSelectAll() {
      const selectAll = document.getElementById('select-all');
      const checkboxes = document.querySelectorAll('input[name="selected_records[]"]');
      
      checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
      });
    }

    function filterRecords() {
      const searchTerm = document.getElementById('record-search').value.toLowerCase();
      const rows = document.querySelectorAll('.record-row');
      
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    }

    // Close modals when clicking outside
    document.getElementById('new-request-modal')?.addEventListener('click', function(e) {
      if (e.target === this) closeNewRequestModal();
    });

    document.getElementById('popup')?.addEventListener('click', function(e) {
      if (e.target === this) closePopup();
    });

    // Close with Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeNewRequestModal();
        closePopup();
      }
    });
  </script>
</body>
</html>