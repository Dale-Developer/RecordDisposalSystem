<?php
require_once 'db_connect.php';

/**
 * Log activity in the system
 * 
 * @param string $log_type Type of activity (Login, Record_Creation, Archive_Request, etc.)
 * @param int|null $user_id User ID who performed the action
 * @param string|null $entity_type Type of entity affected (Record, User, Disposal, etc.)
 * @param string|null $entity_id ID of entity affected
 * @param string|null $entity_name Name/Title of entity
 * @param string|null $action_description Description of the action
 * @param array|null $old_data Old data (for updates)
 * @param array|null $new_data New data (for updates)
 * @param string|null $status Status of action (Success, Failed, Pending, etc.)
 * @param string|null $ip_address User IP address
 * @param string|null $user_agent User browser agent
 * @param int|null $approved_by User ID who approved the action
 * 
 * @return bool|string Returns true on success, error message on failure
 */
function log_activity($log_type, $user_id = null, $entity_type = null, $entity_id = null, 
                      $entity_name = null, $action_description = null, $old_data = null, 
                      $new_data = null, $status = null, $ip_address = null, 
                      $user_agent = null, $approved_by = null) {
    
    global $pdo;
    
    try {
        // Get user IP and agent if not provided
        if ($ip_address === null) {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        }
        
        if ($user_agent === null) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }
        
        // Prepare data for storage
        $old_data_json = $old_data ? json_encode($old_data, JSON_UNESCAPED_UNICODE) : null;
        $new_data_json = $new_data ? json_encode($new_data, JSON_UNESCAPED_UNICODE) : null;
        
        $sql = "INSERT INTO activity_logs 
                (log_type, user_id, entity_type, entity_id, entity_name, action_description, 
                 old_data, new_data, status, ip_address, user_agent, approved_by, created_at)
                VALUES 
                (:log_type, :user_id, :entity_type, :entity_id, :entity_name, :action_description,
                 :old_data, :new_data, :status, :ip_address, :user_agent, :approved_by, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':log_type' => $log_type,
            ':user_id' => $user_id,
            ':entity_type' => $entity_type,
            ':entity_id' => $entity_id,
            ':entity_name' => $entity_name,
            ':action_description' => $action_description,
            ':old_data' => $old_data_json,
            ':new_data' => $new_data_json,
            ':status' => $status,
            ':ip_address' => $ip_address,
            ':user_agent' => $user_agent,
            ':approved_by' => $approved_by
        ]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Get all activity logs with filters
 * 
 * @param array $filters Array of filters (date_from, date_to, log_type, user_id, entity_type, status)
 * @param int $limit Maximum number of logs to return
 * @param int $offset Offset for pagination
 * 
 * @return array Array of logs
 */
function get_activity_logs($filters = [], $limit = 100, $offset = 0) {
    global $pdo;
    
    try {
        $sql = "SELECT 
                    al.log_id,
                    al.log_type,
                    al.created_at,
                    al.entity_type,
                    al.entity_id,
                    al.entity_name,
                    al.action_description,
                    al.status,
                    al.ip_address,
                    al.old_data,
                    al.new_data,
                    al.approved_by,
                    u.user_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    r.role_name,
                    approver.first_name as approver_first_name,
                    approver.last_name as approver_last_name
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.user_id
                LEFT JOIN roles r ON u.role_id = r.role_id
                LEFT JOIN users approver ON al.approved_by = approver.user_id
                WHERE 1=1";
        
        $params = [];
        
        // Apply filters
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(al.created_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(al.created_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        if (!empty($filters['log_type']) && $filters['log_type'] !== 'all') {
            $sql .= " AND al.log_type = :log_type";
            $params[':log_type'] = $filters['log_type'];
        }
        
        if (!empty($filters['user_id']) && $filters['user_id'] !== 'all') {
            $sql .= " AND al.user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }
        
        if (!empty($filters['entity_type']) && $filters['entity_type'] !== 'all') {
            $sql .= " AND al.entity_type = :entity_type";
            $params[':entity_type'] = $filters['entity_type'];
        }
        
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $sql .= " AND al.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        // Search by entity name or action description
        if (!empty($filters['search'])) {
            $sql .= " AND (al.entity_name LIKE :search OR al.action_description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $sql .= " ORDER BY al.created_at DESC";
        
        if ($limit > 0) {
            $sql .= " LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
        }
        
        $stmt = $pdo->prepare($sql);
        
        // Bind parameters with proper types
        foreach ($params as $key => $value) {
            if ($key === ':limit' || $key === ':offset') {
                $stmt->bindValue($key, (int)$value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting activity logs: " . $e->getMessage());
        return [];
    }
}

/**
 * Get log statistics for dashboard
 * 
 * @return array Array of statistics
 */
function get_log_statistics() {
    global $pdo;
    
    try {
        $statistics = [];
        
        // Total logs
        $sql = "SELECT COUNT(*) as total FROM activity_logs";
        $stmt = $pdo->query($sql);
        $statistics['total'] = $stmt->fetchColumn();
        
        // Logs by type
        $sql = "SELECT log_type, COUNT(*) as count 
                FROM activity_logs 
                GROUP BY log_type 
                ORDER BY count DESC";
        $stmt = $pdo->query($sql);
        $statistics['by_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Logs by status
        $sql = "SELECT status, COUNT(*) as count 
                FROM activity_logs 
                WHERE status IS NOT NULL 
                GROUP BY status 
                ORDER BY count DESC";
        $stmt = $pdo->query($sql);
        $statistics['by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recent activities (last 7 days)
        $sql = "SELECT DATE(created_at) as date, COUNT(*) as count 
                FROM activity_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
                GROUP BY DATE(created_at) 
                ORDER BY date DESC";
        $stmt = $pdo->query($sql);
        $statistics['recent'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Most active users
        $sql = "SELECT u.user_id, u.first_name, u.last_name, COUNT(*) as count 
                FROM activity_logs al
                JOIN users u ON al.user_id = u.user_id 
                GROUP BY u.user_id, u.first_name, u.last_name 
                ORDER BY count DESC 
                LIMIT 10";
        $stmt = $pdo->query($sql);
        $statistics['active_users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $statistics;
        
    } catch (Exception $e) {
        error_log("Error getting log statistics: " . $e->getMessage());
        return [];
    }
}