<?php
session_start();
require_once '../db_connect.php';

// Load BOTH files
require_once '../count_functions.php'; // For other counts
require_once '../get_due_tomorrow.php'; // For due tomorrow records

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  header("Location: ../static/index.php");
  exit();
}

// Set timezone
date_default_timezone_set('Asia/Manila');

// Get counts from count_functions.php
$counts = getRecordCounts($pdo);

// Get due tomorrow records - USE THE ENHANCED FUNCTION
$due_records = getRecordsDueTomorrowEnhanced($pdo);

// Debug: Check what we're getting
error_log("Dashboard: Retrieved " . count($due_records) . " records due tomorrow");

// Get disposal requests
$disposal_requests = getAllDisposalRequests($pdo);

// If user is a custodian
if ($_SESSION['role_id'] == 4 && isset($_SESSION['office_id'])) {
  $office_counts = getOfficeRecordCounts($pdo, $_SESSION['office_id']);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard</title>
  <link rel="stylesheet" href="../styles/dashboard.css" />
  <link rel="stylesheet" href="../styles/sidebar.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <link rel="icon" type="image/x-icon" href="../imgs/fav.png">
</head>

<body>
  <nav class="sidebar">
    <?php include 'sidebar.php'; ?>
  </nav>
  <main>
    <div class="dashboard-container">
      <div class="summary-cards">
        <!-- Active Files Card -->
        <div class="card summary-card active-files">
          <div class="status-line teal"></div>
          <div class="card-content">
            <div class="data-text">
              <h2 class="label">ACTIVE RECORDS</h2>
              <p class="value"><?php echo number_format($counts['active_files']); ?></p>
            </div>
            <i class="fas fa-running icon"></i>
          </div>
        </div>

        <!-- Archived Files Card -->
        <div class="card summary-card archivable-files">
          <div class="status-line brown"></div>
          <div class="card-content">
            <div class="data-text">
              <p class="label">ARCHIVED</p>
              <p class="value"><?php echo number_format($counts['archived']); ?></p>
            </div>
            <i class="fas fa-box-archive icon"></i>
          </div>
        </div>

        <!-- For Disposal Card -->
        <div class="card summary-card for-disposal">
          <div class="status-line indigo"></div>
          <div class="card-content">
            <div class="data-text">
              <p class="label">DISPOSED</p>
              <p class="value"><?php echo number_format($counts['for_disposal']); ?></p>
            </div>
            <i class='fas fa-solid fa-trash-can icon'></i>
          </div>
        </div>

        <!-- Pending Request Card -->
        <div class="card summary-card pending-request">
          <div class="status-line yellow"></div>
          <div class="card-content">
            <div class="data-text">
              <p class="label">PENDING REQUEST</p>
              <p class="value"><?php echo number_format($counts['pending_request']); ?></p>
            </div>
            <i class="far fa-clock icon"></i>
          </div>
        </div>
      </div>

      <div class="main-sections">
        <!-- Records Due for Archive Section -->
        <div class="card recent-files">
          <h2 class="section-title">RECORDS DUE FOR ARCHIVE TOMORROW</h2>
          <div class="table-container">
            <table>
              <thead>
                <tr>
                  <th>Record Details</th>
                  <th>Retention End Date</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($due_records)): ?>
                  <tr>
                    <td colspan="3" style="text-align: center; color: #666; padding: 20px;">
                      <i class="fas fa-check-circle" style="color: var(--green); font-size: 24px; margin-bottom: 10px; display: block;"></i>
                      No records scheduled for archive tomorrow<br>
                      <small style="color: #999;">(<?php echo date('F d, Y', strtotime('+1 day')); ?>)</small>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($due_records as $record): ?>
                    <tr>
                      <td>
                        <strong><?php echo htmlspecialchars($record['record_title']); ?></strong><br>
                        <small style="color: #666;"><?php echo htmlspecialchars($record['office_name']); ?></small>
                        <?php if (!empty($record['files'])): ?>
                          <div style="margin-top: 5px;">
                            <small style="color: #888;">
                              <i class="fas fa-paperclip"></i>
                              <?php echo count($record['files']); ?> file(s) attached
                            </small>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td style="font-weight: 600; color: var(--orange);">
                        <?php echo date('m/d/Y', strtotime($record['retention_period_end'])); ?>
                      </td>
                      <td>
                        <span class="status-badge due-tomorrow">
                          <?php echo htmlspecialchars($record['action']); ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Disposal Requests Section -->
        <div class="card recent-tasks">
          <h2 class="section-title">Disposal Requests</h2>
          <div class="task-list">
            <?php if (empty($disposal_requests)): ?>
              <div class="task-item" style="text-align: center; padding: 20px;">
                <p style="color: #666;">
                  <i class="fas fa-inbox" style="color: #ccc; font-size: 24px; margin-bottom: 10px; display: block;"></i>
                  No disposal requests found
                </p>
              </div>
            <?php else: ?>
              <?php foreach ($disposal_requests as $request): ?>
                <div class="task-item">
                  <p class="task-code">R-<?php echo str_pad(htmlspecialchars($request['request_id']), 3, '0', STR_PAD_LEFT); ?></p>
                  <p class="task-details">
                    <?php 
                    $status = htmlspecialchars($request['status']);
                    $status_color = 'inherit';
                    if (strtolower($status) === 'pending') {
                        $status_color = 'var(--orange)';
                    } elseif (strtolower($status) === 'approved') {
                        $status_color = 'var(--green)';
                    } elseif (strtolower($status) === 'rejected') {
                        $status_color = 'var(--red)';
                    } elseif (strtolower($status) === 'completed' || strtolower($status) === 'disposed') {
                        $status_color = 'var(--indigo)';
                    } elseif (strtolower($status) === 'for review') {
                        $status_color = 'var(--yellow)';
                    }
                    ?>
                    <span style="color: <?php echo $status_color; ?>; font-weight: 600;">
                      <?php echo $status; ?>
                    </span>
                  </p>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
  <script src="../scripts/sidebar.js"></script>
</body>

</html>