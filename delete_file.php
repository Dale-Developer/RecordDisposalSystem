<?php
// delete_file.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once 'session.php';
require_once 'db_connect.php';

header('Content-Type: application/json');

try {
    error_log("=== delete_file.php START ===");
    
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized - Please log in');
    }

    if (!isset($_POST['file_id'])) {
        throw new Exception('No file ID provided');
    }

    $file_id = intval($_POST['file_id']);
    $user_id = $_SESSION['user_id'];
    
    error_log("Deleting file ID: $file_id by user ID: $user_id");

    // First, get file info to verify ownership
    $check_sql = "SELECT rf.*, r.created_by 
                  FROM record_files rf 
                  JOIN records r ON rf.record_id = r.record_id 
                  WHERE rf.file_id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$file_id]);
    $file_info = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file_info) {
        throw new Exception('File not found');
    }

    // Check permissions
    $user_sql = "SELECT role_id FROM users WHERE user_id = ?";
    $user_stmt = $pdo->prepare($user_sql);
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    $can_delete = false;
    
    if (in_array($user['role_id'], [1, 2])) {
        $can_delete = true; // Admin/RMO
    } elseif ($file_info['created_by'] == $user_id) {
        $can_delete = true; // Creator
    }

    if (!$can_delete) {
        throw new Exception('You do not have permission to delete this file');
    }

    // CORRECTED: Use DELETE instead of UPDATE is_deleted
    $delete_sql = "DELETE FROM record_files WHERE file_id = ?";
    $delete_stmt = $pdo->prepare($delete_sql);
    $result = $delete_stmt->execute([$file_id]);

    if ($result && $delete_stmt->rowCount() > 0) {
        // Delete physical file
        if (file_exists($file_info['file_path'])) {
            unlink($file_info['file_path']);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'File deleted successfully'
        ]);
    } else {
        throw new Exception('Failed to delete file from database');
    }

} catch (Exception $e) {
    error_log("ERROR: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>