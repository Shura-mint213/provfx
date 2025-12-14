<?php
require_once __DIR__ . '/init.php';

$isApi = str_contains($_SERVER['SCRIPT_NAME'], '/api/');

// Если пользователь не авторизован
if (!isset($_SESSION['user_id'])) {

    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'AUTH_REQUIRED'
        ]);
        exit;
    } else {
        header('Location: login.php');
        exit;
    }
}

$userId = $_SESSION['user_id'];

// Проверка админ-бана (blocker_id = 0)
$stmt = $pdo->prepare("
    SELECT block_level
    FROM user_blocks
    WHERE blocker_id = 0 AND blocked_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$adminBlock = (int)($stmt->fetchColumn() ?? 0);

if ($adminBlock === 4) { // Полный бан
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'ADMIN_BAN'
        ]);
        exit;
    } else {
        die("<h2 style='color:red;text-align:center;margin-top:50px;'>Ваш аккаунт заблокирован администрацией</h2>");
    }
}

// Level 3 — частичный бан (пока не ограничиваем)
