<?php
// settings.php
require_once '../session.php';
require_once '../db_connect.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    handlePostRequest($pdo);
    exit;
}

// Check if user is logged in
$user_id = $_SESSION['user_id'] ?? null;

// Fetch current user data
$current_user = null;
if ($user_id) {
    try {
        $stmt = $pdo->prepare("SELECT u.user_id, u.first_name, u.last_name, u.email, u.password_hash, 
                                      u.role_id, r.role_name, u.office_id, o.office_name 
                               FROM users u 
                               LEFT JOIN roles r ON u.role_id = r.role_id 
                               LEFT JOIN offices o ON u.office_id = o.office_id 
                               WHERE u.user_id = :id");
        $stmt->execute([':id' => $user_id]);
        $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $current_user = [
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@lspu.edu.ph',
            'role_name' => 'Administrator'
        ];
    }
}

// Fetch all users for manage account section (admin only)
$all_users = [];
if ($current_user && isset($current_user['role_name']) && $current_user['role_name'] === 'Admin') {
    try {
        $stmt = $pdo->prepare("SELECT u.user_id, u.first_name, u.last_name, u.email, r.role_name, 
                                      u.office_id, o.office_name, u.created_at 
                               FROM users u 
                               LEFT JOIN roles r ON u.role_id = r.role_id 
                               LEFT JOIN offices o ON u.office_id = o.office_id 
                               ORDER BY u.created_at DESC");
        $stmt->execute();
        $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
    }
}

// Get all offices for dropdown
$offices = [];
try {
    $stmt = $pdo->query("SELECT office_id, office_name FROM offices ORDER BY office_name");
    $offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

// Get all roles for dropdown
$roles = [];
try {
    $stmt = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_name");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

// Function to handle POST requests
function handlePostRequest($pdo) {
    header('Content-Type: application/json');
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }
    
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_profile':
                $response = updateProfile($pdo, $_SESSION['user_id']);
                break;
                
            case 'change_password':
                $response = changePassword($pdo, $_SESSION['user_id']);
                break;
                
            case 'create_user':
                $response = createUser($pdo, $_SESSION['user_id']);
                break;
                
            case 'update_user':
                $response = updateUser($pdo, $_SESSION['user_id']);
                break;
                
            case 'delete_user':
                $response = deleteUser($pdo, $_SESSION['user_id']);
                break;
                
            case 'get_user':
                $response = getUser($pdo, $_SESSION['user_id']);
                break;
                
            default:
                $response = ['success' => false, 'message' => 'Invalid action'];
        }
    } catch (PDOException $e) {
        error_log("Database error in settings: " . $e->getMessage());
        $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    } catch (Exception $e) {
        error_log("Error in settings: " . $e->getMessage());
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

function updateProfile($pdo, $current_user_id) {
    $user_id = $current_user_id;
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // Validate inputs
    if (empty($first_name) || empty($last_name) || empty($email)) {
        return ['success' => false, 'message' => 'All fields are required'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email format'];
    }

    // Check if email is already taken by another user
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :id");
    $stmt->execute([':email' => $email, ':id' => $user_id]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Email already taken'];
    }

    // Update user profile
    $stmt = $pdo->prepare("UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email WHERE user_id = :id");
    
    $success = $stmt->execute([
        ':first_name' => $first_name,
        ':last_name' => $last_name,
        ':email' => $email,
        ':id' => $user_id
    ]);

    if ($success) {
        return ['success' => true, 'message' => 'Profile updated successfully'];
    }

    return ['success' => false, 'message' => 'Failed to update profile'];
}

function changePassword($pdo, $current_user_id) {
    $user_id = $current_user_id;
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';

    if (empty($current_password) || empty($new_password)) {
        return ['success' => false, 'message' => 'All fields are required'];
    }

    if (strlen($new_password) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters long'];
    }

    // Get current password hash
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }

    if (!password_verify($current_password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Current password is incorrect'];
    }

    // Hash new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    // Update password
    $stmt = $pdo->prepare("UPDATE users SET password_hash = :password_hash WHERE user_id = :id");
    $success = $stmt->execute([
        ':password_hash' => $hashed_password,
        ':id' => $user_id
    ]);

    if ($success) {
        return ['success' => true, 'message' => 'Password changed successfully'];
    }

    return ['success' => false, 'message' => 'Failed to change password'];
}

function createUser($pdo, $current_user_id) {
    // Check if current user is admin
    $stmt = $pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = :id");
    $stmt->execute([':id' => $current_user_id]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_user || $current_user['role_name'] !== 'Admin') {
        return ['success' => false, 'message' => 'Unauthorized - Admin access required'];
    }

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role_id = $_POST['role_id'] ?? 2; // Default to Staff role
    $office_id = $_POST['office_id'] ?? null;
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate inputs
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($confirm_password)) {
        return ['success' => false, 'message' => 'All fields are required'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email format'];
    }

    if (strlen($password) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters long'];
    }

    if ($password !== $confirm_password) {
        return ['success' => false, 'message' => 'Passwords do not match'];
    }

    // Check if email already exists
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Email already registered'];
    }

    // Validate role_id exists
    $stmt = $pdo->prepare("SELECT role_id FROM roles WHERE role_id = :role_id");
    $stmt->execute([':role_id' => $role_id]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'message' => 'Invalid role selected'];
    }

    // Validate office_id if provided
    if (!empty($office_id)) {
        $stmt = $pdo->prepare("SELECT office_id FROM offices WHERE office_id = :office_id");
        $stmt->execute([':office_id' => $office_id]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => 'Invalid office selected'];
        }
    }

    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert new user - SIMPLIFIED to match register.php
    try {
        if (empty($office_id)) {
            $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password_hash, role_id, office_id) 
                                  VALUES (:first_name, :last_name, :email, :password_hash, :role_id, NULL)");
            $success = $stmt->execute([
                ':first_name' => $first_name,
                ':last_name' => $last_name,
                ':email' => $email,
                ':password_hash' => $hashed_password,
                ':role_id' => $role_id
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password_hash, role_id, office_id) 
                                  VALUES (:first_name, :last_name, :email, :password_hash, :role_id, :office_id)");
            $success = $stmt->execute([
                ':first_name' => $first_name,
                ':last_name' => $last_name,
                ':email' => $email,
                ':password_hash' => $hashed_password,
                ':role_id' => $role_id,
                ':office_id' => $office_id
            ]);
        }

        if ($success) {
            $new_user_id = $pdo->lastInsertId();
            return [
                'success' => true, 
                'message' => 'User created successfully',
                'user_id' => $new_user_id
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to create user'];
        }
    } catch (PDOException $e) {
        error_log("Create user error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateUser($pdo, $current_user_id) {
    // Check if current user is admin
    $stmt = $pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = :id");
    $stmt->execute([':id' => $current_user_id]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_user || $current_user['role_name'] !== 'Admin') {
        return ['success' => false, 'message' => 'Unauthorized - Admin access required'];
    }

    $user_id = $_POST['user_id'] ?? 0;
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role_id = $_POST['role_id'] ?? 2;
    $office_id = $_POST['office_id'] ?? null;

    // Validate inputs
    if (empty($user_id) || empty($first_name) || empty($last_name) || empty($email)) {
        return ['success' => false, 'message' => 'All required fields must be filled'];
    }

    // Check if email is already taken by another user
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :id");
    $stmt->execute([':email' => $email, ':id' => $user_id]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Email already taken'];
    }

    // Update user
    try {
        if (empty($office_id)) {
            $stmt = $pdo->prepare("UPDATE users SET first_name = :first_name, last_name = :last_name, 
                                  email = :email, role_id = :role_id, office_id = NULL 
                                  WHERE user_id = :id");
            $success = $stmt->execute([
                ':first_name' => $first_name,
                ':last_name' => $last_name,
                ':email' => $email,
                ':role_id' => $role_id,
                ':id' => $user_id
            ]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET first_name = :first_name, last_name = :last_name, 
                                  email = :email, role_id = :role_id, office_id = :office_id 
                                  WHERE user_id = :id");
            $success = $stmt->execute([
                ':first_name' => $first_name,
                ':last_name' => $last_name,
                ':email' => $email,
                ':role_id' => $role_id,
                ':office_id' => $office_id,
                ':id' => $user_id
            ]);
        }

        if ($success) {
            return ['success' => true, 'message' => 'User updated successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to update user'];
        }
    } catch (PDOException $e) {
        error_log("Update user error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteUser($pdo, $current_user_id) {
    // Check if current user is admin
    $stmt = $pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = :id");
    $stmt->execute([':id' => $current_user_id]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_user || $current_user['role_name'] !== 'Admin') {
        return ['success' => false, 'message' => 'Unauthorized - Admin access required'];
    }

    $user_id = $_POST['user_id'] ?? 0;

    // Prevent deleting own account
    if ($user_id == $current_user_id) {
        return ['success' => false, 'message' => 'Cannot delete your own account'];
    }

    // First get user email for logging
    $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }

    // Delete user
    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = :id");
    $success = $stmt->execute([':id' => $user_id]);

    if ($success) {
        return ['success' => true, 'message' => 'User deleted successfully'];
    }

    return ['success' => false, 'message' => 'Failed to delete user'];
}

function getUser($pdo, $current_user_id) {
    // Check if current user is admin
    $stmt = $pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = :id");
    $stmt->execute([':id' => $current_user_id]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_user || $current_user['role_name'] !== 'Admin') {
        return ['success' => false, 'message' => 'Unauthorized - Admin access required'];
    }

    $user_id = $_POST['user_id'] ?? 0;

    $stmt = $pdo->prepare("SELECT u.user_id, u.first_name, u.last_name, u.email, u.role_id, u.office_id 
                          FROM users u WHERE u.user_id = :id");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        return ['success' => true, 'user' => $user];
    }

    return ['success' => false, 'message' => 'User not found'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/x-icon" href="../imgs/fav.png">
  <title>Settings - LSPU RECORDS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../styles/settings.css">
  <style>
    /* Modal Styles */
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        display: none;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.3s ease;
    }

    .modal-content {
        background: white;
        border-radius: var(--radius-lg);
        width: 90%;
        max-width: 500px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: var(--shadow-lg);
        animation: slideIn 0.3s ease;
    }

    .modal-header {
        padding: 24px;
        border-bottom: 1px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-header h3 {
        margin: 0;
        color: var(--dark);
        font-size: 20px;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 24px;
        color: var(--gray-500);
        cursor: pointer;
        padding: 0;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .modal-body {
        padding: 24px;
    }

    .modal-footer {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        padding: 20px 24px;
        border-top: 1px solid var(--gray-200);
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Notification styles */
    .notification {
        display: none;
        padding: 12px 16px;
        margin: 20px 0;
        border-radius: var(--radius-sm);
        font-weight: 500;
        animation: slideDown 0.3s ease;
    }
    
    .notification.success {
        background: linear-gradient(135deg, rgba(67, 97, 238, 0.1) 0%, rgba(67, 97, 238, 0.05) 100%);
        border: 1px solid rgba(67, 97, 238, 0.2);
        color: var(--primary);
    }
    
    .notification.error {
        background: linear-gradient(135deg, rgba(239, 71, 111, 0.1) 0%, rgba(239, 71, 111, 0.05) 100%);
        border: 1px solid rgba(239, 71, 111, 0.2);
        color: var(--danger);
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Password strength indicator */
    .password-strength {
        height: 4px;
        border-radius: 2px;
        margin-top: 8px;
        transition: all 0.3s ease;
    }
    
    .password-strength.weak {
        width: 33%;
        background-color: var(--danger);
    }
    
    .password-strength.medium {
        width: 66%;
        background-color: var(--warning);
    }
    
    .password-strength.strong {
        width: 100%;
        background-color: var(--success);
    }
    
    .password-hint {
        display: none;
        font-size: 12px;
        color: var(--gray-600);
        margin-top: 5px;
    }
    
    .password-hint.show {
        display: block;
    }
  </style>
</head>
<body>
  <div class="settings-page">
    <nav class="sidebar">
      <?php include 'sidebar.php'; ?>
    </nav>
    
    <div class="settings-container">
      <div class="settings-content">
        <!-- Header -->
        <div class="settings-header">
          <h1>LSPU RECORDS</h1>
          <p class="subtitle">Account Settings - Your Profile & System Management Dashboard</p>
        </div>
        
        <!-- Main Content -->
        <div class="settings-main">
          <!-- Left Navigation -->
          <nav class="settings-nav">
            <div class="nav-section">
              <h3 class="nav-section-title"></h3>
              <div class="nav-item active" data-tab="account">
                <i class="fas fa-user-cog"></i>
                <span>My Account</span>
              </div>
              <?php if (isset($current_user['role_name']) && $current_user['role_name'] === 'Admin'): ?>
              <div class="nav-item" data-tab="manage-account">
                <i class="fas fa-users-cog"></i>
                <span>User Management</span>
              </div>
              <?php endif; ?>
              <!-- <div class="nav-item" data-tab="security">
                <i class="fas fa-shield-alt"></i>
                <span>Security</span>
              </div> -->
            </div>
          </nav>
          
          <!-- Right Content -->
          <div class="settings-details">
            <!-- Account Tab -->
            <div id="account-tab" class="tab-content active">
              <!-- Profile Info -->
              <div class="profile-section">
                <div class="profile-avatar">
                  <?php 
                  $initials = '??';
                  if (isset($current_user['first_name']) && isset($current_user['last_name'])) {
                      $initials = substr($current_user['first_name'], 0, 1) . substr($current_user['last_name'], 0, 1);
                  }
                  echo $initials;
                  ?>
                </div>
                <div class="profile-info">
                  <h2>
                    <?php echo htmlspecialchars(($current_user['first_name'] ?? '') . ' ' . ($current_user['last_name'] ?? '')); ?>
                  </h2>
                  <span class="profile-role">
                    <i class="fas fa-crown"></i>
                    <?php echo htmlspecialchars($current_user['role_name'] ?? 'User'); ?>
                  </span>
                  <?php if (!empty($current_user['office_name'])): ?>
                  <span class="profile-office">
                    <i class="fas fa-building"></i>
                    <?php echo htmlspecialchars($current_user['office_name']); ?>
                  </span>
                  <?php endif; ?>
                  <p class="profile-email">
                    <i class="fas fa-envelope"></i>
                    <a href="mailto:<?php echo htmlspecialchars($current_user['email'] ?? ''); ?>">
                      <?php echo htmlspecialchars($current_user['email'] ?? ''); ?>
                    </a>
                  </p>
                </div>
              </div>
              
              <!-- Personal Information Form -->
              <h3 class="form-section-title">Personal Information</h3>
              <form id="profileForm">
                <div class="form-grid">
                  <!-- Row 1 -->
                  <div class="form-group">
                    <label class="form-label">
                      <i class="fas fa-user"></i>
                      First Name
                    </label>
                    <input type="text" class="form-input" id="firstName" value="<?php echo htmlspecialchars($current_user['first_name'] ?? ''); ?>" disabled>
                  </div>
                  
                  <div class="form-group">
                    <label class="form-label">
                      <i class="fas fa-envelope"></i>
                      Email Address
                    </label>
                    <input type="email" class="form-input" id="email" value="<?php echo htmlspecialchars($current_user['email'] ?? ''); ?>" disabled>
                  </div>
                  
                  <!-- Row 2 -->
                  <div class="form-group">
                    <label class="form-label">
                      <i class="fas fa-user"></i>
                      Last Name
                    </label>
                    <input type="text" class="form-input" id="lastName" value="<?php echo htmlspecialchars($current_user['last_name'] ?? ''); ?>" disabled>
                  </div>
                  
                  <div class="form-group">
                    <label class="form-label">
                      <i class="fas fa-building"></i>
                      Office
                    </label>
                    <input type="text" class="form-input" id="office" value="<?php echo htmlspecialchars($current_user['office_name'] ?? 'Not assigned'); ?>" disabled>
                  </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="form-actions">
                  <button type="submit" id="saveBtn" class="btn btn-primary" disabled>
                    <i class="fas fa-save"></i>
                    Save Changes
                  </button>
                  <button type="button" id="cancelBtn" class="btn btn-secondary" style="display: none;">
                    <i class="fas fa-times"></i>
                    Cancel
                  </button>
                  <button type="button" id="editBtn" class="btn btn-primary">
                    <i class="fas fa-edit"></i>
                    Edit Information
                  </button>
                </div>
              </form>

              <!-- Change Password Section -->
              <div class="section-divider"></div>
              
              <h3 class="form-section-title">Change Password</h3>
              <form id="changePasswordForm">
                <div class="form-grid">
                  <div class="form-group">
                    <label class="form-label">
                      <i class="fas fa-lock"></i>
                      Current Password
                    </label>
                    <div class="input-wrapper">
                      <input type="password" class="form-input" id="currentPassword" placeholder="Enter current password" required>
                      <button type="button" class="password-toggle" data-target="currentPassword">
                        <i class="fas fa-eye"></i>
                      </button>
                    </div>
                  </div>
                  
                  <div class="form-group">
                    <label class="form-label">
                      <i class="fas fa-key"></i>
                      New Password
                    </label>
                    <div class="input-wrapper">
                      <input type="password" class="form-input" id="newPassword" placeholder="Enter new password" required>
                      <button type="button" class="password-toggle" data-target="newPassword">
                        <i class="fas fa-eye"></i>
                      </button>
                    </div>
                    <div class="password-strength-container">
                      <div class="password-hint" id="passwordHint">
                        <i class="fas fa-info-circle"></i>
                        Minimum 6 characters with letters and numbers
                      </div>
                      <div class="password-strength" id="passwordStrength"></div>
                    </div>
                  </div>
                  
                  <div class="form-group">
                    <label class="form-label">
                      <i class="fas fa-key"></i>
                      Confirm New Password
                    </label>
                    <div class="input-wrapper">
                      <input type="password" class="form-input" id="confirmPassword" placeholder="Confirm new password" required>
                      <button type="button" class="password-toggle" data-target="confirmPassword">
                        <i class="fas fa-eye"></i>
                      </button>
                    </div>
                  </div>
                </div>
                
                <div class="form-actions">
                  <button type="submit" id="changePasswordBtn" class="btn btn-primary">
                    <i class="fas fa-key"></i>
                    Update Password
                  </button>
                </div>
              </form>
              
              <!-- Notification -->
              <div id="notification" class="notification"></div>
            </div>

            <!-- Manage Account Tab (Admin Only) -->
            <?php if (isset($current_user['role_name']) && $current_user['role_name'] === 'Admin'): ?>
            <div id="manage-account-tab" class="tab-content">
              <h3 class="form-section-title">User Management</h3>
              
              <!-- Stats Cards -->
              <?php if (!empty($all_users)): ?>
                <?php
                $total_users = count($all_users);
                $admin_users = count(array_filter($all_users, fn($user) => isset($user['role_name']) && $user['role_name'] === 'Admin'));
                $staff_users = $total_users - $admin_users;
                ?>
                <div class="stats-grid">
                  <div class="stat-card">
                    <div class="stat-icon users">
                      <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_users; ?></div>
                    <div class="stat-label">Total Users</div>
                  </div>
                  
                  <div class="stat-card">
                    <div class="stat-icon active-users">
                      <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $staff_users; ?></div>
                    <div class="stat-label">Staff Users</div>
                  </div>
                  
                  <div class="stat-card">
                    <div class="stat-icon admins">
                      <i class="fas fa-crown"></i>
                    </div>
                    <div class="stat-value"><?php echo $admin_users; ?></div>
                    <div class="stat-label">Administrators</div>
                  </div>
                </div>
              <?php endif; ?>
              
              <div class="create-user-btn">
                <button type="button" id="createUserBtn" class="btn btn-success">
                  <i class="fas fa-user-plus"></i>
                  Create New User
                </button>
              </div>
              
              <?php if (!empty($all_users)): ?>
                <div class="users-table-container">
                  <table class="users-table">
                    <thead>
                      <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Office</th>
                        <th>Joined</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody id="usersTableBody">
                      <?php foreach ($all_users as $user): ?>
                        <tr data-user-id="<?php echo $user['user_id']; ?>">
                          <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                              <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                                <?php echo substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1); ?>
                              </div>
                              <div>
                                <div style="font-weight: 600; color: var(--dark);">
                                  <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                </div>
                                <div style="font-size: 13px; color: var(--gray-600);">
                                  <?php echo htmlspecialchars($user['email']); ?>
                                </div>
                              </div>
                            </div>
                          </td>
                          <td><span class="role-badge <?php echo strtolower($user['role_name'] ?? 'user'); ?>">
                            <i class="fas fa-<?php echo (isset($user['role_name']) && $user['role_name'] === 'Admin') ? 'crown' : 'user'; ?>"></i>
                            <?php echo $user['role_name'] ?? 'User'; ?>
                          </span></td>
                          <td style="color: var(--gray-600);">
                            <?php echo htmlspecialchars($user['office_name'] ?? 'Not assigned'); ?>
                          </td>
                          <td style="color: var(--gray-600);">
                            <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                          </td>
                          <td>
                            <div class="action-buttons">
                              <button class="action-btn edit" onclick="editUser(<?php echo $user['user_id']; ?>)">
                                <i class="fas fa-edit"></i> Edit
                              </button>
                              <button class="action-btn delete" onclick="showDeleteModal(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')">
                                <i class="fas fa-trash"></i> Delete
                              </button>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="empty-state">
                  <i class="fas fa-users"></i>
                  <h3>No Users Found</h3>
                  <p>Click "Create New User" to add users to the system</p>
                </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Security Tab -->
            <div id="security-tab" class="tab-content">
              <h3 class="form-section-title">Security Settings</h3>
              
              <div class="form-grid">
                <div class="form-group">
                  <div class="stat-card" style="padding: 32px;">
                    <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 24px;">
                      <div style="width: 60px; height: 60px; border-radius: 12px; background: linear-gradient(135deg, rgba(67, 97, 238, 0.15) 0%, rgba(67, 97, 238, 0.05) 100%); display: flex; align-items: center; justify-content: center; font-size: 24px; color: var(--primary);">
                        <i class="fas fa-user-lock"></i>
                      </div>
                      <div>
                        <div style="font-size: 18px; font-weight: 700; color: var(--dark); margin-bottom: 4px;">
                          Account Security
                        </div>
                        <div style="font-size: 14px; color: var(--gray-600);">
                          Manage your account security settings
                        </div>
                      </div>
                    </div>
                    <div style="margin-top: 20px;">
                      <a href="#change-password" class="btn btn-primary" style="width: 100%;" onclick="showPasswordSection()">
                        <i class="fas fa-key"></i>
                        Change Password
                      </a>
                    </div>
                  </div>
                </div>
                
                <div class="form-group">
                  <div class="stat-card" style="padding: 32px;">
                    <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 24px;">
                      <div style="width: 60px; height: 60px; border-radius: 12px; background: linear-gradient(135deg, rgba(76, 201, 240, 0.15) 0%, rgba(76, 201, 240, 0.05) 100%); display: flex; align-items: center; justify-content: center; font-size: 24px; color: var(--success);">
                        <i class="fas fa-history"></i>
                      </div>
                      <div>
                        <div style="font-size: 18px; font-weight: 700; color: var(--dark); margin-bottom: 4px;">
                          Activity Logs
                        </div>
                        <div style="font-size: 14px; color: var(--gray-600);">
                          View your account activity
                        </div>
                      </div>
                    </div>
                    <button type="button" id="viewActivityLogs" class="btn btn-secondary" style="width: 100%;" onclick="viewActivityLogs()">
                      <i class="fas fa-list"></i>
                      View Activity Logs
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Create User Modal -->
  <div id="createUserModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Create New User</h3>
        <button type="button" class="modal-close" onclick="closeModal('createUserModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="createUserForm">
          <div class="form-grid" style="grid-template-columns: 1fr; gap: 20px;">
            <div class="form-group">
              <label class="form-label">First Name *</label>
              <input type="text" class="form-input" id="newFirstName" required>
            </div>
            
            <div class="form-group">
              <label class="form-label">Last Name *</label>
              <input type="text" class="form-input" id="newLastName" required>
            </div>
            
            <div class="form-group">
              <label class="form-label">Email Address *</label>
              <input type="email" class="form-input" id="newEmail" required>
            </div>
            
            <div class="form-group">
              <label class="form-label">Role *</label>
              <select class="form-input" id="newRoleId" required>
                <option value="">Select Role</option>
                <?php foreach ($roles as $role): ?>
                  <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="form-group">
              <label class="form-label">Office</label>
              <select class="form-input" id="newOfficeId">
                <option value="">Select Office (Optional)</option>
                <?php foreach ($offices as $office): ?>
                  <option value="<?php echo $office['office_id']; ?>"><?php echo htmlspecialchars($office['office_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="form-group">
              <label class="form-label">Password *</label>
              <div class="input-wrapper">
                <input type="password" class="form-input" id="newUserPassword" required>
                <button type="button" class="password-toggle" data-target="newUserPassword">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
              <small style="color: var(--gray-600); margin-top: 5px; display: block;">Minimum 6 characters</small>
            </div>
            
            <div class="form-group">
              <label class="form-label">Confirm Password *</label>
              <div class="input-wrapper">
                <input type="password" class="form-input" id="newUserConfirmPassword" required>
                <button type="button" class="password-toggle" data-target="newUserConfirmPassword">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('createUserModal')">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="submitCreateUserForm()">Create User</button>
      </div>
    </div>
  </div>

  <!-- Edit User Modal -->
  <div id="editUserModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Edit User</h3>
        <button type="button" class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="editUserForm">
          <input type="hidden" id="editUserId">
          <div class="form-grid" style="grid-template-columns: 1fr; gap: 20px;">
            <div class="form-group">
              <label class="form-label">First Name *</label>
              <input type="text" class="form-input" id="editFirstName" required>
            </div>
            
            <div class="form-group">
              <label class="form-label">Last Name *</label>
              <input type="text" class="form-input" id="editLastName" required>
            </div>
            
            <div class="form-group">
              <label class="form-label">Email Address *</label>
              <input type="email" class="form-input" id="editEmail" required>
            </div>
            
            <div class="form-group">
              <label class="form-label">Role *</label>
              <select class="form-input" id="editRoleId" required>
                <option value="">Select Role</option>
                <?php foreach ($roles as $role): ?>
                  <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="form-group">
              <label class="form-label">Office</label>
              <select class="form-input" id="editOfficeId">
                <option value="">Select Office (Optional)</option>
                <?php foreach ($offices as $office): ?>
                  <option value="<?php echo $office['office_id']; ?>"><?php echo htmlspecialchars($office['office_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="submitEditUserForm()">Update User</button>
      </div>
    </div>
  </div>

  <!-- Delete User Modal -->
  <div id="deleteUserModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 style="color: var(--danger);"><i class="fas fa-exclamation-triangle"></i> Delete User</h3>
        <button type="button" class="modal-close" onclick="closeModal('deleteUserModal')">&times;</button>
      </div>
      <div class="modal-body">
        <p style="color: var(--gray-700); margin-bottom: 16px;">Are you sure you want to delete <strong id="deleteUserName"></strong>?</p>
        <p style="font-size: 14px; color: var(--danger); background: linear-gradient(135deg, rgba(239, 71, 111, 0.1) 0%, rgba(239, 71, 111, 0.05) 100%); padding: 12px; border-radius: var(--radius-sm); border: 1px solid rgba(239, 71, 111, 0.2);">
          <i class="fas fa-exclamation-circle"></i>
          This action cannot be undone. All user data will be permanently deleted.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('deleteUserModal')">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete User</button>
      </div>
    </div>
  </div>

  <script>
// Global variables
let originalValues = {};
let userToDelete = null;

document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM Loaded - Initializing settings page');
    
    // Initialize original values
    originalValues = {
        firstName: document.getElementById('firstName').value,
        lastName: document.getElementById('lastName').value,
        email: document.getElementById('email').value
    };
    
    // Navigation click handler
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            
            // Update navigation
            document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
            this.classList.add('active');
            
            // Show selected tab
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            const targetTab = document.getElementById(`${tabId}-tab`);
            if (targetTab) {
                targetTab.classList.add('active');
            }
        });
    });
    
    // Edit button click handler
    const editBtn = document.getElementById('editBtn');
    if (editBtn) {
        editBtn.addEventListener('click', function() {
            console.log('Edit button clicked');
            enableFormEditing();
            editBtn.style.display = 'none';
            document.getElementById('cancelBtn').style.display = 'flex';
            document.getElementById('saveBtn').disabled = false;
            document.getElementById('firstName').focus();
            showNotification('You can now edit your profile information.', 'success');
        });
    }
    
    // Cancel button click handler
    const cancelBtn = document.getElementById('cancelBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            console.log('Cancel button clicked');
            resetForm();
            disableFormEditing();
            document.getElementById('editBtn').style.display = 'flex';
            cancelBtn.style.display = 'none';
            document.getElementById('saveBtn').disabled = true;
            showNotification('Changes discarded.', 'success');
        });
    }
    
    // Profile form submission handler
    const profileForm = document.getElementById('profileForm');
    if (profileForm) {
        profileForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            console.log('Profile form submitted');
            
            if (!validateProfileForm()) {
                return;
            }
            
            // Show loading state
            const saveBtn = document.getElementById('saveBtn');
            const originalSaveText = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            saveBtn.disabled = true;
            cancelBtn.disabled = true;
            
            try {
                // Prepare data
                const formData = new FormData();
                formData.append('action', 'update_profile');
                formData.append('first_name', document.getElementById('firstName').value.trim());
                formData.append('last_name', document.getElementById('lastName').value.trim());
                formData.append('email', document.getElementById('email').value.trim());
                
                // Send AJAX request
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                // Parse response carefully
                const text = await response.text();
                console.log('Raw response:', text);
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Failed to parse JSON:', text);
                    throw new Error('Invalid server response');
                }
                
                console.log('Profile update response:', data);
                
                if (data.success) {
                    // Update profile display immediately
                    updateProfileDisplay(
                        document.getElementById('firstName').value,
                        document.getElementById('lastName').value,
                        document.getElementById('email').value
                    );
                    
                    // Update original values
                    originalValues.firstName = document.getElementById('firstName').value;
                    originalValues.lastName = document.getElementById('lastName').value;
                    originalValues.email = document.getElementById('email').value;
                    
                    // Disable form and reset buttons
                    disableFormEditing();
                    document.getElementById('editBtn').style.display = 'flex';
                    cancelBtn.style.display = 'none';
                    cancelBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
                    saveBtn.disabled = true;
                    
                    showNotification(data.message, 'success');
                } else {
                    showNotification(data.message, 'error');
                    saveBtn.disabled = false;
                    cancelBtn.disabled = false;
                    saveBtn.innerHTML = originalSaveText;
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('An error occurred. Please try again.', 'error');
                saveBtn.disabled = false;
                cancelBtn.disabled = false;
                saveBtn.innerHTML = originalSaveText;
            }
        });
    }
    
    // Password change form submission
    const changePasswordForm = document.getElementById('changePasswordForm');
    if (changePasswordForm) {
        changePasswordForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            console.log('Password form submitted');
            
            const currentPassword = document.getElementById('currentPassword').value;
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            
            if (newPassword !== confirmPassword) {
                showNotification('New passwords do not match.', 'error');
                return;
            }
            
            if (newPassword.length < 6) {
                showNotification('Password must be at least 6 characters long.', 'error');
                return;
            }
            
            const changePasswordBtn = document.getElementById('changePasswordBtn');
            const originalBtnText = changePasswordBtn.innerHTML;
            changePasswordBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            changePasswordBtn.disabled = true;
            
            try {
                // Prepare data
                const formData = new FormData();
                formData.append('action', 'change_password');
                formData.append('current_password', currentPassword);
                formData.append('new_password', newPassword);
                
                // Send AJAX request
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                // Parse response carefully
                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Failed to parse JSON:', text);
                    throw new Error('Invalid server response');
                }
                
                console.log('Password change response:', data);
                
                if (data.success) {
                    changePasswordForm.reset();
                    document.getElementById('passwordStrength').className = 'password-strength';
                    document.getElementById('passwordHint').classList.remove('show');
                    showNotification(data.message, 'success');
                } else {
                    showNotification(data.message, 'error');
                }
                
                changePasswordBtn.innerHTML = originalBtnText;
                changePasswordBtn.disabled = false;
            } catch (error) {
                console.error('Error:', error);
                showNotification('An error occurred. Please try again.', 'error');
                changePasswordBtn.innerHTML = originalBtnText;
                changePasswordBtn.disabled = false;
            }
        });
    }
    
    // New password strength checker
    const newPasswordInput = document.getElementById('newPassword');
    if (newPasswordInput) {
        newPasswordInput.addEventListener('input', function() {
            const password = this.value;
            const strength = checkPasswordStrength(password);
            
            document.getElementById('passwordStrength').className = 'password-strength';
            if (password.length > 0) {
                document.getElementById('passwordStrength').classList.add(strength);
                document.getElementById('passwordHint').classList.add('show');
            } else {
                document.getElementById('passwordHint').classList.remove('show');
            }
        });
    }
    
    // Create user button
    const createUserBtn = document.getElementById('createUserBtn');
    if (createUserBtn) {
        createUserBtn.addEventListener('click', function() {
            console.log('Create user button clicked');
            document.getElementById('createUserModal').style.display = 'flex';
        });
    }
    
    // Delete user confirmation
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function() {
            if (userToDelete) {
                console.log('Deleting user:', userToDelete);
                deleteUser(userToDelete.id);
            }
        });
    }
    
    // Password toggle functionality
    document.addEventListener('click', function(e) {
        if (e.target.closest('.password-toggle')) {
            const toggleBtn = e.target.closest('.password-toggle');
            const targetId = toggleBtn.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const icon = toggleBtn.querySelector('i');
            
            if (input && icon) {
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.className = 'fas fa-eye-slash';
                } else {
                    input.type = 'password';
                    icon.className = 'fas fa-eye';
                }
            }
        }
    });
    
    // View activity logs
    const viewActivityLogsBtn = document.getElementById('viewActivityLogs');
    if (viewActivityLogsBtn) {
        viewActivityLogsBtn.addEventListener('click', viewActivityLogs);
    }
    
    console.log('Settings page initialized successfully');
});

