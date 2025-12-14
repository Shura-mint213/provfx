<?php
require_once '../init.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['points'])) {
    echo json_encode(['success' => false, 'message' => 'Нет данных']);
    exit;
}

// Предполагается, что пользователь авторизован
$student_id = $_SESSION['user_id'] ?? null;
if (!$student_id) {
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

$pdo->prepare("DELETE FROM progress_points WHERE student_id=?")->execute([$student_id]);

$stmt = $pdo->prepare("INSERT INTO progress_points (student_id, name, position) VALUES (?, ?, ?)");
foreach ($data['points'] as $p) {
    $name = trim($p['name'] ?? '');
    $position = (int)($p['position'] ?? 0);
    if ($name !== '') $stmt->execute([$student_id, $name, $position]);
}

echo json_encode(['success' => true]);
