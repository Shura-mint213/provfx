<?php
require_once __DIR__ . '/../../init.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];

// Получаем роль текущего пользователя
$stmt = $pdo->prepare("SELECT role, login FROM students WHERE student_id = ?");
$stmt->execute([$currentUserId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || ($currentUser['role'] ?? 'user') !== 'admin') {
    http_response_code(403);
    die("
        <!DOCTYPE html>
        <html lang='ru'>
        <head>
            <meta charset='utf-8'>
            <title>Доступ ограничен</title>
            <script src='https://cdn.tailwindcss.com'></script>
        </head>
        <body class='bg-gray-100 flex items-center justify-center min-h-screen'>
            <div class='bg-white p-8 rounded-2xl shadow-xl max-w-md text-center border border-gray-200'>
                <div class='text-red-500 text-6xl mb-4'>⚠️</div>
                <h1 class='text-2xl font-bold text-gray-800 mb-2'>Доступ ограничен</h1>
                <p class='text-gray-600 mb-6'>Эта страница доступна только администраторам системы.</p>
                <a href='../gallery.php' class='px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold transition duration-200'>Вернуться в галерею</a>
            </div>
        </body>
        </html>
    ");
}

$error = '';
$success = '';

// Обработка изменения роли
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_role') {
    $targetId = (int)($_POST['student_id'] ?? 0);
    $newRole = trim($_POST['role'] ?? 'user');

    if ($newRole !== 'admin' && $newRole !== 'user') {
        $error = 'Некорректное значение роли.';
    } elseif ($targetId === $currentUserId) {
        $error = 'Вы не можете изменить свою собственную роль.';
    } else {
        $stmt = $pdo->prepare("UPDATE students SET role = ? WHERE student_id = ?");
        $stmt->execute([$newRole, $targetId]);
        $success = 'Роль пользователя успешно обновлена.';
    }
}

// Загружаем список всех пользователей
$stmt = $pdo->query("SELECT student_id, login, zachetka, group_number, semester, department, role, is_published FROM students ORDER BY student_id ASC");
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Статистика
$totalUsers = count($allUsers);
$totalAdmins = count(array_filter($allUsers, fn($u) => $u['role'] === 'admin'));
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Панель администратора — Управление пользователями</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        mai: {
                            blue: '#0055A4',
                            dark: '#0A2E5A',
                            light: '#E6F0FA',
                            accent: '#FF6B35'
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Roboto', sans-serif;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-slate-50 via-slate-100 to-indigo-50 min-h-screen text-slate-800">

    <header class="bg-indigo-800 text-white shadow-lg">
        <div class="container mx-auto px-6 py-5 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-white text-indigo-800 font-bold flex items-center justify-center rounded-xl shadow-md"><i class="fa-solid fa-user-shield"></i></div>
                <h1 class="text-2xl font-bold tracking-tight">Панель администратора</h1>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-sm opacity-85">Привет, <strong class="font-semibold"><?= htmlspecialchars($currentUser['login']) ?></strong></span>
                <a href="../gallery.php" class="bg-white/10 hover:bg-white/20 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition duration-200"><i class="fa-solid fa-images mr-2"></i>Галерея</a>
                <a href="../logout.php" class="text-white/80 hover:text-white text-sm hover:underline">Выйти</a>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-6 py-10 max-w-6xl">

        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-xl mb-6 shadow-sm flex items-center">
                <i class="fa-solid fa-circle-exclamation mr-3 text-lg"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-xl mb-6 shadow-sm flex items-center">
                <i class="fa-solid fa-circle-check mr-3 text-lg"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- Статистика -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="bg-white p-6 rounded-2xl shadow-md border border-slate-100 flex items-center space-x-5 hover:shadow-lg transition duration-200">
                <div class="w-14 h-14 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center text-2xl font-bold shadow-inner"><i class="fa-solid fa-users"></i></div>
                <div>
                    <div class="text-slate-400 text-sm font-semibold uppercase tracking-wider">Всего пользователей</div>
                    <div class="text-3xl font-extrabold text-slate-800"><?= $totalUsers ?></div>
                </div>
            </div>
            <div class="bg-white p-6 rounded-2xl shadow-md border border-slate-100 flex items-center space-x-5 hover:shadow-lg transition duration-200">
                <div class="w-14 h-14 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center text-2xl font-bold shadow-inner"><i class="fa-solid fa-user-shield"></i></div>
                <div>
                    <div class="text-slate-400 text-sm font-semibold uppercase tracking-wider">Администраторы</div>
                    <div class="text-3xl font-extrabold text-slate-800"><?= $totalAdmins ?></div>
                </div>
            </div>
        </div>

        <!-- Таблица пользователей -->
        <div class="bg-white rounded-2xl shadow-md border border-slate-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
                <h2 class="text-lg font-bold text-slate-800">Список зарегистрированных пользователей</h2>
                <span class="text-xs font-semibold px-2.5 py-1 bg-indigo-100 text-indigo-700 rounded-full">Таблица БД: students</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100 text-xs font-bold uppercase tracking-wider text-slate-500">
                            <th class="py-4 px-6">ID</th>
                            <th class="py-4 px-6">Аватар</th>
                            <th class="py-4 px-6">Логин</th>
                            <th class="py-4 px-6">Номер зачётки</th>
                            <th class="py-4 px-6">Группа/Семестр</th>
                            <th class="py-4 px-6">Статус стенда</th>
                            <th class="py-4 px-6">Роль</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                        <?php foreach ($allUsers as $u): ?>
                            <tr class="hover:bg-slate-50/50 transition duration-150">
                                <td class="py-4 px-6 font-mono text-slate-500"><?= $u['student_id'] ?></td>
                                <td class="py-4 px-6">
                                    <img src="../get_avatar.php?id=<?= $u['student_id'] ?>" alt="Аватар" class="w-9 h-9 rounded-full object-cover border-2 border-indigo-100 shadow-sm">
                                </td>
                                <td class="py-4 px-6">
                                    <div class="font-bold text-slate-800"><?= htmlspecialchars($u['login']) ?></div>
                                    <div class="text-xs text-indigo-500"><a href="../stand.php?id=<?= $u['student_id'] ?>" target="_blank" class="hover:underline">Посмотреть стенд <i class="fa-solid fa-arrow-up-right-from-square ml-0.5 text-[10px]"></i></a></div>
                                </td>
                                <td class="py-4 px-6 font-mono text-slate-600"><?= htmlspecialchars($u['zachetka'] ?: '—') ?></td>
                                <td class="py-4 px-6">
                                    <?php if ($u['group_number'] || $u['semester']): ?>
                                        <div class="font-semibold text-slate-700"><?= htmlspecialchars($u['group_number'] ?: '—') ?></div>
                                        <div class="text-xs text-slate-500"><?= $u['semester'] ?> семестр, каф. <?= htmlspecialchars($u['department'] ?: '—') ?></div>
                                    <?php else: ?>
                                        <span class="text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-6">
                                    <?php if ($u['is_published']): ?>
                                        <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1 bg-green-50 text-green-700 rounded-full border border-green-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-green-500 mr-1.5"></span>
                                            Опубликован
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1 bg-slate-50 text-slate-600 rounded-full border border-slate-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400 mr-1.5"></span>
                                            Черновик
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-6">
                                    <?php if ($u['student_id'] === $currentUserId): ?>
                                        <span class="inline-flex items-center text-xs font-bold px-3 py-1.5 bg-indigo-600 text-white rounded-xl">
                                            <i class="fa-solid fa-user-shield mr-1"></i> Администратор (Вы)
                                        </span>
                                    <?php else: ?>
                                        <form method="POST" class="flex items-center space-x-2 role-update-form">
                                            <input type="hidden" name="action" value="update_role">
                                            <input type="hidden" name="student_id" value="<?= $u['student_id'] ?>">
                                            <select name="role" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer transition">
                                                <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>Обычный пользователь</option>
                                                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Администратор</option>
                                            </select>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <footer class="text-center text-xs text-slate-400 py-10 mt-10 border-t border-slate-200 max-w-6xl mx-auto">
        © 2026 МГУТУ проект им МШ 2 • Панель управления треком МАИ
    </footer>

</body>

</html>
