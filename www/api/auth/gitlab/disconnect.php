<?php
define('IN_APP', true);
require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../oauth_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'AUTH_REQUIRED', 'message' => 'Необходима авторизация']);
    exit;
}

try {
    delete_user_integration($_SESSION['user_id'], 'gitlab');
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DATABASE_ERROR', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
}
exit;
