<?php
// process_record.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once 'session.php';
require_once 'db_connect.php';

header('Content-Type: application/json');

try {
    error_log("=== process_record.php START ===");
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized - Please log in');
    }

    $user_id = $_SESSION['user_id'];
    $action = $_GET['action'] ?? '';

    error_log("Action: $action, User ID: $user_id");

    if ($action === 'add') {
        handleAddRecord($pdo, $user_id);
    } elseif ($action === 'edit') {
        handleEditRecord($pdo, $user_id);
    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    error_log("=== process_record.php ERROR ===");
    error_log("ERROR: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

function handleAddRecord($pdo, $user_id) {
    error_log("Handling ADD record");
    
    // Validate required fields
    $required = ['record_title', 'office_id', 'record_series_code', 'class_id', 'retention_period', 'disposition_type'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Required field missing: $field");
        }
    }

    // Insert record
    $sql = "INSERT INTO records (
        record_title, office_id, record_series_code, description, 
        class_id, date_created, inclusive_date_from, inclusive_date_to,
        retention_period, disposition_type, status, created_by
    ) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)";

    $stmt = $pdo->prepare($sql);
    
    $status = $_POST['status'] ?? 'Active';
    
    $result = $stmt->execute([
        $_POST['record_title'],
        $_POST['office_id'],
        $_POST['record_series_code'],
        $_POST['description'] ?? '',
        $_POST['class_id'],
        $_POST['inclusive_date_from'] ?? null,
        $_POST['inclusive_date_to'] ?? null,
        $_POST['retention_period'],
        $_POST['disposition_type'],
        $status,
        $user_id
    ]);

    if (!$result) {
        throw new Exception('Failed to create record');
    }

    $record_id = $pdo->lastInsertId();
    error_log("New record created with ID: $record_id");

    // Handle file uploads
    handleFileUploads($pdo, $record_id, $user_id);

    echo json_encode([
        'success' => true,
        'message' => 'Record created successfully',
        'record_id' => $record_id
    ]);
}

function handleEditRecord($pdo, $user_id) {
    error_log("Handling EDIT record");
    
    if (empty($_POST['record_id'])) {
        throw new Exception('Record ID is required for editing');
    }

    $record_id = intval($_POST['record_id']);

    // Validate required fields
    $required = ['record_title', 'office_id', 'record_series_code', 'class_id', 'retention_period', 'disposition_type'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Required field missing: $field");
        }
    }

    // Update record
    $sql = "UPDATE records SET 
        record_title = ?, office_id = ?, record_series_code = ?, description = ?,
        class_id = ?, inclusive_date_from = ?, inclusive_date_to = ?,
        retention_period = ?, disposition_type = ?, status = ?
    WHERE record_id = ?";

    $stmt = $pdo->prepare($sql);
    
    $status = $_POST['status'] ?? 'Active';
    
    $result = $stmt->execute([
        $_POST['record_title'],
        $_POST['office_id'],
        $_POST['record_series_code'],
        $_POST['description'] ?? '',
        $_POST['class_id'],
        $_POST['inclusive_date_from'] ?? null,
        $_POST['inclusive_date_to'] ?? null,
        $_POST['retention_period'],
        $_POST['disposition_type'],
        $status,
        $record_id
    ]);

    if (!$result) {
        throw new Exception('Failed to update record');
    }

    error_log("Record updated: $record_id");

    // Handle file deletions FIRST
    handleFileDeletions($pdo, $record_id, $user_id);

    // Handle new file uploads
    handleFileUploads($pdo, $record_id, $user_id);

    // Handle existing file tag updates
    handleFileTagUpdates($pdo);

    echo json_encode([
        'success' => true,
        'message' => 'Record updated successfully',
        'record_id' => $record_id
    ]);
}

function handleFileDeletions($pdo, $record_id, $user_id) {
    error_log("Handling file deletions");
    
    if (!empty($_POST['removed_files']) && is_array($_POST['removed_files'])) {
        $removed_files = $_POST['removed_files'];
        error_log("Files to remove: " . implode(', ', $removed_files));
        
        foreach ($removed_files as $file_id) {
            $file_id = intval($file_id);
            
            // Verify the file belongs to this record and user has permission
            $check_sql = "SELECT rf.*, r.created_by 
                         FROM record_files rf 
                         JOIN records r ON rf.record_id = r.record_id 
                         WHERE rf.file_id = ? AND rf.record_id = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$file_id, $record_id]);
            $file_info = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($file_info) {
                // Check permissions - user must be creator or have admin role
                $user_sql = "SELECT role_id FROM users WHERE user_id = ?";
                $user_stmt = $pdo->prepare($user_sql);
                $user_stmt->execute([$user_id]);
                $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                
                $can_delete = false;
                
                // Admin and RMO can delete any file
                if (in_array($user['role_id'], [1, 2])) {
                    $can_delete = true;
                }
                // Creator can delete their own files
                elseif ($file_info['created_by'] == $user_id) {
                    $can_delete = true;
                }
                
                if ($can_delete) {
                    // CORRECTED: Use DELETE instead of UPDATE is_deleted
                    $delete_sql = "DELETE FROM record_files WHERE file_id = ?";
                    $delete_stmt = $pdo->prepare($delete_sql);
                    $delete_result = $delete_stmt->execute([$file_id]);
                    
                    if ($delete_result) {
                        error_log("Successfully deleted file ID: $file_id");
                        
                        // Optional: Delete physical file
                        if (file_exists($file_info['file_path'])) {
                            unlink($file_info['file_path']);
                            error_log("Physical file deleted: " . $file_info['file_path']);
                        }
                    } else {
                        error_log("Failed to delete file ID: $file_id");
                    }
                } else {
                    error_log("User doesn't have permission to delete file ID: $file_id");
                }
            } else {
                error_log("File not found or doesn't belong to record: $file_id");
            }
        }
    } else {
        error_log("No files to remove");
    }
}

function handleFileUploads($pdo, $record_id, $user_id) {
    error_log("Handling file uploads");
    
    if (!empty($_FILES['attachments']['name'][0])) {
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $files = $_FILES['attachments'];
        $file_tags = $_POST['file_tags'] ?? [];

        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $file_name = $files['name'][$i];
                $file_tmp = $files['tmp_name'][$i];
                $file_size = $files['size'][$i];
                $file_type = $files['type'][$i];
                $file_tag = $file_tags[$i] ?? '';

                // Generate unique filename
                $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                $unique_name = uniqid() . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $unique_name;

                if (move_uploaded_file($file_tmp, $file_path)) {
                    $sql = "INSERT INTO record_files (
                        record_id, file_name, file_path, file_type, file_size, file_tag, uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $record_id,
                        $file_name,
                        $file_path,
                        $file_type,
                        $file_size,
                        $file_tag,
                        $user_id
                    ]);

                    error_log("File uploaded successfully: $file_name");
                } else {
                    error_log("Failed to move uploaded file: $file_name");
                }
            }
        }
    } else {
        error_log("No new files to upload");
    }
}

function handleFileTagUpdates($pdo) {
    error_log("Handling file tag updates");
    
    if (!empty($_POST['existing_file_tags']) && is_array($_POST['existing_file_tags'])) {
        foreach ($_POST['existing_file_tags'] as $file_id => $file_tag) {
            $file_id = intval($file_id);
            $file_tag = trim($file_tag);
            
            $sql = "UPDATE record_files SET file_tag = ? WHERE file_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$file_tag, $file_id]);
            
            error_log("Updated tag for file ID: $file_id to: $file_tag");
        }
    } else {
        error_log("No file tags to update");
    }
}
?>