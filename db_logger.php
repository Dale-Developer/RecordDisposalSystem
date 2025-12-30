<?php
/**
 * Comprehensive Logging System for Record Disposal System
 * Populates disposal_action_log and record_change_log tables
 * PDO Version - Compatible with your existing code
 */
class SystemLogger {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // ==============================
    // DISPOSAL ACTION LOG METHODS
    // ==============================
    
    /**
     * Log any disposal-related action
     * @param array $data - Action data
     * @return bool - Success status
     */
    public function logDisposalAction($data) {
        $defaults = [
            'request_id' => null,
            'record_id' => null,
            'schedule_id' => null,
            'action_type' => '',
            'performed_by' => null,
            'status_from' => null,
            'status_to' => null,
            'notes' => null,
            'documents' => null,
            'office_id' => null,
            'role_id' => null
        ];
        
        $data = array_merge($defaults, $data);
        
        // Handle JSON documents field
        $documentsJson = $data['documents'] ? json_encode($data['documents']) : null;
        
        $sql = "INSERT INTO disposal_action_log (
            request_id, record_id, schedule_id, action_type, performed_by, 
            status_from, status_to, notes, documents, office_id, role_id
        ) VALUES (:request_id, :record_id, :schedule_id, :action_type, :performed_by, 
                  :status_from, :status_to, :notes, :documents, :office_id, :role_id)";
        
        $stmt = $this->pdo->prepare($sql);
        
