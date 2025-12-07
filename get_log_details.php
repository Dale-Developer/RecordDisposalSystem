<?php
require_once 'session.php';
require_once 'db_connect.php';

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'No log ID provided']);
    exit;
}

$log_id = (int)$_GET['id'];

// Function to check if a table exists
function tableExists($pdo, $tableName) {
    try {
        $result = $pdo->query("SHOW TABLES LIKE '$tableName'");
        return $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Function to safely execute a query
function safeQuery($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Query failed: " . $e->getMessage() . " - SQL: " . $sql);
        return null;
    }
}

try {
    // First, get the basic log information
    $sql = "SELECT 
                al.*,
                u.first_name,
                u.last_name,
                u.email,
                r.role_name
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.user_id
            LEFT JOIN roles r ON u.role_id = r.role_id
            WHERE al.log_id = :log_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':log_id' => $log_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$log) {
        echo json_encode(['success' => false, 'message' => 'Log not found']);
        exit;
    }
    
    // Initialize additional details array
    $additional_details = [];
    $debug_info = [];
    
    // Based on the log type and entity, fetch more details from relevant tables
    if ($log['entity_type'] && $log['entity_id']) {
        $entity_type = strtolower($log['entity_type']);
        $entity_id = $log['entity_id'];
        
        $debug_info['entity_type'] = $entity_type;
        $debug_info['entity_id'] = $entity_id;
        
        // Check common entity types
        switch ($entity_type) {
            case 'record':
            case 'records':
                if (tableExists($pdo, 'records')) {
                    $record_sql = "SELECT r.* FROM records r WHERE r.record_id = :entity_id";
                    $additional_details['record'] = safeQuery($pdo, $record_sql, [':entity_id' => $entity_id]);
                    
                    // Also try to get category/subcategory info if tables exist
                    if ($additional_details['record'] && tableExists($pdo, 'categories') && isset($additional_details['record']['category_id'])) {
                        $category_sql = "SELECT category_name FROM categories WHERE category_id = :cat_id";
                        $category = safeQuery($pdo, $category_sql, [':cat_id' => $additional_details['record']['category_id']]);
                        if ($category) {
                            $additional_details['record']['category_name'] = $category['category_name'];
                        }
                    }
                    
                    if ($additional_details['record'] && tableExists($pdo, 'subcategories') && isset($additional_details['record']['subcategory_id'])) {
                        $subcategory_sql = "SELECT subcategory_name FROM subcategories WHERE subcategory_id = :subcat_id";
                        $subcategory = safeQuery($pdo, $subcategory_sql, [':subcat_id' => $additional_details['record']['subcategory_id']]);
                        if ($subcategory) {
                            $additional_details['record']['subcategory_name'] = $subcategory['subcategory_name'];
                        }
                    }
                }
                break;
                
            case 'user':
            case 'users':
                if (tableExists($pdo, 'users')) {
                    $user_sql = "SELECT u.* FROM users u WHERE u.user_id = :entity_id";
                    $additional_details['user'] = safeQuery($pdo, $user_sql, [':entity_id' => $entity_id]);
                }
                break;
                
            case 'disposal':
            case 'disposal_request':
                if (tableExists($pdo, 'disposal_requests')) {
                    $disposal_sql = "SELECT dr.* FROM disposal_requests dr WHERE dr.request_id = :entity_id";
                    $additional_details['disposal_request'] = safeQuery($pdo, $disposal_sql, [':entity_id' => $entity_id]);
                }
                break;
                
            case 'archive':
            case 'archive_request':
                if (tableExists($pdo, 'archive_requests')) {
                    $archive_sql = "SELECT ar.* FROM archive_requests ar WHERE ar.request_id = :entity_id";
                    $additional_details['archive_request'] = safeQuery($pdo, $archive_sql, [':entity_id' => $entity_id]);
                }
                break;
                
            case 'category':
            case 'categories':
                if (tableExists($pdo, 'categories')) {
                    $category_sql = "SELECT * FROM categories WHERE category_id = :entity_id";
                    $additional_details['category'] = safeQuery($pdo, $category_sql, [':entity_id' => $entity_id]);
                }
                break;
                
            case 'subcategory':
            case 'subcategories':
                if (tableExists($pdo, 'subcategories')) {
                    $subcategory_sql = "SELECT * FROM subcategories WHERE subcategory_id = :entity_id";
                    $additional_details['subcategory'] = safeQuery($pdo, $subcategory_sql, [':entity_id' => $entity_id]);
                }
                break;
                
            case 'role':
            case 'roles':
                if (tableExists($pdo, 'roles')) {
                    $role_sql = "SELECT * FROM roles WHERE role_id = :entity_id";
                    $additional_details['role'] = safeQuery($pdo, $role_sql, [':entity_id' => $entity_id]);
                }
                break;
                
            case 'department':
            case 'departments':
                if (tableExists($pdo, 'departments')) {
                    $dept_sql = "SELECT * FROM departments WHERE department_id = :entity_id";
                    $additional_details['department'] = safeQuery($pdo, $dept_sql, [':entity_id' => $entity_id]);
                }
                break;
                
            default:
                // Try to guess the table name from entity_type
                $table_name = rtrim($entity_type, 's'); // Remove trailing 's'
                $table_name_plural = $table_name . 's';
                
                if (tableExists($pdo, $table_name_plural)) {
                    // Try common ID column patterns
                    $id_columns = [
                        $table_name . '_id',
                        'id',
                        'ID',
                        strtolower($table_name) . '_id'
                    ];
                    
                    foreach ($id_columns as $id_column) {
                        $generic_sql = "SELECT * FROM $table_name_plural WHERE $id_column = :entity_id LIMIT 1";
                        $result = safeQuery($pdo, $generic_sql, [':entity_id' => $entity_id]);
                        if ($result) {
                            $additional_details[$table_name] = $result;
                            break;
                        }
                    }
                }
                break;
        }
    }
    
    // For login/logout events, add session info if available
    if (in_array($log['log_type'], ['USER_LOGIN', 'USER_LOGOUT'])) {
        if (tableExists($pdo, 'user_sessions')) {
            $session_sql = "SELECT * FROM user_sessions 
                           WHERE user_id = :user_id 
                           ORDER BY login_time DESC 
                           LIMIT 1";
            $additional_details['session'] = safeQuery($pdo, $session_sql, [':user_id' => $log['user_id']]);
        }
    }
    
    // Clean up any null values
    $additional_details = array_filter($additional_details, function($detail) {
        return $detail !== null;
    });
    
    // Merge all details
    $log['additional_details'] = $additional_details;
    $log['debug_info'] = $debug_info; // Optional: for debugging
    
    // Format timestamps for display
    $log['formatted_created_at'] = date('F j, Y \a\t h:i A', strtotime($log['created_at']));
    
    // Add database info for debugging
    $log['database_info'] = [
        'activity_logs_count' => safeQuery($pdo, "SELECT COUNT(*) as count FROM activity_logs")['count'] ?? 0,
        'tables_checked' => []
    ];
    
    echo json_encode([
        'success' => true, 
        'log' => $log,
        'debug' => [
            'entity_type' => $log['entity_type'] ?? 'none',
            'entity_id' => $log['entity_id'] ?? 'none',
            'tables_found' => count($additional_details)
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Error fetching log details: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage(),
        'error_details' => $e->getMessage()
    ]);
}