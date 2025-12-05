<?php
require_once 'session.php';
require_once 'db_connect.php';

header('Content-Type: application/json');

try {
    // Check if required parameters are provided
    if (!isset($_GET['request_id']) || !isset($_GET['type'])) {
        throw new Exception("Missing required parameters: request_id and type");
    }

    $requestId = (int)$_GET['request_id'];
    $type = $_GET['type'];

    if ($requestId <= 0) {
        throw new Exception("Invalid request ID");
    }

    if ($type === 'disposal') {
        // Get disposal request details with user information
        $sql = "SELECT 
                    dr.*, 
                    u.first_name, 
                    u.last_name,
                    u.email,
                    COUNT(DISTINCT rd.record_id) as record_count
                FROM disposal_requests dr 
                JOIN users u ON dr.requested_by = u.user_id 
                LEFT JOIN request_details rd ON dr.request_id = rd.request_id
                WHERE dr.request_id = ?
                GROUP BY dr.request_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception("Request not found with ID: " . $requestId);
        }

        // Get records for this request
        $recordsSql = "SELECT 
                            r.record_id,
                            r.record_series_code,
                            r.record_series_title,
                            r.period_from,
                            r.period_to,
                            r.total_years,
                            r.disposition_type,
                            r.status,
                            o.office_name,
                            rc.class_name,
                            rp.period_name as retention_period
                        FROM records r
                        JOIN request_details rd ON r.record_id = rd.record_id
                        JOIN offices o ON r.office_id = o.office_id
                        JOIN record_classification rc ON r.class_id = rc.class_id
                        LEFT JOIN retention_periods rp ON r.retention_period_id = rp.period_id
                        WHERE rd.request_id = ?
                        ORDER BY r.record_series_code";
        
        $recordsStmt = $pdo->prepare($recordsSql);
        $recordsStmt->execute([$requestId]);
        $records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'request' => $request,
            'records' => $records,
            'message' => 'Request details loaded successfully'
        ]);
        
    } else {
        throw new Exception("Invalid request type. Use 'disposal'");
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}