// Helper functions
function enableFormEditing() {
    const inputs = ['firstName', 'lastName', 'email'];
    inputs.forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.disabled = false;
            input.style.background = 'white';
        }
    });
}

function disableFormEditing() {
    const inputs = ['firstName', 'lastName', 'email'];
    inputs.forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.disabled = true;
            input.style.background = 'var(--gray-100)';
        }
    });
}

function resetForm() {
    document.getElementById('firstName').value = originalValues.firstName;
    document.getElementById('lastName').value = originalValues.lastName;
    document.getElementById('email').value = originalValues.email;
}

function validateProfileForm() {
    const firstName = document.getElementById('firstName').value.trim();
    const lastName = document.getElementById('lastName').value.trim();
    const email = document.getElementById('email').value.trim();
    
    if (!firstName || !lastName || !email) {
        showNotification('Please fill in all required fields.', 'error');
        return false;
    }
    
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showNotification('Please enter a valid email address.', 'error');
        document.getElementById('email').focus();
        return false;
    }
    
    return true;
}

function checkPasswordStrength(password) {
    let strength = 0;
    
    if (password.length >= 6) strength++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
    if (/\d/.test(password)) strength++;
    if (/[^A-Za-z0-9]/.test(password)) strength++;
    
    if (strength <= 1) return 'weak';
    if (strength <= 3) return 'medium';
    return 'strong';
}

