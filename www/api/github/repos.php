<?php
define('IN_APP', true);
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../github_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'AUTH_REQUIRED', 'message' => 'Необходима авторизация']);
    exit;
}

// Загружаем токен пользователя из БД
$stmt = $pdo->prepare("SELECT github_token FROM students WHERE student_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$encryptedToken = $stmt->fetchColumn();

if (empty($encryptedToken)) {
    echo json_encode(['success' => false, 'error' => 'GITHUB_NOT_LINKED', 'message' => 'Аккаунт GitHub не подключен']);
    exit;
}

$token = decryptGithubToken($encryptedToken);

if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'DECRYPT_ERROR', 'message' => 'Не удалось расшифровать токен доступа']);
    exit;
}

// Запрашиваем репозитории пользователя из GitHub
$url = "https://api.github.com/user/repos?type=public&per_page=100&sort=updated";
$res = makeGithubApiRequest($token, $url);

if ($res['code'] === 401) {
    echo json_encode(['success' => false, 'error' => 'TOKEN_EXPIRED', 'message' => 'Сессия GitHub устарела, авторизуйтесь заново']);
    exit;
}

if ($res['code'] !== 200) {
    echo json_encode(['success' => false, 'error' => 'GITHUB_API_ERROR', 'message' => 'Ошибка GitHub API (HTTP ' . $res['code'] . ')']);
    exit;
}

$repos = json_decode($res['body'], true);
$processedRepos = [];

if (is_array($repos)) {
    foreach ($repos as $repo) {
        $processedRepos[] = [
            'name'        => $repo['name'],
            'full_name'   => $repo['full_name'],
            'description' => $repo['description'],
            'language'    => $repo['language'],
            'html_url'    => $repo['html_url']
        ];
    }
}

echo json_encode(['success' => true, 'repos' => $processedRepos]);
exit;
