<?php
define('IN_APP', true);
require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../oauth_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Проверяем авторизацию в нашей системе
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'error' => 'AUTH_REQUIRED',
        'message' => 'Необходима авторизация в системе'
    ]);
    exit;
}

// Проверяем, настроены ли константы для GitLab
if (GITLAB_CLIENT_ID === 'placeholder_client_id' || GITLAB_CLIENT_ID === 'your_gitlab_client_id' ||
    GITLAB_CLIENT_SECRET === 'placeholder_client_secret' || GITLAB_CLIENT_SECRET === 'your_gitlab_client_secret') {
    echo json_encode([
        'success' => false,
        'error' => 'GITLAB_NOT_CONFIGURED',
        'message' => 'Интеграция с GitLab не настроена. Задайте GITLAB_CLIENT_ID и GITLAB_CLIENT_SECRET в файле config.php.'
    ]);
    exit;
}

// Генерируем случайный state для защиты от CSRF-атак
$state = bin2hex(random_bytes(16));
$_SESSION['gitlab_oauth_state'] = $state;

// Ссылка авторизации на GitLab
$authUrl = "https://gitlab.com/oauth/authorize?" . http_build_query([
    'client_id'     => GITLAB_CLIENT_ID,
    'redirect_uri'  => GITLAB_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'read_user read_repository read_api',
    'state'         => $state
]);

echo json_encode([
    'success' => true,
    'url'     => $authUrl
]);
exit;