// Update profile display without refresh
function updateProfileDisplay(firstName, lastName, email) {
    // Update profile name
    const profileName = document.querySelector('.profile-info h2');
    if (profileName) {
        profileName.textContent = `${firstName} ${lastName}`;
    }
    
    // Update profile avatar
    const profileAvatar = document.querySelector('.profile-avatar');
    if (profileAvatar) {
        profileAvatar.textContent = firstName.charAt(0) + lastName.charAt(0);
    }
    
    // Update profile email
    const profileEmail = document.querySelector('.profile-email a');
    if (profileEmail) {
        profileEmail.textContent = email;
        profileEmail.href = `mailto:${email}`;
    }
    
    // Update user in management table if visible
    const currentUserId = <?php echo json_encode($user_id ?? 0); ?>;
    const userRow = document.querySelector(`tr[data-user-id="${currentUserId}"]`);
    
    if (userRow) {
        // Update name in table
        const nameElement = userRow.querySelector('div > div:nth-child(2) > div:nth-child(1)');
        if (nameElement) {
            nameElement.textContent = `${firstName} ${lastName}`;
        }
        
        // Update email in table
        const emailElement = userRow.querySelector('div > div:nth-child(2) > div:nth-child(2)');
        if (emailElement) {
            emailElement.textContent = email;
        }
        
        // Update avatar initials
        const avatarElement = userRow.querySelector('div > div:nth-child(1)');
        if (avatarElement) {
            avatarElement.textContent = firstName.charAt(0) + lastName.charAt(0);
        }
    }
}

