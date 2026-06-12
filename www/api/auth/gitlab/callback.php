<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

define('IN_APP', true);
require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../oauth_helper.php';

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>GitLab Авторизация</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen text-slate-800 font-sans">
    <div class="bg-white p-8 rounded-2xl shadow-xl max-w-sm w-full text-center border border-slate-100">
        <div id="loader" class="flex flex-col items-center">
            <div class="w-12 h-12 border-4 border-orange-500 border-t-transparent rounded-full animate-spin mb-4"></div>
            <h1 class="text-lg font-bold">Завершение авторизации...</h1>
            <p class="text-xs text-slate-500 mt-1">Пожалуйста, подождите, мы настраиваем интеграцию.</p>
        </div>
        <div id="result" class="hidden">
            <div id="status-icon" class="text-5xl mb-3"></div>
            <h1 id="status-title" class="text-lg font-bold"></h1>
            <p id="status-desc" class="text-xs text-slate-500 mt-1"></p>
        </div>
    </div>

    <script>
        function sendResult(data) {
            if (window.opener) {
                window.opener.postMessage(data, '*');
            }
            setTimeout(() => {
                window.close();
            }, 1500);
        }
    </script>
<?php

// Функция вывода ошибки
function outputError($msg) {
    echo "
    <script>
        document.getElementById('loader').classList.add('hidden');
        const res = document.getElementById('result');
        res.classList.remove('hidden');
        document.getElementById('status-icon').innerHTML = '❌';
        document.getElementById('status-icon').classList.add('text-red-500');
        document.getElementById('status-title').textContent = 'Ошибка авторизации';
        document.getElementById('status-desc').textContent = " . json_encode($msg) . ";
        
        sendResult({ type: 'gitlab_auth_error', message: " . json_encode($msg) . " });
    </script>
    </body>
    </html>
    ";
    exit;
}

// Функция вывода успеха
function outputSuccess($username) {
    $escapedUsername = json_encode($username);
    echo "
    <script>
        document.getElementById('loader').classList.add('hidden');
        const res = document.getElementById('result');
        res.classList.remove('hidden');
        document.getElementById('status-icon').innerHTML = '🦊';
        document.getElementById('status-icon').classList.add('text-orange-500');
        document.getElementById('status-title').textContent = 'Успешно подключено';
        document.getElementById('status-desc').textContent = 'Аккаунт GitLab @' + {$escapedUsername} + ' подключен.';
        
        sendResult({ type: 'gitlab_auth_success', username: {$escapedUsername} });
    </script>
    </body>
    </html>
    ";
    exit;
}

// Проверяем авторизацию в нашей системе
if (!isset($_SESSION['user_id'])) {
    outputError("Сессия пользователя истекла. Пожалуйста, войдите в систему заново.");
}

$state = $_GET['state'] ?? '';
$savedState = $_SESSION['gitlab_oauth_state'] ?? '';
unset($_SESSION['gitlab_oauth_state']);

if (empty($state) || $state !== $savedState) {
    outputError("Ошибка проверки безопасности (неверное состояние CSRF).");
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    $errorDesc = $_GET['error_description'] ?? 'Авторизация отменена пользователем.';
    outputError($errorDesc);
}

// Обмениваем code на токен доступа в GitLab
$tokenUrl = "https://gitlab.com/oauth/token";
$postFields = [
    'client_id'     => GITLAB_CLIENT_ID,
    'client_secret' => GITLAB_CLIENT_SECRET,
    'code'          => $code,
    'grant_type'    => 'authorization_code',
    'redirect_uri'  => GITLAB_REDIRECT_URI
];

$res = makeGitlabApiRequest(null, $tokenUrl, 'POST', $postFields);

if ($res['code'] !== 200) {
    outputError("Не удалось связаться с сервером GitLab (HTTP {$res['code']}).");
}

$tokenData = json_decode($res['body'], true);

$accessToken = $tokenData['access_token'] ?? '';
$refreshToken = $tokenData['refresh_token'] ?? null;
$expiresIn = $tokenData['expires_in'] ?? null;
$expiresAt = null;
if ($expiresIn) {
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
}

if (empty($accessToken)) {
    $errorMsg = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Не удалось получить токен доступа.';
    outputError($errorMsg);
}

// Получаем имя пользователя из GitLab API
$userUrl = GITLAB_API_URL . "/user";
$userRes = makeGitlabApiRequest($accessToken, $userUrl);

if ($userRes['code'] !== 200) {
    outputError("Не удалось получить данные профиля GitLab (HTTP {$userRes['code']}).");
}

$userData = json_decode($userRes['body'], true);
$gitlabUsername = $userData['username'] ?? '';

if (empty($gitlabUsername)) {
    outputError("Не удалось определить логин GitLab.");
}

// Сохраняем данные в БД
try {
    $platformUserId = $userData['id'] ?? null;
    $email = $userData['email'] ?? null;
    $avatarUrl = $userData['avatar_url'] ?? null;
    $profileUrl = $userData['web_url'] ?? null;
    
    save_user_integration(
        $_SESSION['user_id'],
        'gitlab',
        $platformUserId,
        $gitlabUsername,
        $email,
        $accessToken,
        $refreshToken,
        $expiresAt,
        $avatarUrl,
        $profileUrl,
        $userData
    );
    
    outputSuccess($gitlabUsername);
} catch (Exception $e) {
    outputError("Ошибка при сохранении данных в базу: " . $e->getMessage());
}
