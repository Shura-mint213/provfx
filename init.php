<?php
// init.php — общий файл инициализации

if (!defined('IN_APP')) {
    define('IN_APP', true);
}

// Грузим config.php ВСЕГДА
require_once __DIR__ . '/config.php';

// Если авторизационная проверка нужна — задаём IN_APP в файле, где это требуется
// init.php НЕ должен останавливать работу login.php, logout.php, index.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