// Global functions for user management
window.editUser = async function(userId) {
    console.log('Editing user:', userId);
    
    // Fetch user data via AJAX
    const formData = new FormData();
    formData.append('action', 'get_user');
    formData.append('user_id', userId);
    
    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        // Parse response carefully
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Failed to parse JSON:', text);
            throw new Error('Invalid server response');
        }
        
        console.log('Get user response:', data);
        
        if (data.success) {
            document.getElementById('editUserId').value = data.user.user_id;
            document.getElementById('editFirstName').value = data.user.first_name || '';
            document.getElementById('editLastName').value = data.user.last_name || '';
            document.getElementById('editEmail').value = data.user.email || '';
            document.getElementById('editRoleId').value = data.user.role_id || '';
            document.getElementById('editOfficeId').value = data.user.office_id || '';
            
            document.getElementById('editUserModal').style.display = 'flex';
        } else {
            showNotification(data.message, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showNotification('Failed to fetch user data', 'error');
    }
};

window.submitCreateUserForm = async function() {
    const firstName = document.getElementById('newFirstName').value.trim();
    const lastName = document.getElementById('newLastName').value.trim();
    const email = document.getElementById('newEmail').value.trim();
    const roleId = document.getElementById('newRoleId').value;
    const officeId = document.getElementById('newOfficeId').value;
    const password = document.getElementById('newUserPassword').value;
    const confirmPassword = document.getElementById('newUserConfirmPassword').value;
    
    if (!firstName || !lastName || !email || !roleId || !password || !confirmPassword) {
        showNotification('All required fields must be filled.', 'error');
        return;
    }
    
    if (password.length < 6) {
        showNotification('Password must be at least 6 characters long.', 'error');
        return;
    }
    
    if (password !== confirmPassword) {
        showNotification('Passwords do not match.', 'error');
        return;
    }
    
    const submitBtn = document.querySelector('#createUserModal .btn-primary');
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
    submitBtn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action', 'create_user');
        formData.append('first_name', firstName);
        formData.append('last_name', lastName);
        formData.append('email', email);
        formData.append('role_id', roleId);
        formData.append('office_id', officeId);
        formData.append('password', password);
        formData.append('confirm_password', confirmPassword);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        // Parse response carefully
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Failed to parse JSON:', text);
            throw new Error('Invalid server response');
        }
        
        console.log('Create user response:', data);
        
        if (data.success) {
            // Add user to table without refresh
            addUserToTable({
                user_id: data.user_id || Date.now(),
                first_name: firstName,
                last_name: lastName,
                email: email,
                role_id: roleId,
                office_id: officeId,
                role_name: getRoleName(roleId),
                office_name: getOfficeName(officeId),
                created_at: new Date().toISOString().split('T')[0]
            });
            
            // Close modal and reset form
            closeModal('createUserModal');
            document.getElementById('createUserForm').reset();
            
            // Show success message
            showNotification(data.message, 'success');
            
            // Update stats
            updateUserStats();
            
            // Reset button
            submitBtn.innerHTML = originalBtnText;
            submitBtn.disabled = false;
        } else {
            showNotification(data.message, 'error');
            submitBtn.innerHTML = originalBtnText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Error:', error);
        showNotification('An error occurred. Please try again.', 'error');
        submitBtn.innerHTML = originalBtnText;
        submitBtn.disabled = false;
    }
};

