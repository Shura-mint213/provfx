<?php
define('IN_APP', true);
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../oauth_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'AUTH_REQUIRED', 'message' => 'Необходима авторизация']);
    exit;
}

// Загружаем токен пользователя из БД
$integration = get_user_integration($_SESSION['user_id'], 'gitlab');

if (!$integration || empty($integration['access_token'])) {
    echo json_encode(['success' => false, 'error' => 'GITLAB_NOT_LINKED', 'message' => 'Аккаунт GitLab не подключен']);
    exit;
}

$token = $integration['access_token'];

// Запрашиваем проекты пользователя из GitLab
$url = GITLAB_API_URL . "/projects?simple=true&per_page=100&min_access_level=30&order_by=last_activity_at";
$res = makeGitlabApiRequest($token, $url);

if ($res['code'] === 401) {
    echo json_encode(['success' => false, 'error' => 'TOKEN_EXPIRED', 'message' => 'Сессия GitLab устарела, авторизуйтесь заново']);
    exit;
}

if ($res['code'] !== 200) {
    echo json_encode(['success' => false, 'error' => 'GITLAB_API_ERROR', 'message' => 'Ошибка GitLab API (HTTP ' . $res['code'] . ')']);
    exit;
}

$repos = json_decode($res['body'], true);
$processedRepos = [];

if (is_array($repos)) {
    foreach ($repos as $repo) {
        $processedRepos[] = [
            'id'          => $repo['id'],
            'name'        => $repo['name'],
            'full_name'   => $repo['path_with_namespace'],
            'description' => $repo['description'] ?? '',
            'language'    => null, // Стек определяется при импорте
            'html_url'    => $repo['web_url']
        ];
    }
}

echo json_encode(['success' => true, 'repos' => $processedRepos]);
exit;
