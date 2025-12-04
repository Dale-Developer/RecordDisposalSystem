<?php
// process_record.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();
ob_start();

try {
    error_log("=== process_record.php DEBUG START ===");
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized - User not logged in']);
        exit();
    }
    
    $user_id = $_SESSION['user_id'];
    error_log("User ID: " . $user_id);
    
    // Load database connection
    $script_dir = __DIR__;
    $possiblePaths = [
        $script_dir . '/../db_connect.php',
        $script_dir . '/db_connect.php',
        'db_connect.php',
        '../db_connect.php',
        '../../db_connect.php',
    ];
    
    $dbLoaded = false;
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $dbLoaded = true;
            break;
        }
    }
    
    if (!$dbLoaded) {
        echo json_encode(['success' => false, 'message' => 'Database configuration not found']);
        exit();
    }
    
    if (!isset($pdo)) {
        echo json_encode(['success' => false, 'message' => 'Database connection not established']);
        exit();
    }
    
    // Get action
    $action = $_POST['action'] ?? '';
    error_log("Action: " . $action);
    
    if (empty($action)) {
        echo json_encode(['success' => false, 'message' => 'No action specified']);
        exit();
    }
    
    if ($action === 'add' || $action === 'edit') {
        // Validate required fields
        $required_fields = [
            'record_series_title',
            'office_id', 
            'record_series_code',
            'class_id',
            'period_from'
        ];
        
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            echo json_encode(['success' => false, 'message' => 'Required fields missing: ' . implode(', ', $missing_fields)]);
            exit();
        }
        
        // Get record_id for edit action
        $record_id = isset($_POST['record_id']) ? intval($_POST['record_id']) : null;
        
        if ($action === 'edit' && empty($record_id)) {
            echo json_encode(['success' => false, 'message' => 'Record ID is required for edit']);
            exit();
        }
        
        // --- Handle records_medium checkboxes for ENUM field ---
        function getCombinedMediumValue($selectedMediums) {
            if (empty($selectedMediums)) return 'Paper';
            
            sort($selectedMediums);
            $selectedMediums = array_unique($selectedMediums);
            $count = count($selectedMediums);
            
            if ($count === 1) {
                return $selectedMediums[0];
            }
            
            $combined = implode('/', $selectedMediums);
            
            $allowed_combined = [
                'Paper/Electronic Files',
                'Paper/Computer Printouts', 
                'Electronic Files/Computer Printouts',
                'Paper/Electronic Files/Computer Printouts'
            ];
            
            if (in_array($combined, $allowed_combined)) {
                return $combined;
            }
            
            if ($count === 3 && 
                in_array('Paper', $selectedMediums) && 
                in_array('Electronic Files', $selectedMediums) && 
                in_array('Computer Printouts', $selectedMediums)) {
                return 'Paper/Electronic Files/Computer Printouts';
            }
            
            return $selectedMediums[0];
        }

        $records_medium = 'Paper';
        $selectedMediums = [];

        if (isset($_POST['records_medium']) && is_array($_POST['records_medium'])) {
            $selectedMediums = array_filter($_POST['records_medium']);
        } 
        elseif (isset($_POST['records_medium']) && !empty($_POST['records_medium'])) {
            $records_medium = $_POST['records_medium'];
        }

        if (!empty($selectedMediums)) {
            $records_medium = getCombinedMediumValue($selectedMediums);
        }

        $allowed_mediums = [
            'Paper', 
            'Electronic Files', 
            'Computer Printouts', 
            'Paper/Electronic Files', 
            'Paper/Computer Printouts', 
            'Electronic Files/Computer Printouts', 
            'Paper/Electronic Files/Computer Printouts'
        ];

        if (!in_array($records_medium, $allowed_mediums)) {
            $records_medium = 'Paper';
        }

        error_log("records_medium: " . $records_medium);
        
        // Validate retention period
        $active_years = isset($_POST['active_years']) ? intval($_POST['active_years']) : 0;
        $storage_years = isset($_POST['storage_years']) ? intval($_POST['storage_years']) : 0;
        $time_value = isset($_POST['time_value']) ? $_POST['time_value'] : 'Temporary';
        
        if ($time_value !== 'Permanent' && $active_years === 0 && $storage_years === 0) {
            echo json_encode(['success' => false, 'message' => 'Retention period is required for non-permanent records.']);
            exit();
        }
        
        $total_years = $active_years + $storage_years;
        
        // Prepare record data - ONLY include columns that exist in your table
        $record_data = [
            'record_series_title' => trim($_POST['record_series_title']),
            'office_id' => intval($_POST['office_id']),
            'record_series_code' => trim($_POST['record_series_code']),
            'class_id' => intval($_POST['class_id']),
            'period_from' => $_POST['period_from'],
            'period_to' => isset($_POST['period_to']) && !empty($_POST['period_to']) ? $_POST['period_to'] : null,
            'active_years' => $active_years,
            'storage_years' => $storage_years,
            'total_years' => $total_years,
            'disposition_type' => isset($_POST['disposition_type']) ? trim($_POST['disposition_type']) : 'Active',
            'time_value' => $time_value,
            'volume' => isset($_POST['volume']) && !empty($_POST['volume']) ? floatval($_POST['volume']) : 0,
            'records_medium' => $records_medium,
            'restrictions' => isset($_POST['restrictions']) ? trim($_POST['restrictions']) : 'Open Access',
            'location_of_records' => isset($_POST['location_of_records']) ? trim($_POST['location_of_records']) : '',
            'frequency_of_use' => isset($_POST['frequency_of_use']) ? trim($_POST['frequency_of_use']) : '',
            'duplication' => isset($_POST['duplication']) ? trim($_POST['duplication']) : '',
            'utility_value' => isset($_POST['utility_value']) ? trim($_POST['utility_value']) : '',
            'description' => isset($_POST['description']) ? trim($_POST['description']) : '',
            'disposition_provision' => isset($_POST['disposition_provision']) ? trim($_POST['disposition_provision']) : '',
            'status' => isset($_POST['status']) ? trim($_POST['status']) : 'Active',
            // 'updated_at' is automatically handled by MySQL with ON UPDATE CURRENT_TIMESTAMP
        ];
        
        // Add created_by and creator_office_id for new records
        if ($action === 'add') {
            $record_data['created_by'] = $user_id;
            $record_data['date_created'] = date('Y-m-d');
            // Note: creator_office_id might need to be set based on user's office
            // $record_data['creator_office_id'] = get_user_office_id($user_id);
        }
        
        error_log("Record data: " . print_r($record_data, true));
        
        if ($action === 'add') {
            // Insert new record
            $columns = implode(', ', array_keys($record_data));
            $placeholders = ':' . implode(', :', array_keys($record_data));
            
            $sql = "INSERT INTO records ($columns) VALUES ($placeholders)";
            error_log("Insert SQL: " . $sql);
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($record_data);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Failed to insert record: " . ($errorInfo[2] ?? 'Unknown error'));
            }
            
            $record_id = $pdo->lastInsertId();
            error_log("New record ID: " . $record_id);
            
            $message = 'Record created successfully';
            
        } elseif ($action === 'edit') {
            error_log("Editing record ID: " . $record_id);
            
            // Check if record exists
            $check_sql = "SELECT COUNT(*) FROM records WHERE record_id = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$record_id]);
            $record_exists = $check_stmt->fetchColumn();
            
            if (!$record_exists) {
                throw new Exception('Record not found with ID: ' . $record_id);
            }
            
            $set_clauses = [];
            foreach ($record_data as $key => $value) {
                $set_clauses[] = "$key = :$key";
            }
            
            $sql = "UPDATE records SET " . implode(', ', $set_clauses) . " WHERE record_id = :record_id";
            $record_data['record_id'] = $record_id;
            
            error_log("Update SQL: " . $sql);
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($record_data);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Failed to update record: " . ($errorInfo[2] ?? 'Unknown error'));
            }
            
            $message = 'Record updated successfully';
            
            // Handle removed files
            if (isset($_POST['removed_files']) && is_array($_POST['removed_files'])) {
                foreach ($_POST['removed_files'] as $file_id) {
                    $file_id = intval($file_id);
                    if ($file_id > 0) {
                        // Get file path
                        $get_file_sql = "SELECT file_path FROM record_files WHERE file_id = ? AND record_id = ?";
                        $get_file_stmt = $pdo->prepare($get_file_sql);
                        $get_file_stmt->execute([$file_id, $record_id]);
                        $file = $get_file_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($file && isset($file['file_path']) && file_exists($file['file_path'])) {
                            unlink($file['file_path']);
                        }
                        
                        // Delete from database
                        $delete_sql = "DELETE FROM record_files WHERE file_id = ? AND record_id = ?";
                        $delete_stmt = $pdo->prepare($delete_sql);
                        $delete_stmt->execute([$file_id, $record_id]);
                    }
                }
            }
            
            // Update existing file tags
            if (isset($_POST['existing_file_tags']) && is_array($_POST['existing_file_tags'])) {
                foreach ($_POST['existing_file_tags'] as $file_id => $tag) {
                    $file_id = intval($file_id);
                    if ($file_id > 0) {
                        $tag_sql = "UPDATE record_files SET file_tag = ? WHERE file_id = ? AND record_id = ?";
                        $tag_stmt = $pdo->prepare($tag_sql);
                        $tag_stmt->execute([trim($tag), $file_id, $record_id]);
                    }
                }
            }
        }
        
        // Handle file uploads
        $uploaded_files_count = 0;
        if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name']) && count($_FILES['attachments']['name']) > 0) {
            error_log("Processing file uploads...");
            
            // Create upload directory
            $upload_dir = __DIR__ . '/../uploads/records/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_tags = isset($_POST['file_tags']) && is_array($_POST['file_tags']) ? $_POST['file_tags'] : [];
            
            for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
                if (!isset($_FILES['attachments']['name'][$i]) || 
                    $_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK ||
                    empty($_FILES['attachments']['name'][$i])) {
                    continue;
                }
                
                $file_name = $_FILES['attachments']['name'][$i];
                $file_tmp = $_FILES['attachments']['tmp_name'][$i];
                $file_size = $_FILES['attachments']['size'][$i];
                $file_type = $_FILES['attachments']['type'][$i];
                
                // Validate file size (max 10MB)
                if ($file_size > 10 * 1024 * 1024) {
                    continue;
                }
                
                // Generate safe filename
                $original_name = pathinfo($file_name, PATHINFO_FILENAME);
                $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
                $safe_name = preg_replace('/[^a-zA-Z0-9-_]/', '', $original_name);
                $unique_name = $safe_name . '_' . uniqid() . '.' . $file_ext;
                $file_path = $upload_dir . $unique_name;
                
                // Move uploaded file
                if (move_uploaded_file($file_tmp, $file_path)) {
                    // Get file tag
                    $file_tag = '';
                    if (isset($file_tags[$i]) && !empty(trim($file_tags[$i]))) {
                        $file_tag = trim($file_tags[$i]);
                    }
                    
                    // Insert file record
                    $file_sql = "INSERT INTO record_files 
                                (record_id, file_name, file_path, file_type, file_size, file_tag, uploaded_by) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)";
                    
                    $file_stmt = $pdo->prepare($file_sql);
                    $file_result = $file_stmt->execute([
                        $record_id,
                        $file_name,
                        $file_path,
                        $file_type,
                        $file_size,
                        $file_tag,
                        $user_id
                    ]);
                    
                    if ($file_result) {
                        $uploaded_files_count++;
                    }
                }
            }
        }
        
        // Return success response
        $response = [
            'success' => true,
            'message' => $message,
            'record_id' => $record_id,
            'files_uploaded' => $uploaded_files_count
        ];
        
        echo json_encode($response);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
    }
    
} catch (Exception $e) {
    error_log("ERROR: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

ob_end_flush();
?>