window.submitEditUserForm = async function() {
    const userId = document.getElementById('editUserId').value;
    const firstName = document.getElementById('editFirstName').value.trim();
    const lastName = document.getElementById('editLastName').value.trim();
    const email = document.getElementById('editEmail').value.trim();
    const roleId = document.getElementById('editRoleId').value;
    const officeId = document.getElementById('editOfficeId').value;
    
    if (!userId || !firstName || !lastName || !email || !roleId) {
        showNotification('All required fields must be filled.', 'error');
        return;
    }
    
    const submitBtn = document.querySelector('#editUserModal .btn-primary');
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    submitBtn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_user');
        formData.append('user_id', userId);
        formData.append('first_name', firstName);
        formData.append('last_name', lastName);
        formData.append('email', email);
        formData.append('role_id', roleId);
        formData.append('office_id', officeId);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        // Parse response carefully
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Failed to parse JSON:', text);
            throw new Error('Invalid server response');
        }
        
        console.log('Update user response:', data);
        
        if (data.success) {
            // Update user in table without refresh
            updateUserInTable({
                user_id: userId,
                first_name: firstName,
                last_name: lastName,
                email: email,
                role_id: roleId,
                office_id: officeId,
                role_name: getRoleName(roleId),
                office_name: getOfficeName(officeId)
            });
            
            // Close modal
            closeModal('editUserModal');
            
            // Show success message
            showNotification(data.message, 'success');
            
            // Reset button
            submitBtn.innerHTML = originalBtnText;
            submitBtn.disabled = false;
        } else {
            showNotification(data.message, 'error');
            submitBtn.innerHTML = originalBtnText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Error:', error);
        showNotification('An error occurred. Please try again.', 'error');
        submitBtn.innerHTML = originalBtnText;
        submitBtn.disabled = false;
    }
};

