<?php
require_once __DIR__ . '/../init.php';

$error = '';
$success = '';
$userId = $_SESSION['user_id'] ?? null;
$isNew = isset($_GET['new']) && $_GET['new'] == 1;
$hasOpenProject = false;

// =======================
// ЗАГРУЖАЕМ ПОЛЬЗОВАТЕЛЯ
// =======================
$user = null;
$progress = []; // Инициализируем пустой массив
if ($userId && !$isNew) {
    // Загружаем данные студента
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Загружаем динамические таблицы
    $stmt = $pdo->prepare("SELECT * FROM skills WHERE student_id=? ORDER BY id ASC");
    $stmt->execute([$userId]);
    $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM competencies WHERE student_id=? ORDER BY id ASC");
    $stmt->execute([$userId]);
    $competencies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Загружаем проекты через таблицу project_members
    $stmt = $pdo->prepare("
        SELECT p.*, pm.role 
        FROM projects p
        INNER JOIN project_members pm ON p.id = pm.project_id
        WHERE pm.student_id = ?
        ORDER BY p.id ASC
    ");
    $stmt->execute([$userId]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ПРОВЕРКА НАЛИЧИЯ ОТКРЫТОГО ПРОЕКТА
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM projects p
        INNER JOIN project_members pm ON p.id = pm.project_id
        WHERE pm.student_id = ? AND p.status = 'в процессе'
    ");
    $stmt->execute([$userId]);
    $hasOpenProject = ($stmt->fetchColumn() > 0);

    $stmt = $pdo->prepare("SELECT * FROM criteria WHERE student_id=? ORDER BY id ASC");
    $stmt->execute([$userId]);
    $criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM terrell_points WHERE student_id=? ORDER BY position ASC");
    $stmt->execute([$userId]);
    $progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM reflections WHERE student_id=? ORDER BY id ASC");
    $stmt->execute([$userId]);
    $reflections = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =======================
// ЕСЛИ POST — ОБРАБОТКА
// =======================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ------ Все поля из формы ------
    $form = [
        'zachetka'     => trim($_POST['zachetka'] ?? ''),
        'login'        => trim($_POST['login'] ?? ''),
        'password'     => $_POST['password'] ?? '',
        'direction'    => $_POST['direction'] ?? '',
        'group_number' => $_POST['group_number'] ?? '',
        'semester'     => (int)($_POST['semester'] ?? 0),
        'department'   => $_POST['department'] ?? '',
        'about'        => $_POST['about'] ?? '',
        'hobbies'      => $_POST['hobbies'] ?? '',
        'soft_skills'  => $_POST['soft_skills'] ?? '',
        'smart_goal'   => $_POST['smart_goal'] ?? '',
        'deadline'     => $_POST['deadline'] ?: null,
        'is_published' => isset($_POST['is_published']) ? 1 : 0
    ];

    // ------ Обработка слабой стороны ------
    $selectedWeakness = $_POST['weakness'] ?? '';
    $weaknessOther   = trim($_POST['weakness_other'] ?? '');

    // Для базы сохраняем текстовое значение
    $form['weakness'] = ($selectedWeakness === 'другое') ? $weaknessOther : $selectedWeakness;

    // Для возврата данных в форму при ошибке
    $form['weakness_selected'] = $selectedWeakness;
    $form['weakness_other']    = $weaknessOther;

    // ------ Динамические поля ------
    $skills        = $_POST['skills'] ?? [];
    $competencies  = $_POST['competencies'] ?? [];
    $projects      = $_POST['projects'] ?? [];
    $criteria      = $_POST['criteria'] ?? [];
    $progress      = $_POST['terrell_points'] ?? [];
    $reflections   = $_POST['reflections'] ?? [];

    // =======================
    // ВАЛИДАЦИЯ
    // =======================
    $requiredFields = [
        'zachetka' => 'Номер зачётки',
        'login' => 'Логин',
    ];

    if ($isNew) {
        $requiredFields['password'] = 'Пароль';
    }

    $emptyFields = [];
    foreach ($requiredFields as $field => $label) {
        if (trim($form[$field] ?? '') === '') {
            $emptyFields[] = $label;
        }
    }

    if ($emptyFields) {
        $error = "Заполните обязательные поля: " . implode(', ', $emptyFields);
    }

    // Проверка уникальности номера зачётки (исключаем текущего пользователя)
    if (!$error) {
        $stmtCheck = $pdo->prepare("SELECT student_id FROM students WHERE zachetka = ? AND student_id != ?");
        $checkId = $userId ?: 0; // если новый — ищем все совпадения, если редактируем — исключаем себя
        $stmtCheck->execute([$form['zachetka'], $checkId]);
        if ($stmtCheck->fetch()) {
            $error = "Номер зачётки «{$form['zachetka']}» уже используется другим студентом!";
        }
    }

    // Проверка на количество проектов "в процессе"
    if (!$error) {
        $inProgressCount = 0;
        if (!empty($projects) && is_array($projects)) {
            foreach ($projects as $p) {
                if (isset($p['status']) && $p['status'] === 'в процессе') {
                    $inProgressCount++;
                }
            }
        }
        if ($inProgressCount > 1) {
            $error = "Нельзя, чтобы у одного пользователя было больше одного проекта в процессе.";
        }
    }
    // Если ошибка — возвращаем данные обратно в форму
    if ($error) {
        $user = $form;
        $skills       = $skills;
        $competencies = $competencies;
        $projects     = $projects;
        $criteria     = array_map(fn($c) => ['criterion' => $c], $criteria);
        $progress     = $progress;
        $reflections  = $reflections;
    } else {

        // =======================
        // СОХРАНЕНИЕ В БД
        // =======================
        $avatar_blob = $user['avatar'] ?? null;
        if (!empty($_FILES['avatar']['tmp_name'])) {
            if ($_FILES['avatar']['error'] === 0 && $_FILES['avatar']['size'] <= 2 * 1024 * 1024) {
                $avatar_blob = file_get_contents($_FILES['avatar']['tmp_name']);
            }
        }

        $password_hash = $form['password']
            ? password_hash($form['password'], PASSWORD_DEFAULT)
            : ($user['password'] ?? '');

        if ($user) {
            // ---- UPDATE ----
            $sql = "UPDATE students SET
                zachetka=?, login=?, direction=?, group_number=?, semester=?, department=?,
                avatar=?, about=?, hobbies=?, soft_skills=?, weakness=?, smart_goal=?, deadline=?, is_published=?";
            $params = [
                $form['zachetka'],
                $form['login'],
                $form['direction'],
                $form['group_number'],
                $form['semester'],
                $form['department'],
                $avatar_blob,
                $form['about'],
                $form['hobbies'],
                $form['soft_skills'],
                $form['weakness'],
                $form['smart_goal'],
                $form['deadline'],
                $form['is_published']
            ];

            if ($form['password']) {
                $sql .= ", password=?";
                $params[] = $password_hash;
            }

            $sql .= " WHERE student_id=?";
            $params[] = $userId;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $student_id = $userId;
        } else {
            // ---- INSERT ----
            $stmt = $pdo->prepare("
                INSERT INTO students
                (zachetka, login, password, direction, group_number, semester, department, avatar,
                    about, hobbies, soft_skills, weakness, smart_goal, deadline, is_published)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $form['zachetka'],
                $form['login'],
                $password_hash,
                $form['direction'],
                $form['group_number'],
                $form['semester'],
                $form['department'],
                $avatar_blob,
                $form['about'],
                $form['hobbies'],
                $form['soft_skills'],
                $form['weakness'],
                $form['smart_goal'],
                $form['deadline'],
                $form['is_published']
            ]);

            $student_id = $pdo->lastInsertId();
            $_SESSION['user_id'] = $student_id;
            $_SESSION['login'] = $form['login'];
            $_SESSION['zachetka'] = $form['zachetka'];
        }

        /**
         * Функция проверки: может ли пользователь создать новый проект?
         */
        function canCreateProject($pdo, $userId)
        {
            if (!$userId) return true;
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects p 
                           JOIN project_members pm ON p.id = pm.project_id 
                           WHERE pm.student_id = ? AND p.status = 'в процессе'");
            $stmt->execute([$userId]);
            return ($stmt->fetchColumn() == 0);
        }

        /**
         * Функция проверки: открыт ли конкретный проект для редактирования?
         */
        function isProjectEditable($pdo, $projectId)
        {
            $stmt = $pdo->prepare("SELECT status FROM projects WHERE id = ?");
            $stmt->execute([$projectId]);
            $status = $stmt->fetchColumn();
            return ($status === 'open' || $status === 'в процессе');
        }

        // Функция сохранения динамических таблиц
        function saveTable($pdo, $table, $rows, $student_id, $fields)
        {
            $pdo->prepare("DELETE FROM $table WHERE student_id=?")->execute([$student_id]);
            foreach ($rows as $row) {
                $clean = [];
                foreach ($fields as $f) {
                    $clean[$f] = trim($row[$f] ?? '');
                }
                if (!array_filter($clean)) continue;
                $placeholders = implode(",", array_fill(0, count($fields) + 1, "?"));
                $colNames = implode(",", $fields);
                $pdo->prepare("INSERT INTO $table (student_id, $colNames) VALUES ($placeholders)")
                    ->execute(array_merge([$student_id], array_values($clean)));
            }
        }

        saveTable($pdo, 'skills', $skills, $student_id, ['name', 'level']);
        saveTable($pdo, 'competencies', $competencies, $student_id, ['name', 'level', 'artifact_url', 'type']);
        saveTable($pdo, 'criteria', array_map(fn($c) => ['criterion' => $c], $criteria), $student_id, ['criterion']);
        saveTable($pdo, 'terrell_points', $progress, $student_id, ['name', 'type', 'comment', 'position']);
        saveTable($pdo, 'reflections', $reflections, $student_id, ['what_worked', 'what_failed', 'changes']);

        // ==============================
        // СОХРАНЕНИЕ ПРОЕКТОВ (ИСПРАВЛЕНО)
        // ==============================
        // Находим существующие связи с проектами до сохранения изменений
        $existingProjectIds = [];
        $stmt = $pdo->prepare("SELECT project_id FROM project_members WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $existingProjectIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $submittedProjectIds = [];
        if (!empty($projects) && is_array($projects)) {
            foreach ($projects as $p) {
                if (!empty($p['id'])) {
                    $submittedProjectIds[] = (int)$p['id'];
                }
            }
        }

        // Вычисляем проекты, которые были удалены пользователем из формы
        $deletedProjectIds = array_diff($existingProjectIds, $submittedProjectIds);

        foreach ($deletedProjectIds as $pid) {
            // Если студент является владельцем/создателем проекта, удаляем сам проект из таблицы projects (каскад удалит участников)
            $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ? AND student_id = ?");
            $stmt->execute([$pid, $student_id]);

            // Если он был просто участником, удаляем его из участников проекта
            $stmt = $pdo->prepare("DELETE FROM project_members WHERE student_id = ? AND project_id = ?");
            $stmt->execute([$student_id, $pid]);
        }

        if (!empty($projects) && is_array($projects)) {
            foreach ($projects as $p) {
                // Если это новый проект (нет id)
                if (empty($p['id']) && !empty($p['name'])) {
                    // Создаем новый проект (с привязкой к владельцу student_id)
                    $stmt = $pdo->prepare("
                        INSERT INTO projects (student_id, name, description, tech_stack, role, repo_url, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $student_id,
                        $p['name'] ?? '',
                        $p['description'] ?? '',
                        $p['tech_stack'] ?? '',
                        $p['role'] ?? '',
                        $p['repo_url'] ?? '',
                        $p['status'] ?? 'в процессе'
                    ]);

                    $projectId = $pdo->lastInsertId();

                    // Добавляем владельца в project_members
                    $pdo->prepare("
                        INSERT INTO project_members (project_id, student_id, role)
                        VALUES (?, ?, 'owner')
                    ")->execute([$projectId, $student_id]);
                }
                // Если существующий проект
                else if (!empty($p['id'])) {
                    // Обновляем проект
                    $stmt = $pdo->prepare("
                        UPDATE projects 
                        SET name=?, description=?, tech_stack=?, role=?, repo_url=?, status=?
                        WHERE id=?
                    ");
                    $stmt->execute([
                        $p['name'] ?? '',
                        $p['description'] ?? '',
                        $p['tech_stack'] ?? '',
                        $p['role'] ?? '',
                        $p['repo_url'] ?? '',
                        $p['status'] ?? 'в процессе',
                        $p['id']
                    ]);

                    // Добавляем связь студента с проектом (если еще нет)
                    $checkStmt = $pdo->prepare("SELECT id FROM project_members WHERE project_id = ? AND student_id = ?");
                    $checkStmt->execute([$p['id'], $student_id]);
                    if (!$checkStmt->fetch()) {
                        $pdo->prepare("
                            INSERT INTO project_members (project_id, student_id, role)
                            VALUES (?, ?, 'owner')
                        ")->execute([$p['id'], $student_id]);
                    }
                }
            }
        }

        $is_published = !empty($_POST['is_published']);

        // Обновляем сессию на всякий случай
        $_SESSION['user_id'] = $student_id;
        $_SESSION['login']    = $form['login'];

        // Формируем URL с параметром saved=1
        if ($is_published) {
            // Опубликован → сразу на красивый стенд
            header("Location: stand.php?id={$student_id}&saved=1");
        } else {
            // Не опубликован → в галерею с уведомлением
            header("Location: gallery.php?saved=1");
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Трек студента — Редактор стенда</title>
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
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Roboto', sans-serif;
        }

        .avatar-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #0055A4;
            background: #E5E7EB;
        }

        .step {
            display: none;
        }

        .step.active {
            display: block;
        }

        /* Красная звёздочка для обязательных полей */
        label.required::after {
            content: " *";
            color: red;
        }

        .skill-item {
            margin-bottom: 10px;
        }

        .rating input {
            margin-left: 5px;
        }

        .section-title {
            font-size: 18px;
            margin-top: 25px;
            font-weight: bold;
        }

        .add-btn {
            margin-top: 10px;
            display: block;
        }

        /* Tooltip styles */
        .tooltip-wrapper {
            display: inline-block;
            position: relative;
            cursor: default;
            margin-left: 8px;
        }

        .tooltip-icon {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            background: #e5e7eb;
            color: #111827;
            border: 1px solid #d1d5db;
        }

        .tooltip-content {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            bottom: calc(100% + 8px);
            min-width: 180px;
            max-width: 320px;
            background: #111827;
            color: white;
            padding: 8px 10px;
            border-radius: 6px;
            font-size: 13px;
            line-height: 1.3;
            display: none;
            z-index: 50;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .tooltip-wrapper:hover .tooltip-content,
        .tooltip-wrapper:focus-within .tooltip-content {
            display: block;
        }

        .tooltip-content::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border-width: 6px;
            border-style: solid;
            border-color: #111827 transparent transparent transparent;
        }

        /* small helpers */
        .hidden {
            display: none !important;
        }

        .flex {
            display: flex;
        }

        .items-center {
            align-items: center;
        }

        .space-x-2>*+* {
            margin-left: .5rem;
        }

        /* keep existing small classes from your UI (tailwind-like) */
        .btn-prev,
        .btn-next {
            cursor: pointer;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">

    <header class="bg-indigo-700 text-white w-full shadow-lg">
        <div class="container mx-auto px-6 py-6 flex justify-between items-center">
            <div class="flex items-center space-x-4">
                <div class="w-12 h-12 bg-white text-indigo-700 font-bold flex items-center justify-center rounded-full">ТС</div>
                <h1 class="text-3xl font-bold">
                    <?= isset($_SESSION['user_id']) && !$isNew ? 'Редактирование стенда' : 'Создание стенда' ?>
                </h1>
            </div>
            <?php if (isset($_SESSION['user_id']) && !$isNew): ?>
                <div class="space-x-4">
                    <?php include __DIR__ . '/notifications.php'; ?>
                    <a href="gallery.php" class="bg-white text-indigo-700 px-6 py-3 rounded-lg font-bold hover:bg-gray-100">Галерея</a>
                    <a href="logout.php" class="text-white hover:underline">Выйти</a>
                </div>
            <?php endif; ?>
        </div>
    </header>


    <div class="container mx-auto px-6 py-8">

        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-4 rounded-lg mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-lg p-6 mb-6">
            <!-- прогресс -->
            <div class="flex items-center justify-between mb-4">
                <div id="step-indicators-container" class="flex-1 flex justify-between max-w-4xl mx-auto">
                    <!-- Заполняется динамически через JS -->
                </div>
            </div>

            <form id="studentForm" method="POST" enctype="multipart/form-data">
                <!-- Шаг 1 -->
                <div class="step active" id="step-basic">
                    <div class="max-w-3xl mx-auto">
                        <h2 class="text-xl font-bold text-mai-blue mb-4">Шаг 1 — Основные данные</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="text-sm required">Номер зачётки</label>
                                <input name="zachetka" value="<?= htmlspecialchars($user['zachetka'] ?? '') ?>" class="w-full px-4 py-2 border rounded-lg" required>
                            </div>
                            <div>
                                <label class="text-sm required">Логин (публичное имя)</label>
                                <input name="login" value="<?= htmlspecialchars($user['login'] ?? '') ?>" class="w-full px-4 py-2 border rounded-lg" required>
                            </div>
                            <?php if ($isNew): ?>
                                <div>
                                    <label class="text-sm required">Пароль</label>
                                    <input type="password" name="password" class="w-full px-4 py-2 border rounded-lg" <?= $isNew ? 'required' : '' ?>>
                                </div>
                            <?php endif; ?>

                            <div>
                                <label class="text-sm">Направление</label>
                                <select name="direction" class="w-full px-4 py-2 border rounded-lg">
                                    <option value="09.03.01" <?= ($user['direction'] ?? '') === '09.03.01' ? 'selected' : '' ?>>09.03.01 — Информатика и ВТ</option>
                                    <option value="09.03.03" <?= ($user['direction'] ?? '') === '09.03.03' ? 'selected' : '' ?>>09.03.03 — Прикл. информатика</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm">Группа</label>
                                <input name="group_number" value="<?= htmlspecialchars($user['group_number'] ?? '') ?>" class="w-full px-4 py-2 border rounded-lg">
                            </div>
                            <div>
                                <label class="text-sm">Семестр</label>
                                <select name="semester" class="w-full px-4 py-2 border rounded-lg">
                                    <?php for ($s = 1; $s <= 8; $s++): ?>
                                        <option value="<?= $s ?>" <?= ($user['semester'] ?? 0) == $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm">Кафедра</label>
                                <input name="department" value="<?= htmlspecialchars($user['department'] ?? '') ?>" class="w-full px-4 py-2 border rounded-lg" placeholder="607">
                            </div>
                        </div>

                        <div class="mt-4 flex items-center space-x-6">
                            <div>
                                <?php
                                // Определяем источник аватара
                                if (!empty($_FILES['avatar']['tmp_name'])) {
                                    // Превью загруженного файла при ошибке формы
                                    $avatar_src = 'data:' . mime_content_type($_FILES['avatar']['tmp_name']) . ';base64,' .
                                        base64_encode(file_get_contents($_FILES['avatar']['tmp_name']));
                                } elseif (!empty($user['student_id']) && !empty($user['avatar'])) {
                                    // Аватар из базы
                                    $avatar_src = "get_avatar.php?id=" . $user['student_id'] . "&t=" . time();
                                } else {
                                    // Плейсхолдер
                                    $avatar_src = "https://placehold.co/120x120/0055A4/FFFFFF?text=Аватар";
                                }
                                ?>
                                <img id="avatarPreview" src="<?= $avatar_src ?>" data-original="<?= $avatar_src ?>" class="avatar-preview" alt="Аватар">
                                <input id="avatarUpload" type="file" name="avatar" accept="image/*">
                            </div>
                        </div>

                        <div class="mt-6 flex justify-between">
                            <button type="button" class="btn-prev px-4 py-2 bg-gray-200 rounded-lg hidden">← Назад</button>
                            <button type="button" class="btn-next px-6 py-2 bg-mai-blue text-white rounded-lg">Далее →</button>
                        </div>
                    </div>
                </div>

                <!-- Шаг 2 -->
                <div class="step" id="step-whoami">
                    <div class="max-w-3xl mx-auto">
                        <h2 class="text-xl font-bold text-mai-blue mb-4">Шаг 2 — Кто я сейчас</h2>
                        <div>
                            <label class="text-sm">О себе</label>
                            <textarea name="about" rows="3" placeholder="Например: Я студент 2 курса, увлекаюсь программированием..." class="w-full px-4 py-2 border rounded-lg"><?= htmlspecialchars($user['about'] ?? '') ?></textarea>
                        </div>

                        <div class="mt-4">
                            <div class="flex justify-between items-center">
                                <label class="text-sm font-medium">Навыки</label>
                                <button type="button" id="addSkillBtn" class="px-3 py-1 bg-mai-blue text-white rounded">+ Добавить</button>
                            </div>
                            <div id="skillsList" class="mt-3 space-y-2">
                                <!-- навыки динамически заполняются JS -->
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                            <div>
                                <label class="text-sm">Увлечения</label>
                                <input name="hobbies" placeholder="Например: играю на гитаре, занимаюсь спортом..." value="<?= htmlspecialchars($user['hobbies'] ?? '') ?>" class="w-full px-4 py-2 border rounded-lg">
                            </div>
                            <div>
                                <label class="text-sm">Софт-скиллы</label>
                                <input name="soft_skills" value="<?= htmlspecialchars($user['soft_skills'] ?? '') ?>" class="w-full px-4 py-2 border rounded-lg">
                            </div>
                        </div>

                        <?php
                        // Список слабых сторон
                        $weaknesses = ['лентяй', 'перфекционист', 'прокрастинатор', 'неуверен в себе', 'другое'];

                        // Если в БД пусто -> поставить по умолчанию "лентяй"
                        $current = trim($user['weakness'] ?? '');
                        if ($current === '') {
                            $current = 'лентяй';
                        }

                        $isOther = !in_array($current, $weaknesses);
                        ?>
                        <div class="mt-4">
                            <label class="text-sm">Слабая сторона</label>
                            <div class="flex flex-wrap gap-3 mt-2">
                                <?php foreach ($weaknesses as $w): ?>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="weakness" value="<?= $w ?>"
                                            <?php if ($w === 'другое' && $isOther): ?>
                                            checked
                                            <?php elseif ($current === $w): ?>
                                            checked
                                            <?php endif; ?>
                                            class="form-radio weakness-radio">
                                        <span class="ml-2"><?= $w ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <input id="weakness_other" type="text" name="weakness_other"
                                class="mt-2 w-full px-4 py-2 border rounded-lg <?= $isOther ? '' : 'hidden' ?>"
                                placeholder="Если другое..."
                                value="<?= $isOther ? htmlspecialchars($current) : '' ?>"
                                <?= $isOther ? '' : 'disabled' ?>>
                        </div>

                        <div class="mt-6 flex justify-between">
                            <button type="button" class="btn-prev px-6 py-2 bg-gray-200 rounded-lg">← Назад</button>
                            <button type="button" class="btn-next px-6 py-2 bg-mai-blue text-white rounded-lg">Далее →</button>
                        </div>
                    </div>
                </div>

                <!-- Шаг 3 -->
                <div class="step" id="step-goal">
                    <div class="max-w-3xl mx-auto">
                        <h2 class="text-xl font-bold text-mai-blue mb-4">Шаг 3 — Цель на семестр</h2>

                        <div>
                            <label class="text-sm">SMART-цель (до 200 символов)
                                <span class="tooltip-wrapper" tabindex="0" aria-label="Инфо о SMART-цели">
                                    <span class="tooltip-icon">?</span>
                                    <span class="tooltip-content">SMART-цель — конкретная, измеримая, достижимая, релевантная и ограниченная по времени. До 200 символов.</span>
                                </span>
                            </label>
                            <!-- Пояснения с основной страницы убраны (текст перемещён в тултип). -->
                            <textarea id="smartGoal" placeholder="Например: повысить средний балл до 4.5 к концу семестра..." name="smart_goal" maxlength="200" rows="3" class="w-full px-4 py-2 border rounded-lg"><?= htmlspecialchars($user['smart_goal'] ?? '') ?></textarea>
                            <div class="text-right text-xs text-gray-500 mt-1"><span id="goalCount">0</span>/200</div>
                        </div>

                        <div class="mt-4">
                            <div class="flex justify-between items-center">
                                <label class="text-sm font-medium">Критерии успеха
                                    <span class="tooltip-wrapper" tabindex="0">
                                        <span class="tooltip-icon">?</span>
                                        <span class="tooltip-content">Критерии успеха — как можно понять, что цель достигнута. Можно добавить несколько кратких критериев.</span>
                                    </span>
                                </label>
                                <button type="button" id="addCriterionBtn" class="px-3 py-1 bg-mai-blue text-white rounded">+ Добавить</button>
                            </div>
                            <div id="criteriaList" class="mt-2 space-y-2">
                                <!-- критерии динамически заполняются JS -->
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="text-sm">Срок выполнения
                                <span class="tooltip-wrapper" tabindex="0">
                                    <span class="tooltip-icon">?</span>
                                    <span class="tooltip-content">Срок выполнения — конкретный дедлайн (дата), к которому планируете достичь SMART-цели.</span>
                                </span>
                            </label>
                            <input type="date" name="deadline" value="<?= htmlspecialchars($user['deadline'] ?? '') ?>" class="w-full px-4 py-2 border rounded-lg">
                        </div>

                        <div class="mt-6 flex justify-between">
                            <button type="button" class="btn-prev px-6 py-2 bg-gray-200 rounded-lg">← Назад</button>
                            <button type="button" class="btn-next px-6 py-2 bg-mai-blue text-white rounded-lg">Далее →</button>
                        </div>
                    </div>
                </div>

                <!-- Шаг Прогресс -->
                <div class="step" id="step-progress">
                    <div class="max-w-4xl mx-auto bg-white rounded-xl shadow-md p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-bold text-mai-blue">Шаг — Карта Тэррелл (Прогресс)</h2>
                        </div>
                        <div id="terrellMap" class="flex flex-wrap items-center justify-center p-4 bg-gray-50 rounded-lg min-h-32 gap-4">
                            <!-- точки будут добавляться через JS -->
                        </div>
                        <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4 no-print">
                            <button type="button" onclick="addLocalProgressPoint('achievement')" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition font-bold">
                                + Достижение
                            </button>
                            <button type="button" onclick="addLocalProgressPoint('crisis')" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition font-bold">
                                + Кризис
                            </button>
                            <button type="button" onclick="addLocalProgressPoint('goal')" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition font-bold">
                                + Цель
                            </button>
                        </div>
                        <div class="mt-8 flex justify-between pt-4 no-print border-t">
                            <button type="button" class="btn-prev px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">← Назад</button>
                            <button type="button" class="btn-next px-6 py-2 bg-mai-blue text-white rounded-lg hover:bg-mai-dark transition">Далее →</button>
                        </div>
                    </div>
                </div>

                <!-- Шаг Компетенции -->
                <div class="step" id="step-competencies">
                    <div class="max-w-5xl mx-auto">
                        <h2 class="text-xl font-bold text-mai-blue mb-4">Шаг — Компетенции и проекты</h2>

                        <!-- Интеграция с GitHub -->
                        <div class="mb-8 p-6 bg-slate-50 border border-slate-200 rounded-2xl">
                            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                                <div class="flex items-center space-x-3">
                                    <div class="w-12 h-12 bg-slate-100 text-slate-700 rounded-xl flex items-center justify-center text-2xl shadow-inner">
                                        <i class="fa-brands fa-github"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-extrabold text-slate-800 tracking-tight">Интеграция с GitHub</h3>
                                        <p class="text-xs text-slate-500 font-medium">Автоматический импорт ваших проектов из GitHub репозиториев</p>
                                    </div>
                                </div>

                                <div id="github-connection-container">
                                    <!-- Будет заполнено через JS -->
                                </div>
                            </div>

                            <!-- Контейнер для списка репозиториев (показывается после авторизации) -->
                            <div id="github-repos-container" class="hidden mt-6 pt-6 border-t border-slate-200">
                                <label class="block text-sm font-bold text-slate-700 mb-2">Выберите репозитории для импорта:</label>
                                <div id="github-repos-list" class="max-h-60 overflow-y-auto space-y-2 border border-slate-200 rounded-xl p-3 bg-white mb-4">
                                    <!-- Заполняется JS -->
                                </div>
                                <div class="flex flex-col sm:flex-row justify-between items-center gap-3">
                                    <span class="text-xs text-slate-500 font-semibold" id="github-selected-count">Выбрано проектов: 0</span>
                                    <button type="button" id="github-import-btn" class="bg-green-600 hover:bg-green-700 text-white px-5 py-2.5 rounded-xl font-bold transition duration-200 text-sm shadow-md shadow-green-100 flex items-center">
                                        <i class="fa-solid fa-file-import mr-2"></i> Экспортировать выбранные проекты из GitHub
                                    </button>
                                </div>
                                <div id="github-import-loader" class="hidden mt-3 flex items-center text-sm text-indigo-600 font-bold">
                                    <i class="fa-solid fa-circle-notch fa-spin mr-2"></i> Загрузка и анализ репозиториев...
                                </div>
                            </div>
                        </div>

                        <div class="mb-6">
                            <div class="flex justify-between items-center">
                                <h3 class="font-semibold">Компетенции</h3>
                                <button type="button" id="addCompetencyBtn" class="px-3 py-1 bg-mai-blue text-white rounded">+ Добавить</button>
                            </div>
                            <div id="competenciesList" class="mt-3 space-y-3">
                                <!-- компетенции динамически заполняются JS -->
                            </div>
                        </div>

                        <div class="mb-6">
                            <div class="flex justify-between items-center">
                                <h3 class="font-semibold">Проекты</h3>
                                <button type="button" id="addProjectBtn" class="px-3 py-1 bg-mai-blue text-white rounded">+ Добавить</button>
                            </div>
                            <div id="projectsList" class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- проекты динамически заполняются JS -->
                            </div>
                        </div>

                        <div class="mt-6 flex justify-between">
                            <button type="button" class="btn-prev px-6 py-2 bg-gray-200 rounded-lg">← Назад</button>
                            <button type="button" class="btn-next px-6 py-2 bg-mai-blue text-white rounded-lg">Далее →</button>
                        </div>
                    </div>
                </div>

                <!-- Шаг Рефлексия -->
                <div class="step" id="step-reflection">
                    <div class="max-w-3xl mx-auto">
                        <h2 class="text-xl font-bold text-mai-blue mb-4">Шаг — Рефлексия</h2>
                        <div class="space-y-4">
                            <div>
                                <label class="text-sm">Что получилось хорошо?</label>
                                <textarea name="reflections[0][what_worked]" placeholder="Например: отлично выступил в командном проекте..." rows="3" class="w-full px-4 py-2 border rounded-lg"><?= $reflections[0]['what_worked'] ?? '' ?></textarea>
                            </div>
                            <div>
                                <label class="text-sm">Что не получилось?</label>
                                <textarea name="reflections[0][what_failed]" placeholder="Например: не успел вовремя сдать лабораторные..." rows="3" class="w-full px-4 py-2 border rounded-lg"><?= $reflections[0]['what_failed'] ?? '' ?></textarea>
                            </div>
                            <div>
                                <label class="text-sm">Что изменю в следующем семестре?</label>
                                <textarea name="reflections[0][changes]" placeholder="Например: составлю расписание и буду придерживаться дедлайнов..." rows="3" class="w-full px-4 py-2 border rounded-lg"><?= $reflections[0]['changes'] ?? '' ?></textarea>
                            </div>

                            <div class="flex items-center space-x-3 mt-2">
                                <input type="checkbox" id="is_published" name="is_published" <?= ($user['is_published'] ?? 0) ? 'checked' : '' ?> class="h-5 w-5">
                                <label for="is_published" class="font-bold">Опубликовать стенд в галерее</label>
                            </div>
                        </div>

                        <div class="mt-6 flex justify-between">
                            <button type="button" class="btn-prev px-6 py-2 bg-gray-200 rounded-lg">← Назад</button>
                            <button type="submit" id="submitBtn" class="px-8 py-3 bg-green-600 text-white rounded-lg font-bold">Сохранить</button>
                        </div>

                    </div>
                </div>
            </form>
        </div>

        <footer class="text-center text-sm text-gray-500">
            © 2026 МГУТУ проект им МШ 2
        </footer>
    </div>
    <script>
        const $ = (sel, ctx = document) => ctx.querySelector(sel);
        const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

        // === Динамическая кнопка "Сохранить и опубликовать" ===
        const publishCheckbox = $('input[name="is_published"]');
        const submitBtn = $('button[type="submit"]');

        function updateButtonText() {
            if (!submitBtn) return;
            if (publishCheckbox?.checked) {
                submitBtn.innerHTML = 'Сохранить и опубликовать';
                submitBtn.classList.remove('bg-indigo-600', 'hover:bg-indigo-700');
                submitBtn.classList.add('bg-green-600', 'hover:bg-green-700');
            } else {
                submitBtn.innerHTML = 'Сохранить';
                submitBtn.classList.remove('bg-green-600', 'hover:bg-green-700');
                submitBtn.classList.add('bg-indigo-600', 'hover:bg-indigo-700');
            }
        }

        if (publishCheckbox && submitBtn) {
            publishCheckbox.addEventListener('change', updateButtonText);
            updateButtonText(); // сразу при загрузке
        }

        // Пример: внутри функции добавления точки
        async function addTerrellPoint(type) { // type = 'achievement' | 'crisis' | 'goal'
            const name = prompt(`Введите название ${type === 'achievement' ? 'достижения' : type === 'crisis' ? 'кризиса' : 'цели'}:`)?.trim();
            if (!name) return;

            const comment = prompt("Комментарий (опционально):")?.trim() || '';

            try {
                const formData = new FormData();
                formData.append('action', 'add');
                formData.append('project_id', currentProjectId); // ← ОБЯЗАТЕЛЬНО! должен быть известен
                formData.append('name', name);
                formData.append('type', type);
                formData.append('comment', comment);

                const response = await fetch('api/terrell_api.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const json = await response.json();

                if (!json.success) {
                    alert(json.error || 'Не удалось добавить точку');
                    return;
                }

                // Успех → обновляем интерфейс
                console.log('Добавлена точка:', json.id);
                // Здесь обычно: перерисовать список точек, добавить новую строку в DOM и т.д.
                refreshTerrellPoints();

            } catch (err) {
                console.error(err);
                alert('Ошибка при добавлении: ' + err.message);
            }
        }

        // === Навигация по шагам формы (Динамическая) ===
        let currentStepIndex = 0;
        let stepsConfig = [];

        function updateStepsConfig() {
            // Проверяем наличие проекта со статусом "в процессе"
            const selects = document.querySelectorAll('.project-status-select');
            let hasInProgress = false;
            selects.forEach(s => {
                if (s.value === 'в процессе') {
                    hasInProgress = true;
                }
            });

            if (hasInProgress) {
                stepsConfig = [{
                        id: 'step-basic',
                        title: 'Основные'
                    },
                    {
                        id: 'step-whoami',
                        title: 'Кто я'
                    },
                    {
                        id: 'step-goal',
                        title: 'Цель'
                    },
                    {
                        id: 'step-competencies',
                        title: 'Компетенции'
                    },
                    {
                        id: 'step-progress',
                        title: 'Прогресс'
                    },
                    {
                        id: 'step-reflection',
                        title: 'Рефлексия'
                    }
                ];
            } else {
                stepsConfig = [{
                        id: 'step-basic',
                        title: 'Основные'
                    },
                    {
                        id: 'step-whoami',
                        title: 'Кто я'
                    },
                    {
                        id: 'step-goal',
                        title: 'Цель'
                    },
                    {
                        id: 'step-competencies',
                        title: 'Компетенции'
                    },
                    {
                        id: 'step-reflection',
                        title: 'Рефлексия'
                    }
                ];
            }
        }

        function renderStepIndicators() {
            const container = document.getElementById('step-indicators-container');
            if (!container) return;

            container.innerHTML = stepsConfig.map((step, i) => {
                const isActive = i <= currentStepIndex;
                const bgClass = isActive ? 'bg-mai-blue text-white' : 'bg-gray-300 text-gray-600';
                return `
                    <div class="text-center cursor-pointer" onclick="goToStep(${i})">
                        <div class="w-8 h-8 rounded-full ${bgClass} flex items-center justify-center mx-auto mb-1 step-indicator font-bold" data-step="${i + 1}">
                            ${i + 1}
                        </div>
                        <div class="text-xs">${step.title}</div>
                    </div>
                `;
            }).join('');
        }

        function showStep(index) {
            if (index < 0 || index >= stepsConfig.length) return;

            // Скрываем все шаги
            document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));

            currentStepIndex = index;
            const step = stepsConfig[index];
            const targetStep = document.getElementById(step.id);
            if (targetStep) {
                targetStep.classList.add('active');

                // Показываем/скрываем кнопку "Назад" на первом шаге
                const btnPrev = targetStep.querySelector('.btn-prev');
                if (btnPrev) {
                    if (index === 0) {
                        btnPrev.classList.add('hidden');
                    } else {
                        btnPrev.classList.remove('hidden');
                    }
                }
            }

            renderStepIndicators();
        }

        function nextStep() {
            if (currentStepIndex < stepsConfig.length - 1) {
                showStep(currentStepIndex + 1);
            }
        }

        function prevStep() {
            if (currentStepIndex > 0) {
                showStep(currentStepIndex - 1);
            }
        }

        function goToStep(index) {
            // Разрешаем переходить только на шаги, которые уже пройдены или следующие
            if (index >= 0 && index < stepsConfig.length) {
                showStep(index);
            }
        }

        function refreshSteps() {
            const currentStepId = stepsConfig[currentStepIndex]?.id;
            updateStepsConfig();
            let newIndex = stepsConfig.findIndex(step => step.id === currentStepId);
            if (newIndex === -1) {
                newIndex = 0;
            }
            showStep(newIndex);
        }

        // === Отслеживание статуса проектов ===
        document.addEventListener('focusin', e => {
            if (e.target && e.target.classList.contains('project-status-select')) {
                e.target.dataset.prevValue = e.target.value;
            }
        });

        document.addEventListener('change', e => {
            if (e.target && e.target.classList.contains('project-status-select')) {
                if (e.target.value === 'в процессе') {
                    // Проверяем, есть ли уже другой проект "в процессе"
                    const selects = document.querySelectorAll('.project-status-select');
                    let inProgressCount = 0;
                    selects.forEach(s => {
                        if (s !== e.target && s.value === 'в процессе') {
                            inProgressCount++;
                        }
                    });

                    if (inProgressCount >= 1) {
                        alert('Нельзя, чтобы у одного пользователя было больше одного проекта в процессе.');
                        e.target.value = e.target.dataset.prevValue || 'завершен';
                        return;
                    }
                }
                refreshSteps();
            }
        });

        // === Остальные функции (создание строк, drag & drop и т.д.) ===
        let skillIndex = 0,
            compIndex = 0,
            projectIndex = 0,
            criterionIndex = 0,
            progressIndex = 0;

        function escapeHtml(s) {
            if (!s) return '';
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // === Skills 0–5 ===
        function createSkillRow(name = '', level = 2) {
            const idx = skillIndex++;
            const div = document.createElement('div');
            div.className = 'flex items-center gap-2 mb-2';
            div.innerHTML = `
                <input name="skills[${idx}][name]" value="${escapeHtml(name)}" placeholder="Навык" class="flex-1 px-3 py-2 border rounded" required>
                <select name="skills[${idx}][level]" class="px-3 py-2 border rounded">
                    ${[0,1,2,3,4,5].map(v=>`<option value="${v}" ${v==level?'selected':''}>${v}</option>`).join('')}
                </select>
                <button type="button" class="remove-skill text-red-500 px-2">Удалить</button>
            `;
            div.querySelector('.remove-skill').addEventListener('click', () => div.remove());
            return div;
        }

        // === Competencies 0–5 ===
        function createCompetencyRow(name = '', level = 2, url = '', type = 'hard') {
            const idx = compIndex++;
            const div = document.createElement('div');
            div.className = 'p-3 border rounded bg-gray-50 mb-2';
            div.innerHTML = `
                <input name="competencies[${idx}][name]" value="${escapeHtml(name)}" placeholder="Название" class="w-full mb-1 px-2 py-1 border rounded" required>
                <select name="competencies[${idx}][level]" class="mb-1 px-2 py-1 border rounded">
                    ${[0,1,2,3,4,5].map(v=>`<option value="${v}" ${v==level?'selected':''}>${v}</option>`).join('')}
                </select>
                <select name="competencies[${idx}][type]" class="mb-1 px-2 py-1 border rounded">
                    <option value="hard" ${type==='hard'?'selected':''}>Hard</option>
                    <option value="soft" ${type==='soft'?'selected':''}>Soft</option>
                </select>
                <input name="competencies[${idx}][artifact_url]" value="${escapeHtml(url)}" placeholder="Ссылка (опционально)" class="w-full mb-1 px-2 py-1 border rounded">
                <button type="button" class="remove-competency text-red-500">Удалить</button>
            `;
            div.querySelector('.remove-competency').addEventListener('click', () => div.remove());
            return div;
        }

        function createProjectCard(data = {}) {
            const idx = projectIndex++;
            const div = document.createElement('div');
            div.className = 'p-3 border rounded bg-gray-50 mb-2 project-card';
            div.innerHTML = `
                <input type="hidden" name="projects[${idx}][id]" value="${escapeHtml(data.id||'')}">
                <input name="projects[${idx}][name]" value="${escapeHtml(data.name||'')}" placeholder="Название проекта" class="w-full mb-1 px-2 py-1 border rounded font-bold" required>
                <textarea name="projects[${idx}][description]" placeholder="Описание" class="w-full mb-1 px-2 py-1 border rounded">${escapeHtml(data.description||'')}</textarea>
                <input name="projects[${idx}][tech_stack]" value="${escapeHtml(data.tech_stack||'')}" placeholder="Стек" class="w-full mb-1 px-2 py-1 border rounded">
                <input name="projects[${idx}][role]" value="${escapeHtml(data.role||'')}" placeholder="Роль" class="w-full mb-1 px-2 py-1 border rounded">
                <input name="projects[${idx}][repo_url]" value="${escapeHtml(data.repo_url||'')}" placeholder="Ссылка репо" class="w-full mb-1 px-2 py-1 border rounded">
                <select name="projects[${idx}][status]" class="mb-1 px-2 py-1 border rounded project-status-select">
                    <option value="в процессе" ${data.status==='в процессе'?'selected':''}>в процессе</option>
                    <option value="завершен" ${data.status==='завершен'?'selected':''}>завершён</option>
                    <option value="приостановлен" ${data.status==='приостановлен'?'selected':''}>приостановлен</option>
                </select>
                <button type="button" class="remove-project text-red-500">Удалить</button>
            `;
            div.querySelector('.remove-project').addEventListener('click', () => {
                div.remove();
                refreshSteps();
            });
            return div;
        }

        function createCriterionRow(text = '') {
            const idx = criterionIndex++;
            const div = document.createElement('div');
            div.className = 'flex gap-2 mb-2';
            div.innerHTML = `
                <input type="text" name="criteria[${idx}]" value="${escapeHtml(text)}" placeholder="Критерий успеха" class="flex-1 px-3 py-1 border rounded" required>
                <button type="button" class="remove-criterion text-red-500">Удалить</button>
            `;
            div.querySelector('.remove-criterion').addEventListener('click', () => div.remove());
            return div;
        }

        function createProgressPoint(point = {}) {
            const idx = progressIndex++;
            const div = document.createElement('div');
            const typeClass = point.type === 'crisis' ? 'bg-red-500' : (point.type === 'goal' ? 'bg-green-500' : 'bg-blue-500');
            div.className = 'p-3 border rounded mb-2 flex flex-col items-center justify-center bg-white shadow-sm';
            div.style.width = '160px';
            div.style.height = '160px';
            div.style.flexShrink = '0';
            div.style.transition = 'transform 0.2s ease';

            div.innerHTML = `
                <div class="w-8 h-8 rounded-full ${typeClass} text-white flex items-center justify-center mb-1 text-sm font-bold">
                    ${point.type === 'achievement' ? '🏆' : (point.type === 'crisis' ? '⚠️' : '🎯')}
                </div>
                <input type="hidden" name="terrell_points[${idx}][type]" value="${escapeHtml(point.type || 'achievement')}">
                <input type="hidden" name="terrell_points[${idx}][position]" class="point-position" value="${escapeHtml(point.position || idx)}">
                <input name="terrell_points[${idx}][name]" value="${escapeHtml(point.name || '')}" placeholder="Описание" class="w-full text-center px-2 py-1 border rounded mb-1 text-sm font-medium" required>
                <input name="terrell_points[${idx}][comment]" value="${escapeHtml(point.comment || '')}" placeholder="Комментарий" class="w-full text-center px-2 py-1 border rounded mb-1 text-xs text-gray-500">
                <button type="button" class="remove-progress text-red-500 px-2 py-0.5 text-xs rounded border border-red-300 hover:bg-red-50">Удалить</button>
            `;

            div.querySelector('.remove-progress').addEventListener('click', () => {
                div.remove();
                updatePositions(document.getElementById('terrellMap'));
            });
            div.setAttribute('draggable', true);

            return div;
        }

        function updatePositions(container) {
            if (!container) return;
            const inputs = container.querySelectorAll('.point-position');
            inputs.forEach((input, index) => {
                input.value = index;
            });
        }

        function addLocalProgressPoint(type) {
            const name = prompt(`Введите название ${type === 'achievement' ? 'достижения' : type === 'crisis' ? 'кризиса' : 'цели'}:`)?.trim();
            if (!name) return;
            const comment = prompt("Комментарий (опционально):")?.trim() || '';
            const progressMap = document.getElementById('terrellMap');
            if (progressMap) {
                const newPoint = createProgressPoint({
                    type,
                    name,
                    comment
                });
                progressMap.appendChild(newPoint);
                updatePositions(progressMap);
            }
        }

        function enableProgressDragAndDrop(container) {
            let dragEl = null;
            let placeholder = document.createElement('div');
            placeholder.className = 'border-2 border-dashed border-mai-blue rounded';
            placeholder.style.width = '160px';
            placeholder.style.height = '160px';
            placeholder.style.flexShrink = '0';
            placeholder.style.margin = '4px';
            placeholder.style.transition = 'all 0.2s';

            container.addEventListener('dragstart', e => {
                dragEl = e.target.closest('div[draggable]');
                if (!dragEl) return;

                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', '');
                dragEl.style.opacity = '0.5';

                container.insertBefore(placeholder, dragEl.nextSibling);
            });

            container.addEventListener('dragend', e => {
                if (dragEl) {
                    container.insertBefore(dragEl, placeholder);
                    dragEl.style.opacity = '';
                    placeholder.remove();
                    dragEl = null;
                    updatePositions(container);
                }
            });

            container.addEventListener('dragover', e => {
                e.preventDefault();
                const target = e.target.closest('div[draggable]');
                if (!target || target === dragEl) return;

                const rect = target.getBoundingClientRect();
                const after = (e.clientX - rect.left) > rect.width / 2;
                if (after) {
                    target.parentNode.insertBefore(placeholder, target.nextSibling);
                } else {
                    target.parentNode.insertBefore(placeholder, target);
                }
            });

            container.addEventListener('drop', e => {
                e.preventDefault();
                if (dragEl) {
                    container.insertBefore(dragEl, placeholder);
                    dragEl.style.opacity = '';
                    placeholder.remove();
                    dragEl = null;
                    updatePositions(container);
                }
            });
        }

        // === Initialization ===
        document.addEventListener('DOMContentLoaded', () => {
            // Navigation buttons
            $$('.btn-next').forEach(btn => btn.addEventListener('click', nextStep));
            $$('.btn-prev').forEach(btn => btn.addEventListener('click', prevStep));

            const skillsList = $('#skillsList');
            const compList = $('#competenciesList');
            const projList = $('#projectsList');
            const criteriaList = $('#criteriaList');
            const progressMap = $('#terrellMap');

            $('#addSkillBtn').addEventListener('click', () => skillsList.appendChild(createSkillRow()));
            $('#addCompetencyBtn').addEventListener('click', () => compList.appendChild(createCompetencyRow()));
            $('#addProjectBtn').addEventListener('click', () => {
                // Если проект уже есть в процессе, то новый создаем завершенным
                const selects = document.querySelectorAll('.project-status-select');
                let hasInProgress = false;
                selects.forEach(s => {
                    if (s.value === 'в процессе') hasInProgress = true;
                });
                const defaultStatus = hasInProgress ? 'завершен' : 'в процессе';
                projList.appendChild(createProjectCard({
                    status: defaultStatus
                }));
                refreshSteps();
            });
            $('#addCriterionBtn').addEventListener('click', () => criteriaList.appendChild(createCriterionRow()));

            if (progressMap) {
                enableProgressDragAndDrop(progressMap);
            }

            // Load server data
            <?php if ($user): ?>
                const serverData = {
                    skills: <?= json_encode($skills ?? []) ?>,
                    competencies: <?= json_encode($competencies ?? []) ?>,
                    projects: <?= json_encode($projects ?? []) ?>,
                    criteria: <?= json_encode(array_column($criteria ?? [], 'criterion')) ?>,
                    terrell_points: <?= json_encode($progress ?? []) ?>,
                    reflections: <?= json_encode($reflections ?? []) ?>
                };

                if (serverData.skills) {
                    serverData.skills.forEach(s => skillsList.appendChild(createSkillRow(s.name, s.level)));
                    skillIndex = serverData.skills.length;
                }

                if (serverData.competencies) {
                    serverData.competencies.forEach(c => compList.appendChild(createCompetencyRow(c.name, c.level, c.artifact_url, c.type)));
                    compIndex = serverData.competencies.length;
                }

                if (serverData.projects) {
                    serverData.projects.forEach(p => projList.appendChild(createProjectCard(p)));
                    projectIndex = serverData.projects.length;
                }

                if (serverData.criteria) {
                    serverData.criteria.forEach(c => criteriaList.appendChild(createCriterionRow(c)));
                    criterionIndex = serverData.criteria.length;
                }

                if (serverData.terrell_points && progressMap) {
                    serverData.terrell_points.forEach(p => progressMap.appendChild(createProgressPoint(p)));
                    progressIndex = serverData.terrell_points.length;
                }

                // Рефлексии
                if (serverData.reflections && serverData.reflections.length > 0) {
                    const r = serverData.reflections[0];

                    const input1 = document.querySelector('[name="reflections[0][what_worked]"]');
                    const input2 = document.querySelector('[name="reflections[0][what_failed]"]');
                    const input3 = document.querySelector('[name="reflections[0][changes]"]');

                    if (input1) input1.value = r.what_worked || '';
                    if (input2) input2.value = r.what_failed || '';
                    if (input3) input3.value = r.changes || '';
                }
            <?php endif; ?>

            // Инициализация шагов и индикаторов
            updateStepsConfig();
            showStep(0);

            // Avatar preview
            const avatarInput = document.getElementById('avatarUpload');
            const avatarPreview = document.getElementById('avatarPreview');

            if (avatarInput && avatarPreview) {
                avatarInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (!file) {
                        avatarPreview.src = avatarPreview.dataset.original || "https://placehold.co/120x120/0055A4/FFFFFF?text=Аватар";
                        return;
                    }

                    if (file.size > 2 * 1024 * 1024) {
                        alert('Файл слишком большой! Максимум 2 МБ.');
                        this.value = '';
                        return;
                    }

                    if (!file.type.match(/^image\//)) {
                        alert('Пожалуйста, выберите изображение');
                        this.value = '';
                        return;
                    }

                    const newSrc = URL.createObjectURL(file) + "#" + Date.now();

                    avatarPreview.src = "";
                    avatarPreview.src = newSrc;

                    avatarPreview.onload = () => {
                        URL.revokeObjectURL(newSrc.split("#")[0]);
                    };
                });

                avatarPreview.dataset.original = avatarPreview.src;
            }

            // Weakness "другое"
            const radios = document.querySelectorAll('input[name="weakness"]');
            const otherInput = document.getElementById('weakness_other');

            if (!otherInput) return;

            function updateOtherVisibility() {
                let selected = null;
                if (radios && radios.length > 0) {
                    radios.forEach(r => {
                        if (r.checked) selected = r.value;
                    });
                }

                if (selected === 'другое') {
                    otherInput.classList.remove('hidden');
                    otherInput.removeAttribute('disabled');
                } else {
                    otherInput.classList.add('hidden');
                    otherInput.setAttribute('disabled', 'disabled');
                }
            }

            if (radios && radios.length > 0) {
                radios.forEach(r => r.addEventListener('change', updateOtherVisibility));
            }

            updateOtherVisibility();

            // === Интеграция с GitHub (JS Логика) ===
            let githubUsername = <?php
                                    $githubUser = $user['github_username'] ?? '';
                                    echo json_encode($githubUser, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
                                    ?>;

            function updateGithubUI() {
                const connContainer = document.getElementById('github-connection-container');
                const reposContainer = document.getElementById('github-repos-container');

                if (githubUsername) {
                    connContainer.innerHTML = `
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm font-semibold text-green-700 bg-green-50 border border-green-200 px-3 py-1.5 rounded-lg flex items-center">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500 mr-2"></span>
                                Подключен: @${escapeHtml(githubUsername)}
                            </span>
                            <button type="button" id="github-show-repos-btn" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-xs font-bold transition duration-200">
                                <i class="fa-solid fa-list-check mr-1.5"></i> Показать репозитории
                            </button>
                            <button type="button" id="github-disconnect-btn" class="text-red-500 hover:text-red-700 text-xs font-bold hover:underline px-2">
                                Отключить
                            </button>
                        </div>
                    `;

                    document.getElementById('github-show-repos-btn').addEventListener('click', loadGithubRepos);
                    document.getElementById('github-disconnect-btn').addEventListener('click', disconnectGithub);
                } else {
                    connContainer.innerHTML = `
                        <div class="relative inline-block group">
                            <button type="button" id="github-connect-btn" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl font-bold transition duration-200 flex items-center shadow-md shadow-indigo-100">
                                <i class="fa-brands fa-github mr-2 text-lg"></i> Авторизоваться в GitHub
                            </button>
                            <div class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 hidden group-hover:block w-64 bg-slate-800 text-white text-xs rounded-lg p-2 shadow-lg z-50 text-center">
                                Авторизовавшись в GitHub, вы сможете выбрать свои публичные проекты и автоматически добавить их в стенд
                                <div class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-slate-800"></div>
                            </div>
                        </div>
                    `;

                    document.getElementById('github-connect-btn').addEventListener('click', startGithubAuth);
                    reposContainer.classList.add('hidden');
                }
            }

            function startGithubAuth() {
                fetch('api/auth/github/url.php', {
                        method: 'POST'
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.url) {
                            const width = 600,
                                height = 700;
                            const left = (window.innerWidth - width) / 2 + window.screenX;
                            const top = (window.innerHeight - height) / 2 + window.screenY;
                            const popup = window.open(data.url, 'github_oauth', `width=${width},height=${height},left=${left},top=${top}`);

                            window.addEventListener('message', function receiveMessage(event) {
                                if (event.data && event.data.type === 'github_auth_success') {
                                    window.removeEventListener('message', receiveMessage);
                                    githubUsername = event.data.username;
                                    updateGithubUI();
                                    loadGithubRepos();
                                } else if (event.data && event.data.type === 'github_auth_error') {
                                    window.removeEventListener('message', receiveMessage);
                                    alert('Ошибка авторизации: ' + event.data.message);
                                }
                            });
                        } else {
                            alert('Ошибка: ' + (data.message || 'Не удалось получить ссылку авторизации'));
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Сетевая ошибка при получении ссылки авторизации');
                    });
            }

            function loadGithubRepos() {
                const reposContainer = document.getElementById('github-repos-container');
                const reposList = document.getElementById('github-repos-list');
                reposContainer.classList.remove('hidden');
                reposList.innerHTML = '<div class="text-sm text-slate-500 p-2"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Загрузка списка репозиториев...</div>';

                fetch('api/github/repos.php')
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            if (data.repos.length === 0) {
                                reposList.innerHTML = '<div class="text-sm text-slate-500 p-2">У вас нет публичных репозиториев.</div>';
                                return;
                            }

                            reposList.innerHTML = data.repos.map((repo, i) => `
                                <label class="flex items-start gap-3 p-2 hover:bg-slate-50 rounded-lg cursor-pointer transition">
                                    <input type="checkbox" name="github_selected_repos[]" value="${escapeHtml(repo.full_name)}" class="mt-1 github-repo-checkbox rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                    <div class="text-xs">
                                        <div class="font-bold text-slate-800">${escapeHtml(repo.name)}</div>
                                        <div class="text-slate-500 mt-0.5">${escapeHtml(repo.description || 'Без описания')}</div>
                                        <div class="text-slate-400 mt-0.5 font-mono">${escapeHtml(repo.language || 'Не определен')} • <a href="${escapeHtml(repo.html_url)}" target="_blank" class="text-indigo-500 hover:underline">Ссылка <i class="fa-solid fa-arrow-up-right-from-square text-[8px]"></i></a></div>
                                    </div>
                                </label>
                            `).join('');

                            // Привязываем слушатели к чекбоксам
                            document.querySelectorAll('.github-repo-checkbox').forEach(cb => {
                                cb.addEventListener('change', updateSelectedReposCount);
                            });

                            updateSelectedReposCount();
                        } else {
                            if (data.error === 'TOKEN_EXPIRED') {
                                githubUsername = '';
                                updateGithubUI();
                                alert('Сессия GitHub истекла. Пожалуйста, авторизуйтесь заново.');
                            } else {
                                reposList.innerHTML = `<div class="text-sm text-red-500 p-2">Ошибка: ${escapeHtml(data.message || 'Не удалось загрузить список')}</div>`;
                            }
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        reposList.innerHTML = '<div class="text-sm text-red-500 p-2">Сетевая ошибка при загрузке репозиториев</div>';
                    });
            }

            function updateSelectedReposCount() {
                const checked = document.querySelectorAll('.github-repo-checkbox:checked').length;
                document.getElementById('github-selected-count').textContent = `Выбрано проектов: ${checked}`;
            }

            function disconnectGithub() {
                if (!confirm('Вы уверены, что хотите отключить интеграцию с GitHub?')) return;

                fetch('api/auth/github/disconnect.php', {
                        method: 'POST'
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            githubUsername = '';
                            updateGithubUI();
                        } else {
                            alert('Ошибка: ' + (data.message || 'Не удалось отключить аккаунт'));
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Сетевая ошибка при отключении');
                    });
            }

            document.getElementById('github-import-btn').addEventListener('click', () => {
                const checkedBoxes = document.querySelectorAll('.github-repo-checkbox:checked');
                if (checkedBoxes.length === 0) {
                    alert('Пожалуйста, выберите хотя бы один репозиторий.');
                    return;
                }

                const repos = Array.from(checkedBoxes).map(cb => cb.value);
                const loader = document.getElementById('github-import-loader');
                const importBtn = document.getElementById('github-import-btn');

                loader.classList.remove('hidden');
                importBtn.disabled = true;
                importBtn.classList.add('opacity-50');

                fetch('api/github/import.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            repos
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        loader.classList.add('hidden');
                        importBtn.disabled = false;
                        importBtn.classList.remove('opacity-50');

                        if (data.success && data.projects) {
                            const projList = document.getElementById('projectsList');
                            let unparsedStackCount = 0;

                            data.projects.forEach(p => {
                                projList.appendChild(createProjectCard(p));
                                if (p.stack_not_recognized) {
                                    unparsedStackCount++;
                                }
                            });

                            checkedBoxes.forEach(cb => cb.checked = false);
                            updateSelectedReposCount();
                            refreshSteps();

                            if (unparsedStackCount > 0) {
                                alert(`Импортировано проектов: ${data.projects.length}. В ${unparsedStackCount} проектах стек не распознан автоматически, заполните его вручную.`);
                            } else {
                                alert(`Успешно импортировано проектов: ${data.projects.length}`);
                            }
                        } else {
                            alert('Ошибка импорта: ' + (data.message || 'Неизвестная ошибка'));
                        }
                    })
                    .catch(err => {
                        loader.classList.add('hidden');
                        importBtn.disabled = false;
                        importBtn.classList.remove('opacity-50');
                        console.error(err);
                        alert('Сетевая ошибка при импорте проектов');
                    });
            });

            updateGithubUI();
        });
    </script>
</body>

</html>