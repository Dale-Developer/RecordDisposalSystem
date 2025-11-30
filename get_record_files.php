<?php
// ENABLE ALL ERROR REPORTING FOR DEBUGGING
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    error_log("=== get_record_files.php DEBUG START ===");
    
    // Check if ID parameter is provided
    if (!isset($_GET['id'])) {
        throw new Exception('No ID parameter provided. Received: ' . print_r($_GET, true));
    }

    $record_id = intval($_GET['id']);
    error_log("Processing files for record ID: " . $record_id);

    // Load session.php - Use the exact path you provided
    $sessionPath = '../Record_Disposal_System/session.php';
    error_log("Loading session.php from: " . $sessionPath);
    
    if (!file_exists($sessionPath)) {
        throw new Exception("session.php not found at: " . realpath($sessionPath));
    }
    
    require_once $sessionPath;
    error_log("session.php loaded successfully");
    
    // Check login
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized - User not logged in');
    }
    
    error_log("User authenticated: " . $_SESSION['user_id']);

    // Load db_connect.php - Same location as session.php
    $dbPath = '../Record_Disposal_System/db_connect.php';
    error_log("Loading db_connect.php from: " . $dbPath);
    
    if (!file_exists($dbPath)) {
        throw new Exception("db_connect.php not found at: " . realpath($dbPath));
    }
    
    require_once $dbPath;
    error_log("db_connect.php loaded successfully");

    // Check if database connection is established
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

    error_log("Executing files SQL: " . $sql . " with record_id: " . $record_id);
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$record_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Files found: " . count($files));
    
    // Log each file found for debugging
    foreach ($files as $index => $file) {
        error_log("File " . ($index + 1) . ": " . $file['file_name'] . " (ID: " . $file['file_id'] . ")");
    }

    // Success response
    echo json_encode([
        'success' => true,
        'files' => $files,
        'file_count' => count($files)
    ]);

    error_log("=== get_record_files.php DEBUG END - SUCCESS ===");

} catch (Exception $e) {
    error_log("=== get_record_files.php DEBUG END - ERROR ===");
    error_log("ERROR: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage(),
        'record_id' => isset($record_id) ? $record_id : null
    ]);
}
?>