window.showDeleteModal = function(userId, userName) {
    console.log('Showing delete modal for user:', userId, userName);
    userToDelete = { id: userId, name: userName };
    document.getElementById('deleteUserName').textContent = userName;
    document.getElementById('deleteUserModal').style.display = 'flex';
};

window.closeModal = function(modalId) {
    console.log('Closing modal:', modalId);
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
    userToDelete = null;
};

window.deleteUser = async function(userId) {
    console.log('Deleting user:', userId);
    const deleteBtn = document.getElementById('confirmDeleteBtn');
    const originalBtnText = deleteBtn.innerHTML;
    deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    deleteBtn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_user');
        formData.append('user_id', userId);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        // Parse response carefully
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Failed to parse JSON:', text);
            throw new Error('Invalid server response');
        }
        
        console.log('Delete user response:', data);
        
        if (data.success) {
            // Remove user row from table with animation
            const row = document.querySelector(`tr[data-user-id="${userId}"]`);
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-100px)';
                
                setTimeout(() => {
                    row.remove();
                    
                    // Check if table is empty
                    const tableBody = document.getElementById('usersTableBody');
                    const rows = tableBody.querySelectorAll('tr[data-user-id]');
                    
                    if (rows.length === 0) {
                        // Show empty state
                        tableBody.innerHTML = `
                            <tr class="empty-state">
                                <td colspan="5" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-users" style="font-size: 48px; color: #dee2e6; margin-bottom: 16px;"></i>
                                    <h3 style="color: #6c757d; margin-bottom: 8px;">No Users Found</h3>
                                    <p style="color: #adb5bd;">Click "Create New User" to add users to the system</p>
                                </td>
                            </tr>
                        `;
                    }
                    
                    // Update stats
                    updateUserStats();
                }, 300);
            }
            
            // Close modal
            closeModal('deleteUserModal');
            
            // Show success message
            showNotification(data.message, 'success');
            
            // Reset button
            deleteBtn.innerHTML = originalBtnText;
            deleteBtn.disabled = false;
        } else {
            showNotification(data.message, 'error');
            deleteBtn.innerHTML = originalBtnText;
            deleteBtn.disabled = false;
        }
    } catch (error) {
        console.error('Error:', error);
        showNotification('An error occurred. Please try again.', 'error');
        deleteBtn.innerHTML = originalBtnText;
        deleteBtn.disabled = false;
    }
};

