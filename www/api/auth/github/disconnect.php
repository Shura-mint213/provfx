<?php
define('IN_APP', true);
require_once __DIR__ . '/../../../../init.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'AUTH_REQUIRED', 'message' => 'Необходима авторизация']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE students SET github_username = NULL, github_token = NULL WHERE student_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DATABASE_ERROR', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
}
exit;
