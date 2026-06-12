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

// Проверяем, настроены ли константы для GitHub
if (GITHUB_CLIENT_ID === 'placeholder_client_id' || GITHUB_CLIENT_SECRET === 'placeholder_client_secret') {
    echo json_encode([
        'success' => false,
        'error' => 'GITHUB_NOT_CONFIGURED',
        'message' => 'Интеграция с GitHub не настроена. Задайте GITHUB_CLIENT_ID и GITHUB_CLIENT_SECRET в файле config.php.'
    ]);
    exit;
}

// Генерируем случайный state для защиты от CSRF-атак
$state = bin2hex(random_bytes(16));
$_SESSION['github_oauth_state'] = $state;

// Ссылка авторизации на GitHub
$authUrl = "https://github.com/login/oauth/authorize?" . http_build_query([
    'client_id'    => GITHUB_CLIENT_ID,
    'redirect_uri' => GITHUB_REDIRECT_URI,
    'scope'        => 'read:user public_repo',
    'state'        => $state
]);

echo json_encode([
    'success' => true,
    'url'     => $authUrl
]);
exit;