// Helper function to add user to table
function addUserToTable(userData) {
    const tableBody = document.getElementById('usersTableBody');
    if (!tableBody) return;
    
    // Remove empty state if it exists
    const emptyState = tableBody.querySelector('.empty-state');
    if (emptyState) {
        emptyState.remove();
    }
    
    // Create new row
    const newRow = document.createElement('tr');
    newRow.setAttribute('data-user-id', userData.user_id);
    
    // Get office name and role name
    const officeName = userData.office_name || 'Not assigned';
    const roleName = userData.role_name || 'User';
    const isAdmin = roleName === 'Admin';
    
    // Format date
    const joinDate = new Date(userData.created_at).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
    
    newRow.innerHTML = `
        <td>
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                    ${userData.first_name.charAt(0)}${userData.last_name.charAt(0)}
                </div>
                <div>
                    <div style="font-weight: 600; color: var(--dark);">
                        ${userData.first_name} ${userData.last_name}
                    </div>
                    <div style="font-size: 13px; color: var(--gray-600);">
                        ${userData.email}
                    </div>
                </div>
            </div>
        </td>
        <td><span class="role-badge ${roleName.toLowerCase()}">
            <i class="fas fa-${isAdmin ? 'crown' : 'user'}"></i>
            ${roleName}
        </span></td>
        <td style="color: var(--gray-600);">
            ${officeName}
        </td>
        <td style="color: var(--gray-600);">
            ${joinDate}
        </td>
        <td>
            <div class="action-buttons">
                <button class="action-btn edit" onclick="editUser(${userData.user_id})">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button class="action-btn delete" onclick="showDeleteModal(${userData.user_id}, '${userData.first_name} ${userData.last_name}')">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </td>
    `;
    
    // Add fade-in animation
    newRow.style.opacity = '0';
    newRow.style.transform = 'translateY(-20px)';
    
    // Add to top of table
    tableBody.insertBefore(newRow, tableBody.firstChild);
    
    // Animate in
    setTimeout(() => {
        newRow.style.transition = 'all 0.3s ease';
        newRow.style.opacity = '1';
        newRow.style.transform = 'translateY(0)';
    }, 10);
}

