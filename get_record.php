<?php
// ENABLE ALL ERROR REPORTING FOR DEBUGGING
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    error_log("=== get_record.php DEBUG START ===");
    
    // Check if ID parameter is provided
    if (!isset($_GET['id'])) {
        throw new Exception('No ID parameter provided. Received: ' . print_r($_GET, true));
    }

    $record_id = intval($_GET['id']);
    error_log("Processing record ID: " . $record_id);

    // Fixed root directory - THIS IS THE CORRECT WAY
    $root = realpath(__DIR__ . "/..");
    error_log("Root directory: " . $root);

    // FIXED: Add proper path separator
    $sessionPath = $root . "/session.php"; // CHANGED THIS LINE
    error_log("Session path: " . $sessionPath);
    
    if (!file_exists($sessionPath)) {
        // Try alternative paths if the main one fails
        $alternativePaths = [
            $root . "/session.php",
            __DIR__ . "/../session.php",
            dirname(__DIR__) . "/session.php",
            "C:/xampp/htdocs/Record_Disposal_System/session.php"
        ];
        
        $found = false;
        foreach ($alternativePaths as $altPath) {
            if (file_exists($altPath)) {
                $sessionPath = $altPath;
                $found = true;
                error_log("Found session.php at: " . $altPath);
                break;
            }
        }
        
        if (!$found) {
            throw new Exception("session.php not found. Checked paths: " . implode(", ", $alternativePaths));
        }
    }
    
    require_once $sessionPath;
    error_log("session.php loaded successfully from: " . $sessionPath);
    
    // Check if session started properly
    if (!isset($_SESSION)) {
        throw new Exception("Session not started properly");
    }
    
    // Check login
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized - User not logged in. Session data: ' . print_r($_SESSION, true));
    }
    
    error_log("User authenticated: " . $_SESSION['user_id']);

    // Load db_connect.php - ALSO FIXED PATH
    $dbPath = $root . "/db_connect.php";
    error_log("DB path: " . $dbPath);
    
    if (!file_exists($dbPath)) {
        // Try alternative paths for db_connect.php
        $alternativePaths = [
            $root . "/db_connect.php",
            __DIR__ . "/../db_connect.php",
            dirname(__DIR__) . "/db_connect.php",
            "C:/xampp/htdocs/Record_Disposal_System/db_connect.php"
        ];
        
        $found = false;
        foreach ($alternativePaths as $altPath) {
            if (file_exists($altPath)) {
                $dbPath = $altPath;
                $found = true;
                error_log("Found db_connect.php at: " . $altPath);
                break;
            }
        }
        
        if (!$found) {
            throw new Exception("db_connect.php not found. Checked paths: " . implode(", ", $alternativePaths));
        }
    }
    
    require_once $dbPath;
    error_log("db_connect.php loaded successfully from: " . $dbPath);

    // Check if database connection is established
    if (!isset($pdo)) {
        throw new Exception('Database connection not established - $pdo variable not set');
    }

    // Test database connection
    try {
        $pdo->query("SELECT 1")->execute();
        error_log("Database connection test passed");
    } catch (PDOException $e) {
        throw new Exception('Database connection failed: ' . $e->getMessage());
    }

    // Prepare and execute main query
    $sql = "SELECT 
                r.*,
                o.office_name,
                rc.class_name,
                rc.functional_category,
                u.first_name,
                u.last_name
            FROM records r
            LEFT JOIN offices o ON r.office_id = o.office_id
            LEFT JOIN record_classification rc ON r.class_id = rc.class_id
            LEFT JOIN users u ON r.created_by = u.user_id
            WHERE r.record_id = ?";

    error_log("Executing SQL: " . $sql);
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$record_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        throw new Exception('Record not found with ID: ' . $record_id);
    }

    error_log("Record found: " . $record['record_title']);

    // Load attached files
    $file_sql = "SELECT * FROM record_files WHERE record_id = ?";
    $file_stmt = $pdo->prepare($file_sql);
    $file_stmt->execute([$record_id]);
    $record["files"] = $file_stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Files loaded: " . count($record["files"]));

    // Success response
    echo json_encode([
        'success' => true, 
        'record' => $record
    ]);

    error_log("=== get_record.php DEBUG END - SUCCESS ===");

} catch (Exception $e) {
    error_log("=== get_record.php DEBUG END - ERROR ===");
    error_log("ERROR: " . $e->getMessage());
    error_log("File: " . $e->getFile());
    error_log("Line: " . $e->getLine());
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>