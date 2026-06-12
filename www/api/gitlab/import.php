<?php
define('IN_APP', true);
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../oauth_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'AUTH_REQUIRED', 'message' => 'Необходима авторизация']);
    exit;
}

// Загружаем токен пользователя
$integration = get_user_integration($_SESSION['user_id'], 'gitlab');

if (!$integration || empty($integration['access_token'])) {
    echo json_encode(['success' => false, 'error' => 'GITLAB_NOT_LINKED', 'message' => 'Аккаунт GitLab не подключен']);
    exit;
}

$token = $integration['access_token'];

// Считываем JSON-тело запроса
$requestBody = file_get_contents('php://input');
$params = json_decode($requestBody, true);
$selectedRepos = $params['repos'] ?? [];

if (empty($selectedRepos) || !is_array($selectedRepos)) {
    echo json_encode(['success' => false, 'error' => 'EMPTY_REPOS', 'message' => 'Не выбраны проекты для импорта']);
    exit;
}

$importedProjects = [];

foreach ($selectedRepos as $repoId) {
    // Если передан полный путь, url-кодируем его для API
    $projectId = is_numeric($repoId) ? $repoId : urlencode($repoId);
    
    // Запрашиваем информацию о проекте
    $url = GITLAB_API_URL . "/projects/{$projectId}";
    $res = makeGitlabApiRequest($token, $url);
    
    if ($res['code'] === 200) {
        $repoData = json_decode($res['body'], true);
        
        $name = $repoData['name'] ?? '';
        $description = $repoData['description'] ?? '';
        $repoUrl = $repoData['web_url'] ?? '';
        
        // Получаем основной язык программирования проекта
        $langUrl = GITLAB_API_URL . "/projects/{$repoData['id']}/languages";
        $langRes = makeGitlabApiRequest($token, $langUrl);
        $primaryLanguage = '';
        if ($langRes['code'] === 200) {
            $languages = json_decode($langRes['body'], true);
            if (is_array($languages) && !empty($languages)) {
                arsort($languages);
                $primaryLanguage = key($languages);
            }
        }
        
        // Автоматическое определение стека технологий
        $techStack = detectGitlabTechStack($token, $repoData['id'], $primaryLanguage);
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
