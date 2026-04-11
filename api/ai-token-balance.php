<?php
/**
 * Quick endpoint to return the current user's AI token balance.
 * Used for polling in the template editor and workspace.
 * GET /api/ai-token-balance.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'balance' => 0]);
    exit;
}

$userId  = (int)($user['id'] ?? 0);
$balance = 0;

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT balance FROM user_ai_tokens WHERE user_id=?");
    $stmt->execute([$userId]);
    $balance = (int)($stmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

echo json_encode(['success' => true, 'balance' => $balance]);