        return $stmt->execute([
            ':request_id' => $data['request_id'],
            ':record_id' => $data['record_id'],
            ':schedule_id' => $data['schedule_id'],
            ':action_type' => $data['action_type'],
            ':performed_by' => $data['performed_by'],
            ':status_from' => $data['status_from'],
            ':status_to' => $data['status_to'],
            ':notes' => $data['notes'],
            ':documents' => $documentsJson,
            ':office_id' => $data['office_id'],
            ':role_id' => $data['role_id']
        ]);
    }
    
    /**
     * Convenience method: Log disposal request creation
     */
    public function logRequestCreate($requestId, $userId, $notes = '') {
        $userContext = $this->getUserContext($userId);
        
        return $this->logDisposalAction([
            'request_id' => $requestId,
            'action_type' => 'REQUEST_CREATE',
            'performed_by' => $userId,
            'status_from' => null,
            'status_to' => 'Pending',
            'notes' => $notes,
            'office_id' => $userContext['office_id'] ?? null,
            'role_id' => $userContext['role_id'] ?? null
        ]);
    }
    
    /**
     * Convenience method: Log disposal request submission
     */
    public function logRequestSubmit($requestId, $userId, $notes = '') {
        $userContext = $this->getUserContext($userId);
        
        return $this->logDisposalAction([
            'request_id' => $requestId,
            'action_type' => 'REQUEST_SUBMIT',
            'performed_by' => $userId,
            'status_from' => 'Draft',
            'status_to' => 'Pending',
            'notes' => $notes,
            'office_id' => $userContext['office_id'] ?? null,
            'role_id' => $userContext['role_id'] ?? null
        ]);
    }
    
    /**
     * Convenience method: Log disposal request approval
     */
    public function logRequestApprove($requestId, $approverId, $records = [], $notes = '') {
        $userContext = $this->getUserContext($approverId);
        $success = true;
        
        // Log the request approval
        $success = $success && $this->logDisposalAction([
            'request_id' => $requestId,
            'action_type' => 'REQUEST_APPROVE',
            'performed_by' => $approverId,
            'status_from' => 'Pending',
            'status_to' => 'Approved',
            'notes' => $notes,
            'office_id' => $userContext['office_id'] ?? null,
            'role_id' => $userContext['role_id'] ?? null
        ]);
        
        // Log disposal completion for each record
        foreach ($records as $recordId) {
            $success = $success && $this->logDisposalAction([
                'request_id' => $requestId,
                'record_id' => $recordId,
                'action_type' => 'DISPOSAL_COMPLETE',
                'performed_by' => $approverId,
                'status_from' => 'Scheduled for Disposal',
                'status_to' => 'Disposed',
                'notes' => $notes,
                'office_id' => $userContext['office_id'] ?? null,
                'role_id' => $userContext['role_id'] ?? null
            ]);
        }
        
        return $success;
    }
    
    /**
     * Convenience method: Log disposal request rejection
     */
    public function logRequestReject($requestId, $rejectorId, $reason = '') {
        $userContext = $this->getUserContext($rejectorId);
        
        return $this->logDisposalAction([
            'request_id' => $requestId,
            'action_type' => 'REQUEST_REJECT',
            'performed_by' => $rejectorId,
            'status_from' => 'Pending',
            'status_to' => 'Rejected',
            'notes' => $reason,
            'office_id' => $userContext['office_id'] ?? null,
            'role_id' => $userContext['role_id'] ?? null
        ]);
    }
    
    /**
     * Convenience method: Log schedule creation
     */
    public function logScheduleCreate($scheduleId, $recordId, $userId, $notes = '') {
        $userContext = $this->getUserContext($userId);
        
        return $this->logDisposalAction([
            'schedule_id' => $scheduleId,
            'record_id' => $recordId,
            'action_type' => 'SCHEDULE_CREATE',
            'performed_by' => $userId,
            'status_from' => null,
            'status_to' => 'Pending',
            'notes' => $notes,
            'office_id' => $userContext['office_id'] ?? null,
            'role_id' => $userContext['role_id'] ?? null
        ]);
    }
    
    /**
     * Convenience method: Log archive completion
     */
    public function logArchiveComplete($recordId, $userId, $notes = '') {
        $userContext = $this->getUserContext($userId);
        
        return $this->logDisposalAction([
            'record_id' => $recordId,
            'action_type' => 'ARCHIVE_COMPLETE',
            'performed_by' => $userId,
            'status_from' => 'Active',
            'status_to' => 'Archived',
            'notes' => $notes,
            'office_id' => $userContext['office_id'] ?? null,
            'role_id' => $userContext['role_id'] ?? null
        ]);
    }
    
    // ==============================
    // RECORD CHANGE LOG METHODS
    // ==============================
    
    /**
     * Log a single field change for a record
     * @param array $data - Change data
     * @return bool - Success status
     */
    public function logRecordChange($data) {
        $defaults = [
            'record_id' => null,
            'user_id' => null,
            'field_name' => '',
            'field_type' => 'text',
            'old_value_text' => null,
            'new_value_text' => null,
            'old_value_int' => null,
            'new_value_int' => null,
            'old_value_date' => null,
            'new_value_date' => null,
            'old_value_enum' => null,
            'new_value_enum' => null,
            'old_reference_id' => null,
            'new_reference_id' => null,
            'change_reason' => null
        ];
        
        $data = array_merge($defaults, $data);
        
        $sql = "INSERT INTO record_change_log (
            record_id, user_id, field_name, field_type,
            old_value_text, new_value_text,
            old_value_int, new_value_int,
            old_value_date, new_value_date,
            old_value_enum, new_value_enum,
            old_reference_id, new_reference_id,
            change_reason
        ) VALUES (
            :record_id, :user_id, :field_name, :field_type,
            :old_value_text, :new_value_text,
            :old_value_int, :new_value_int,
            :old_value_date, :new_value_date,
            :old_value_enum, :new_value_enum,
            :old_reference_id, :new_reference_id,
            :change_reason
        )";
        
        $stmt = $this->pdo->prepare($sql);
        
        return $stmt->execute([
            ':record_id' => $data['record_id'],
            ':user_id' => $data['user_id'],
            ':field_name' => $data['field_name'],
            ':field_type' => $data['field_type'],
            ':old_value_text' => $data['old_value_text'],
            ':new_value_text' => $data['new_value_text'],
            ':old_value_int' => $data['old_value_int'],
            ':new_value_int' => $data['new_value_int'],
            ':old_value_date' => $data['old_value_date'],
            ':new_value_date' => $data['new_value_date'],
            ':old_value_enum' => $data['old_value_enum'],
            ':new_value_enum' => $data['new_value_enum'],
            ':old_reference_id' => $data['old_reference_id'],
            ':new_reference_id' => $data['new_reference_id'],
            ':change_reason' => $data['change_reason']
        ]);
    }
    
    /**
     * Compare old and new record data and log all changes
     * @param int $recordId - Record ID
     * @param int $userId - User ID who made changes
     * @param array $oldData - Old record data (from database)
     * @param array $newData - New record data (from form)
     * @param string $reason - Reason for changes
     * @return bool - Success status
     */
    public function logRecordChanges($recordId, $userId, $oldData, $newData, $reason = 'Record update') {
        $success = true;
        
        foreach ($oldData as $field => $oldValue) {
            if (isset($newData[$field]) && $oldValue != $newData[$field]) {
                $fieldType = $this->determineFieldType($field);
                
                $logData = [
                    'record_id' => $recordId,
                    'user_id' => $userId,
                    'field_name' => $field,
                    'field_type' => $fieldType,
                    'change_reason' => $reason
                ];
                
                // Set appropriate old/new values based on field type
                switch ($fieldType) {
                    case 'text':
                        $logData['old_value_text'] = (string)$oldValue;
                        $logData['new_value_text'] = (string)$newData[$field];
                        break;
                        
                    case 'number':
                        $logData['old_value_int'] = (int)$oldValue;
                        $logData['new_value_int'] = (int)$newData[$field];
                        break;
                        
                    case 'date':
                        $logData['old_value_date'] = $oldValue;
                        $logData['new_value_date'] = $newData[$field];
                        break;
                        
                    case 'enum':
                        $logData['old_value_enum'] = (string)$oldValue;
                        $logData['new_value_enum'] = (string)$newData[$field];
                        break;
                        
                    case 'foreign_key':
                        $logData['old_reference_id'] = (int)$oldValue;
                        $logData['new_reference_id'] = (int)$newData[$field];
                        break;
                }
                
                $success = $success && $this->logRecordChange($logData);
            }
        }
        
        return $success;
    }
    
    /**
     * Log record status change
     */
    public function logRecordStatusChange($recordId, $userId, $oldStatus, $newStatus, $reason = '') {
        return $this->logRecordChange([
            'record_id' => $recordId,
            'user_id' => $userId,
            'field_name' => 'status',
            'field_type' => 'enum',
            'old_value_enum' => $oldStatus,
            'new_value_enum' => $newStatus,
            'change_reason' => $reason
        ]);
    }
    
    /**
     * Log record disposal
     */
    public function logRecordDisposal($recordId, $userId, $reason = 'Disposal approved') {
        return $this->logRecordStatusChange($recordId, $userId, 'Scheduled for Disposal', 'Disposed', $reason);
    }
    
    /**
     * Log record archiving
     */
    public function logRecordArchive($recordId, $userId, $reason = 'Archiving approved') {
        return $this->logRecordStatusChange($recordId, $userId, 'Active', 'Archived', $reason);
    }
    
    // ==============================
    // HELPER METHODS
    // ==============================
    
    /**
     * Get user's current office and role
     */
    public function getUserContext($userId) {
        $sql = "SELECT u.office_id, u.role_id 
                FROM users u 
                WHERE u.user_id = :user_id";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Determine field type for database columns
     */
    private function determineFieldType($fieldName) {
        // Foreign key fields
        $foreignKeyFields = ['office_id', 'class_id', 'retention_period_id', 'created_by'];
        if (in_array($fieldName, $foreignKeyFields)) {
            return 'foreign_key';
        }
        
        // Date fields
        $dateFields = ['period_from', 'period_to', 'date_created', 'created_at', 'updated_at'];
        if (in_array($fieldName, $dateFields)) {
            return 'date';
        }
        
        // Enum fields
        $enumFields = [
            'records_medium', 'restrictions', 'utility_value', 'disposition_type', 
            'status', 'frequency_of_use', 'time_value'
        ];
        if (in_array($fieldName, $enumFields)) {
            return 'enum';
        }
        
        // Number fields
        $numberFields = ['volume', 'active_years', 'storage_years', 'total_years'];
        if (in_array($fieldName, $numberFields)) {
            return 'number';
        }
        
        // Default to text
        return 'text';
    }
    
    /**
     * Get old record data for comparison
     */
    public function getOldRecordData($recordId) {
        $sql = "SELECT * FROM records WHERE record_id = :record_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':record_id' => $recordId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Quick logging for debugging
     */
    public function logMessage($message, $userId = null, $entityType = null, $entityId = null) {
        if (!$userId && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
        }
        
        $userContext = $this->getUserContext($userId);
        
        return $this->logDisposalAction([
            'action_type' => 'SYSTEM_LOG',
            'performed_by' => $userId,
            'notes' => $message,
            'office_id' => $userContext['office_id'] ?? null,
            'role_id' => $userContext['role_id'] ?? null
        ]);
    }
}

// ==============================
// QUICK USAGE EXAMPLES
// ==============================
/*
// 1. Include in your file:
require_once 'db_logger.php';
$logger = new SystemLogger($pdo);

// 2. Log disposal request approval:
$logger->logRequestApprove($requestId, $userId, [$recordId1, $recordId2], 'Approved for disposal');

// 3. Log disposal request rejection:
$logger->logRequestReject($requestId, $userId, 'Missing required documents');

// 4. Log record changes (when updating):
$oldData = $logger->getOldRecordData($recordId);
$logger->logRecordChanges($recordId, $userId, $oldData, $_POST, 'User updated record details');

// 5. Log record status change:
$logger->logRecordStatusChange($recordId, $userId, 'Active', 'Archived', 'Transferred to archives');

// 6. Simple debug message:
$logger->logMessage('User accessed disposal page', $userId);
*/
?>