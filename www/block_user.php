<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../check_auth.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

$viewerId = $_SESSION['user_id'] ?? null;

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$targetId = (int)($input['target_id'] ?? 0);
$level = (int)($input['level'] ?? 0); // 1–4

if (!$viewerId || !$targetId || $targetId === $viewerId) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    // удалить существующий блок
    if ($level === 0) {
        $pdo->prepare("DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?")
            ->execute([$viewerId, $targetId]);

        echo json_encode(['success' => true, 'message' => 'Block removed']);
        exit;
    }

    // создать/обновить блок
    $stmt = $pdo->prepare("
        INSERT INTO user_blocks (blocker_id, blocked_id, block_level)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE block_level = VALUES(block_level)
    ");
    $stmt->execute([$viewerId, $targetId, $level]);

    echo json_encode(['success' => true, 'message' => 'Block updated']);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
