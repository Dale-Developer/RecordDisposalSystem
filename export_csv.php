<?php
function exportActivityLogsToCSV($logs, $filename = 'activity_logs') {
    $filename = $filename . '_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, [
        'ID', 'Date & Time', 'Action Type', 'Description', 
        'Actor Name', 'Actor Role', 'Actor Office',
        'Entity Type', 'Entity ID', 'Entity Title', 'Entity Code',
        'Status', 'IP Address', 'Page URL'
    ]);
    
    // Rows
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['id'],
            $log['created_at'],
            $log['action_type'],
            $log['description'],
            $log['actor_name'],
            $log['actor_role'],
            $log['actor_office'],
            $log['entity_type'],
            $log['entity_id'],
            $log['entity_title'],
            $log['entity_code'],
            $log['status'],
            $log['ip_address'],
            $log['page_url']
        ]);
    }
    
    fclose($output);
}
?>