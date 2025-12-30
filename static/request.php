<?php
require_once '../session.php';
require_once '../db_connect.php';
require_once '../db_logger.php'; // ADD THIS LINE

// Create logger instance
$logger = new SystemLogger($pdo); // ADD THIS LINE

// Check database connection
if (!isset($pdo)) {
    die("Database connection not established");
}
$user_role = $_SESSION['role_id'] ?? 0;
$is_admin = ($user_role == 1); // Admin = role_id 1

// Check if disposal_request_details table exists
$tableCheck = $pdo->query("SHOW TABLES LIKE 'disposal_request_details'");
$tableExists = $tableCheck->rowCount() > 0;

if (!$tableExists) {
    $errorMessage = "Required database table 'disposal_request_details' does not exist. Please run the SQL script to create it.";
    $message = $errorMessage;
    $messageType = 'error';
}

// ========== PROCESS APPROVE/DECLINE REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    try {
        if (!$tableExists) {
            throw new Exception("Database setup incomplete. Please contact administrator.");
        }

        $request_id = (int) $_POST['request_id'];
        $action = $_POST['action_type'];
        $user_id = $_SESSION['user_id'] ?? null;
        $remarks = $_POST['remarks'] ?? null; // Get remarks from POST data

        if (!$user_id) {
            throw new Exception("User not logged in");
        }

        if ($request_id <= 0) {
            throw new Exception("Invalid request ID");
        }

        // Start transaction
        $pdo->beginTransaction();

        if ($action === 'approve') {
            // Get record IDs for this request BEFORE updating
            $recordStmt = $pdo->prepare("
                SELECT drd.record_id, r.status as current_status 
                FROM disposal_request_details drd
                JOIN records r ON drd.record_id = r.record_id
                WHERE drd.request_id = ?
            ");
            $recordStmt->execute([$request_id]);
            $records = $recordStmt->fetchAll(PDO::FETCH_ASSOC);
            $recordIds = array_column($records, 'record_id');

            // Update request status to Approved
            $stmt = $pdo->prepare("
                UPDATE disposal_requests 
                SET status = 'Approved', 
                    remarks = ?
                WHERE request_id = ?
            ");
            $stmt->execute([$remarks, $request_id]);

            // Log the disposal request approval
            $logger->logRequestApprove($request_id, $user_id, $recordIds, $remarks); // ADD THIS LINE

            // Update all records attached to this request to 'Disposed'
            $updateRecords = $pdo->prepare("
                UPDATE records r
                JOIN disposal_request_details drd ON r.record_id = drd.record_id
                SET r.status = 'Disposed'
                WHERE drd.request_id = ?
            ");
            $updateRecords->execute([$request_id]);

            // Log each record's status change
            foreach ($records as $record) {
                $logger->logRecordStatusChange(
                    $record['record_id'], 
                    $user_id, 
                    $record['current_status'], 
                    'Disposed', 
                    'Disposal approved - Request #' . $request_id
                ); // ADD THIS LINE
            }

            $_SESSION['message'] = "Request R-" . str_pad($request_id, 3, '0', STR_PAD_LEFT) . " has been approved. All attached records have been marked as Disposed.";
            $_SESSION['message_type'] = 'success';
        } elseif ($action === 'decline') {
            // Validate remarks for decline action
            if (empty(trim($remarks))) {
                throw new Exception("Remarks are required when declining a request. Please provide a reason.");
            }

            // Get record IDs for this request BEFORE updating
            $recordStmt = $pdo->prepare("
                SELECT drd.record_id, r.status as current_status 
                FROM disposal_request_details drd
                JOIN records r ON drd.record_id = r.record_id
                WHERE drd.request_id = ?
            ");
            $recordStmt->execute([$request_id]);
            $records = $recordStmt->fetchAll(PDO::FETCH_ASSOC);

            // Update request status to Rejected with remarks
            $stmt = $pdo->prepare("
                UPDATE disposal_requests 
                SET status = 'Rejected', 
                    remarks = ?
                WHERE request_id = ?
            ");
            $stmt->execute([$remarks, $request_id]);

            // Log the disposal request rejection
            $logger->logRequestReject($request_id, $user_id, $remarks); // ADD THIS LINE

            // Update all records attached to this request back to 'Archived'
            $updateRecords = $pdo->prepare("
                UPDATE records r
                JOIN disposal_request_details drd ON r.record_id = drd.record_id
                SET r.status = 'Archived'
                WHERE drd.request_id = ?
            ");
            $updateRecords->execute([$request_id]);

            // Log each record's status change
            foreach ($records as $record) {
                $logger->logRecordStatusChange(
                    $record['record_id'], 
                    $user_id, 
                    $record['current_status'], 
                    'Archived', 
                    'Disposal rejected - ' . $remarks
                ); // ADD THIS LINE
            }

            $_SESSION['message'] = "Request R-" . str_pad($request_id, 3, '0', STR_PAD_LEFT) . " has been rejected. All attached records have been returned to Archived status.";
            $_SESSION['message_type'] = 'success';
        } else {
            throw new Exception("Invalid action type");
        }

        // Commit transaction
        $pdo->commit();

        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } catch (Exception $e) {
        // Rollback on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Process disposal request form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_type']) && $_POST['submit_type'] === 'disposal_request') {
    try {
        // Check if table exists before processing
        if (!$tableExists) {
            throw new Exception("Database setup incomplete. Please contact administrator.");
        }

        // Validate required fields
        $required_fields = ['agency_name', 'request_date', 'agency_address'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Please fill in all required fields");
            }
        }

        // Validate at least one record is selected
        if (!isset($_POST['selected_records']) || empty($_POST['selected_records'])) {
            throw new Exception("Please select at least one record for disposal");
        }

        // Get the logged-in user ID
        $user_id = $_SESSION['user_id'] ?? null;
        if (!$user_id) {
            throw new Exception("User not logged in");
        }

        // Start transaction
        $pdo->beginTransaction();

        // Insert disposal request
        $stmt = $pdo->prepare("
            INSERT INTO disposal_requests 
            (agency_name, agency_address, agency_telephone, request_date, grds_rds_item_number, provisions_complied, status, requested_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, NOW())
        ");

        $stmt->execute([
            $_POST['agency_name'],
            $_POST['agency_address'],
            $_POST['agency_telephone'] ?? null,
            $_POST['request_date'],
            $_POST['grds_rds_item_number'] ?? null,
            $_POST['provisions_complied'] ?? null,
            $user_id
        ]);

        $request_id = $pdo->lastInsertId();

        // Log disposal request creation
        $logger->logRequestCreate($request_id, $user_id, 'New disposal request created'); // ADD THIS LINE

        // Insert request details for each selected record
        $stmt_details = $pdo->prepare("
            INSERT INTO disposal_request_details (request_id, record_id) 
            VALUES (?, ?)
        ");

        // Update record status
        $stmt_update = $pdo->prepare("
            UPDATE records 
            SET status = 'Scheduled for Disposal' 
            WHERE record_id = ?
        ");

        foreach ($_POST['selected_records'] as $record_id) {
            $stmt_details->execute([$request_id, $record_id]);
            $stmt_update->execute([$record_id]);
            
            // Log each record's status change
            $logger->logRecordStatusChange(
                $record_id, 
                $user_id, 
                'Archived', 
                'Scheduled for Disposal', 
                'Added to disposal request #' . $request_id
            ); // ADD THIS LINE
        }

        // Commit transaction
        $pdo->commit();

        // Success message
        $_SESSION['message'] = "Disposal request submitted successfully! Request ID: R-" . str_pad($request_id, 3, '0', STR_PAD_LEFT);
        $_SESSION['message_type'] = 'success';

        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } catch (Exception $e) {
        // Rollback on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Check for session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'] ?? 'success';
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
} elseif (!isset($message)) {
    $message = '';
    $messageType = '';
}

// Query to get archived records for disposal
try {
    if ($tableExists) {
        // Debug: Check what statuses exist
        $debugSql = "SELECT DISTINCT status FROM records";
        $debugStmt = $pdo->query($debugSql);
        $statuses = $debugStmt->fetchAll(PDO::FETCH_COLUMN);
        error_log("Available statuses in records: " . implode(', ', $statuses));

        // Get archived records
        $sql = "SELECT 
                    r.record_id,
                    r.record_series_code,
                    r.record_series_title as record_title,
                    o.office_name,
                    rc.class_name,
                    r.period_from as inclusive_date_from,
                    r.period_to as inclusive_date_to,
                    r.total_years,
                    rp.period_name as retention_period,
                    r.status
                FROM records r
                JOIN offices o ON r.office_id = o.office_id
                JOIN record_classification rc ON r.class_id = rc.class_id
                LEFT JOIN retention_periods rp ON r.retention_period_id = rp.period_id
                WHERE r.status = 'Archived'  -- ONLY Archived status
                AND r.record_id NOT IN (
                    SELECT drd.record_id 
                    FROM disposal_request_details drd
                    JOIN disposal_requests dr ON drd.request_id = dr.request_id
                    WHERE dr.status IN ('Pending', 'Approved')  -- Exclude from Pending AND Approved requests
                )
                ORDER BY r.record_series_code ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $disposableRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        error_log("Found " . count($disposableRecords) . ' archived records for disposal');

        // Debug: Show what we found
        if (count($disposableRecords) > 0) {
            foreach ($disposableRecords as $record) {
                error_log("Record ID: {$record['record_id']}, Code: {$record['record_series_code']}, " .
                    "Title: {$record['record_title']}, Status: {$record['status']}");
            }
        }

        // Check why records might be excluded
        if (count($disposableRecords) === 0) {
            // Check all archived records and why they're excluded
            $checkSql = "SELECT 
                            r.record_id,
                            r.record_series_code,
                            r.record_series_title,
                            r.status,
                            CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM disposal_request_details drd
                                    JOIN disposal_requests dr ON drd.request_id = dr.request_id
                                    WHERE drd.record_id = r.record_id AND dr.status IN ('Pending', 'Approved')
                                ) THEN 'In pending/approved request'
                                ELSE 'Available'
                            END as availability
                        FROM records r
                        WHERE r.status = 'Archived'";

            $checkStmt = $pdo->query($checkSql);
            $checkResults = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

            error_log("Debug check of ALL archived records:");
            foreach ($checkResults as $check) {
                error_log("Record ID: {$check['record_id']}, Code: {$check['record_series_code']}, " .
                    "Title: {$check['record_series_title']}, Status: {$check['status']}, Availability: {$check['availability']}");
            }
        }
    } else {
        $disposableRecords = [];
    }
} catch (Exception $e) {
    $disposableRecords = [];
    if (!isset($message) || empty($message)) {
        $message = "Error loading records: " . $e->getMessage();
        $messageType = 'error';
    }
    error_log("Error in disposable records query: " . $e->getMessage());
}

// Query for disposal requests
try {
    if ($tableExists) {
        $requestsSql = "SELECT 
                            dr.*, 
                            u.first_name, 
                            u.last_name,
                            u.email,
                            COUNT(drd.record_id) as record_count,
                            (SELECT MIN(period_from) FROM records r2 
                             JOIN disposal_request_details drd2 ON r2.record_id = drd2.record_id 
                             WHERE drd2.request_id = dr.request_id) as oldest_period,
                            (SELECT MAX(COALESCE(period_to, period_from)) FROM records r2 
                             JOIN disposal_request_details drd2 ON r2.record_id = drd2.record_id 
                             WHERE drd2.request_id = dr.request_id) as latest_period
                        FROM disposal_requests dr 
                        JOIN users u ON dr.requested_by = u.user_id 
                        LEFT JOIN disposal_request_details drd ON dr.request_id = drd.request_id
                        GROUP BY dr.request_id
                        ORDER BY dr.request_id DESC";

        $requestsStmt = $pdo->prepare($requestsSql);
        $requestsStmt->execute();
        $disposalRequests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $disposalRequests = [];
    }
} catch (Exception $e) {
    $disposalRequests = [];
    if (!isset($message) || empty($message)) {
        $message = "Error loading disposal requests: " . $e->getMessage();
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requests Management</title>
    <link rel="stylesheet" href="../styles/request.css">
    <link rel="icon" type="image/x-icon" href="../imgs/fav.png">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* Additional styles for approve/decline buttons */
        .btn.approve {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
        }

        .btn.approve:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn.decline {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
        }

        .btn.decline:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            padding: 20px 30px;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .modal-footer-left {
            display: flex;
            gap: 12px;
        }

        .modal-footer-right {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 140px;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn i {
            margin-right: 8px;
            font-size: 1.1em;
        }

        .btn-cancel {
            background: #6b7280;
            color: white;
            border: none;
        }

        .btn-cancel:hover {
            background: #4b5563;
        }

        .btn-submit {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            border: none;
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 0.85em;
            font-weight: 600;
            display: inline-block;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
            /* border: 1px solid #fbbf24; */
        }

        .status-approved {
            background-color: #d1fae5;
            color: #065f46;
            /* border: 1px solid #10b981; */
        }

        .status-rejected {
            background-color: #fee2e2;
            color: #991b1b;
            /* border: 1px solid #f87171; */
        }

        .status-disposed {
            background-color: #e0f2fe;
            color: #075985;
            /* border: 1px solid #0ea5e9; */
        }

        /* Decline Modal Specific Styles */
        #decline-modal .disposal-modal-content {
            background: linear-gradient(135deg, #ffffff 0%, #fef2f2 100%);
            /* border-top: 4px solid #ef4444; */
        }

        #decline-modal .form-textarea {
            min-height: 120px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            font-family: inherit;
            resize: vertical;
        }

        #decline-modal .form-textarea:focus {
            outline: none;
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }

        .char-count {
            font-size: 0.85em;
            color: #6b7280;
        }

        .required-text {
            font-size: 0.85em;
            font-weight: 500;
        }

        /* Remarks Display in View Modal */
        .view-remarks {
            background-color: #f8f9fa;
            border-left: 4px solid #ef4444;
            padding: 12px 15px;
            border-radius: 0 8px 8px 0;
            margin-top: 10px;
            font-style: italic;
            color: #4b5563;
        }

        .view-remarks.approved {
            border-left-color: #10b981;
        }

        .view-remarks.rejected {
            border-left-color: #ef4444;
        }
    </style>
</head>

<body>
    <nav class="sidebar">
        <?php include 'sidebar.php'; ?>
    </nav>

    <main>
        <!-- REQUESTS TABLE VIEW -->
        <div class="header">
            <h1>REQUESTS MANAGEMENT</h1>
            <div class="actions">
                <button class="button primary-action-btn" onclick="openDisposalModal()" <?php echo !$tableExists ? 'disabled' : ''; ?>>
                    <i class='bx bx-trash'></i> New Disposal Request
                </button>
            </div>
        </div>

        <!-- Display messages -->
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType === 'error' ? 'error' : 'success'; ?>">
                <i class='bx <?php echo $messageType === 'error' ? 'bx-error-circle' : 'bx-check-circle'; ?>'></i>
                <?php echo htmlspecialchars($message); ?>
                <button onclick="this.parentElement.style.display='none'">
                    <i class='bx bx-x'></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Database Setup Warning -->
        <?php if (!$tableExists): ?>
            <div class="message error">
                <i class='bx bx-error-circle'></i>
                Database setup required. Please run this SQL in your database:
                <pre style="background: #f5f5f5; padding: 10px; margin: 10px 0; border-radius: 4px; overflow: auto;">
                    -- 1. First drop the foreign key constraint
                    ALTER TABLE `disposal_requests` DROP FOREIGN KEY `disposal_requests_ibfk_1`;

                    -- 2. Then drop the column
                    ALTER TABLE `disposal_requests` DROP COLUMN `record_id`;

                    -- 3. Create disposal_request_details table
                    CREATE TABLE IF NOT EXISTS `disposal_request_details` (
                      `disposal_detail_id` int(11) NOT NULL AUTO_INCREMENT,
                      `request_id` int(11) NOT NULL,
                      `record_id` int(11) NOT NULL,
                      PRIMARY KEY (`disposal_detail_id`),
                      UNIQUE KEY `unique_disposal_request_record` (`request_id`,`record_id`),
                      KEY `record_id` (`record_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

                    -- 4. Add foreign key constraints
                    ALTER TABLE `disposal_request_details`
                      ADD CONSTRAINT `disposal_request_details_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `disposal_requests` (`request_id`) ON DELETE CASCADE,
                      ADD CONSTRAINT `disposal_request_details_ibfk_2` FOREIGN KEY (`record_id`) REFERENCES `records` (`record_id`);
                                    </pre>
            </div>
        <?php endif; ?>

        <!-- Disposal Requests Table -->
        <div class="card dashboard-card">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>REQUEST ID</th>
                            <th>AGENCY NAME</th>
                            <th>RECORD INFORMATION</th>
                            <th>REQUESTED BY</th>
                            <th>DATE</th>
                            <th>PERIOD COVERED</th>
                            <th>STATUS</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($disposalRequests)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 3rem; color: #78909c;">
                                    <i class='bx bx-package'
                                        style="font-size: 3rem; display: block; margin-bottom: 1rem;"></i>
                                    <?php echo $tableExists ? 'No disposal requests found' : 'Database setup required'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($disposalRequests as $request): ?>
                                <tr>
                                    <td>
                                        <strong
                                            style="color: #1e3a8a;">R-<?php echo str_pad($request['request_id'], 3, '0', STR_PAD_LEFT); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($request['agency_name']); ?></td>
                                    <td>
                                        <div style="font-weight: 600; color: #1f366c;">
                                            <?php echo $request['record_count'] ?? 0; ?> Record(s)
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>
                                    </td>
                                    <td style="white-space: nowrap">
                                        <?php echo date('m/d/Y', strtotime($request['request_date'])); ?>
                                    </td>
                                    <td style="white-space: nowrap">
                                        <?php
                                        // Calculate period covered
                                        if (!empty($request['oldest_period']) && !empty($request['latest_period'])) {
                                            $oldest = date('Y', strtotime($request['oldest_period']));
                                            $latest = date('Y', strtotime($request['latest_period']));

                                            if ($oldest == $latest) {
                                                echo htmlspecialchars($oldest);
                                            } else {
                                                echo htmlspecialchars($oldest . '-' . $latest);
                                            }
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td style="width: 1%; white-space: nowrap;">
                                        <span class="status-badge status-<?php echo strtolower($request['status']); ?>">
                                            <?php echo $request['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="view-btn" data-request-id="<?php echo $request['request_id']; ?>" <?php echo !$tableExists ? 'disabled' : ''; ?>>
                                            <i class='bx bx-show'></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- CREATE DISPOSAL REQUEST MODAL -->
        <div id="disposal-modal" class="disposal-modal-overlay">
            <div class="disposal-modal-content">
                <div class="disposal-modal-header">
                    <button class="disposal-close-modal" onclick="closeDisposalModal()">
                        <i class='bx bx-x'></i>
                    </button>
                    <h2 class="disposal-modal-title">
                        <i class='bx bx-trash' style="margin-right: 10px;"></i> NEW DISPOSAL REQUEST
                    </h2>
                    <p class="disposal-modal-subtitle">Complete the form below to submit a disposal request</p>
                </div>

                <div class="disposal-modal-body">
                    <form id="disposal-form" method="POST"
                        action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>"
                        onsubmit="return validateDisposalForm()">
                        <!-- Agency Information Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <div class="section-title-icon"><i class='bx bx-building'></i></div>
                                <h3 class="section-title-text">Agency Information</h3>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Agency Name <span class="required">*</span></label>
                                    <input type="text" class="form-input" id="agency_name" name="agency_name" required
                                        placeholder="Enter agency name">
                                    <div class="error-message" id="agency-name-error"></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Request Date <span class="required">*</span></label>
                                    <input type="date" class="form-input" id="request_date" name="request_date"
                                        value="<?php echo date('Y-m-d'); ?>" required>
                                    <div class="error-message" id="date-error"></div>
                                </div>
                                <div class="form-group full-width">
                                    <label class="form-label">Agency Address <span class="required">*</span></label>
                                    <textarea class="form-input form-textarea" id="agency_address" name="agency_address"
                                        required placeholder="Enter complete agency address"></textarea>
                                    <div class="error-message" id="address-error"></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Telephone Number</label>
                                    <input type="tel" class="form-input" id="agency_telephone" name="agency_telephone"
                                        placeholder="(123) 456-7890">
                                </div>
                            </div>
                        </div>

                        <!-- Provisions Complied Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <div class="section-title-icon"><i class='bx bx-check-shield'></i></div>
                                <h3 class="section-title-text">Provisions Complied</h3>
                            </div>
                            <div class="form-group">
                                <label class="form-label">List the provisions that have been complied with</label>
                                <textarea class="form-input form-textarea" id="provisions_complied"
                                    name="provisions_complied"
                                    placeholder="Describe the provisions, regulations, or requirements that have been met..."></textarea>
                            </div>
                        </div>

                        <!-- Records Selection Section -->
                        <div class="records-selection-section">
                            <div class="section-title">
                                <div class="section-title-icon"><i class='bx bx-file'></i></div>
                                <h3 class="section-title-text">Select Records for Disposal</h3>
                            </div>
                            <!-- Search Box -->
                            <div class="search-containers-request">
                                <i class='bx bx-search search-icon'></i>
                                <input type="text" class="search-input" id="record-search"
                                    placeholder="Search records by title, code, or office..." onkeyup="filterRecords()">
                            </div>

                            <!-- Selected Records Summary -->
                            <div class="selected-summary" id="selected-summary">
                                <div class="summary-header">
                                    <h4 class="summary-title">Selected Records</h4>
                                    <span class="selected-count" id="selected-count">0 selected</span>
                                </div>
                                <table class="summary-table" id="selected-table">
                                    <thead>
                                        <tr>
                                            <th>Record Series Code</th>
                                            <th>Record Title</th>
                                            <th>Office</th>
                                            <th>Period Covered</th>
                                            <th>Retention Period</th>
                                        </tr>
                                    </thead>
                                    <tbody><!-- Will be populated by JavaScript --></tbody>
                                </table>
                            </div>

                            <!-- Records Table -->
                            <div class="records-table-container">
                                <table class="records-table">
                                    <thead>
                                        <tr>
                                            <th class="checkbox-cell"><input type="checkbox" id="select-all"
                                                    onchange="toggleSelectAll()"></th>
                                            <th>RECORD SERIES CODE</th>
                                            <th>RECORD TITLE</th>
                                            <th>OFFICE</th>
                                            <th>CLASSIFICATION</th>
                                            <th>PERIOD COVERED</th>
                                            <th>RETENTION PERIOD</th>
                                            <th>STATUS</th>
                                        </tr>
                                    </thead>
                                    <tbody id="records-table-body">
                                        <?php if (empty($disposableRecords)): ?>
                                            <tr>
                                                <td colspan="8" class="no-records-message">
                                                    <div class="no-records-icon"><i class='bx bx-file-blank'></i></div>
                                                    <div class="no-records-text">
                                                        <?php echo $tableExists ? 'No records available for disposal' : 'Database setup required'; ?>
                                                    </div>
                                                    <div class="no-records-subtext">
                                                        <?php echo $tableExists ? 'All records have either been processed or are not yet ready for disposal.' : 'Please run the SQL script to set up the database tables.'; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($disposableRecords as $record):
                                                $fromYear = $record['inclusive_date_from'] ? date('Y', strtotime($record['inclusive_date_from'])) : 'N/A';
                                                $toYear = $record['inclusive_date_to'] ? date('Y', strtotime($record['inclusive_date_to'])) : 'N/A';
                                                $retention = !empty($record['total_years']) && is_numeric($record['total_years']) ?
                                                    $record['total_years'] . ' years' : (!empty($record['total_years']) ? htmlspecialchars($record['total_years']) : 'N/A');
                                            ?>
                                                <tr class="record-row" data-id="<?php echo $record['record_id']; ?>">
                                                    <td class="checkbox-cell">
                                                        <input type="checkbox" name="selected_records[]"
                                                            value="<?php echo $record['record_id']; ?>"
                                                            onchange="updateSelectedSummary()"
                                                            data-code="<?php echo htmlspecialchars($record['record_series_code']); ?>"
                                                            data-title="<?php echo htmlspecialchars($record['record_title']); ?>"
                                                            data-office="<?php echo htmlspecialchars($record['office_name']); ?>"
                                                            data-from="<?php echo $fromYear; ?>"
                                                            data-to="<?php echo $toYear; ?>"
                                                            data-retention="<?php echo $retention; ?>">
                                                    </td>
                                                    <td><?php echo htmlspecialchars($record['record_series_code']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['record_title']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['office_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                                                    <td><?php echo $fromYear . ' - ' . $toYear; ?></td>
                                                    <td><?php echo $retention; ?></td>
                                                    <td>
                                                        <span
                                                            class="status-badge status-<?php echo strtolower($record['status']); ?>">
                                                            <i class='bx bx-circle'
                                                                style="font-size: 0.6rem; margin-right: 4px;"></i>
                                                            <?php echo htmlspecialchars($record['status']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <input type="hidden" name="submit_type" value="disposal_request">
                        </div>
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="submit" form="disposal-form" class="btn btn-submit" id="submit-btn" <?php echo empty($disposableRecords) || !$tableExists ? 'disabled' : ''; ?>>
                        <i class='bx bx-paper-plane' style="margin-right: 8px;"></i> Submit Disposal Request
                    </button>
                </div>
            </div>
        </div>

        <!-- VIEW REQUEST MODAL -->
        <div id="view-request-modal" class="disposal-modal-overlay">
            <div class="disposal-modal-content">
                <div class="disposal-modal-header">
                    <button class="disposal-close-modal" onclick="closeViewModal()">
                        <i class='bx bx-x'></i>
                    </button>
                    <h2 class="disposal-modal-title">
                        <i class='bx bx-show' style="margin-right: 10px;"></i> DISPOSAL REQUEST DETAILS
                    </h2>
                    <p class="disposal-modal-subtitle">Request ID: <strong id="view-request-id">Loading...</strong></p>
                </div>

                <div class="disposal-modal-body" id="view-modal-body">
                    <!-- Loading State -->
                    <div id="view-loading" style="text-align: center; padding: 40px;">
                        <i class='bx bx-loader-circle bx-spin'
                            style="font-size: 3rem; color: #1e3a8a; margin-bottom: 20px;"></i>
                        <p style="color: #64748b; font-size: 1.1rem;">Loading request details...</p>
                    </div>

                    <!-- Content will be loaded here -->
                    <div id="view-content" style="display: none;">
                        <!-- Agency Information Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <div class="section-title-icon"><i class='bx bx-building'></i></div>
                                <h3 class="section-title-text">Agency Information</h3>
                            </div>
                            <div class="form-grid">
                                <div class="form-group"><label class="form-label">Agency Name</label>
                                    <div class="form-input view-only" id="view-agency-name">Loading...</div>
                                </div>
                                <div class="form-group"><label class="form-label">Request Date</label>
                                    <div class="form-input view-only" id="view-request-date">Loading...</div>
                                </div>
                                <div class="form-group full-width"><label class="form-label">Agency Address</label>
                                    <div class="form-input form-textarea view-only" id="view-agency-address">Loading...
                                    </div>
                                </div>
                                <div class="form-group"><label class="form-label">Telephone Number</label>
                                    <div class="form-input view-only" id="view-agency-telephone">Loading...</div>
                                </div>
                            </div>
                        </div>

                        <!-- Provisions Complied Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <div class="section-title-icon"><i class='bx bx-check-shield'></i></div>
                                <h3 class="section-title-text">Provisions Complied</h3>
                            </div>
                            <div class="form-group">
                                <label class="form-label">List the provisions that have been complied with</label>
                                <div class="form-input form-textarea view-only" id="view-provisions">Loading...</div>
                            </div>
                        </div>

                        <!-- Requester Information Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <div class="section-title-icon"><i class='bx bx-user'></i></div>
                                <h3 class="section-title-text">Requester Information</h3>
                            </div>
                            <div class="form-grid">
                                <div class="form-group"><label class="form-label">Requested By</label>
                                    <div class="form-input view-only" id="view-requester-name">Loading...</div>
                                </div>
                                <div class="form-group"><label class="form-label">Email</label>
                                    <div class="form-input view-only" id="view-requester-email">Loading...</div>
                                </div>
                                <div class="form-group"><label class="form-label">Date Submitted</label>
                                    <div class="form-input view-only" id="view-created-at">Loading...</div>
                                </div>
                                <div class="form-group"><label class="form-label">Request Status</label>
                                    <div class="form-input view-only" id="view-status">Loading...</div>
                                </div>
                            </div>
                        </div>

                        <!-- Records Section -->
                        <div class="records-selection-section">
                            <div class="section-title">
                                <div class="section-title-icon"><i class='bx bx-file'></i></div>
                                <h3 class="section-title-text">Records for Disposal</h3>
                                <span class="selected-count" id="view-record-count">0 records</span>
                            </div>
                            <div class="records-table-container">
                                <table class="records-table">
                                    <thead>
                                        <tr>
                                            <th>RECORD SERIES CODE</th>
                                            <th>RECORD TITLE</th>
                                            <th>OFFICE</th>
                                            <th>CLASSIFICATION</th>
                                            <th>PERIOD COVERED</th>
                                            <th>RETENTION PERIOD</th>
                                            <th>STATUS</th>
                                        </tr>
                                    </thead>
                                    <tbody id="view-records-table"><!-- Will be populated by JavaScript --></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <div class="modal-footer-left">
                        <button type="button" class="btn btn-submit" onclick="printRequest()">
                            <i class='bx bx-printer' style="margin-right: 8px;"></i> Print Request
                        </button>
                    </div>
                    <?php if ($is_admin): ?>
                        <div class="modal-footer-right">
                            <button type="button" class="btn approve" id="approve-btn" onclick="approveRequest()">
                                <i class='bx bx-check' style='color:#ffffff'></i>Approve Request
                            </button>
                            <button type="button" class="btn decline" id="decline-btn" onclick="declineRequest()">
                                <i class='bx bx-x' style='color:#ffffff'></i> Decline Request
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- DECLINE REMARKS MODAL -->
        <div id="decline-modal" class="disposal-modal-overlay">
            <div class="disposal-modal-content" style="max-width: 500px;">
                <div class="disposal-modal-header">
                    <button class="disposal-close-modal" onclick="closeDeclineModal()">
                        <i class='bx bx-x'></i>
                    </button>
                    <h2 class="disposal-modal-title">
                        <i class='bx bx-x-circle' style="margin-right: 10px; color: #ef4444;"></i> DECLINE REQUEST
                    </h2>
                    <p class="disposal-modal-subtitle">Please provide a reason for declining this request</p>
                </div>

                <div class="disposal-modal-body">
                    <div class="form-section">
                        <div class="form-group full-width">
                            <label class="form-label">Remarks <span class="required">*</span></label>
                            <textarea
                                class="form-input form-textarea"
                                id="decline-remarks"
                                placeholder="Enter the reason for declining this request..."
                                rows="5"
                                maxlength="500"></textarea>
                            <div class="form-help" style="display: flex; justify-content: space-between; margin-top: 5px;">
                                <span class="char-count">
                                    <span id="char-count">0</span>/500 characters
                                </span>
                                <span class="required-text" style="color: #ef4444;">Required for decline</span>
                            </div>
                            <div class="error-message" id="remarks-error"></div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeDeclineModal()">
                        <i class='bx bx-x' style="margin-right: 8px;"></i> Cancel
                    </button>
                    <button type="button" class="btn decline" onclick="submitDecline()">
                        <i class='bx bx-check' style="margin-right: 8px;"></i> Confirm Decline
                    </button>
                </div>
            </div>
        </div>
    </main>

    <script>
        // ========== GLOBAL VARIABLES ==========
        let currentRequestId = null;
        let declineRequestId = null;

        // ========== DISPOSAL MODAL FUNCTIONS ==========
        function openDisposalModal() {
            document.getElementById('disposal-modal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
            resetForm();
            updateSelectedSummary();
        }

        function closeDisposalModal() {
            document.getElementById('disposal-modal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function toggleSelectAll() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('input[name="selected_records[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = selectAll.checked);
            updateSelectedSummary();
        }

        function filterRecords() {
            const searchTerm = document.getElementById('record-search').value.toLowerCase();
            const rows = document.querySelectorAll('.record-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            const selectAll = document.getElementById('select-all');
            const visibleCheckboxes = document.querySelectorAll('.record-row[style=""] input[name="selected_records[]"]');
            const checkedVisible = Array.from(visibleCheckboxes).filter(cb => cb.checked).length;

            selectAll.checked = visibleCount > 0 && checkedVisible === visibleCount;
            selectAll.indeterminate = checkedVisible > 0 && checkedVisible < visibleCount;
        }

        function updateSelectedSummary() {
            const checkboxes = document.querySelectorAll('input[name="selected_records[]"]:checked');
            const summaryContainer = document.getElementById('selected-summary');
            const selectedCount = document.getElementById('selected-count');
            const summaryTable = document.querySelector('#selected-table tbody');
            const submitBtn = document.getElementById('submit-btn');

            summaryTable.innerHTML = '';
            selectedCount.textContent = `${checkboxes.length} selected`;

            if (checkboxes.length > 0) {
                summaryContainer.classList.add('show');
                checkboxes.forEach(checkbox => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                    <td>${checkbox.dataset.code}</td>
                    <td>${checkbox.dataset.title}</td>
                    <td>${checkbox.dataset.office}</td>
                    <td>${checkbox.dataset.from} - ${checkbox.dataset.to}</td>
                    <td>${checkbox.dataset.retention}</td>
                `;
                    summaryTable.appendChild(row);
                });
                submitBtn.disabled = false;
            } else {
                summaryContainer.classList.remove('show');
                submitBtn.disabled = true;
            }
        }

        function validateDisposalForm() {
            let isValid = true;

            document.querySelectorAll('.error-message').forEach(error => error.classList.remove('show'));
            document.querySelectorAll('.form-input').forEach(input => input.classList.remove('error'));

            const requiredFields = ['agency_name', 'request_date', 'agency_address'];
            requiredFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (!field.value.trim()) {
                    field.classList.add('error');
                    document.getElementById(`${fieldId}-error`).textContent = 'This field is required';
                    document.getElementById(`${fieldId}-error`).classList.add('show');
                    isValid = false;
                }
            });

            const checkboxes = document.querySelectorAll('input[name="selected_records[]"]:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one record for disposal.');
                return false;
            }

            const requestDate = new Date(document.getElementById('request_date').value);
            const today = new Date();
            if (requestDate > today) {
                document.getElementById('request_date').classList.add('error');
                document.getElementById('date-error').textContent = 'Request date cannot be in the future';
                document.getElementById('date-error').classList.add('show');
                isValid = false;
            }

            if (isValid) {
                const submitBtn = document.getElementById('submit-btn');
                submitBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin" style="margin-right: 8px;"></i> Submitting...';
                submitBtn.disabled = true;
            }

            return isValid;
        }

        function resetForm() {
            document.getElementById('disposal-form').reset();
            document.querySelectorAll('.error-message').forEach(error => error.classList.remove('show'));
            document.querySelectorAll('.form-input').forEach(input => input.classList.remove('error'));
            document.getElementById('select-all').checked = false;
            document.getElementById('record-search').value = '';
            filterRecords();
        }

        // ========== VIEW MODAL FUNCTIONS ==========
        async function openViewModal(requestId) {
            console.log('Opening view modal for request ID:', requestId);
            const modal = document.getElementById('view-request-modal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            currentRequestId = requestId;
            document.getElementById('view-request-id').textContent = `R-${String(requestId).padStart(3, '0')}`;
            document.getElementById('view-loading').style.display = 'block';
            document.getElementById('view-content').style.display = 'none';

            // Only reset buttons if they exist (admin users)
            const approveBtn = document.getElementById('approve-btn');
            const declineBtn = document.getElementById('decline-btn');
            if (approveBtn && declineBtn) {
                approveBtn.disabled = false;
                declineBtn.disabled = false;
                approveBtn.innerHTML = '<i class="bx bx-check" style="color:#ffffff"></i>Approve Request';
                declineBtn.innerHTML = '<i class="bx bx-x" style="color:#ffffff"></i> Decline Request';
            }

            try {
                const response = await fetch(`../get_request_details.php?request_id=${requestId}&type=disposal`);
                if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

                const data = await response.json();
                console.log('Response data:', data);

                if (data.success) {
                    populateViewModal(data.request, data.records);
                } else {
                    throw new Error(data.message || 'Failed to load request details');
                }
            } catch (error) {
                console.error('Error loading request details:', error);
                document.getElementById('view-loading').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <i class='bx bx-error' style="font-size: 3rem; color: #dc3545; margin-bottom: 20px;"></i>
                    <p style="color: #dc3545; font-size: 1.1rem;">Failed to load request details</p>
                    <p style="color: #6c757d; font-size: 0.9rem;">${error.message}</p>
                    <button onclick="closeViewModal()" style="margin-top: 20px; padding: 8px 16px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">Close</button>
                </div>
            `;
            }
        }

        function populateViewModal(request, records) {
            console.log('Populating view modal');
            document.getElementById('view-loading').style.display = 'none';
            document.getElementById('view-content').style.display = 'block';

            // Agency Information
            document.getElementById('view-agency-name').textContent = request.agency_name || 'N/A';
            document.getElementById('view-request-date').textContent = formatDate(request.request_date);
            document.getElementById('view-agency-address').textContent = request.agency_address || 'N/A';
            document.getElementById('view-agency-telephone').textContent = request.agency_telephone || 'N/A';

            // Provisions
            document.getElementById('view-provisions').textContent = request.provisions_complied || 'N/A';

            // Requester Information
            document.getElementById('view-requester-name').textContent = `${request.first_name || ''} ${request.last_name || ''}`.trim() || 'N/A';
            document.getElementById('view-requester-email').textContent = request.email || 'N/A';
            document.getElementById('view-created-at').textContent = formatDateTime(request.created_at);

            // Status
            const statusElement = document.getElementById('view-status');
            statusElement.textContent = request.status || 'Pending';
            statusElement.className = 'form-input view-only status-badge';
            statusElement.classList.add(`status-${(request.status || 'pending').toLowerCase()}`);

            // Add remarks display if remarks exist
            if (request.remarks) {
                const existingRemarksSection = document.querySelector('#view-content .remarks-section');
                if (existingRemarksSection) {
                    existingRemarksSection.remove();
                }

                const remarksSection = document.createElement('div');
                remarksSection.className = 'form-section remarks-section';
                remarksSection.innerHTML = `
                <div class="section-title">
                    <div class="section-title-icon"><i class='bx bx-message-alt-detail'></i></div>
                    <h3 class="section-title-text">Remarks</h3>
                </div>
                <div class="form-group">
                    <div class="form-input form-textarea view-only remarks-display" style="background-color: #f8f9fa; border-left: 4px solid #${request.status === 'Rejected' ? 'ef4444' : '10b981'};">
                        ${escapeHtml(request.remarks)}
                    </div>
                </div>
            `;

                // Insert after status section
                const statusSection = document.querySelector('#view-content .form-section:has(#view-status)');
                statusSection.parentNode.insertBefore(remarksSection, statusSection.nextSibling);
            }

            // Check if admin buttons exist before trying to modify them
            const approveBtn = document.getElementById('approve-btn');
            const declineBtn = document.getElementById('decline-btn');

            if (approveBtn && declineBtn) {
                // Only update buttons if they exist (admin users)
                if (request.status === 'Pending') {
                    approveBtn.disabled = false;
                    declineBtn.disabled = false;
                } else {
                    approveBtn.disabled = true;
                    declineBtn.disabled = true;
                    if (request.status === 'Approved') {
                        approveBtn.innerHTML = '<i class="bx bx-check-circle" style="color:#ffffff"></i>Already Approved';
                    } else if (request.status === 'Rejected') {
                        declineBtn.innerHTML = '<i class="bx bx-x-circle" style="color:#ffffff"></i>Already Rejected';
                    }
                }
            }

            // Record count
            const recordCount = request.record_count || (records ? records.length : 0);
            document.getElementById('view-record-count').textContent = `${recordCount} record(s)`;

            // Populate records table
            const recordsTable = document.getElementById('view-records-table');
            recordsTable.innerHTML = '';

            if (records && records.length > 0) {
                records.forEach(record => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                    <td>${escapeHtml(record.record_series_code || 'N/A')}</td>
                    <td>${escapeHtml(record.record_series_title || 'N/A')}</td>
                    <td>${escapeHtml(record.office_name || 'N/A')}</td>
                    <td>${escapeHtml(record.class_name || 'N/A')}</td>
                    <td>${formatDate(record.period_from)} - ${formatDate(record.period_to)}</td>
                    <td>${formatRetentionPeriod(record)}</td>
                    <td><span class="status-badge status-${(record.status || 'pending').toLowerCase()}">${record.status || 'Pending'}</span></td>
                `;
                    recordsTable.appendChild(row);
                });
            } else {
                recordsTable.innerHTML = `
                <tr><td colspan="7" style="text-align: center; padding: 2rem; color: #78909c;">
                    <i class='bx bx-file-blank' style="font-size: 2rem; display: block; margin-bottom: 1rem;"></i>
                    No records found for this request
                </td></tr>
            `;
            }
        }

        function closeViewModal() {
            document.getElementById('view-request-modal').style.display = 'none';
            document.body.style.overflow = 'auto';
            currentRequestId = null;
        }

        // ========== DECLINE MODAL FUNCTIONS ==========
        function showDeclineModal(requestId) {
            declineRequestId = requestId;

            // Reset form
            document.getElementById('decline-remarks').value = '';
            document.getElementById('char-count').textContent = '0';
            document.getElementById('remarks-error').classList.remove('show');

            // Show modal
            document.getElementById('decline-modal').style.display = 'flex';
            document.body.style.overflow = 'hidden';

            // Focus on textarea
            setTimeout(() => {
                document.getElementById('decline-remarks').focus();
            }, 100);
        }

        function closeDeclineModal() {
            document.getElementById('decline-modal').style.display = 'none';
            document.body.style.overflow = 'auto';
            declineRequestId = null;
        }

        function updateCharCount() {
            const textarea = document.getElementById('decline-remarks');
            const charCount = document.getElementById('char-count');
            charCount.textContent = textarea.value.length;

            // Change color when approaching limit
            if (textarea.value.length > 450) {
                charCount.style.color = '#ef4444';
            } else if (textarea.value.length > 400) {
                charCount.style.color = '#f59e0b';
            } else {
                charCount.style.color = '#6b7280';
            }
        }

        async function submitDecline() {
            const remarks = document.getElementById('decline-remarks').value.trim();
            const errorElement = document.getElementById('remarks-error');

            // Validate remarks
            if (!remarks) {
                errorElement.textContent = 'Please provide a reason for declining this request';
                errorElement.classList.add('show');
                return;
            }

            if (remarks.length < 10) {
                errorElement.textContent = 'Please provide a more detailed reason (minimum 10 characters)';
                errorElement.classList.add('show');
                return;
            }

            errorElement.classList.remove('show');

            const declineBtn = document.querySelector('#decline-modal .decline');
            const originalText = declineBtn.innerHTML;

            // Show loading state
            declineBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin" style="margin-right: 8px;"></i> Processing...';
            declineBtn.disabled = true;

            try {
                const formData = new FormData();
                formData.append('request_id', declineRequestId);
                formData.append('action_type', 'decline');
                formData.append('remarks', remarks);

                const response = await fetch('<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>', {
                    method: 'POST',
                    body: formData
                });

                if (response.ok) {
                    // Close modals and reload
                    closeDeclineModal();
                    closeViewModal();
                    window.location.reload();
                } else {
                    throw new Error('Failed to decline request');
                }
            } catch (error) {
                console.error('Error declining request:', error);
                alert('Failed to decline request: ' + error.message);
                declineBtn.innerHTML = originalText;
                declineBtn.disabled = false;
            }
        }

        // ========== APPROVE/DECLINE FUNCTIONS ==========
        async function approveRequest() {
            if (!currentRequestId) return;

            const approveBtn = document.getElementById('approve-btn');
            const declineBtn = document.getElementById('decline-btn');

            // Check if buttons exist (admin only)
            if (!approveBtn || !declineBtn) return;

            if (!confirm('Are you sure you want to approve this disposal request? This action cannot be undone.')) {
                return;
            }

            approveBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin" style="color:#ffffff"></i> Approving...';
            approveBtn.disabled = true;
            declineBtn.disabled = true;

            try {
                const formData = new FormData();
                formData.append('request_id', currentRequestId);
                formData.append('action_type', 'approve');
                formData.append('remarks', ''); // Empty remarks for approve

                const response = await fetch('<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>', {
                    method: 'POST',
                    body: formData
                });

                if (response.ok) {
                    // Close modal and reload page
                    closeViewModal();
                    window.location.reload();
                } else {
                    throw new Error('Failed to approve request');
                }
            } catch (error) {
                console.error('Error approving request:', error);
                alert('Failed to approve request: ' + error.message);
                approveBtn.innerHTML = '<i class="bx bx-check" style="color:#ffffff"></i>Approve Request';
                approveBtn.disabled = false;
                declineBtn.disabled = false;
            }
        }

        function declineRequest() {
            if (!currentRequestId) return;

            // Instead of immediate confirmation, show remarks modal
            showDeclineModal(currentRequestId);
        }

        // ========== PRINT FUNCTION ==========
        function printRequest() {
            const printContent = document.getElementById('view-content').cloneNode(true);
            const requestId = document.getElementById('view-request-id').textContent;

            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Disposal Request - ${requestId}</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; color: #333; }
                    .print-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #1e3a8a; padding-bottom: 20px; }
                    .print-header h1 { color: #1e3a8a; margin-bottom: 5px; }
                    .print-header .request-id { font-size: 1.2em; color: #666; }
                    .print-date { text-align: right; margin-bottom: 20px; color: #666; }
                    .form-section { margin-bottom: 25px; page-break-inside: avoid; }
                    .section-title { background-color: #f8f9fa; padding: 10px 15px; border-left: 4px solid #1e3a8a; margin-bottom: 15px; border-radius: 4px; }
                    .section-title h3 { margin: 0; color: #1e3a8a; font-size: 1.1em; }
                    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 15px; }
                    .form-group { margin-bottom: 15px; }
                    .full-width { grid-column: 1 / -1; }
                    .form-label { font-weight: bold; display: block; margin-bottom: 5px; color: #555; }
                    .form-input { padding: 8px 10px; border: 1px solid #ddd; border-radius: 4px; background-color: #f9f9fa; min-height: 36px; }
                    .form-textarea { min-height: 60px; white-space: pre-wrap; line-height: 1.4; }
                    table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 0.9em; }
                    th { background-color: #f8f9fa; padding: 10px; text-align: left; border: 1px solid #dee2e6; font-weight: bold; }
                    td { padding: 10px; border: 1px solid #dee2e6; vertical-align: top; }
                    .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: 600; display: inline-block; }
                    .status-pending { background-color: #fff3cd; color: #856404; }
                    .status-approved { background-color: #d4edda; color: #155724; }
                    .status-rejected { background-color: #f8d7da; color: #721c24; }
                    .status-disposed { background-color: #d1ecf1; color: #0c5460; }
                    .remarks-display { background-color: #f8f9fa; border-left: 4px solid #ef4444; padding: 10px; margin-top: 5px; }
                    .print-footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; color: #666; font-size: 0.9em; }
                    @media print { 
                        body { padding: 0; } 
                        .print-header { border-bottom: 1px solid #000; } 
                        .print-date { display: none; } 
                        .form-section { margin-bottom: 20px; } 
                        table { page-break-inside: avoid; } 
                    }
                </style>
            </head>
            <body>
                <div class="print-header">
                    <h1>DISPOSAL REQUEST DETAILS</h1>
                    <div class="request-id">Request ID: ${requestId}</div>
                </div>
                <div class="print-date">Printed on: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}</div>
                ${printContent.innerHTML}
                <div class="print-footer">This document is system-generated. For verification, please refer to the original request in the system.</div>
                <script>
                    window.onload = function() {
                        setTimeout(function() {
                            window.print();
                            setTimeout(function() {
                                window.close();
                            }, 100);
                        }, 500);
                    };
                <\/script>
            </body>
            </html>
        `);
            printWindow.document.close();
        }

        // ========== HELPER FUNCTIONS ==========
        function formatDate(dateString) {
            if (!dateString || dateString.includes('0000-00-00')) return 'N/A';
            const date = new Date(dateString);
            return isNaN(date.getTime()) ? 'N/A' : date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        function formatDateTime(dateTimeString) {
            if (!dateTimeString || dateTimeString.includes('0000-00-00')) return 'N/A';
            const date = new Date(dateTimeString);
            return isNaN(date.getTime()) ? 'N/A' : date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function formatRetentionPeriod(record) {
            if (record.total_years && record.total_years > 0) return `${record.total_years} years`;
            if (record.retention_period) return record.retention_period;
            return 'N/A';
        }

        function escapeHtml(text) {
            if (text == null) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ========== EVENT LISTENERS ==========
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize
            updateSelectedSummary();
            document.getElementById('request_date').value = new Date().toISOString().split('T')[0];

            // Modal close handlers
            document.getElementById('disposal-modal').addEventListener('click', function(e) {
                if (e.target === this) closeDisposalModal();
            });

            document.getElementById('view-request-modal').addEventListener('click', function(e) {
                if (e.target === this) closeViewModal();
            });

            document.getElementById('decline-modal').addEventListener('click', function(e) {
                if (e.target === this) closeDeclineModal();
            });

            // Escape key to close modals
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeDisposalModal();
                    closeViewModal();
                    closeDeclineModal();
                }
            });

            // Character count for decline remarks
            document.getElementById('decline-remarks').addEventListener('input', updateCharCount);

            // View button click handler
            document.addEventListener('click', function(e) {
                const viewBtn = e.target.closest('.view-btn');
                if (viewBtn && !viewBtn.disabled) {
                    e.preventDefault();
                    e.stopPropagation();
                    const requestId = viewBtn.getAttribute('data-request-id');
                    if (requestId) {
                        console.log('View button clicked, request ID:', requestId);
                        openViewModal(parseInt(requestId));
                    }
                }
            });
        });
    </script>
</body>

</html>