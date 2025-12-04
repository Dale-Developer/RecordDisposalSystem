<?php
// get_record.php - SIMPLIFIED VERSION
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    // Check if ID parameter is provided
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        throw new Exception('No ID parameter provided');
    }

    $record_id = intval($_GET['id']);
    
    // Get the current directory
    $currentDir = __DIR__;
    
    // Try different paths for includes
    $paths = [
        $currentDir . '/../session.php',
        $currentDir . '/session.php',
        dirname(dirname($currentDir)) . '/session.php'
    ];
    
    $sessionLoaded = false;
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $sessionLoaded = true;
            break;
        }
    }
    
    if (!$sessionLoaded) {
        throw new Exception('session.php not found');
    }
    
    // Check login
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized - User not logged in');
    }
    
    // Load database connection
    $dbPaths = [
        $currentDir . '/../db_connect.php',
        $currentDir . '/db_connect.php',
        dirname(dirname($currentDir)) . '/db_connect.php'
    ];
    
    $dbLoaded = false;
    foreach ($dbPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $dbLoaded = true;
            break;
        }
    }
    
    if (!$dbLoaded) {
        throw new Exception('db_connect.php not found');
    }
    
    if (!isset($pdo)) {
        throw new Exception('Database connection not established');
    }
    
    // Test database connection
    $pdo->query("SELECT 1");
    
    // Prepare and execute main query
    $sql = "SELECT 
                r.*,
                o.office_name,
                rc.class_name,
                rc.functional_category,
                rc.retention_period,
                rc.disposition_action,
                rc.nap_authority,
                u.first_name,
                u.last_name
            FROM records r
            LEFT JOIN offices o ON r.office_id = o.office_id
            LEFT JOIN record_classification rc ON r.class_id = rc.class_id
            LEFT JOIN users u ON r.created_by = u.user_id
            WHERE r.record_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$record_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        throw new Exception('Record not found with ID: ' . $record_id);
    }
    
    // Success response
    echo json_encode([
        'success' => true, 
        'record' => $record
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>