<?php
// ================================================
// — Карта Тэррелл (привязана к открытому проекту)
// ================================================
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json; charset=utf-8');

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'AUTH_REQUIRED']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ================================================
// Загрузка точек (для формы)
if ($action === 'load') {
    $stmt = $pdo->prepare("
        SELECT id FROM projects 
        WHERE student_id = ? AND status = 'open' 
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $projectId = $stmt->fetchColumn();

    if (!$projectId) {
        echo json_encode(['success' => true, 'points' => [], 'message' => 'Нет открытого проекта']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, name, type, comment, position 
        FROM terrell_points 
        WHERE project_id = ? 
        ORDER BY position ASC
    ");
    $stmt->execute([$projectId]);
    $points = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'project_id' => $projectId, 'points' => $points]);
    exit;
}

// ================================================
// Добавление точки (+ Достижение / + Кризис / + Цель)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'add' || empty($action))) {
    $projectId = (int)($_POST['project_id'] ?? 0);
    $name      = trim($_POST['name'] ?? '');
    $type      = $_POST['type'] ?? '';
    $comment   = trim($_POST['comment'] ?? '');

    if (!$projectId || empty($name) || !in_array($type, ['achievement', 'crisis', 'goal'])) {
        echo json_encode(['success' => false, 'error' => 'Неверные данные']);
        exit;
    }

    // Проверка: проект открыт и принадлежит пользователю
    $stmt = $pdo->prepare("
        SELECT status FROM projects 
        WHERE id = ? AND student_id = ?
    ");
    $stmt->execute([$projectId, $userId]);
    $status = $stmt->fetchColumn();

    if ($status !== 'open') {
        echo json_encode(['success' => false, 'error' => 'Проект закрыт или не принадлежит вам. Редактирование невозможно.']);
        exit;
    }

    // Позиция (авто-инкремент)
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(position), 0) + 1 
        FROM terrell_points 
        WHERE project_id = ?
    ");
    $stmt->execute([$projectId]);
    $position = (int)$stmt->fetchColumn();

    $insert = $pdo->prepare("
        INSERT INTO terrell_points 
        (project_id, student_id, name, type, comment, position) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([$projectId, $userId, $name, $type, $comment, $position]);

    echo json_encode([
        'success' => true,
        'id'      => $pdo->lastInsertId(),
        'position' => $position
    ]);
    exit;
}

// ================================================
// Если действие неизвестно
echo json_encode(['success' => false, 'error' => 'Unknown action']);
exit;
