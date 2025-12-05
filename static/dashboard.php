<?php
session_start();
require_once '../db_connect.php';
require_once '../count_functions.php'; // Include the counts file

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  header("Location: ../static/index.php");
  exit();
}

// Get all data using the functions
$counts = getRecordCounts($pdo);
$due_records = getRecordsDueForArchive($pdo);
$recent_requests = getRecentArchiveRequests($pdo);

// If user is a custodian, get office-specific counts
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
            <!-- <i class="fas fa-file-export icon"></i> -->
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
          <h2 class="section-title">RECORDS DUE FOR ARCHIVE</h2>
          <div class="table-container">
            <table>
              <thead>
                <tr>
                  <th>Record Details</th>
                  <th>Due Date</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($due_records)): ?>
                  <tr>
                    <td colspan="3" style="text-align: center; color: #666;">
                      No records due for archiving tomorrow
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
                      <td><?php echo date('m/d/Y', strtotime($record['due_date'])); ?></td>
                      <td style="color: var(--orange); font-weight: 600;">
                        <?php echo htmlspecialchars($record['action']); ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Recent Archive Request Section -->
        <div class="card recent-tasks">
          <h2 class="section-title">Recent Disposal Request</h2>
          <div class="task-list">
            <?php if (empty($recent_requests)): ?>
              <div class="task-item">
                <p class="task-details" style="text-align: center; color: #666;">No recent archive requests</p>
              </div>
            <?php else: ?>
              <?php foreach ($recent_requests as $request): ?>
                <div class="task-item">
                  <p class="task-code"><?php echo htmlspecialchars($request['code']); ?></p>
                  <p class="task-details"><?php echo htmlspecialchars($request['details']); ?></p>
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