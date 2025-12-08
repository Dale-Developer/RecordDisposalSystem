<?php
require_once '../session.php';
require_once '../db_connect.php';

// Check if user is admin
$user_id = $_SESSION['user_id'] ?? null;

// Fetch current user data
$current_user = null;
if ($user_id) {
    try {
        $stmt = $pdo->prepare("SELECT first_name, last_name, email, phone, location, role FROM users WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log error but continue with default values
        error_log("Database error: " . $e->getMessage());
    }
}

// Fetch all users for manage account section (admin only)
$all_users = [];
if ($current_user && $current_user['role'] === 'admin') {
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, created_at, status FROM users ORDER BY created_at DESC");
        $stmt->execute();
        $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
    }
}

// Set default values if database fetch fails
if (!$current_user) {
    $current_user = [
        'first_name' => 'Lee',
        'last_name' => 'Sung-min',
        'email' => 'Leesung-min@gmail.com',
        'phone' => '+1 (555) 123-4567',
        'location' => 'Seoul, South Korea',
        'role' => 'Administrator'
    ];
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
              <h3 class="nav-section-title">SETTINGS</h3>
              <div class="nav-item active" data-tab="account">
                <i class="fas fa-user-cog"></i>
                <span>My Account</span>
              </div>
              <div class="nav-item" data-tab="manage-account">
                <i class="fas fa-users-cog"></i>
                <span>User Management</span>
              </div>
              <div class="nav-item" data-tab="security">
                <i class="fas fa-shield-alt"></i>
                <span>Security</span>
              </div>
              <div class="nav-item" data-tab="help">
                <i class="fas fa-question-circle"></i>
                <span>Help & Support</span>
              </div>
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
                  $initials = substr($current_user['first_name'], 0, 1) . substr($current_user['last_name'], 0, 1);
                  echo $initials;
                  ?>
                </div>
                <div class="profile-info">
                  <h2>
                    <?php echo htmlspecialchars($current_user['first_name'] . ' ' . $current_user['last_name']); ?>
                  </h2>
                  <span class="profile-role">
                    <i class="fas fa-crown"></i>
                    <?php echo htmlspecialchars(ucfirst($current_user['role'])); ?>
                  </span>
                  <p class="profile-email">
                    <i class="fas fa-envelope"></i>
                    <a href="mailto:<?php echo htmlspecialchars($current_user['email']); ?>">
                      <?php echo htmlspecialchars($current_user['email']); ?>
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
                    <input type="text" class="form-input" id="firstName" value="<?php echo htmlspecialchars($current_user['first_name']); ?>" disabled>
                  </div>
                  
                  <div class="form-group">
                    <label class="form-label">
                      <i class="fas fa-envelope"></i>
                      Email Address
                    </label>
                    <input type="email" class="form-input" id="email" value="<?php echo htmlspecialchars($current_user['email']); ?>" disabled>
                  </div>
                  
                  <!-- Row 2 -->
                  <div class="form-group">
                    <label class="form-label">
                      <i class="fas fa-user"></i>
                      Last Name
                    </label>
                    <input type="text" class="form-input" id="lastName" value="<?php echo htmlspecialchars($current_user['last_name']); ?>" disabled>
                  </div>
                  
                  <div class="form-group">
                    <label class="form-label">
                      <i class="fas fa-phone"></i>
                      Phone Number
                    </label>
                    <input type="tel" class="form-input" id="phone" value="<?php echo htmlspecialchars($current_user['phone']); ?>" disabled>
                  </div>
                  
                  <!-- Row 3 -->
                  <div class="form-group full-width">
                    <label class="form-label">
                      <i class="fas fa-map-marker-alt"></i>
                      Location
                    </label>
                    <input type="text" class="form-input" id="location" value="<?php echo htmlspecialchars($current_user['location']); ?>" disabled>
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
                        Minimum 8 characters with letters and numbers
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

            <!-- Manage Account Tab -->
            <div id="manage-account-tab" class="tab-content">
              <h3 class="form-section-title">User Management</h3>
              
              <!-- Stats Cards -->
              <?php if (!empty($all_users)): ?>
                <?php
                $total_users = count($all_users);
                $active_users = count(array_filter($all_users, fn($user) => $user['status'] === 'active'));
                $admin_users = count(array_filter($all_users, fn($user) => $user['role'] === 'admin'));
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
                    <div class="stat-value"><?php echo $active_users; ?></div>
                    <div class="stat-label">Active Users</div>
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
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody id="usersTableBody">
                      <?php foreach ($all_users as $user): ?>
                        <tr data-user-id="<?php echo $user['id']; ?>">
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
                          <td><span class="role-badge <?php echo $user['role']; ?>">
                            <i class="fas fa-<?php echo $user['role'] === 'admin' ? 'crown' : 'user'; ?>"></i>
                            <?php echo ucfirst($user['role']); ?>
                          </span></td>
                          <td><span class="status-badge <?php echo $user['status']; ?>">
                            <i class="fas fa-<?php echo $user['status'] === 'active' ? 'check-circle' : 'times-circle'; ?>"></i>
                            <?php echo ucfirst($user['status']); ?>
                          </span></td>
                          <td style="color: var(--gray-600);">
                            <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                          </td>
                          <td>
                            <div class="action-buttons">
                              <button class="action-btn edit" onclick="editUser(<?php echo $user['id']; ?>)">
                                <i class="fas fa-edit"></i> Edit
                              </button>
                              <button class="action-btn delete" onclick="showDeleteModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')">
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

            <!-- Security Tab -->
            <div id="security-tab" class="tab-content">
              <h3 class="form-section-title">Security Settings</h3>
              
              <div class="form-grid">
                <div class="form-group">
                  <div class="stat-card" style="padding: 32px;">
                    <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 24px;">
                      <div style="width: 60px; height: 60px; border-radius: 12px; background: linear-gradient(135deg, rgba(67, 97, 238, 0.15) 0%, rgba(67, 97, 238, 0.05) 100%); display: flex; align-items: center; justify-content: center; font-size: 24px; color: var(--primary);">
                        <i class="fas fa-shield-alt"></i>
                      </div>
                      <div>
                        <div style="font-size: 18px; font-weight: 700; color: var(--dark); margin-bottom: 4px;">
                          Two-Factor Authentication
                        </div>
                        <div style="font-size: 14px; color: var(--gray-600);">
                          Add an extra layer of security to your account
                        </div>
                      </div>
                    </div>
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                      <div style="position: relative;">
                        <input type="checkbox" id="twoFactorEnabled" style="display: none;">
                        <div style="width: 48px; height: 24px; background: var(--gray-300); border-radius: 12px; position: relative; transition: var(--transition);"></div>
                        <div style="position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; background: white; border-radius: 50%; transition: var(--transition); box-shadow: var(--shadow-sm);"></div>
                      </div>
                      <span style="font-weight: 600; color: var(--gray-700);">Enable 2FA</span>
                    </label>
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
                          Login History
                        </div>
                        <div style="font-size: 14px; color: var(--gray-600);">
                          View recent login activity
                        </div>
                      </div>
                    </div>
                    <button type="button" id="viewLoginHistory" class="btn btn-secondary" style="width: 100%;">
                      <i class="fas fa-list"></i>
                      View Login History
                    </button>
                  </div>
                </div>
              </div>
              
              <div class="form-actions">
                <button type="button" id="saveSecurityBtn" class="btn btn-primary">
                  <i class="fas fa-save"></i>
                  Save Security Settings
                </button>
              </div>
            </div>

            <!-- Help & Support Tab -->
            <div id="help-tab" class="tab-content">
              <h3 class="form-section-title">Help & Support</h3>
              
              <div class="form-grid">
                <div class="form-group">
                  <div class="stat-card" style="padding: 32px;">
                    <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 24px;">
                      <div style="width: 60px; height: 60px; border-radius: 12px; background: linear-gradient(135deg, rgba(239, 71, 111, 0.15) 0%, rgba(239, 71, 111, 0.05) 100%); display: flex; align-items: center; justify-content: center; font-size: 24px; color: var(--danger);">
                        <i class="fas fa-life-ring"></i>
                      </div>
                      <div>
                        <div style="font-size: 18px; font-weight: 700; color: var(--dark); margin-bottom: 4px;">
                          Get Help
                        </div>
                        <div style="font-size: 14px; color: var(--gray-600);">
                          Contact our support team for assistance
                        </div>
                      </div>
                    </div>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                      <button type="button" class="btn btn-secondary" style="flex: 1;">
                        <i class="fas fa-book"></i>
                        Documentation
                      </button>
                      <button type="button" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-comments"></i>
                        Live Chat
                      </button>
                    </div>
                  </div>
                </div>
                
                <div class="form-group">
                  <div class="stat-card" style="padding: 32px;">
                    <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 24px;">
                      <div style="width: 60px; height: 60px; border-radius: 12px; background: linear-gradient(135deg, rgba(255, 209, 102, 0.15) 0%, rgba(255, 209, 102, 0.05) 100%); display: flex; align-items: center; justify-content: center; font-size: 24px; color: var(--warning);">
                        <i class="fas fa-headset"></i>
                      </div>
                      <div>
                        <div style="font-size: 18px; font-weight: 700; color: var(--dark); margin-bottom: 4px;">
                          Contact Support
                        </div>
                        <div style="font-size: 14px; color: var(--gray-600);">
                          Submit a support request
                        </div>
                      </div>
                    </div>
                    <div style="font-size: 14px; color: var(--gray-600); margin-bottom: 20px;">
                      <p><i class="fas fa-envelope"></i> support@lspurecords.edu</p>
                      <p><i class="fas fa-phone"></i> (555) 123-4567</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modals -->
  <div id="createUserModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: var(--radius-lg); width: 90%; max-width: 500px; box-shadow: var(--shadow-lg); animation: modalSlideIn 0.3s ease;">
      <div style="padding: 24px; border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0; color: var(--dark); font-size: 20px;">Create New User</h3>
        <button onclick="closeModal('createUserModal')" style="background: none; border: none; font-size: 24px; color: var(--gray-500); cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">&times;</button>
      </div>
      <div style="padding: 24px;">
        <form id="createUserForm">
          <div class="form-grid" style="grid-template-columns: 1fr; gap: 20px;">
            <div class="form-group">
              <label class="form-label">First Name</label>
              <input type="text" class="form-input" id="newFirstName" required>
            </div>
            
            <div class="form-group">
              <label class="form-label">Last Name</label>
              <input type="text" class="form-input" id="newLastName" required>
            </div>
            
            <div class="form-group">
              <label class="form-label">Email Address</label>
              <input type="email" class="form-input" id="newEmail" required>
            </div>
            
            <div class="form-group">
              <label class="form-label">Role</label>
              <select class="form-input" id="newRole" required>
                <option value="user">User</option>
                <option value="admin">Administrator</option>
              </select>
            </div>
            
            <div class="form-group">
              <label class="form-label">Password</label>
              <div class="input-wrapper">
                <input type="password" class="form-input" id="newUserPassword" required>
                <button type="button" class="password-toggle" data-target="newUserPassword">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
          </div>
          
          <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--gray-200);">
            <button type="button" class="btn btn-secondary" onclick="closeModal('createUserModal')">Cancel</button>
            <button type="submit" class="btn btn-primary">Create User</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="deleteUserModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: var(--radius-lg); width: 90%; max-width: 400px; box-shadow: var(--shadow-lg); animation: modalSlideIn 0.3s ease;">
      <div style="padding: 24px; border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0; color: var(--danger); font-size: 20px;"><i class="fas fa-exclamation-triangle"></i> Delete User</h3>
        <button onclick="closeModal('deleteUserModal')" style="background: none; border: none; font-size: 24px; color: var(--gray-500); cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">&times;</button>
      </div>
      <div style="padding: 24px;">
        <p style="color: var(--gray-700); margin-bottom: 16px;">Are you sure you want to delete <strong id="deleteUserName"></strong>?</p>
        <p style="font-size: 14px; color: var(--danger); background: linear-gradient(135deg, rgba(239, 71, 111, 0.1) 0%, rgba(239, 71, 111, 0.05) 100%); padding: 12px; border-radius: var(--radius-sm); border: 1px solid rgba(239, 71, 111, 0.2);">
          <i class="fas fa-exclamation-circle"></i>
          This action cannot be undone. All user data will be permanently deleted.
        </p>
      </div>
      <div style="display: flex; gap: 12px; justify-content: flex-end; padding: 20px 24px; border-top: 1px solid var(--gray-200);">
        <button type="button" class="btn btn-secondary" onclick="closeModal('deleteUserModal')">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete User</button>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // DOM Elements
      const editBtn = document.getElementById('editBtn');
      const saveBtn = document.getElementById('saveBtn');
      const cancelBtn = document.getElementById('cancelBtn');
      const firstNameInput = document.getElementById('firstName');
      const lastNameInput = document.getElementById('lastName');
      const emailInput = document.getElementById('email');
      const phoneInput = document.getElementById('phone');
      const locationInput = document.getElementById('location');
      const profileForm = document.getElementById('profileForm');
      const notification = document.getElementById('notification');
      const navItems = document.querySelectorAll('.nav-item');
      const tabContents = document.querySelectorAll('.tab-content');
      
      // Password change elements
      const changePasswordForm = document.getElementById('changePasswordForm');
      const newPasswordInput = document.getElementById('newPassword');
      const passwordStrength = document.getElementById('passwordStrength');
      const passwordHint = document.getElementById('passwordHint');
      
      // User management elements
      const createUserBtn = document.getElementById('createUserBtn');
      const createUserForm = document.getElementById('createUserForm');
      const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
      
      // Original values
      const originalValues = {
        firstName: firstNameInput.value,
        lastName: lastNameInput.value,
        email: emailInput.value,
        phone: phoneInput.value,
        location: locationInput.value
      };
      
      let userToDelete = null;
      
      // Navigation click handler
      navItems.forEach(item => {
        item.addEventListener('click', function() {
          const tabId = this.getAttribute('data-tab');
          
          // Update navigation
          navItems.forEach(i => i.classList.remove('active'));
          this.classList.add('active');
          
          // Show selected tab
          tabContents.forEach(content => content.classList.remove('active'));
          document.getElementById(`${tabId}-tab`).classList.add('active');
        });
      });
      
      // Edit button click handler
      editBtn.addEventListener('click', function() {
        enableFormEditing();
        editBtn.style.display = 'none';
        cancelBtn.style.display = 'flex';
        saveBtn.disabled = false;
        firstNameInput.focus();
        showNotification('You can now edit your profile information.', 'success');
      });
      
      // Cancel button click handler
      cancelBtn.addEventListener('click', function() {
        resetForm();
        disableFormEditing();
        editBtn.style.display = 'flex';
        cancelBtn.style.display = 'none';
        saveBtn.disabled = true;
        showNotification('Changes discarded.', 'success');
      });
      
      // Profile form submission handler
      profileForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (!validateProfileForm()) {
          return;
        }
        
        // Show loading state
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        saveBtn.disabled = true;
        cancelBtn.disabled = true;
        
        // Simulate API call
        setTimeout(() => {
          // Update profile display
          document.querySelector('.profile-info h2').textContent = 
            `${firstNameInput.value} ${lastNameInput.value}`;
          document.querySelector('.profile-avatar').textContent = 
            firstNameInput.value.charAt(0) + lastNameInput.value.charAt(0);
          
          // Update original values
          originalValues.firstName = firstNameInput.value;
          originalValues.lastName = lastNameInput.value;
          originalValues.email = emailInput.value;
          originalValues.phone = phoneInput.value;
          originalValues.location = locationInput.value;
          
          // Disable form
          disableFormEditing();
          
          // Reset buttons
          editBtn.style.display = 'flex';
          cancelBtn.style.display = 'none';
          cancelBtn.disabled = false;
          saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
          saveBtn.disabled = true;
          
          showNotification('Profile updated successfully!', 'success');
        }, 1500);
      });
      
      // Password change form submission
      changePasswordForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        
        if (newPassword !== confirmPassword) {
          showNotification('New passwords do not match.', 'error');
          return;
        }
        
        if (newPassword.length < 8) {
          showNotification('Password must be at least 8 characters long.', 'error');
          return;
        }
        
        const changePasswordBtn = document.getElementById('changePasswordBtn');
        changePasswordBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
        changePasswordBtn.disabled = true;
        
        setTimeout(() => {
          changePasswordForm.reset();
          passwordStrength.className = 'password-strength';
          changePasswordBtn.innerHTML = '<i class="fas fa-key"></i> Update Password';
          changePasswordBtn.disabled = false;
          showNotification('Password changed successfully!', 'success');
        }, 1500);
      });
      
      // New password strength checker
      newPasswordInput.addEventListener('input', function() {
        const password = this.value;
        const strength = checkPasswordStrength(password);
        
        passwordStrength.className = 'password-strength';
        if (password.length > 0) {
          passwordStrength.classList.add(strength);
          passwordHint.classList.add('show');
        } else {
          passwordHint.classList.remove('show');
        }
      });
      
      // Create user button
      createUserBtn.addEventListener('click', function() {
        document.getElementById('createUserModal').style.display = 'flex';
      });
      
      // Create user form submission
      createUserForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const userData = {
          firstName: document.getElementById('newFirstName').value,
          lastName: document.getElementById('newLastName').value,
          email: document.getElementById('newEmail').value,
          role: document.getElementById('newRole').value,
          password: document.getElementById('newUserPassword').value
        };
        
        if (userData.password.length < 8) {
          showNotification('Password must be at least 8 characters long.', 'error');
          return;
        }
        
        createUser(userData);
      });
      
      // Delete user confirmation
      confirmDeleteBtn.addEventListener('click', function() {
        if (userToDelete) {
          deleteUser(userToDelete.id);
        }
      });
      
      // Password toggle functionality
      document.addEventListener('click', function(e) {
        if (e.target.closest('.password-toggle')) {
          const toggleBtn = e.target.closest('.password-toggle');
          const targetId = toggleBtn.getAttribute('data-target');
          const input = document.getElementById(targetId);
          const icon = toggleBtn.querySelector('i');
          
          if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'fas fa-eye-slash';
          } else {
            input.type = 'password';
            icon.className = 'fas fa-eye';
          }
        }
      });
      
      // Security toggle
      document.getElementById('twoFactorEnabled').addEventListener('change', function(e) {
        const toggle = e.target.closest('label');
        const switchElement = toggle.querySelector('div:nth-child(2)');
        const knob = toggle.querySelector('div:nth-child(3)');
        
        if (this.checked) {
          switchElement.style.background = 'var(--primary)';
          knob.style.left = 'calc(100% - 22px)';
        } else {
          switchElement.style.background = 'var(--gray-300)';
          knob.style.left = '2px';
        }
      });
      
      // View login history
      document.getElementById('viewLoginHistory').addEventListener('click', function() {
        showNotification('Loading login history...', 'success');
      });
      
      // Helper functions
      function enableFormEditing() {
        const inputs = [firstNameInput, lastNameInput, emailInput, phoneInput, locationInput];
        inputs.forEach(input => {
          input.disabled = false;
          input.style.background = 'white';
        });
      }
      
      function disableFormEditing() {
        const inputs = [firstNameInput, lastNameInput, emailInput, phoneInput, locationInput];
        inputs.forEach(input => {
          input.disabled = true;
          input.style.background = 'var(--gray-100)';
        });
      }
      
      function resetForm() {
        firstNameInput.value = originalValues.firstName;
        lastNameInput.value = originalValues.lastName;
        emailInput.value = originalValues.email;
        phoneInput.value = originalValues.phone;
        locationInput.value = originalValues.location;
      }
      
      function validateProfileForm() {
        const email = emailInput.value.trim();
        
        if (!firstNameInput.value.trim() || !lastNameInput.value.trim() || !email) {
          showNotification('Please fill in all required fields.', 'error');
          return false;
        }
        
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
          showNotification('Please enter a valid email address.', 'error');
          emailInput.focus();
          return false;
        }
        
        const phone = phoneInput.value.trim();
        if (phone && !/^[\d\s\-\+\(\)]+$/.test(phone)) {
          showNotification('Please enter a valid phone number.', 'error');
          phoneInput.focus();
          return false;
        }
        
        return true;
      }
      
      function checkPasswordStrength(password) {
        let strength = 0;
        
        if (password.length >= 8) strength++;
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
        if (/\d/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;
        
        if (strength <= 1) return 'weak';
        if (strength <= 3) return 'medium';
        return 'strong';
      }
      
      function createUser(userData) {
        const loadingBtn = createUserForm.querySelector('button[type="submit"]');
        loadingBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
        loadingBtn.disabled = true;
        
        setTimeout(() => {
          const tableBody = document.getElementById('usersTableBody');
          
          // Remove empty state if exists
          if (tableBody.querySelector('.empty-state')) {
            tableBody.innerHTML = '';
          }
          
          const userId = Date.now();
          const row = document.createElement('tr');
          row.setAttribute('data-user-id', userId);
          row.innerHTML = `
            <td>
              <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                  ${userData.firstName.charAt(0)}${userData.lastName.charAt(0)}
                </div>
                <div>
                  <div style="font-weight: 600; color: var(--dark);">
                    ${userData.firstName} ${userData.lastName}
                  </div>
                  <div style="font-size: 13px; color: var(--gray-600);">
                    ${userData.email}
                  </div>
                </div>
              </div>
            </td>
            <td><span class="role-badge ${userData.role}">
              <i class="fas fa-${userData.role === 'admin' ? 'crown' : 'user'}"></i>
              ${userData.role.charAt(0).toUpperCase() + userData.role.slice(1)}
            </span></td>
            <td><span class="status-badge active">
              <i class="fas fa-check-circle"></i>
              Active
            </span></td>
            <td style="color: var(--gray-600);">
              ${new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
            </td>
            <td>
              <div class="action-buttons">
                <button class="action-btn edit" onclick="editUser(${userId})">
                  <i class="fas fa-edit"></i> Edit
                </button>
                <button class="action-btn delete" onclick="showDeleteModal(${userId}, '${userData.firstName} ${userData.lastName}')">
                  <i class="fas fa-trash"></i> Delete
                </button>
              </div>
            </td>
          `;
          
          tableBody.prepend(row);
          createUserForm.reset();
          closeModal('createUserModal');
          loadingBtn.innerHTML = 'Create User';
          loadingBtn.disabled = false;
          
          // Update stats
          updateUserStats();
          
          showNotification(`User ${userData.firstName} ${userData.lastName} created successfully!`, 'success');
        }, 1500);
      }
      
      function updateUserStats() {
        const tableBody = document.getElementById('usersTableBody');
        const rows = tableBody.querySelectorAll('tr');
        
        // Update total users
        document.querySelector('.stat-value').textContent = rows.length;
        
        // Update active users (assuming all new users are active)
        document.querySelectorAll('.stat-value')[1].textContent = rows.length;
        
        // Update admin count
        const adminCount = Array.from(rows).filter(row => {
          const roleBadge = row.querySelector('.role-badge');
          return roleBadge && roleBadge.classList.contains('admin');
        }).length;
        document.querySelectorAll('.stat-value')[2].textContent = adminCount;
      }
      
      function showNotification(message, type) {
        notification.textContent = message;
        notification.className = `notification ${type}`;
        notification.style.display = 'block';
        
        setTimeout(() => {
          notification.style.display = 'none';
        }, 4000);
      }
    });
    
    // Global functions for user management
    function editUser(userId) {
      showNotification(`Editing user...`, 'success');
    }
    
    function showDeleteModal(userId, userName) {
      window.userToDelete = { id: userId, name: userName };
      document.getElementById('deleteUserName').textContent = userName;
      document.getElementById('deleteUserModal').style.display = 'flex';
    }
    
    function closeModal(modalId) {
      document.getElementById(modalId).style.display = 'none';
      window.userToDelete = null;
    }
    
    function deleteUser(userId) {
      const tableBody = document.getElementById('usersTableBody');
      const row = tableBody.querySelector(`tr[data-user-id="${userId}"]`);
      
      if (row) {
        const deleteBtn = document.getElementById('confirmDeleteBtn');
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        deleteBtn.disabled = true;
        
        setTimeout(() => {
          row.style.opacity = '0';
          row.style.transform = 'translateX(-100px)';
          setTimeout(() => {
            row.remove();
            
            if (tableBody.children.length === 0) {
              tableBody.innerHTML = `
                <div class="empty-state">
                  <i class="fas fa-users"></i>
                  <h3>No Users Found</h3>
                  <p>Click "Create New User" to add users to the system</p>
                </div>
              `;
            }
            
            closeModal('deleteUserModal');
            
            // Update stats
            const stats = document.querySelector('.stats-grid');
            if (stats) {
              const rows = tableBody.querySelectorAll('tr:not(.empty-state)');
              if (rows.length === 0) {
                document.querySelectorAll('.stat-value')[0].textContent = '0';
                document.querySelectorAll('.stat-value')[1].textContent = '0';
                document.querySelectorAll('.stat-value')[2].textContent = '0';
              } else {
                updateUserStats();
              }
            }
            
            showNotification(`User deleted successfully!`, 'success');
          }, 300);
        }, 1000);
      }
    }
    
    function updateUserStats() {
      const tableBody = document.getElementById('usersTableBody');
      const rows = tableBody.querySelectorAll('tr:not(.empty-state)');
      
      if (rows.length > 0) {
        const totalUsers = rows.length;
        const activeUsers = Array.from(rows).filter(row => {
          const statusBadge = row.querySelector('.status-badge');
          return statusBadge && statusBadge.classList.contains('active');
        }).length;
        const adminUsers = Array.from(rows).filter(row => {
          const roleBadge = row.querySelector('.role-badge');
          return roleBadge && roleBadge.classList.contains('admin');
        }).length;
        
        document.querySelectorAll('.stat-value')[0].textContent = totalUsers;
        document.querySelectorAll('.stat-value')[1].textContent = activeUsers;
        document.querySelectorAll('.stat-value')[2].textContent = adminUsers;
      }
    }
  </script>
</body>
</html>