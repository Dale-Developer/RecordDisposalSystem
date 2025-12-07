<?php
// Get user information from session
$first_name = $_SESSION['first_name'] ?? 'User';
$last_name = $_SESSION['last_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'staff'; // Default to staff if not set
$role_id = $_SESSION['role_id'] ?? 2; // Default to staff (2) if not set

// Debug: Check session values (remove this in production)
// error_log("Session role_id: " . ($_SESSION['role_id'] ?? 'not set'));
// error_log("Session role: " . ($_SESSION['role'] ?? 'not set'));

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Check if user is admin
$is_admin = ($role_id == 1);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>LSPU Records</title>
  <link rel="stylesheet" href="../styles/sidebar.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <style>
    /* Add style for role badge */
    .profile-icon small {
      font-size: 0.7rem;
      background: #4a6cf7;
      color: white;
      padding: 2px 6px;
      border-radius: 10px;
      margin-left: 5px;
    }
  </style>
</head>

<body>
  <div class="topbar">
    <div class="topbar-logo">
      <img src="../imgs/lspu.png" alt="LSPU Logo" />
      <h1>LSPU RECORDS</h1>
    </div>

    <div class="profile-nav">
      <a class="profile-icon" href="#">
        <i class="bx bx-user-circle"></i>
        <span><?php echo htmlspecialchars($first_name); ?></span>
        <small><?php echo $is_admin ? 'Admin' : 'Staff'; ?></small>
      </a>
      <div class="icon-nav">
        <li>
          <a class="nav-icon" href="../static/settings.php">
            <i class="bx bx-cog"></i>
          </a>
        </li>
      </div>
    </div>
  </div>

  <div class="sidebar">
    <div class="nav">
      <ul>
        <li>
          <a href="../static/dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>" data-page="dashboard">
            <i class="bx bxs-dashboard"></i>
            <span>Dashboard</span>
          </a>
        </li>
        <li>
          <a href="../static/record_management.php" class="<?php echo ($current_page == 'record_management.php') ? 'active' : ''; ?>" data-page="records">
            <i class="bx bx-folder-open"></i>
            <span>Record Management</span>
          </a>
        </li>
        <li>
          <a href="../static/request.php" class="<?php echo ($current_page == 'request.php') ? 'active' : ''; ?>" data-page="requests">
            <i class="bx bx-mail-send"></i>
            <span>Request</span>
          </a>
        </li>
        <li>
          <a href="../static/archived.php" class="<?php echo ($current_page == 'archived.php') ? 'active' : ''; ?>" data-page="archived">
            <i class="bx bxs-package"></i>
            <span>Archived</span>
          </a>
        </li>
        
        <!-- Only show Reports & Logs for Admin -->
        <?php if ($is_admin): ?>
        <li>
          <a href="../static/reports.php" class="<?php echo ($current_page == 'reports.php') ? 'active' : ''; ?>" data-page="reports">
            <i class="bx bxs-report"></i>
            <span>Report & Logs</span>
          </a>
        </li>
        <?php endif; ?>
      </ul>
    </div>
    <div class="logout">
      <a href="../logout.php">
        <i class="bx bx-log-out"></i>
        <span>Logout</span>
      </a>
    </div>
  </div>

  <script src="../scripts/sidebar.js"></script>
</body>

</html>