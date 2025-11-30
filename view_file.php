<?php
// view_file.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once 'session.php';
require_once 'db_connect.php';

try {
    error_log("=== view_file.php START ===");
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized - Please log in');
    }

    // Check if file ID is provided
    if (!isset($_GET['id'])) {
        throw new Exception('No file ID provided');
    }

    $file_id = intval($_GET['id']);
    $user_id = $_SESSION['user_id'];
    
    error_log("Viewing file ID: $file_id by user ID: $user_id");

    // Get file information from database
    $sql = "SELECT rf.*, r.record_id, r.record_title, r.office_id, o.office_name, 
                   u.first_name, u.last_name, rc.security_level
            FROM record_files rf
            JOIN records r ON rf.record_id = r.record_id
            LEFT JOIN offices o ON r.office_id = o.office_id
            LEFT JOIN users u ON rf.uploaded_by = u.user_id
            LEFT JOIN record_classification rc ON r.class_id = rc.class_id
            WHERE rf.file_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$file_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        throw new Exception('File not found');
    }

    error_log("File found: " . $file['file_name']);

    // Check security level and permissions
    checkFileAccess($file, $user_id);

    // Check if physical file exists
    if (!file_exists($file['file_path'])) {
        throw new Exception('Physical file not found on server');
    }

    // Get file MIME type
    $mime_type = getFileMimeType($file['file_path'], $file['file_type']);
    
    error_log("Serving file: " . $file['file_name'] . " as " . $mime_type);

    // Set headers and output file
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . filesize($file['file_path']));
    header('Content-Disposition: inline; filename="' . $file['file_name'] . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Clear output buffer and send file
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    readfile($file['file_path']);
    exit;

} catch (Exception $e) {
    error_log("=== view_file.php ERROR ===");
    error_log("ERROR: " . $e->getMessage());
    
    // Show error page
    showErrorPage($e->getMessage());
}

function checkFileAccess($file, $user_id) {
    global $pdo;
    
    // Get user role and office
    $user_sql = "SELECT role_id, office_id FROM users WHERE user_id = ?";
    $user_stmt = $pdo->prepare($user_sql);
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User not found');
    }

    $can_access = false;

    // Admin and RMO can access all files
    if (in_array($user['role_id'], [1, 2])) {
        $can_access = true;
        error_log("Admin/RMO access granted");
    }
    // File uploader can access their own files
    elseif ($file['uploaded_by'] == $user_id) {
        $can_access = true;
        error_log("Uploader access granted");
    }
    // Custodian can access files from their office
    elseif ($user['role_id'] == 4 && $user['office_id'] == $file['office_id']) {
        $can_access = true;
        error_log("Custodian access granted for office: " . $user['office_id']);
    }
    // Check if file is public
    elseif ($file['security_level'] === 'Public') {
        $can_access = true;
        error_log("Public file access granted");
    }

    if (!$can_access) {
        throw new Exception('You do not have permission to view this file');
    }
}

function getFileMimeType($file_path, $fallback_type = 'application/octet-stream') {
    if (function_exists('finfo_file')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);
        return $mime_type ?: $fallback_type;
    }
    
    // Fallback MIME types
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $mime_types = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'txt'  => 'text/plain',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    
    return $mime_types[$extension] ?? $fallback_type;
}

function showErrorPage($error_message) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>File View Error - LSPU Record Disposal System</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                background: #f5f5f5; 
                margin: 0; 
                padding: 20px; 
                display: flex; 
                justify-content: center; 
                align-items: center; 
                min-height: 100vh; 
            }
            .error-container { 
                background: white; 
                padding: 2rem; 
                border-radius: 8px; 
                box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
                text-align: center; 
                max-width: 500px; 
            }
            .error-icon { 
                font-size: 3rem; 
                color: #dc3545; 
                margin-bottom: 1rem; 
            }
            .error-title { 
                color: #dc3545; 
                margin-bottom: 1rem; 
            }
            .error-message { 
                color: #666; 
                margin-bottom: 2rem; 
            }
            .btn { 
                display: inline-block; 
                padding: 10px 20px; 
                background: #007bff; 
                color: white; 
                text-decoration: none; 
                border-radius: 4px; 
                border: none; 
                cursor: pointer; 
            }
            .btn:hover { 
                background: #0056b3; 
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1 class="error-title">Cannot View File</h1>
            <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
            <button class="btn" onclick="window.history.back()">Go Back</button>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>