// Helper function to update user in table
function updateUserInTable(userData) {
    const userRow = document.querySelector(`tr[data-user-id="${userData.user_id}"]`);
    if (!userRow) return;
    
    const officeName = userData.office_name || 'Not assigned';
    const roleName = userData.role_name || 'User';
    const isAdmin = roleName === 'Admin';
    
    // Update name
    const nameElement = userRow.querySelector('div > div:nth-child(2) > div:nth-child(1)');
    if (nameElement) {
        nameElement.textContent = `${userData.first_name} ${userData.last_name}`;
    }
    
    // Update email
    const emailElement = userRow.querySelector('div > div:nth-child(2) > div:nth-child(2)');
    if (emailElement) {
        emailElement.textContent = userData.email;
    }
    
    // Update avatar initials
    const avatarElement = userRow.querySelector('div > div:nth-child(1)');
    if (avatarElement) {
        avatarElement.textContent = userData.first_name.charAt(0) + userData.last_name.charAt(0);
    }
    
    // Update role
    const roleElement = userRow.querySelector('.role-badge');
    if (roleElement) {
        roleElement.className = `role-badge ${roleName.toLowerCase()}`;
        roleElement.innerHTML = `<i class="fas fa-${isAdmin ? 'crown' : 'user'}"></i> ${roleName}`;
    }
    
    // Update office
    const officeCell = userRow.querySelector('td:nth-child(3)');
    if (officeCell) {
        officeCell.textContent = officeName;
    }
}

// Helper function to get role name
function getRoleName(roleId) {
    const roleSelect = document.getElementById('newRoleId') || document.getElementById('editRoleId');
    if (roleSelect) {
        const option = roleSelect.querySelector(`option[value="${roleId}"]`);
        if (option) {
            return option.textContent;
        }
    }
    return roleId == 1 ? 'Admin' : 'Staff';
}

// Helper function to get office name
function getOfficeName(officeId) {
    if (!officeId) return 'Not assigned';
    
    // Try to get office name from dropdown
    const officeSelect = document.getElementById('newOfficeId') || document.getElementById('editOfficeId');
    if (officeSelect) {
        const option = officeSelect.querySelector(`option[value="${officeId}"]`);
        if (option) {
            return option.textContent;
        }
    }
    
    return 'Office ' + officeId;
}

// Update user stats
function updateUserStats() {
    const tableBody = document.getElementById('usersTableBody');
    if (!tableBody) return;
    
    const rows = tableBody.querySelectorAll('tr[data-user-id]');
    const statsGrid = document.querySelector('.stats-grid');
    
    if (statsGrid) {
        const totalUsers = rows.length;
        const adminUsers = Array.from(rows).filter(row => {
            const roleBadge = row.querySelector('.role-badge');
            return roleBadge && roleBadge.textContent.includes('Admin');
        }).length;
        const staffUsers = totalUsers - adminUsers;
        
        // Update stats cards
        const statValues = document.querySelectorAll('.stat-value');
        if (statValues[0]) statValues[0].textContent = totalUsers;
        if (statValues[1]) statValues[1].textContent = staffUsers;
        if (statValues[2]) statValues[2].textContent = adminUsers;
    }
}

function showNotification(message, type) {
    const notification = document.getElementById('notification');
    if (notification) {
        notification.textContent = message;
        notification.className = `notification ${type}`;
        notification.style.display = 'block';
        
        setTimeout(() => {
            notification.style.display = 'none';
        }, 4000);
    } else {
        // Fallback alert if notification element doesn't exist
        alert(message);
    }
}

window.showPasswordSection = function() {
    // Switch to account tab and show password section
    const accountTab = document.querySelector('[data-tab="account"]');
    if (accountTab) {
        accountTab.click();
        setTimeout(() => {
            const passwordForm = document.getElementById('changePasswordForm');
            if (passwordForm) {
                passwordForm.scrollIntoView({ behavior: 'smooth' });
            }
        }, 100);
    }
};

window.viewActivityLogs = function() {
    showNotification('Activity logs feature coming soon!', 'info');
};
</script>
</body>
</html>