<?php
require_once '../init.php';

$student_id = (int)($_GET['id'] ?? 0);

if (!$student_id) {
    http_response_code(404);
    exit;
}

// Берём только BLOB аватара
$stmt = $pdo->prepare("SELECT avatar FROM students WHERE student_id = ?");
$stmt->execute([$student_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

// Если аватар найден и не пустой
if ($data && !empty($data['avatar'])) {

    // Определяем MIME-тип по содержимому BLOB
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($data['avatar']);

    // Разрешённые форматы
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (in_array($mime, $allowed)) {
        header("Content-Type: $mime");
        header("Content-Length: " . strlen($data['avatar']));
        echo $data['avatar'];
        exit;
    }
}

// Если аватара нет или формат не изображение → выдаём заглушку
$default = __DIR__ . '/assets/images/default-avatar.png';

if (file_exists($default)) {
    header('Content-Type: image/png');
    readfile($default);
    exit;
}

// Если нет даже файла заглушки → SVG fallback
header('Content-Type: image/svg+xml');
echo '<?xml version="1.0" encoding="UTF-8"?>
<svg width="200" height="200" xmlns="http://www.w3.org/2000/svg">
 <rect width="100%" height="100%" fill="#e5e7eb"/>
 <text x="50%" y="50%" font-size="42" text-anchor="middle" dy=".3em" fill="#6b7280">?</text>
</svg>';
exit;
