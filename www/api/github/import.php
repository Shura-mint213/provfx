<?php

define('IN_APP', true);
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../github_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'AUTH_REQUIRED', 'message' => 'Необходима авторизация']);
    exit;
}

// Загружаем токен пользователя
$stmt = $pdo->prepare("SELECT github_token FROM students WHERE student_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$encryptedToken = $stmt->fetchColumn();

if (empty($encryptedToken)) {
    echo json_encode(['success' => false, 'error' => 'GITHUB_NOT_LINKED', 'message' => 'Аккаунт GitHub не подключен']);
    exit;
}

$token = decryptGithubToken($encryptedToken);

if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'DECRYPT_ERROR', 'message' => 'Не удалось расшифровать токен']);
    exit;
}

// Считываем JSON-тело запроса
$requestBody = file_get_contents('php://input');
$params = json_decode($requestBody, true);
$selectedRepos = $params['repos'] ?? [];

if (empty($selectedRepos) || !is_array($selectedRepos)) {
    echo json_encode(['success' => false, 'error' => 'EMPTY_REPOS', 'message' => 'Не выбраны репозитории для импорта']);
    exit;
}

$importedProjects = [];

foreach ($selectedRepos as $repoFullName) {
    // Безопасно парсим имя владельца и репозитория
    $parts = explode('/', $repoFullName);
    if (count($parts) !== 2) continue;
    $owner = $parts[0];
    $repoName = $parts[1];
    
    // Запрашиваем информацию о репозитории
    $url = "https://api.github.com/repos/{$owner}/{$repoName}";
    $res = makeGithubApiRequest($token, $url);
    
    if ($res['code'] === 200) {
        $repoData = json_decode($res['body'], true);
        
        $name = $repoData['name'] ?? $repoName;
        $description = $repoData['description'] ?? '';
        $repoUrl = $repoData['html_url'] ?? "https://github.com/{$repoFullName}";
        $primaryLanguage = $repoData['language'] ?? '';
        
        // Автоматическое определение стека технологий
        $techStack = detectGithubTechStack($token, $owner, $repoName, $primaryLanguage);
        $stackNotRecognized = empty($techStack);
        
        $importedProjects[] = [
            'name'                 => $name,
            'description'          => $description,
            'tech_stack'           => $techStack,
            'repo_url'             => $repoUrl,
            'role'                 => 'owner', // По умолчанию роль владельца
            'status'               => 'в процессе', // По умолчанию в процессе
            'stack_not_recognized' => $stackNotRecognized
        ];
    }
}

echo json_encode([
    'success'  => true,
    'projects' => $importedProjects
]);
exit;
