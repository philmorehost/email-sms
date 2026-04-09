<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!rateLimit('security_log_api_' . session_id(), 60, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded']);
    exit;
}

try {
    $db = getDB();
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $stmt = $db->prepare(
        "SELECT id, event_type, ip_address, username, country_code, details, is_trusted, created_at
         FROM security_logs ORDER BY created_at DESC LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logs as &$log) {
        $log['is_trusted'] = (bool)$log['is_trusted'];
        $log['king_icon']  = $log['is_trusted'] ? '👑' : '';
        $log['time_ago']   = timeAgo($log['created_at']);
    }
    unset($log);

    echo json_encode(['success' => true, 'logs' => $logs]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)   return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
