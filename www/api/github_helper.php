<?php
if (!defined('IN_APP')) {
    define('IN_APP', true);
}
require_once __DIR__ . '/../../config.php';

// Защищенные значения по умолчанию для OAuth
if (!defined('GITHUB_CLIENT_ID')) define('GITHUB_CLIENT_ID', 'placeholder_client_id');
if (!defined('GITHUB_CLIENT_SECRET')) define('GITHUB_CLIENT_SECRET', 'placeholder_client_secret');
if (!defined('GITHUB_REDIRECT_URI')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Определяем путь относительно DOCUMENT_ROOT
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $helperDir = __DIR__;
    
    $webPath = '';
    if ($docRoot && strpos($helperDir, $docRoot) === 0) {
        $webPath = substr($helperDir, strlen($docRoot));
        $webPath = str_replace('\\', '/', $webPath);
    }
    
    $webPath = rtrim($webPath, '/');
    define('GITHUB_REDIRECT_URI', $protocol . '://' . $host . $webPath . '/auth/github/callback.php');
}
if (!defined('GITHUB_ENCRYPTION_KEY')) define('GITHUB_ENCRYPTION_KEY', 'default_secret_key_32_characters_long_!!');

/**
 * Получение ключа шифрования (sha256 от константы)
 */
function getEncryptionKey() {
    return hash('sha256', GITHUB_ENCRYPTION_KEY, true);
}

/**
 * Шифрование токена
 */
function encryptGithubToken($token) {
    if (empty($token)) return '';
    $key = getEncryptionKey();
    $cipher = "aes-256-cbc";
    $ivlen = openssl_cipher_iv_length($cipher);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $ciphertext = openssl_encrypt($token, $cipher, $key, 0, $iv);
    return base64_encode($iv . $ciphertext);
}

/**
 * Дешифрование токена
 */
function decryptGithubToken($encrypted) {
    if (empty($encrypted)) return '';
    $key = getEncryptionKey();
    $cipher = "aes-256-cbc";
    $data = base64_decode($encrypted);
    $ivlen = openssl_cipher_iv_length($cipher);
    if (strlen($data) < $ivlen) return '';
    $iv = substr($data, 0, $ivlen);
    $ciphertext = substr($data, $ivlen);
    return openssl_decrypt($ciphertext, $cipher, $key, 0, $iv);
}

/**
 * Запрос к GitHub API через cURL
 */
function makeGithubApiRequest($token, $url, $method = 'GET', $postData = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $headers = [
        "User-Agent: public_html-App",
        "Accept: application/vnd.github.v3+json"
    ];
    
    if ($token) {
        $headers[] = "Authorization: Bearer " . $token;
    }
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($postData) {
            if (is_array($postData)) {
                $headers[] = "Content-Type: application/x-www-form-urlencoded";
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            } else {
                $headers[] = "Content-Type: application/json";
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            }
        }
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // Для отладки на localhost, если curl ругается на SSL-сертификаты
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'body' => $response
    ];
}

/**
 * Загрузка содержимого файла из репозитория
 */
function fetchGithubFileContent($token, $owner, $repo, $path) {
    $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}";
    $res = makeGithubApiRequest($token, $url);
    if ($res['code'] === 200) {
        $data = json_decode($res['body'], true);
        if (isset($data['content']) && isset($data['encoding']) && $data['encoding'] === 'base64') {
            return base64_decode($data['content']);
        }
    }
    return null;
}

/**
 * Определение стека технологий репозитория
 */
function detectGithubTechStack($token, $owner, $repo, $primaryLanguage) {
    $stack = [];
    if (!empty($primaryLanguage)) {
        $stack[] = $primaryLanguage;
    }
    
    // Получаем список файлов в корне репозитория
    $url = "https://api.github.com/repos/{$owner}/{$repo}/contents";
    $res = makeGithubApiRequest($token, $url);
    
    if ($res['code'] === 200) {
        $files = json_decode($res['body'], true);
        if (is_array($files)) {
            $fileNames = array_column($files, 'name');
            
            // 1. Node.js / Frontend
            if (in_array('package.json', $fileNames)) {
                if (!in_array('JavaScript', $stack) && !in_array('TypeScript', $stack)) {
                    $stack[] = 'Node.js';
                }
                $pkg = fetchGithubFileContent($token, $owner, $repo, 'package.json');
                if ($pkg) {
                    $pkgData = json_decode($pkg, true);
                    $deps = array_merge($pkgData['dependencies'] ?? [], $pkgData['devDependencies'] ?? []);
                    if (isset($deps['react'])) $stack[] = 'React';
                    if (isset($deps['vue'])) $stack[] = 'Vue.js';
                    if (isset($deps['@angular/core'])) $stack[] = 'Angular';
                    if (isset($deps['next'])) $stack[] = 'Next.js';
                    if (isset($deps['express'])) $stack[] = 'Express';
                    if (isset($deps['@nestjs/core'])) $stack[] = 'NestJS';
                    if (isset($deps['nuxt'])) $stack[] = 'Nuxt.js';
                }
            }
            
            // 2. Python
            if (in_array('requirements.txt', $fileNames) || in_array('pyproject.toml', $fileNames) || in_array('Pipfile', $fileNames)) {
                if (!in_array('Python', $stack)) {
                    $stack[] = 'Python';
                }
                $req = fetchGithubFileContent($token, $owner, $repo, 'requirements.txt');
                if ($req) {
                    if (stripos($req, 'django') !== false) $stack[] = 'Django';
                    if (stripos($req, 'flask') !== false) $stack[] = 'Flask';
                    if (stripos($req, 'fastapi') !== false) $stack[] = 'FastAPI';
                }
            }
            
            // 3. PHP
            if (in_array('composer.json', $fileNames)) {
                if (!in_array('PHP', $stack)) {
                    $stack[] = 'PHP';
                }
                $comp = fetchGithubFileContent($token, $owner, $repo, 'composer.json');
                if ($comp) {
                    $compData = json_decode($comp, true);
                    $deps = array_merge($compData['require'] ?? [], $compData['require-dev'] ?? []);
                    if (isset($deps['laravel/framework'])) $stack[] = 'Laravel';
                    if (isset($deps['symfony/symfony']) || isset($deps['symfony/http-kernel'])) $stack[] = 'Symfony';
                    if (isset($deps['yiisoft/yii2'])) $stack[] = 'Yii2';
                }
            }
            
            // 4. Java
            if (in_array('pom.xml', $fileNames) || in_array('build.gradle', $fileNames)) {
                if (!in_array('Java', $stack)) {
                    $stack[] = 'Java';
                }
                if (in_array('pom.xml', $fileNames)) {
                    $pom = fetchGithubFileContent($token, $owner, $repo, 'pom.xml');
                    if ($pom && stripos($pom, 'spring-boot') !== false) {
                        $stack[] = 'Spring Boot';
                    }
                }
            }
            
            // 5. Go
            if (in_array('go.mod', $fileNames)) {
                if (!in_array('Go', $stack)) {
                    $stack[] = 'Go';
                }
            }
            
            // 6. Rust
            if (in_array('Cargo.toml', $fileNames)) {
                if (!in_array('Rust', $stack)) {
                    $stack[] = 'Rust';
                }
            }
        }
    }
    
    $stack = array_unique($stack);
    return implode(', ', $stack);
}
