<?php
// log_activity.php
// Helper functions for logging user activities

/**
 * Log user activity with independent user information
 * @param PDO $pdo Database connection
 * @param string $action_type Type of action (e.g., 'RECORD_CREATE', 'DISPOSAL_REQUEST_APPROVE')
 * @param string $description Description of the action
 * @param int|null $user_id User ID (optional, will use session if not provided)
 * @param array $additional_data Additional data to store in the log
 * @return int|false Log ID on success, false on failure
 */
function logActivity($pdo, $action_type, $description, $user_id = null, $additional_data = []) {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Get user ID from session if not provided
    if ($user_id === null && isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    }
    
    // Prepare log data with defaults
    $log_data = [
        'action_type' => $action_type,
        'description' => $description,
        'user_id' => $user_id,
        'status' => 'Success',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        'page' => basename($_SERVER['PHP_SELF']),
        'entity_type' => $additional_data['entity_type'] ?? null,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    // Merge additional data
    $log_data = array_merge($log_data, $additional_data);
    
    // If user_id is provided, get and store user information independently
    if ($user_id) {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    CONCAT(first_name, ' ', last_name) as user_name,
                    email as user_email,
                    role_id,
                    office_id
                FROM users 
                WHERE user_id = ?
            ");
            $stmt->execute([$user_id]);
            
            if ($user_info = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Get role name
                $role_stmt = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ?");
                $role_stmt->execute([$user_info['role_id']]);
                $role_name = $role_stmt->fetchColumn();
                
                // Get office name
                $office_stmt = $pdo->prepare("SELECT office_name FROM offices WHERE office_id = ?");
                $office_stmt->execute([$user_info['office_id']]);
                $office_name = $office_stmt->fetchColumn();
                
                // Add to log data
                $log_data['user_name'] = $user_info['user_name'];
                $log_data['user_email'] = $user_info['user_email'];
                $log_data['user_role'] = $role_name;
                $log_data['user_office'] = $office_name;
                $log_data['role_id'] = $user_info['role_id'];
                $log_data['office_id'] = $user_info['office_id'];
            }
        } catch (Exception $e) {
            // If user lookup fails, log with minimal info
            error_log("Failed to get user info for logging: " . $e->getMessage());
        }
    }
    
    // Remove null values to avoid database errors
    $log_data = array_filter($log_data, function($value) {
        return $value !== null;
    });
    
    // Build SQL query
    $columns = implode(', ', array_keys($log_data));
    $placeholders = ':' . implode(', :', array_keys($log_data));
    
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs ($columns) VALUES ($placeholders)");
        
        // Bind parameters
        foreach ($log_data as $key => $value) {
            // Handle JSON fields
            if (in_array($key, ['old_values', 'new_values']) && is_array($value)) {
                $stmt->bindValue(':' . $key, json_encode($value), PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }
        
        $stmt->execute();
        return $pdo->lastInsertId();
        
    } catch (PDOException $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Quick logging functions for common actions
 */

function logRecordCreate($pdo, $record_id, $user_id = null, $record_data = []) {
    return logActivity($pdo, 'RECORD_CREATE', 'Created new record', $user_id, [
        'record_id' => $record_id,
        'entity_type' => 'Record',
        'new_values' => $record_data
    ]);
}

function logRecordUpdate($pdo, $record_id, $user_id = null, $old_data = [], $new_data = []) {
    return logActivity($pdo, 'RECORD_UPDATE', 'Updated record details', $user_id, [
        'record_id' => $record_id,
        'entity_type' => 'Record',
        'old_values' => $old_data,
        'new_values' => $new_data
    ]);
}

function logRecordDelete($pdo, $record_id, $user_id = null, $deleted_data = []) {
    return logActivity($pdo, 'RECORD_DELETE', 'Deleted record', $user_id, [
        'record_id' => $record_id,
        'entity_type' => 'Record',
        'old_values' => $deleted_data
    ]);
}

function logDisposalRequestCreate($pdo, $request_id, $user_id = null, $request_data = []) {
    return logActivity($pdo, 'DISPOSAL_REQUEST_CREATE', 'Created disposal request', $user_id, [
        'disposal_request_id' => $request_id,
        'entity_type' => 'DisposalRequest',
        'new_values' => $request_data
    ]);
}

function logDisposalRequestApprove($pdo, $request_id, $user_id = null, $old_status = null) {
    return logActivity($pdo, 'DISPOSAL_REQUEST_APPROVE', 'Approved disposal request', $user_id, [
        'disposal_request_id' => $request_id,
        'entity_type' => 'DisposalRequest',
        'request_status_from' => $old_status,
        'request_status_to' => 'Approved'
    ]);
}

function logDisposalRequestDecline($pdo, $request_id, $user_id = null, $old_status = null, $reason = '') {
    return logActivity($pdo, 'DISPOSAL_REQUEST_DECLINE', 'Declined disposal request: ' . $reason, $user_id, [
        'disposal_request_id' => $request_id,
        'entity_type' => 'DisposalRequest',
        'request_status_from' => $old_status,
        'request_status_to' => 'Declined'
    ]);
}

function logArchiveRequestCreate($pdo, $request_id, $user_id = null, $request_data = []) {
    return logActivity($pdo, 'ARCHIVE_REQUEST_CREATE', 'Created archive request', $user_id, [
        'archive_request_id' => $request_id,
        'entity_type' => 'ArchiveRequest',
        'new_values' => $request_data
    ]);
}

function logArchiveRequestApprove($pdo, $request_id, $user_id = null, $old_status = null) {
    return logActivity($pdo, 'ARCHIVE_REQUEST_APPROVE', 'Approved archive request', $user_id, [
        'archive_request_id' => $request_id,
        'entity_type' => 'ArchiveRequest',
        'request_status_from' => $old_status,
        'request_status_to' => 'Approved'
    ]);
}

function logArchiveRequestDecline($pdo, $request_id, $user_id = null, $old_status = null, $reason = '') {
    return logActivity($pdo, 'ARCHIVE_REQUEST_DECLINE', 'Declined archive request: ' . $reason, $user_id, [
        'archive_request_id' => $request_id,
        'entity_type' => 'ArchiveRequest',
        'request_status_from' => $old_status,
        'request_status_to' => 'Declined'
    ]);
}

function logUserLogin($pdo, $user_id, $email) {
    return logActivity($pdo, 'USER_LOGIN', 'User ' . $email . ' logged in successfully', $user_id, [
        'entity_type' => 'User'
    ]);
}

function logUserLogout($pdo, $user_id = null, $email = 'Unknown') {
    return logActivity($pdo, 'USER_LOGOUT', 'User ' . $email . ' logged out', $user_id, [
        'entity_type' => 'User'
    ]);
}

function logUserCreate($pdo, $new_user_id, $user_data = [], $admin_user_id = null) {
    return logActivity($pdo, 'USER_CREATE', 'Created new user account', $admin_user_id, [
        'entity_type' => 'User',
        'new_values' => $user_data
    ]);
}

function logUserUpdate($pdo, $updated_user_id, $old_data = [], $new_data = [], $admin_user_id = null) {
    return logActivity($pdo, 'USER_UPDATE', 'Updated user account', $admin_user_id, [
        'entity_type' => 'User',
        'old_values' => $old_data,
        'new_values' => $new_data
    ]);
}

function logUserDelete($pdo, $deleted_user_id, $deleted_data = [], $admin_user_id = null) {
    return logActivity($pdo, 'USER_DELETE', 'Deleted user account', $admin_user_id, [
        'entity_type' => 'User',
        'old_values' => $deleted_data
    ]);
}
?>