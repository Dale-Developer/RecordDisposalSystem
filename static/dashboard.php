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

// Get ALL records for calendar (not just tomorrow)
function getRecordsForCalendar($pdo, $start_date, $end_date) {
    try {
        // Simple query that should work with your database
        $sql = "
            SELECT 
                r.record_id,
                r.record_title,
                r.retention_period_end,
                o.office_name,
                'Archive' as action
            FROM records r
            JOIN offices o ON r.office_id = o.office_id
            WHERE r.retention_period_end BETWEEN ? AND ?
            ORDER BY r.retention_period_end ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start_date, $end_date]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Try to get files if files table exists
        foreach ($records as &$record) {
            $record['files'] = [];
            $record['current_status'] = 'Active';
            
            try {
                $file_sql = "SELECT file_name, file_path FROM files WHERE record_id = ?";
                $file_stmt = $pdo->prepare($file_sql);
                $file_stmt->execute([$record['record_id']]);
                $record['files'] = $file_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Continue without files
            }
        }
        
        return $records;
        
    } catch (PDOException $e) {
        error_log("Error in getRecordsForCalendar: " . $e->getMessage());
        return []; // Return empty array if everything fails
    }
}

// Get current month and year for calendar
$current_month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$current_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Calculate start and end dates for the month
$start_date = date('Y-m-01', mktime(0, 0, 0, $current_month, 1, $current_year));
$end_date = date('Y-m-t', mktime(0, 0, 0, $current_month, 1, $current_year));

// Get records for the entire month
$calendar_records = getRecordsForCalendar($pdo, $start_date, $end_date);

