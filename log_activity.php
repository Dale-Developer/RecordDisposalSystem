<?php
// SIMPLE LOGGING FUNCTION
function log_activity($action_type, $description, $record_id = null) {
    global $pdo;
    
    try {
        // Get user from session (if logged in)
        session_start();
        $user_id = $_SESSION['user_id'] ?? null;
        
        // Get IP address
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        
        // Insert into database
        $sql = "INSERT INTO activity_logs 
                (action_type, description, user_id, record_id, ip_address, created_at)
                VALUES (:action, :desc, :user_id, :record_id, :ip, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':action' => $action_type,
            ':desc' => $description,
            ':user_id' => $user_id,
            ':record_id' => $record_id,
            ':ip' => $ip_address
        ]);
        
        return true;
        
    } catch (Exception $e) {
        // Don't crash if logging fails
        error_log("Logging error: " . $e->getMessage());
        return false;
    }
}

// Easy shortcut function
function logAction($action, $description, $record_id = null) {
    return log_activity($action, $description, $record_id);
}
?>