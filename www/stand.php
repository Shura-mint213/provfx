<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../check_auth.php';
require_once __DIR__ . '/api/oauth_helper.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Студент не найден');

// Загружаем студента (опубликованный)
$stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ? AND is_published = 1");
$stmt->execute([$id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) die('Стенд не опубликован или удалён');

// Загружаем интеграции для студента
$githubIntegration = get_user_integration($id, 'github');
$gitlabIntegration = get_user_integration($id, 'gitlab');

// Определяем кто просматривает стенд
$viewerId = $_SESSION['user_id'] ?? null;
$is_owner = $viewerId && $viewerId == $id;
$is_logged_in = !!$viewerId;

// Получим проекты, связанные с данным студентом:
// — проекты, которые он создал (owner)
// — проекты, в которых он участник (project_members)
$projectsStmt = $pdo->prepare("
    SELECT DISTINCT p.*
    FROM projects p
    INNER JOIN project_members pm ON pm.project_id = p.id
    WHERE pm.student_id = :sid
    ORDER BY p.id DESC
");
$projectsStmt->execute(['sid' => $id]);
$projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);

// Получим участников для всех проектов (карта project_id => array of members)
$membersMap = [];
error_log("=== DEBUG stand.php ===");
error_log("Student ID: $id");
error_log("SQL запрос для проектов готовится...");
if (!empty($projects)) {
    $projectIds = array_column($projects, 'id');
    $in = implode(',', array_fill(0, count($projectIds), '?'));
    $q = "
        SELECT pm.project_id, pm.student_id, s.login, s.student_id
        FROM project_members pm
        JOIN students s ON s.student_id = pm.student_id
        WHERE pm.project_id IN ($in)
        ORDER BY pm.id ASC
    ";
    error_log("Выполняем запрос с параметром sid = $id");
    $stmt = $pdo->prepare($q);
    $stmt->execute($projectIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $pid = (int)$r['project_id'];
        $membersMap[$pid][] = $r;
    }
}

// Получим проекты текущего просматривающего (чтобы он мог приглашать в свои проекты)
$viewerProjects = [];
if ($is_logged_in) {
    $vpStmt = $pdo->prepare("
        SELECT DISTINCT p.id, p.name
        FROM projects p
        INNER JOIN project_members pm ON pm.project_id = p.id
        WHERE pm.student_id = ?
        ORDER BY p.id DESC
    ");
    $vpStmt->execute([$viewerId]);
    $viewerProjects = $vpStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получим входящие приглашения для просматривающего (чтобы он мог принять/отклонить) — опционально
$incomingInvitations = [];
if ($is_logged_in && $viewerId === $id) { // показываем владельцу стенда его входящие приглашения
    $invStmt = $pdo->prepare("
        SELECT pi.id, pi.project_id, pi.sender_id, pi.status, pi.created_at, p.name as project_name, s.login as sender_login
        FROM project_invitations pi
        JOIN projects p ON p.id = pi.project_id
        JOIN students s ON s.student_id = pi.sender_id
        WHERE pi.receiver_id = ? AND pi.status = 'pending'
        ORDER BY pi.created_at DESC
    ");
    $invStmt->execute([$viewerId]);
    $incomingInvitations = $invStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получение навыков / компетенций (как раньше)
$skills = $pdo->prepare("SELECT name, level FROM skills WHERE student_id = ?");
$skills->execute([$id]);
$skills = $skills->fetchAll(PDO::FETCH_ASSOC);

$competencies = $pdo->prepare("SELECT name, level, type FROM competencies WHERE student_id = ?");
$competencies->execute([$id]);
$competencies = $competencies->fetchAll(PDO::FETCH_ASSOC);

$criteria = $pdo->prepare("SELECT criterion FROM criteria WHERE student_id = ?");
$criteria->execute([$id]);
$criteria = $criteria->fetchAll(PDO::FETCH_ASSOC);

$projectsForDisplay = $projects; // уже подготовлены
$reflections = $pdo->prepare("SELECT what_worked, what_failed, changes FROM reflections WHERE student_id = ?");
$reflections->execute([$id]);
$reflections = $reflections->fetchAll(PDO::FETCH_ASSOC);

$progressStmt = $pdo->prepare("
    SELECT name 
    FROM terrell_points 
    WHERE student_id = ? 
    ORDER BY position ASC
");
$progressStmt->execute([$id]);
$progress = $progressStmt->fetchAll(PDO::FETCH_COLUMN);

$all_skills = array_merge($skills, $competencies);

// Фильтруем проекты текущего пользователя, исключая проекты, где студент уже участник
$projectsAvailableForInvite = [];
if ($is_logged_in && !$is_owner && !empty($viewerProjects)) {
    foreach ($viewerProjects as $vp) {
        $projectId = (int)$vp['id'];
        $members = $membersMap[$projectId] ?? [];

        // Проверим, есть ли просматриваемый студент среди участников
        $alreadyMember = false;
        foreach ($members as $m) {
            if ((int)$m['student_id'] === $id) {
                $alreadyMember = true;
                break;
            }
        }

        if (!$alreadyMember && !empty($vp['name'])) {
            $projectsAvailableForInvite[] = $vp;
        } elseif (!$alreadyMember && empty($vp['name'])) {
            $vp['name'] = 'Без названия';
            $projectsAvailableForInvite[] = $vp;
        }
    }
}

// Средний рейтинг пользователя
$ratingStmt = $pdo->prepare("
    SELECT 
        ROUND(AVG(rating), 2) AS avg_rating,
        COUNT(*) AS cnt
    FROM project_ratings
    WHERE ratee_id = ?
");
$ratingStmt->execute([$id]);
$userRating = $ratingStmt->fetch(PDO::FETCH_ASSOC);

// Комментарии о пользователе
$commentsStmt = $pdo->prepare("
    SELECT 
        pr.comment,
        pr.rating,
        s.login AS author,
        p.name AS project_name
    FROM project_ratings pr
    JOIN students s ON s.student_id = pr.rater_id
    JOIN projects p ON p.id = pr.project_id
    WHERE pr.ratee_id = ?
        AND pr.comment <> ''
    ORDER BY pr.id DESC
");
$commentsStmt->execute([$id]);
$userComments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student['login']) ?> — Трек МАИ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>

    <style>
        body {
            font-family: 'Roboto', sans-serif;
        }

        .avatar-sm {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.06);
        }

        .stacked {
            display: inline-flex;
            align-items: center;
        }

        .stacked img {
            margin-left: -12px;
            border: 2px solid #fff;
        }

        .avatar-overlap {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
        }

        .modal-backdrop {
            background: rgba(0, 0, 0, 0.45);
        }

        @media print {
            body {
                background: white !important;
            }

            .no-print {
                display: none !important;
            }
        }

        .comment-item {
            display: none;
            opacity: 0;
            transform: translateY(12px);
            transition: opacity 0.35s ease, transform 0.35s ease;
        }

        .comment-item.visible {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .progress-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 40px 70px;
            /* вертикальный и горизонтальный зазор */
            position: relative;
        }

        .progress-item {
            position: relative;
            display: flex;
            align-items: center;
        }

        /* Точка */
        .progress-item .circle {
            width: 22px;
            height: 22px;
            background-color: #2563eb;
            /* синий Tailwind */
            border: 4px solid white;
            border-radius: 50%;
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.2);
            position: relative;
            z-index: 5;
        }

        /* Карточка */
        .progress-item .card {
            margin-left: 14px;
            background: white;
            border: 1px solid #d1d5db;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 1.05rem;
            font-weight: 500;
            color: #1f2937;
            white-space: nowrap;
        }

        /* Линия между точками */
        .progress-item::after {
            content: "";
            position: absolute;
            top: 50%;
            left: calc(58px + 20px);
            width: 70px;
            /* длина линии */
            height: 3px;
            background-color: #2563eb;
            transform: translateY(-50%);
            z-index: 1;
        }

        /* Если элемент последний в ряду — линия убирается */
        .progress-item:last-child::after {
            display: none;
        }

        /* Перенос строки: элемент, который НЕ помещается справа, не рисует линию */
        @media (min-width: 300px) {
            .progress-item {
                max-width: calc(100% - 100px);
            }
        }

        @media print {

            /* Убираем фоны, тени */
            * {
                background: white !important;
                box-shadow: none !important;
            }

            /* Уменьшаем всё масштабно */
            body {
                zoom: 0.72;
                /* Ключевое: масштабируем всю страницу */
            }

            header,
            .no-print {
                display: none !important;
            }

            img {
                max-width: 140px !important;
                max-height: 140px !important;
            }

            h1 {
                font-size: 28px !important;
            }

            h2 {
                font-size: 22px !important;
            }

            h3 {
                font-size: 18px !important;
            }

            p,
            li,
            span,
            div {
                font-size: 15px !important;
                line-height: 1.2 !important;
            }

            /* Контейнер страницы */
            .container,
            main,
            .max-w-6xl {
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            /* Сильно уменьшаем отступы внутри блоков */
            .p-12,
            .px-12,
            .py-12 {
                padding: 12px !important;
            }

            .p-10 {
                padding: 10px !important;
            }

            .p-8 {
                padding: 8px !important;
            }

            .p-6 {
                padding: 6px !important;
            }

            /* Карточки проектов меньше */
            .shadow-lg,
            .shadow-2xl {
                padding: 10px !important;
            }

            /* Сетка навыков плотнее */
            .grid {
                gap: 10px !important;
            }

            /* Карта прогресса компактная */
            .progress-grid {
                gap: 25px 40px !important;
            }

            .progress-item .card {
                padding: 6px 10px !important;
                font-size: 14px !important;
            }

            .progress-item .circle {
                width: 14px !important;
                height: 14px !important;
                border-width: 2px !important;
            }

            .progress-item::after {
                width: 40px !important;
                height: 2px !important;
            }
        }
    </style>
</head>

<body class="bg-gradient-to-br from-indigo-50 via-blue-50 to-cyan-50 min-h-screen">

    <header class="bg-indigo-700 text-white shadow-lg no-print">
        <div class="container mx-auto px-6 py-6 flex justify-between items-center">
            <h1 class="text-3xl font-bold">Трек студента МАИ</h1>
            <div class="flex items-center gap-6">
                <?php if ($is_logged_in): ?>
                    <?php include __DIR__ . '/notifications.php'; ?>
                    <span class="text-lg font-semibold"><?= htmlspecialchars($_SESSION['login']) ?></span>
                    <a href="gallery.php" class="bg-white text-indigo-700 px-6 py-3 rounded-lg font-bold hover:bg-gray-100">Галерея</a>
                    <!-- <a href="form.php" class="bg-amber-500 hover:bg-amber-600 text-white px-6 py-3 rounded-lg font-bold">Редактировать</a> -->
                    <a onclick="window.print()" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-bold cursor-pointer">Распечатать</a>
                    <?php include __DIR__ . '/header_right.php'; ?>
                <?php else: ?>
                    <a href="login.php" class="bg-white text-indigo-700 px-6 py-3 rounded-lg font-bold hover:bg-gray-100">Войти</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-6 py-12 max-w-6xl">
        <div class="bg-white rounded-3xl shadow-2xl overflow-hidden">

            <!-- Шапка -->
            <div class="bg-gradient-to-r from-indigo-700 via-blue-600 to-cyan-600 text-white p-12 text-center">
                <img src="get_avatar.php?id=<?= $student['student_id'] ?>"
                    class="w-48 h-48 rounded-full mx-auto border-8 border-white shadow-2xl object-cover" alt="Аватар">
                <h1 class="text-6xl font-black mt-8 tracking-tight flex items-center justify-center gap-4">
                    <?= htmlspecialchars($student['login']) ?>

                    <?php if ($userRating['cnt'] > 0): ?>
                        <span class="flex items-center gap-1 text-2xl bg-white/20 px-4 py-1 rounded-full">
                            ⭐ <?= number_format($userRating['avg_rating'], 1) ?>
                            <span class="text-sm opacity-80">(<?= $userRating['cnt'] ?>)</span>
                        </span>
                    <?php else: ?>
                        <span class="text-xl opacity-70">(без оценок)</span>
                    <?php endif; ?>
                </h1>

                <p class="text-2xl mt-4 opacity-95">
                    <?= htmlspecialchars($student['group_number']) ?> • <?= htmlspecialchars($student['semester']) ?> семестр •
                    Кафедра <?= htmlspecialchars($student['department']) ?>
                </p>
                <div class="mt-5 flex flex-wrap justify-center gap-3">
                    <?php if ($githubIntegration): ?>
                        <a href="https://github.com/<?= htmlspecialchars($githubIntegration['username']) ?>" target="_blank" class="inline-flex items-center text-white bg-slate-900/80 hover:bg-slate-900 border border-slate-700/50 px-5 py-2.5 rounded-2xl text-base font-bold transition duration-200 gap-2 shadow-lg shadow-black/10 no-print">
                            <i class="fa-brands fa-github text-xl"></i>
                            <span>GitHub: @<?= htmlspecialchars($githubIntegration['username']) ?></span>
                        </a>
                    <?php else: ?>
                        <span class="inline-flex items-center text-white/40 bg-slate-800/30 border border-slate-700/20 px-5 py-2.5 rounded-2xl text-base font-bold cursor-not-allowed gap-2 no-print" title="GitHub не подключен">
                            <i class="fa-brands fa-github text-xl"></i>
                            <span>GitHub не подключен</span>
                        </span>
                    <?php endif; ?>

                    <?php if ($gitlabIntegration): ?>
                        <a href="<?= !empty($gitlabIntegration['profile_url']) ? htmlspecialchars($gitlabIntegration['profile_url']) : 'https://gitlab.com/' . htmlspecialchars($gitlabIntegration['username']) ?>" target="_blank" class="inline-flex items-center text-white bg-orange-600/80 hover:bg-orange-600 border border-orange-500/50 px-5 py-2.5 rounded-2xl text-base font-bold transition duration-200 gap-2 shadow-lg shadow-black/10 no-print">
                            <i class="fa-brands fa-gitlab text-xl"></i>
                            <span>GitLab: @<?= htmlspecialchars($gitlabIntegration['username']) ?></span>
                        </a>
                    <?php else: ?>
                        <span class="inline-flex items-center text-white/40 bg-slate-800/30 border border-slate-700/20 px-5 py-2.5 rounded-2xl text-base font-bold cursor-not-allowed gap-2 no-print" title="GitLab не подключен">
                            <i class="fa-brands fa-gitlab text-xl"></i>
                            <span>GitLab не подключен</span>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Контент -->
            <div class="grid md:grid-cols-2 gap-12 p-12">
                <!-- Левая колонка -->
                <div>
                    <h2 class="text-3xl font-bold text-gray-800 mb-6 border-b-4 border-indigo-600 inline-block">О себе</h2>
                    <p class="text-lg text-gray-700 leading-relaxed"><?= nl2br(htmlspecialchars($student['about'] ?: '—')) ?></p>

                    <h2 class="text-3xl font-bold text-gray-800 mt-10 mb-6 border-b-4 border-indigo-600 inline-block">Хобби</h2>
                    <p class="text-lg text-gray-700"><?= htmlspecialchars($student['hobbies'] ?: '—') ?></p>

                    <h2 class="text-3xl font-bold text-gray-800 mt-10 mb-6 border-b-4 border-indigo-600 inline-block">Софт-скиллы</h2>
                    <p class="text-lg text-gray-700"><?= htmlspecialchars($student['soft_skills'] ?: '—') ?></p>

                    <h2 class="text-3xl font-bold text-gray-800 mt-10 mb-6 border-b-4 border-red-600 inline-block">Слабое место</h2>
                    <p class="text-lg text-red-700 font-medium"><?= htmlspecialchars($student['weakness'] ?: '—') ?></p>
                </div>

                <!-- Правая колонка -->
                <div>
                    <h2 class="text-3xl font-bold text-gray-800 mb-6 border-b-4 border-amber-600 inline-block">SMART-цель</h2>
                    <div class="bg-amber-50 border-l-8 border-amber-600 p-8 rounded-xl">
                        <p class="text-2xl italic font-medium text-gray-800"><?= nl2br(htmlspecialchars($student['smart_goal'] ?: 'Цель не указана')) ?></p>
                        <?php if ($student['deadline']): ?>
                            <p class="text-amber-700 font-bold mt-6 text-right">Дедлайн: <?= date('d.m.Y', strtotime($student['deadline'])) ?></p>
                        <?php endif; ?>
                    </div>

                    <h2 class="text-3xl font-bold text-gray-800 mt-10 mb-6 border-b-4 border-emerald-600 inline-block">Критерии успеха</h2>
                    <?php if (!empty($criteria)): ?>
                        <ul class="space-y-4">
                            <?php foreach ($criteria as $c): ?>
                                <li class="bg-emerald-50 border-l-8 border-emerald-600 p-4 rounded-xl text-lg text-gray-800"><?= htmlspecialchars($c['criterion']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-gray-600">Критерии не указаны</p>
                    <?php endif; ?>

                    <!-- Кнопки приглашений (новый функционал) -->
                    <?php if ($is_logged_in && !$is_owner && !empty($projectsAvailableForInvite)): ?>
                        <div class="mt-6">
                            <button id="inviteBtn" class="bg-indigo-600 text-white px-4 py-2 rounded-lg font-bold">
                                Пригласить в проект
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if ($is_owner && !empty($incomingInvitations)): ?>
                        <div class="mt-6">
                            <h4 class="font-semibold">Входящие приглашения</h4>
                            <?php foreach ($incomingInvitations as $inv): ?>
                                <div class="p-4 bg-yellow-50 border-l-4 border-yellow-400 rounded mt-2 flex justify-between items-center">
                                    <div>
                                        <div class="font-medium"><?= htmlspecialchars($inv['sender_login']) ?> приглашает в проект “<?= htmlspecialchars($inv['project_name']) ?>”</div>
                                        <div class="text-sm text-gray-600">от <?= htmlspecialchars($inv['created_at']) ?></div>
                                    </div>
                                    <div class="space-x-2">
                                        <button class="respond-invite px-3 py-1 rounded bg-green-600 text-white" data-id="<?= $inv['id'] ?>" data-action="accept">Принять</button>
                                        <button class="respond-invite px-3 py-1 rounded bg-gray-200" data-id="<?= $inv['id'] ?>" data-action="reject">Отклонить</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Навыки -->
            <div class="px-12 pb-12">
                <h2 class="text-4xl font-bold text-gray-800 mb-6 border-b-4 border-green-600 inline-block">Навыки и компетенции</h2>
                <?php if ($all_skills): ?>
                    <div class="grid md:grid-cols-2 gap-6">
                        <?php foreach ($all_skills as $s): ?>
                            <div class="bg-white p-6 rounded-xl shadow flex justify-between items-center border">
                                <span class="text-xl font-semibold"><?= htmlspecialchars($s['name']) ?></span>
                                <span class="text-3xl"><?= str_repeat('★', max(0, min(5, (int)$s['level']))) . str_repeat('☆', max(0, 5 - (int)$s['level'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-600">Навыки не указаны</p>
                <?php endif; ?>
            </div>

            <!-- Проекты -->
            <div class="px-12 pb-12">
                <h2 class="text-4xl font-bold text-gray-800 mb-6 border-b-4 border-cyan-600 inline-block">Проекты</h2>
                <?php if (!empty($projectsForDisplay)): ?>
                    <div class="space-y-6">
                        <?php foreach ($projectsForDisplay as $p):
                            $pid = (int)$p['id'];
                            $members = $membersMap[$pid] ?? [];
                        ?>
                            <div class="bg-gray-50 p-8 rounded-2xl shadow-lg border border-gray-200 relative">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h3 class="text-3xl font-bold mb-3"><?= htmlspecialchars($p['name']) ?></h3>
                                        <p class="text-gray-700 mb-4"><?= nl2br(htmlspecialchars($p['description'] ?: '')) ?></p>
                                        <p class="text-sm text-gray-600">
                                            <?php if (!empty($p['tech_stack'])): ?>
                                                <strong>Стек:</strong> <?= htmlspecialchars($p['tech_stack']) ?><br>
                                            <?php endif; ?>
                                            <?php if (!empty($p['role'])): ?>
                                                <strong>Роль:</strong> <?= htmlspecialchars($p['role']) ?><br>
                                            <?php endif; ?>
                                            <?php if (!empty($p['repo_url'])): ?>
                                                <strong>Репозиторий:</strong> <a class="text-blue-600 underline" href="<?= htmlspecialchars($p['repo_url']) ?>" target="_blank">Ссылка</a>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-sm text-gray-500"><?= htmlspecialchars($p['status']) ?></div>
                                </div>

                                <!-- Участники -->
                                <?php if (!empty($members)): ?>
                                    <div class="mt-6 flex items-center">
                                        <div class="stacked">
                                            <?php
                                            $show = array_slice($members, 0, 3);
                                            foreach ($show as $idx => $m):
                                            ?>
                                                <img src="get_avatar.php?id=<?= $m['student_id'] ?>" title="<?= htmlspecialchars($m['login']) ?>" class="avatar-overlap" style="margin-left: <?= $idx === 0 ? '0' : '-12px' ?>; z-index: <?= 10 - $idx ?>;">
                                            <?php endforeach; ?>
                                            <?php if (count($members) > 3): ?>
                                                <div class="inline-flex items-center justify-center bg-gray-200 text-sm rounded-full h-9 w-9 ml-[-12px] border border-white">+<?= count($members) - 3 ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <button class="ml-4 px-4 py-2 text-sm bg-white border rounded-lg shadow-sm hover:bg-gray-100 view-members" data-project="<?= $pid ?>">Участники</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-600">Проекты не указаны</p>
                <?php endif; ?>
            </div>


            <div class="px-12 pb-12 no-print">
                <h2 class="text-4xl font-bold text-gray-800 mb-6 border-b-4 border-indigo-600 inline-block">
                    Отзывы и комментарии
                </h2>

                <div id="commentsContainer" class="space-y-6"></div>

                <div
                    id="noCommentsMessage"
                    class="hidden mt-6 p-6 bg-gray-50 border border-gray-200 rounded-xl text-center text-gray-600 text-lg">
                    Комментариев пока нет
                </div>

                <div class="mt-6 text-center no-print">
                    <button
                        id="loadMoreComments"
                        class="hidden px-6 py-3 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 transition">
                        Показать ещё
                    </button>
                </div>

            </div>



            <!-- Карта прогресса -->
            <!-- Горизонтальная карта прогресса -->
            <div class="px-12 pb-12">
                <h2 class="text-4xl font-bold text-gray-900 mb-8 border-b-4 border-blue-600 inline-block">
                    Карта прогресса
                </h2>

                <?php if (!empty($progress)): ?>

                    <div class="relative p-10 bg-gradient-to-br from-gray-50 to-gray-100 rounded-2xl shadow-lg border border-gray-200">

                        <div class="progress-grid">
                            <?php foreach ($progress as $index => $p): ?>
                                <div class="progress-item">
                                    <div class="circle"></div>
                                    <div class="card">
                                        <?= htmlspecialchars($p) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>

                <?php else: ?>

                    <div class="p-10 bg-gray-50 border border-gray-200 rounded-2xl text-center shadow">
                        <p class="text-xl text-gray-600">
                            Карта прогресса пока пустая.<br>
                            Добавьте первые шаги в своём профиле!
                        </p>
                    </div>

                <?php endif; ?>
            </div>

            <!-- Рефлексия -->
            <div class="px-12 pb-16">
                <h2 class="text-4xl font-bold text-gray-800 mb-6 border-b-4 border-purple-600 inline-block">Рефлексия</h2>
                <?php if (!empty($reflections)): ?>
                    <?php foreach ($reflections as $r): ?>
                        <div class="bg-purple-50 border-l-8 border-purple-600 p-8 rounded-2xl shadow mb-8">
                            <p class="text-xl mb-4"><strong>Что получилось:</strong><br><?= nl2br(htmlspecialchars($r['what_worked'])) ?></p>
                            <p class="text-xl mb-4"><strong>Что не получилось:</strong><br><?= nl2br(htmlspecialchars($r['what_failed'])) ?></p>
                            <p class="text-xl"><strong>Что изменить:</strong><br><?= nl2br(htmlspecialchars($r['changes'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-gray-600">Рефлексия пока отсутствует</p>
                <?php endif; ?>
            </div>

            <!-- QR -->
            <div class="bg-gray-900 text-white p-10 text-center no-print">
                <p class="text-xl mb-6">Поделись стендом:</p>
                <div id="qrcode" class="inline-block bg-white p-6 rounded-2xl shadow-2xl"></div>
                <p class="mt-6 font-mono text-sm break-all opacity-80"><?= "https://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}" ?></p>
            </div>
        </div>
    </main>

    <!-- Модалка приглашений -->
    <div id="inviteModal" class="hidden fixed inset-0 flex items-center justify-center z-50">
        <div class="modal-backdrop absolute inset-0"></div>
        <div class="relative bg-white rounded-lg p-6 z-60 w-full max-w-lg shadow-lg">
            <h3 class="text-xl font-bold mb-3">Пригласить <?= htmlspecialchars($student['login']) ?> в проект</h3>
            <p class="text-sm text-gray-600 mb-4">Выберите проект, в который хотите пригласить пользователя:</p>
            <div>
                <select id="inviteProjectSelect" class="w-full p-2 border rounded">
                    <option value="">— выберите проект —</option>
                    <?php foreach ($projectsAvailableForInvite as $vp): ?>
                        <option value="<?= (int)$vp['id'] ?>"><?= htmlspecialchars($vp['name'] ?: 'Без названия') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <button id="inviteCancel" class="px-4 py-2 rounded bg-gray-200">Отмена</button>
                <button id="inviteSend" class="px-4 py-2 rounded bg-indigo-600 text-white">Отправить приглашение</button>
            </div>
            <div id="inviteResult" class="mt-3 text-sm"></div>
        </div>
    </div>

    <div id="membersModal" class="hidden fixed inset-0 flex items-center justify-center z-50">
        <div class="modal-backdrop absolute inset-0"></div>
        <div class="relative bg-white rounded-lg p-6 z-60 w-full max-w-2xl shadow-lg">
            <h3 class="text-xl font-bold mb-3">Участники проекта</h3>
            <div id="membersList" class="space-y-2 max-h-96 overflow-auto"></div>
            <div class="mt-4 flex justify-end">
                <button id="membersClose" class="px-4 py-2 rounded bg-gray-200">Закрыть</button>
            </div>
        </div>
    </div>

    <div id="ratingModal" class="hidden fixed inset-0 flex items-center justify-center z-50">
        <div class="modal-backdrop absolute inset-0"></div>
        <div class="relative bg-white rounded-lg p-6 z-60 w-full max-w-md shadow-lg">
            <h3 class="text-xl font-bold mb-3">Оценить участника</h3>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Оценка (1-5)</label>
                <select id="ratingSelect" class="w-full p-2 border rounded">
                    <option value="">Выберите</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Комментарий</label>
                <textarea id="ratingComment" class="w-full p-2 border rounded" rows="4"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button id="ratingCancel" class="px-4 py-2 rounded bg-gray-200">Отмена</button>
                <button id="ratingSubmit" class="px-4 py-2 rounded bg-green-600 text-white">Отправить</button>
            </div>
        </div>
    </div>

    <script>
        // ===== Комментарии (серверная пагинация) =====
        const COMMENTS_INITIAL = 3;
        const COMMENTS_STEP = 10;

        let commentsOffset = 0;
        let commentsTotal = 0;

        const noCommentsMessage = document.getElementById('noCommentsMessage');
        const commentsContainer = document.getElementById('commentsContainer');
        const loadBtn = document.getElementById('loadMoreComments');

        function renderComment(c) {
            const div = document.createElement('div');
            div.className = 'comment-item bg-indigo-50 border-l-8 border-indigo-600 p-6 rounded-xl shadow';

            div.innerHTML = `
                <div class="flex justify-between items-center mb-2">
                    <div class="font-semibold text-lg">
                        ${escapeHtml(c.author)}
                        <span class="text-sm text-gray-500">
                            — ${escapeHtml(c.project_name)}
                        </span>
                    </div>
                    <div class="text-amber-500 text-lg">
                        ${'★'.repeat(c.rating)}
                    </div>
                </div>
                <p class="text-gray-800 text-lg leading-relaxed">
                    ${escapeHtml(c.comment).replace(/\n/g, '<br>')}
                </p>
            `;

            commentsContainer.appendChild(div);

            // анимация
            requestAnimationFrame(() => div.classList.add('visible'));
        }

        function updateButton() {
            const left = commentsTotal - commentsOffset;
            if (left <= 0) {
                loadBtn.classList.add('hidden');
            } else {
                loadBtn.textContent = `Показать ещё (${Math.min(left, COMMENTS_STEP)})`;
                loadBtn.classList.remove('hidden');
            }
        }

        function loadComments(limit) {
            fetch(`comments_api.php?student_id=${viewedStudentId}&offset=${commentsOffset}&limit=${limit}`)
                .then(r => r.json())
                .then(json => {
                    if (!json.success) return;

                    commentsTotal = json.total;
                    // 👉 если комментариев вообще нет
                    if (commentsTotal === 0) {
                        noCommentsMessage.classList.remove('hidden');
                        loadBtn.classList.add('hidden');
                        return;
                    }

                    // если есть — скрываем заглушку
                    noCommentsMessage.classList.add('hidden');

                    json.items.forEach(renderComment);
                    commentsOffset += json.items.length;

                    updateButton();

                });
        }

        // старт
        document.addEventListener('DOMContentLoaded', () => {
            loadComments(COMMENTS_INITIAL);

            loadBtn.addEventListener('click', () => {
                loadComments(COMMENTS_STEP);
            });
        });


        const viewerId = <?= $viewerId ? (int)$viewerId : 'null' ?>;
        const viewedStudentId = <?= (int)$id ?>;

        document.addEventListener('DOMContentLoaded', () => {
            // invite modal
            const inviteBtn = document.getElementById('inviteBtn');
            const inviteModal = document.getElementById('inviteModal');
            const inviteCancel = document.getElementById('inviteCancel');
            const inviteSend = document.getElementById('inviteSend');
            const inviteSelect = document.getElementById('inviteProjectSelect');
            const inviteResult = document.getElementById('inviteResult');

            if (inviteBtn) {
                inviteBtn.addEventListener('click', () => {
                    inviteResult.innerText = '';
                    inviteModal.classList.remove('hidden');
                });
                inviteCancel.addEventListener('click', () => inviteModal.classList.add('hidden'));
                inviteSend.addEventListener('click', () => {
                    const projectId = inviteSelect.value;
                    if (!projectId) {
                        inviteResult.innerText = 'Выберите проект.';
                        inviteResult.style.color = 'red';
                        return;
                    }
                    inviteSend.disabled = true;
                    inviteResult.innerText = 'Отправка...';

                    fetch('invite_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'invite',
                            project_id: projectId,
                            receiver_id: viewedStudentId
                        })
                    }).then(r => r.json()).then(json => {
                        inviteSend.disabled = false;
                        if (json.success) {
                            inviteResult.style.color = 'green';
                            inviteResult.innerText = 'Приглашение отправлено.';
                        } else {
                            inviteResult.style.color = 'red';
                            inviteResult.innerText = json.error || json.message || 'Ошибка';
                        }
                    }).catch(e => {
                        inviteSend.disabled = false;
                        inviteResult.style.color = 'red';
                        inviteResult.innerText = 'Сетeвая ошибка';
                    });
                });
            }

            // members modal
            const membersModal = document.getElementById('membersModal');
            const membersList = document.getElementById('membersList');
            const membersClose = document.getElementById('membersClose');

            document.querySelectorAll('.view-members').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const projectId = btn.dataset.project;
                    membersList.innerHTML = '<div>Загрузка...</div>';
                    membersModal.classList.remove('hidden');
                    fetch('invite_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'members',
                            project_id: projectId
                        })
                    }).then(r => r.json()).then(json => {
                        if (!json.success) {
                            membersList.innerHTML = '<div class="text-red-500">Ошибка загрузки</div>';
                            return;
                        }
                        const members = json.members || [];
                        if (members.length === 0) {
                            membersList.innerHTML = '<div class="text-gray-500">Участников пока нет</div>';
                            return;
                        }
                        membersList.innerHTML = '';
                        members.forEach(m => {
                            const div = document.createElement('div');
                            div.className = 'flex items-center justify-between p-2 border-b';
                            let rateButtonHtml = '';

                            if (
                                viewerId && // авторизован
                                json.is_viewer_member && // участник проекта
                                Number(viewerId) !== Number(m.student_id) // НЕ сам себя
                            ) {
                                rateButtonHtml = `
                                    <button 
                                        data-student="${m.student_id}" 
                                        data-project="${projectId}" 
                                        class="rate-btn px-2 py-1 text-sm ${
                                            m.has_rated 
                                                ? 'bg-amber-500 hover:bg-amber-600' 
                                                : 'bg-blue-600 hover:bg-blue-700'
                                        } text-white rounded"
                                    >
                                        ${m.has_rated ? 'Изменить оценку' : 'Оценить'}
                                    </button>
                                `;
                            }
                            div.innerHTML = `
                                <div class="flex items-center gap-3">
                                    <img src="get_avatar.php?id=${m.student_id}" class="avatar-sm" alt="">
                                    <div>
                                        <div class="font-medium">${escapeHtml(m.login)}</div>
                                        <div class="text-sm text-gray-500">
                                            Оценка: ${m.rating === null ? '—' : parseFloat(m.rating).toFixed(1)}
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <a href="stand.php?id=${m.student_id}" 
                                    class="px-2 py-1 text-sm bg-gray-100 rounded">
                                        Открыть стенд
                                    </a>
                                    ${rateButtonHtml}
                                </div>
                            `;

                            membersList.appendChild(div);
                        });
                        // bind rate buttons
                        membersList.querySelectorAll('.rate-btn').forEach(btn => {
                            btn.addEventListener('click', e => {
                                e.stopPropagation();
                                const studentId = btn.dataset.student;
                                const projectId = btn.dataset.project;
                                fetch('invite_api.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json'
                                    },
                                    body: JSON.stringify({
                                        action: 'get_rating',
                                        project_id: projectId,
                                        student_id: studentId
                                    })
                                }).then(res => res.json()).then(data => {
                                    if (data.success) {
                                        ratingSelect.value = data.rating || '';
                                        ratingComment.value = data.comment || '';
                                        ratingModal.dataset.project = projectId;
                                        ratingModal.dataset.student = studentId;
                                        ratingModal.classList.remove('hidden');
                                    } else {
                                        alert('Ошибка загрузки оценки');
                                    }
                                });
                            });
                        });
                    }).catch(() => membersList.innerHTML = '<div class="text-red-500">Ошибка</div>');
                });
            });

            membersClose.addEventListener('click', () => membersModal.classList.add('hidden'));

            // rating modal
            const ratingModal = document.getElementById('ratingModal');
            const ratingCancel = document.getElementById('ratingCancel');
            const ratingSubmit = document.getElementById('ratingSubmit');
            const ratingSelect = document.getElementById('ratingSelect');
            const ratingComment = document.getElementById('ratingComment');

            ratingCancel.addEventListener('click', () => ratingModal.classList.add('hidden'));
            ratingSubmit.addEventListener('click', () => {
                const projectId = ratingModal.dataset.project;
                const rateeId = ratingModal.dataset.student;
                const rating = ratingSelect.value;
                const comment = ratingComment.value.trim();
                if (!rating) {
                    alert('Выберите оценку');
                    return;
                }
                fetch('invite_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'rate_member',
                        project_id: projectId,
                        ratee_id: rateeId,
                        rating: rating,
                        comment: comment
                    })
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        ratingModal.classList.add('hidden');
                        // reload members modal
                        document.querySelector(`.view-members[data-project="${projectId}"]`).click();
                    } else {
                        alert(data.error || 'Ошибка сохранения оценки');
                    }
                });
            });

            // обработка входящих приглашений (у владельца стенда)
            document.querySelectorAll('.respond-invite').forEach(b => {
                b.addEventListener('click', () => {
                    const id = b.dataset.id;
                    const action = b.dataset.action;
                    b.disabled = true;
                    fetch('invite_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'respond',
                            invitation_id: id,
                            response: action
                        })
                    }).then(r => r.json()).then(j => {
                        if (j.success) {
                            location.reload();
                        } else {
                            alert(j.error || 'Ошибка');
                            b.disabled = false;
                        }
                    });
                });
            });

        }); // DOMContentLoaded

        function escapeHtml(s) {
            if (!s) return '';
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        const qr = qrcode(0, 'M');
        qr.addData(window.location.href);
        qr.make();
        document.getElementById('qrcode').innerHTML = qr.createImgTag(10, 16);
    </script>

</body>

</html>