// Debug: Log what we found
error_log("Calendar records retrieved: " . count($calendar_records));
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
  <style>
    /* Calendar Styles */
    .calendar-container {
      padding: var(--space-sm);
      height: 100%;
      display: flex;
      flex-direction: column;
      min-height: 0;
      overflow: hidden;
    }
    
    .calendar-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: var(--space-md);
      padding: 0 var(--space-sm);
      flex-shrink: 0;
    }
    
    .calendar-title {
      font-size: var(--font-size-lg);
      font-weight: 700;
      color: var(--primary-blue);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .calendar-nav {
      display: flex;
      gap: var(--space-xs);
    }
    
    .calendar-nav button {
      background: var(--light-gray);
      border: 1px solid var(--medium-gray);
      padding: var(--space-xs) var(--space-sm);
      border-radius: var(--border-radius-sm);
      cursor: pointer;
      font-weight: 600;
      color: var(--primary-blue);
      transition: all var(--transition-fast);
      font-size: var(--font-size-xs);
      white-space: nowrap;
    }
    
    .calendar-nav button:hover {
      background: var(--primary-blue-light);
      border-color: var(--primary-blue);
      transform: translateY(-1px);
    }
    
    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 2px;
      text-align: center;
      flex: 1;
      min-height: 0;
    }
    
    .calendar-day-header {
      padding: var(--space-xs);
      font-weight: 600;
      color: var(--text-dark);
      background: var(--light-gray);
      border-radius: var(--border-radius-sm);
      font-size: var(--font-size-sm);
      border: 1px solid var(--medium-gray);
    }
    
    .calendar-day {
      padding: var(--space-xs);
      background: var(--white);
      border: 1px solid var(--medium-gray);
      border-radius: var(--border-radius-sm);
      cursor: pointer;
      transition: all var(--transition-fast);
      position: relative;
      min-height: 40px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-start;
    }
    
    .calendar-day:hover {
      background: var(--primary-blue-light);
      transform: translateY(-1px);
    }
    
    .calendar-day.empty {
      background: var(--light-gray);
      border: 1px dashed var(--medium-gray);
      cursor: default;
    }
    
    .calendar-day.empty:hover {
      background: var(--light-gray);
      transform: none;
    }
    
    .day-number {
      font-size: var(--font-size-sm);
      font-weight: 600;
      color: var(--text-dark);
      text-align: center;
    }
    
    .event-tick {
      color: var(--yellow);
      font-size: 0.9rem;
      margin-top: 2px;
    }
    
    .today {
      background: var(--primary-blue-light);
      border-color: var(--primary-blue);
    }
    
    .today .day-number {
      color: var(--primary-blue);
      font-weight: 800;
    }
    
    .selected-date {
      background: var(--yellow-light);
      border-color: var(--yellow);
    }
    
    .date-info-panel {
      margin-top: var(--space-md);
      padding: var(--space-md);
      background: var(--light-gray);
      border-radius: var(--border-radius-md);
      border-left: 4px solid var(--yellow);
      flex-shrink: 0;
    }
    
    .date-info-title {
      font-weight: 700;
      color: var(--text-dark);
      margin-bottom: var(--space-sm);
      display: flex;
      align-items: center;
      gap: var(--space-xs);
    }
    
    .date-info-title i {
      color: var(--yellow);
    }
    
    .no-events {
      color: var(--text-light);
      font-style: italic;
      text-align: center;
      padding: var(--space-md);
    }
    
    .event-list {
      max-height: 150px;
      overflow-y: auto;
    }
    
    .event-item {
      padding: var(--space-sm);
      background: var(--white);
      border-radius: var(--border-radius-sm);
      margin-bottom: var(--space-xs);
      border-left: 3px solid var(--yellow);
    }
    
    .event-title {
      font-weight: 600;
      color: var(--text-dark);
      margin-bottom: var(--space-xs);
    }
    
    .event-details {
      font-size: var(--font-size-xs);
      color: var(--text-light);
    }
    
    .event-count-badge {
      position: absolute;
      top: 3px;
      right: 3px;
      background: var(--yellow);
      color: #856404;
      font-size: 0.6rem;
      font-weight: 800;
      padding: 1px 4px;
      border-radius: 10px;
    }
    
    .calendar-day-header:nth-child(1) { 
      color: #dc3545; 
    }
    
    .calendar-day-header:nth-child(7) { 
      color: var(--primary-blue); 
    }
    
    .status-badge {
      display: inline-block;
      padding: 2px 6px;
      border-radius: var(--border-radius-sm);
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
    }
    
    .status-badge.due-tomorrow {
      background-color: var(--yellow-light);
      color: #856404;
      border: 1px solid var(--yellow);
    }
    
    .tomorrow-indicator {
      color: var(--primary-blue);
      font-size: 0.7em;
      font-weight: bold;
    }
  </style>
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

        <!-- Calendar View for Archive Schedule -->
        <div class="card recent-files">
          <h2 class="section-title">ARCHIVE SCHEDULE CALENDAR</h2>
          
          <div class="calendar-container">
            <?php
            // Get first day of month
            $first_day = mktime(0, 0, 0, $current_month, 1, $current_year);
            $month_name = date('F', $first_day);
            $year = date('Y', $first_day);
            
            // Get number of days in month
            $days_in_month = date('t', $first_day);
            
            // Get day of week for first day (0=Sunday, 6=Saturday)
            $first_day_of_week = date('w', $first_day);
            
            // Group records by date
            $records_by_date = [];
            foreach ($calendar_records as $record) {
                if (!empty($record['retention_period_end'])) {
                    $date = date('Y-m-d', strtotime($record['retention_period_end']));
                    if (!isset($records_by_date[$date])) {
                        $records_by_date[$date] = [];
                    }
                    $records_by_date[$date][] = $record;
                }
            }
            
            // Also add tomorrow's records
            foreach ($due_records as $record) {
                if (!empty($record['retention_period_end'])) {
                    $date = date('Y-m-d', strtotime($record['retention_period_end']));
                    if (!isset($records_by_date[$date])) {
                        $records_by_date[$date] = [];
                    }
                    $records_by_date[$date][] = $record;
                }
            }
            
            // Get next/previous month
            $prev_month = $current_month - 1;
            $prev_year = $current_year;
            if ($prev_month < 1) {
                $prev_month = 12;
                $prev_year--;
            }
            
            $next_month = $current_month + 1;
            $next_year = $current_year;
            if ($next_month > 12) {
                $next_month = 1;
                $next_year++;
            }
            
            // Selected date from query parameter
            $selected_date = isset($_GET['date']) ? $_GET['date'] : null;
            
            // Get tomorrow's date for highlighting
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            ?>
            
            <!-- Calendar Navigation -->
            <div class="calendar-header">
              <div class="calendar-nav">
                <button onclick="changeMonth(<?php echo $prev_month; ?>, <?php echo $prev_year; ?>)">
                  <i class="fas fa-chevron-left"></i> Prev
                </button>
                <button onclick="changeMonth(<?php echo date('n'); ?>, <?php echo date('Y'); ?>)">
                  Today
                </button>
                <button onclick="changeMonth(<?php echo $next_month; ?>, <?php echo $next_year; ?>)">
                  Next <i class="fas fa-chevron-right"></i>
                </button>
              </div>
              <div class="calendar-title">
                <?php echo $month_name . ' ' . $year; ?>
              </div>
            </div>
            
            <!-- Calendar Grid -->
            <div class="calendar-grid">
              <div class="calendar-day-header">Sun</div>
              <div class="calendar-day-header">Mon</div>
              <div class="calendar-day-header">Tue</div>
              <div class="calendar-day-header">Wed</div>
              <div class="calendar-day-header">Thu</div>
              <div class="calendar-day-header">Fri</div>
              <div class="calendar-day-header">Sat</div>
              
              <?php
              // Empty cells for days before first day of month
              for ($i = 0; $i < $first_day_of_week; $i++) {
                  echo '<div class="calendar-day empty"></div>';
              }
              
              // Days of the month
              $today = date('Y-m-d');
              for ($day = 1; $day <= $days_in_month; $day++) {
                  $date_str = date('Y-m-d', mktime(0, 0, 0, $current_month, $day, $current_year));
                  $is_today = ($date_str == $today);
                  $is_tomorrow = ($date_str == $tomorrow);
                  $is_selected = ($date_str == $selected_date);
                  $has_records = isset($records_by_date[$date_str]);
                  $record_count = $has_records ? count($records_by_date[$date_str]) : 0;
                  
                  $day_class = "calendar-day";
                  if ($is_today) $day_class .= " today";
                  if ($is_selected) $day_class .= " selected-date";
                  
                  echo '<div class="' . $day_class . '" onclick="selectDate(\'' . $date_str . '\')">';
                  echo '<div class="day-number">' . $day . '</div>';
                  
                  if ($has_records) {
                      if ($record_count > 1) {
                          echo '<div class="event-count-badge">' . $record_count . '</div>';
                      }
                      echo '<i class="fas fa-check-circle event-tick" title="Records due for archive"></i>';
                  }
                  
                  echo '</div>';
              }
              
              // Fill remaining cells
              $total_cells = 42;
              $filled_cells = $first_day_of_week + $days_in_month;
              for ($i = $filled_cells; $i < $total_cells; $i++) {
                  echo '<div class="calendar-day empty"></div>';
              }
              ?>
            </div>
            
            <!-- Date Information Panel -->
            <!-- <div class="date-info-panel">
              <?php if ($selected_date && isset($records_by_date[$selected_date])): ?>
                <?php
                $selected_date_formatted = date('F d, Y', strtotime($selected_date));
                $is_tomorrow = ($selected_date == $tomorrow);
                ?>
                <div class="date-info-title">
                  <i class="fas fa-calendar-check"></i>
                  <?php if ($is_tomorrow): ?>
                    <span style="color: #ff9800;">TOMORROW:</span>
                  <?php endif; ?>
                  Records due for archive on <?php echo $selected_date_formatted; ?>
                </div>
                <div class="event-list">
                  <?php foreach ($records_by_date[$selected_date] as $record): ?>
                    <div class="event-item">
                      <div class="event-title"><?php echo htmlspecialchars($record['record_title'] ?? 'Untitled Record'); ?></div>
                      <div class="event-details">
                        <strong>Office:</strong> <?php echo htmlspecialchars($record['office_name'] ?? 'Unknown Office'); ?><br>
                        <strong>Status:</strong> 
                        <span class="status-badge due-tomorrow">
                          <?php echo htmlspecialchars($record['action'] ?? 'Archive'); ?>
                        </span>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php elseif ($selected_date): ?>
                <div class="date-info-title">
                  <i class="fas fa-calendar-day"></i>
                  <?php echo date('F d, Y', strtotime($selected_date)); ?>
                </div>
                <p class="no-events">No records scheduled for archive on this date.</p>
              <?php else: ?>
                <div class="date-info-title">
                  <i class="fas fa-calendar-alt"></i>
                  Click on a date with a yellow tick to view archive details
                </div>
                <p class="no-events">
                  Dates with <i class="fas fa-check-circle" style="color: var(--yellow);"></i> indicate records due for archive.
                </p>
              <?php endif; ?>
            </div> -->
          </div>
        </div>
      </div>
    </div>
    
    <script>
      function changeMonth(month, year) {
        const url = new URL(window.location.href);
        url.searchParams.set('month', month);
        url.searchParams.set('year', year);
        // Keep selected date if it exists
        const currentDate = url.searchParams.get('date');
        if (currentDate) {
          url.searchParams.set('date', currentDate);
        }
        window.location.href = url.toString();
      }
      
      function selectDate(date) {
        const url = new URL(window.location.href);
        url.searchParams.set('date', date);
        url.searchParams.set('month', <?php echo $current_month; ?>);
        url.searchParams.set('year', <?php echo $current_year; ?>);
        window.location.href = url.toString();
      }
    </script>
  </main>
  <script src="../scripts/sidebar.js"></script>
</body>
</html>