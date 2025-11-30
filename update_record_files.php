<?php
// update_record_files.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once '../Record_Disposal_System/session.php';
require_once '../Record_Disposal_System/db_connect.php';

header('Content-Type: application/json');

try {
    error_log("=== update_record_files.php START ===");
    
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized - Please log in');
    }

    if (!isset($_POST['record_id'])) {
        throw new Exception('No record ID provided');
    }

    $record_id = intval($_POST['record_id']);
    $user_id = $_SESSION['user_id'];
    
    error_log("Updating record ID: $record_id by user ID: $user_id");

    // Handle file deletions if any
    if (isset($_POST['deleted_files']) && !empty($_POST['deleted_files'])) {
        $deleted_files = json_decode($_POST['deleted_files'], true);
        
        if (is_array($deleted_files)) {
            foreach ($deleted_files as $file_id) {
                $file_id = intval($file_id);
                error_log("Processing deletion for file ID: $file_id");
                
                // Use the same deletion logic as delete_file.php
                $delete_sql = "DELETE FROM record_files WHERE file_id = ?";
                $delete_stmt = $pdo->prepare($delete_sql);
                $delete_stmt->execute([$file_id]);
                
                error_log("Deleted file ID: $file_id");
            }
        }
    }

    // Handle record field updates
    $update_fields = [];
    $update_values = [];
    
    // List of allowed fields to update
    $allowed_fields = [
        'record_title', 'description', 'record_series_code', 
        'inclusive_date_from', 'inclusive_date_to', 'retention_period',
        'disposition_type', 'status', 'class_id', 'office_id'
    ];
    
    foreach ($allowed_fields as $field) {
        if (isset($_POST[$field])) {
            $update_fields[] = "$field = ?";
            $update_values[] = $_POST[$field];
        }
    }
    
    // Only proceed if there are fields to update
    if (!empty($update_fields)) {
        $update_values[] = $record_id; // For WHERE clause
        
        $update_sql = "UPDATE records SET " . implode(', ', $update_fields) . " WHERE record_id = ?";
        error_log("Executing update SQL: $update_sql");
        
        $update_stmt = $pdo->prepare($update_sql);
        $update_result = $update_stmt->execute($update_values);
        
        if (!$update_result) {
            throw new Exception('Failed to update record');
        }
        
        error_log("Record updated successfully");
    }

    // Handle new file uploads
    if (!empty($_FILES)) {
        error_log("Processing new file uploads");
        require_once 'file_upload_handler.php'; // You'll need to create this
    }

    echo json_encode([
        'success' => true,
        'message' => 'Record updated successfully',
        'record_id' => $record_id
    ]);

    error_log("=== update_record_files.php END - SUCCESS ===");

} catch (Exception $e) {
    error_log("=== update_record_files.php END - ERROR ===");
    error_log("ERROR: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>