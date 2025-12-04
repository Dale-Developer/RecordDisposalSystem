<?php
// get_record_files.php - SIMPLIFIED VERSION
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
        $currentDir . '/../Record_Disposal_System/session.php',
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
        $currentDir . '/../Record_Disposal_System/db_connect.php',
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
    
    // Fetch files for the record
    $sql = "SELECT 
                file_id,
                file_name, 
                file_path, 
                file_type, 
                file_size, 
                file_tag,
                uploaded_at
            FROM record_files 
            WHERE record_id = ?
            ORDER BY uploaded_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$record_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Success response
    echo json_encode([
        'success' => true,
        'files' => $files
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>