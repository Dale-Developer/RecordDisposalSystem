<?php
// Enable full error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set content type to JSON
header('Content-Type: application/json');

// Start output buffering
ob_start();

// Manual path resolution - try multiple possible paths
$possiblePaths = [
    dirname(__DIR__) . '/session.php',
    __DIR__ . '/../session.php',
    'C:/xampp/htdocs/Record_Disposal_System/session.php'
];

$sessionLoaded = false;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $sessionLoaded = true;
        error_log("Successfully loaded session.php from: " . $path);
        break;
    }
}

if (!$sessionLoaded) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Error: Could not find session.php file'
    ]);
    exit;
}

// Do the same for db_connect.php
$dbLoaded = false;
$dbPaths = [
    dirname(__DIR__) . '/db_connect.php',
    __DIR__ . '/../db_connect.php',
    'C:/xampp/htdocs/Record_Disposal_System/db_connect.php'
];

foreach ($dbPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $dbLoaded = true;
        error_log("Successfully loaded db_connect.php from: " . $path);
        break;
    }
}

if (!$dbLoaded) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Error: Could not find db_connect.php file'
    ]);
    exit;
}

try {
    if (!isset($pdo)) {
        throw new Exception('Database connection not established');
    }

    $pdo->query("SELECT 1")->execute();

    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized access - User not logged in');
    }

    $action = $_GET['action'] ?? 'add';
    error_log("Action: " . $action);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method. Expected POST, got ' . $_SERVER['REQUEST_METHOD']);
    }

    // Log received data for debugging
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));

    // Get form data
    $record_id = $_POST['record_id'] ?? null;
    $record_title = trim($_POST['record_title'] ?? '');
    $office_id = $_POST['office_id'] ?? '';
    $record_series_code = trim($_POST['record_series_code'] ?? '');
    $class_id = $_POST['class_id'] ?? '';
    $inclusive_date_from = $_POST['inclusive_date_from'] ?? null;
    $inclusive_date_to = $_POST['inclusive_date_to'] ?? null;
    $retention_period = trim($_POST['retention_period'] ?? '');
    $disposition_type = $_POST['disposition_type'] ?? 'Archive';
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $created_by = $_SESSION['user_id'];

    // Validate required fields
    if (empty($record_title)) {
        throw new Exception('Record title is required');
    }
    if (empty($office_id)) {
        throw new Exception('Office/Department is required');
    }
    if (empty($record_series_code)) {
        throw new Exception('Record series code is required');
    }
    if (empty($class_id)) {
        throw new Exception('Classification is required');
    }
    if (empty($retention_period)) {
        throw new Exception('Retention period is required');
    }

    if ($inclusive_date_from && $inclusive_date_to) {
        if (strtotime($inclusive_date_from) > strtotime($inclusive_date_to)) {
            throw new Exception('End date cannot be before start date');
        }
    }

    if ($action === 'add') {
        // Check if record series code already exists
        $check_sql = "SELECT record_id FROM records WHERE record_series_code = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$record_series_code]);

        if ($check_stmt->fetch()) {
            throw new Exception('Record series code already exists. Please use a unique code.');
        }

        // Insert into records table
        $sql = "INSERT INTO records (
                record_title, 
                office_id, 
                record_series_code, 
                class_id, 
                inclusive_date_from, 
                inclusive_date_to, 
                retention_period, 
                disposition_type, 
                description, 
                created_by,
                date_created,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)";

        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            $record_title,
            $office_id,
            $record_series_code,
            $class_id,
            $inclusive_date_from ?: null,
            $inclusive_date_to ?: null,
            $retention_period,
            $disposition_type,
            $description,
            $created_by,
            $status
        ]);

        if (!$success) {
            throw new Exception('Failed to insert record into database');
        }

        $new_record_id = $pdo->lastInsertId();
        error_log("New record created with ID: " . $new_record_id);

        // Handle file uploads
        $uploaded_files = [];
        if (!empty($_FILES['attachments']['name'][0])) {
            $uploaded_files = handleFileUploads($_FILES['attachments'], $new_record_id);
        }

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Record added successfully',
            'record_id' => $new_record_id,
            'uploaded_files' => $uploaded_files
        ]);

    } elseif ($action === 'edit' && $record_id) {
        // Check if record exists
        $check_sql = "SELECT record_id FROM records WHERE record_id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$record_id]);

        if (!$check_stmt->fetch()) {
            throw new Exception('Record not found');
        }

        // Check if record series code already exists (excluding current record)
        $check_sql = "SELECT record_id FROM records WHERE record_series_code = ? AND record_id != ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$record_series_code, $record_id]);

        if ($check_stmt->fetch()) {
            throw new Exception('Record series code already exists. Please use a unique code.');
        }

        // Update existing record
        $sql = "UPDATE records SET 
                record_title = ?,
                office_id = ?,
                record_series_code = ?,
                class_id = ?,
                inclusive_date_from = ?,
                inclusive_date_to = ?,
                retention_period = ?,
                disposition_type = ?,
                description = ?,
                status = ?
            WHERE record_id = ?";

        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            $record_title,
            $office_id,
            $record_series_code,
            $class_id,
            $inclusive_date_from ?: null,
            $inclusive_date_to ?: null,
            $retention_period,
            $disposition_type,
            $description,
            $status,
            $record_id
        ]);

        if (!$success) {
            throw new Exception('Failed to update record in database');
        }

        // Handle file uploads for edits
        $uploaded_files = [];
        if (!empty($_FILES['attachments']['name'][0])) {
            $uploaded_files = handleFileUploads($_FILES['attachments'], $record_id);
        }

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Record updated successfully',
            'uploaded_files' => $uploaded_files
        ]);
    } else {
        throw new Exception('Invalid action or record ID');
    }

} catch (Exception $e) {
    ob_clean();
    error_log("Process Record Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

ob_end_flush();

/**
 * Handle file uploads and insert into record_files table
 */
function handleFileUploads($files, $record_id)
{
    global $pdo;

    // Use absolute path for uploads directory
    $upload_dir = dirname(__DIR__) . '/uploads/records/';

    // Create upload directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception("Could not create upload directory: " . $upload_dir);
        }
    }

    // Check if directory is writable
    if (!is_writable($upload_dir)) {
        throw new Exception("Upload directory is not writable: " . $upload_dir);
    }

    $uploaded_files = [];

    // Debug: Log file information
    error_log("Processing file uploads for record ID: " . $record_id);
    error_log("Number of files: " . count($files['name']));

    // Handle multiple files
    $file_count = count($files['name']);

    for ($i = 0; $i < $file_count; $i++) {
        error_log("Processing file {$i}: " . $files['name'][$i] . " (Error: " . $files['error'][$i] . ")");
        
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $file_name = basename($files['name'][$i]);
            $file_tmp = $files['tmp_name'][$i];
            $file_size = $files['size'][$i];
            $file_type = $files['type'][$i];

            // Generate unique filename to prevent conflicts
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $unique_name = uniqid() . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $unique_name;

            // Validate file size (max 10MB)
            if ($file_size > 10 * 1024 * 1024) {
                throw new Exception("File {$file_name} is too large. Maximum size is 10MB.");
            }

            // Validate file type
            $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif'];
            $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            if (!in_array($file_extension, $allowed_types)) {
                throw new Exception("File type not allowed for {$file_name}. Allowed types: " . implode(', ', $allowed_types));
            }

            // Move uploaded file
            if (move_uploaded_file($file_tmp, $upload_path)) {
                // Get file tag if provided
                $file_tag = $_POST['file_tags'][$i] ?? '';

                // Insert into record_files table
                $sql = "INSERT INTO record_files (
                        record_id, 
                        file_name, 
                        file_path, 
                        file_type, 
                        file_size, 
                        file_tag,
                        uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)";

                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute([
                    $record_id,
                    $file_name,
                    $unique_name,
                    $file_type,
                    $file_size,
                    $file_tag,
                    $_SESSION['user_id']
                ]);

                if ($result) {
                    error_log("Successfully inserted file into database: " . $file_name);
                    $uploaded_files[] = $file_name;
                } else {
                    error_log("Failed to insert file into database: " . $file_name);
                    throw new Exception("Failed to save file information to database: {$file_name}");
                }
            } else {
                error_log("Failed to move uploaded file: " . $file_name);
                throw new Exception("Failed to upload file: {$file_name}");
            }
        } else {
            error_log("File upload error for {$files['name'][$i]}: " . $files['error'][$i]);
        }
    }

    error_log("Total files uploaded successfully: " . count($uploaded_files));
    return $uploaded_files;
}