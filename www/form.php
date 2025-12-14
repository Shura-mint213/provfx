<?php
require_once __DIR__ . '/../init.php';

$error = '';
$success = '';
$userId = $_SESSION['user_id'] ?? null;
$isNew = isset($_GET['new']) && $_GET['new'] == 1;

// =======================
// ЗАГРУЖАЕМ ПОЛЬЗОВАТЕЛЯ
// =======================
$user = null;
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

    $stmt = $pdo->prepare("SELECT * FROM criteria WHERE student_id=? ORDER BY id ASC");
    $stmt->execute([$userId]);
    $criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM progress_points WHERE student_id=? ORDER BY position ASC");
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
    $progress      = $_POST['progress_points'] ?? [];
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
                $pdo->prepare("
                    INSERT INTO $table (student_id," . implode(",", $fields) . ")
                    VALUES (" . implode(",", array_fill(0, count($fields) + 1, "?")) . ")
                ")->execute(array_merge([$student_id], array_values($clean)));
            }
        }

        saveTable($pdo, 'skills', $skills, $student_id, ['name', 'level']);
        saveTable($pdo, 'competencies', $competencies, $student_id, ['name', 'level', 'artifact_url', 'type']);
        saveTable($pdo, 'criteria', array_map(fn($c) => ['criterion' => $c], $criteria), $student_id, ['criterion']);
        saveTable($pdo, 'progress_points', $progress, $student_id, ['name', 'type', 'comment', 'position']);
        saveTable($pdo, 'reflections', $reflections, $student_id, ['what_worked', 'what_failed', 'changes']);

        // ==============================
        // СОХРАНЕНИЕ ПРОЕКТОВ (ИСПРАВЛЕНО)
        // ==============================
        if (!empty($projects)) {
            // Сначала удаляем все связи студента с проектами
            $pdo->prepare("DELETE FROM project_members WHERE student_id=?")->execute([$student_id]);

            foreach ($projects as $p) {
                // Если это новый проект (нет id)
                if (empty($p['id']) && !empty($p['name'])) {
                    // Создаем новый проект (без student_id!)
                    $stmt = $pdo->prepare("
                        INSERT INTO projects (name, description, tech_stack, role, repo_url, status)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
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
                <div class="w-12 h-12 bg-white text-indigo-700 font-bold flex items-center justify-center rounded-full">МАИ</div>
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
                <div class="flex-1 flex justify-between max-w-4xl mx-auto">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <div class="text-center">
                            <div class="w-8 h-8 rounded-full <?= $i == 1 ? 'bg-mai-blue text-white' : 'bg-gray-300 text-gray-600' ?> flex items-center justify-center mx-auto mb-1 step-indicator" data-step="<?= $i ?>"><?= $i ?></div>
                            <div class="text-xs"><?= ['Основные', 'Кто я', 'Цель', 'Прогресс', 'Компетенции', 'Рефлексия'][$i - 1] ?></div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <form id="studentForm" method="POST" enctype="multipart/form-data">
                <!-- Шаг 1 -->
                <div class="step active" id="step-1">
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
                <div class="step" id="step-2">
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
                                <!-- динамически заполняется JS -->
                                <!-- если в $user есть навыки, вы можете их заполнить здесь при выводе -->
                                <?php
                                if (!empty($user['skills']) && is_array($user['skills'])):
                                    foreach ($user['skills'] as $sk):
                                        $sk_name = htmlspecialchars($sk['name'] ?? '');
                                        $sk_val = isset($sk['value']) ? (int)$sk['value'] : 0;
                                ?>
                                        <div class="skill-item flex items-center space-x-2">
                                            <input type="text" name="skills[][name]" value="<?= $sk_name ?>" placeholder="Навык" class="px-3 py-2 border rounded-lg" />
                                            <select name="skills[][value]" class="px-3 py-2 border rounded-lg">
                                                <?php for ($r = 0; $r <= 5; $r++): ?>
                                                    <option value="<?= $r ?>" <?= $r === $sk_val ? 'selected' : '' ?>><?= $r ?></option>
                                                <?php endfor; ?>
                                            </select>
                                            <button type="button" class="remove-skill px-2 py-1 bg-gray-200 rounded">Удалить</button>
                                        </div>
                                <?php
                                    endforeach;
                                endif;
                                ?>
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
                <div class="step" id="step-3">
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
                                <?php
                                if (!empty($user['criteria']) && is_array($user['criteria'])):
                                    foreach ($user['criteria'] as $c):
                                ?>
                                        <div class="flex items-center space-x-2">
                                            <input type="text" name="criteria[]" value="<?= htmlspecialchars($c) ?>" placeholder="Критерий" class="w-full px-3 py-2 border rounded-lg">
                                            <button type="button" class="remove-criterion px-2 py-1 bg-gray-200 rounded">Удалить</button>
                                        </div>
                                <?php
                                    endforeach;
                                endif;
                                ?>
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

                <!-- Шаг 4 -->
                <div class="step" id="step-4">
                    <div class="max-w-4xl mx-auto">
                        <h2 class="text-xl font-bold text-mai-blue mb-4">Шаг 4 — Карта прогресса
                            <span class="tooltip-wrapper" tabindex="0">
                                <span class="tooltip-icon">?</span>
                                <span class="tooltip-content">Карта прогресса — краткая визуализация или список шагов, которые помогут достичь цели. Добавляйте точки прогресса и описывайте их.</span>
                            </span>
                        </h2>
                        <div id="progressMap" class="p-4 bg-gray-50 rounded-lg min-h-40 flex flex-wrap gap-4">
                            <!-- точки прогресса появятся тут -->
                            <?php
                            if (!empty($user['progress']) && is_array($user['progress'])):
                                foreach ($user['progress'] as $p):
                            ?>
                                    <div class="progress-point p-2 bg-white border rounded">
                                        <?= htmlspecialchars($p) ?>
                                        <input type="hidden" name="progress[]" value="<?= htmlspecialchars($p) ?>">
                                    </div>
                            <?php
                                endforeach;
                            endif;
                            ?>
                        </div>

                        <div class="mt-4 flex space-x-3">
                            <button type="button" id="addProgressPointBtn" class="px-3 py-2 bg-mai-blue text-white rounded">+ Добавить точку</button>
                            <button type="button" id="clearProgressBtn" class="px-3 py-2 bg-gray-200 rounded">Очистить</button>
                        </div>

                        <div class="mt-6 flex justify-between">
                            <button type="button" class="btn-prev px-6 py-2 bg-gray-200 rounded-lg">← Назад</button>
                            <button type="button" class="btn-next px-6 py-2 bg-mai-blue text-white rounded-lg">Далее →</button>
                        </div>
                    </div>
                </div>

                <!-- Шаг 5 -->
                <div class="step" id="step-5">
                    <div class="max-w-5xl mx-auto">
                        <h2 class="text-xl font-bold text-mai-blue mb-4">Шаг 5 — Компетенции и проекты</h2>

                        <div class="mb-6">
                            <div class="flex justify-between items-center">
                                <h3 class="font-semibold">Компетенции</h3>
                                <button type="button" id="addCompetencyBtn" class="px-3 py-1 bg-mai-blue text-white rounded">+ Добавить</button>
                            </div>
                            <div id="competenciesList" class="mt-3 space-y-3">
                                <?php
                                if (!empty($user['competencies']) && is_array($user['competencies'])):
                                    foreach ($user['competencies'] as $c):
                                ?>
                                        <div class="competency-item flex items-center space-x-2">
                                            <input type="text" name="competencies[][name]" value="<?= htmlspecialchars($c['name']) ?>" placeholder="Компетенция" class="px-3 py-2 border rounded-lg" />
                                            <select name="competencies[][value]" class="px-3 py-2 border rounded-lg">
                                                <?php for ($r = 0; $r <= 5; $r++): ?>
                                                    <option value="<?= $r ?>" <?= $r === (int)($c['value'] ?? 0) ? 'selected' : '' ?>><?= $r ?></option>
                                                <?php endfor; ?>
                                            </select>
                                            <button type="button" class="remove-competency px-2 py-1 bg-gray-200 rounded">Удалить</button>
                                        </div>
                                <?php
                                    endforeach;
                                endif;
                                ?>
                            </div>
                        </div>

                        <div class="mb-6">
                            <div class="flex justify-between items-center">
                                <h3 class="font-semibold">Проекты</h3>
                                <button type="button" id="addProjectBtn" class="px-3 py-1 bg-mai-blue text-white rounded">+ Добавить</button>
                            </div>
                            <div id="projectsList" class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- проекты динамически -->
                                <?php
                                if (!empty($user['projects']) && is_array($user['projects'])):
                                    foreach ($user['projects'] as $p):
                                ?>
                                        <div class="project-item p-3 border rounded">
                                            <input type="text" name="projects[][title]" value="<?= htmlspecialchars($p['title']) ?>" placeholder="Название проекта" class="w-full px-3 py-2 border rounded-lg mb-2" />
                                            <textarea name="projects[][desc]" class="w-full px-3 py-2 border rounded-lg" rows="3" placeholder="Краткое описание"><?= htmlspecialchars($p['desc']) ?></textarea>
                                        </div>
                                <?php
                                    endforeach;
                                endif;
                                ?>
                            </div>
                        </div>

                        <div class="mt-6 flex justify-between">
                            <button type="button" class="btn-prev px-6 py-2 bg-gray-200 rounded-lg">← Назад</button>
                            <button type="button" class="btn-next px-6 py-2 bg-mai-blue text-white rounded-lg">Далее →</button>
                        </div>
                    </div>
                </div>

                <!-- Шаг 6 -->
                <div class="step" id="step-6">
                    <div class="max-w-3xl mx-auto">
                        <h2 class="text-xl font-bold text-mai-blue mb-4">Шаг 6 — Рефлексия</h2>
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
            © 2025 Московский авиационный институт (МАИ)
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

        // === Навигация по шагам формы ===
        let currentStep = 1;
        const totalSteps = 6;

        function showStep(n) {
            $$('.step').forEach(s => s.classList.remove('active'));
            $(`#step-${n}`)?.classList.add('active');

            $$('.step-indicator').forEach(ind => {
                const stepNum = parseInt(ind.dataset.step);
                ind.classList.toggle('bg-mai-blue', stepNum <= n);
                ind.classList.toggle('text-white', stepNum <= n);
                ind.classList.toggle('bg-gray-300', stepNum > n);
                ind.classList.toggle('text-gray-600', stepNum > n);
            });

            currentStep = n;
        }

        function nextStep() {
            if (currentStep < totalSteps) showStep(currentStep + 1);
        }

        function prevStep() {
            if (currentStep > 1) showStep(currentStep - 1);
        }

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
                <input name="skills[${idx}][name]" value="${escapeHtml(name)}" placeholder="Навык" class="flex-1 px-3 py-2 border rounded">
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
                <input name="competencies[${idx}][name]" value="${escapeHtml(name)}" placeholder="Название" class="w-full mb-1 px-2 py-1 border rounded">
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
            div.className = 'p-3 border rounded bg-gray-50 mb-2';
            div.innerHTML = `
            <input name="projects[${idx}][name]" value="${escapeHtml(data.name||'')}" placeholder="Название проекта" class="w-full mb-1 px-2 py-1 border rounded font-bold">
            <textarea name="projects[${idx}][description]" placeholder="Описание" class="w-full mb-1 px-2 py-1 border rounded">${escapeHtml(data.description||'')}</textarea>
            <input name="projects[${idx}][tech_stack]" value="${escapeHtml(data.tech_stack||'')}" placeholder="Стек" class="w-full mb-1 px-2 py-1 border rounded">
            <input name="projects[${idx}][role]" value="${escapeHtml(data.role||'')}" placeholder="Роль" class="w-full mb-1 px-2 py-1 border rounded">
            <input name="projects[${idx}][repo_url]" value="${escapeHtml(data.repo_url||'')}" placeholder="Ссылка репо" class="w-full mb-1 px-2 py-1 border rounded">
            <select name="projects[${idx}][status]" class="mb-1 px-2 py-1 border rounded">
                <option value="в процессе" ${data.status==='в процессе'?'selected':''}>в процессе</option>
                <option value="завершен" ${data.status==='завершен'?'selected':''}>завершён</option>
                <option value="приостановлен" ${data.status==='приостановлен'?'selected':''}>приостановлен</option>
            </select>
            <button type="button" class="remove-project text-red-500">Удалить</button>
        `;
            div.querySelector('.remove-project').addEventListener('click', () => div.remove());
            return div;
        }

        function createCriterionRow(text = '') {
            const idx = criterionIndex++;
            const div = document.createElement('div');
            div.className = 'flex gap-2 mb-2';
            div.innerHTML = `
            <input type="text" name="criteria[${idx}]" value="${escapeHtml(text)}" placeholder="Критерий успеха" class="flex-1 px-3 py-1 border rounded">
            <button type="button" class="remove-criterion text-red-500">Удалить</button>
        `;
            div.querySelector('.remove-criterion').addEventListener('click', () => div.remove());
            return div;
        }

        function createProgressPoint(point = {}) {
            const idx = progressIndex++;
            const div = document.createElement('div');
            div.className = 'p-3 border rounded mb-2 flex flex-col items-center justify-center bg-white shadow-sm';
            div.style.width = '120px'; // шире для удобства текста
            div.style.height = '120px'; // выше, чтобы все элементы помещались
            div.style.flexShrink = '0';
            div.style.transition = 'transform 0.2s ease';

            div.innerHTML = `
                <div class="w-8 h-8 rounded-full bg-mai-blue text-white flex items-center justify-center mb-2 text-sm font-bold">●</div>
                <input name="progress_points[${idx}][name]" value="${escapeHtml(point.name||'')}" placeholder="Описание" class="w-full text-center px-2 py-1 border rounded mb-2 text-sm">
                <button type="button" class="remove-progress text-red-500 px-2 py-1 text-xs rounded border border-red-300 hover:bg-red-50">Удалить</button>
            `;

            div.querySelector('.remove-progress').addEventListener('click', () => div.remove());
            div.setAttribute('draggable', true);

            return div;
        }

        function enableProgressDragAndDrop(container) {
            let dragEl = null;
            let placeholder = document.createElement('div');
            placeholder.className = 'border-2 border-dashed border-mai-blue rounded';
            placeholder.style.width = '120px';
            placeholder.style.height = '120px';
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
                }
            });

            container.addEventListener('dragover', e => {
                e.preventDefault();
                const target = e.target.closest('div[draggable]');
                if (!target || target === dragEl) return;

                const rect = target.getBoundingClientRect();
                const after = (e.clientY - rect.top) > rect.height / 2;
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
                }
            });
        }


        // === Initialization ===
        document.addEventListener('DOMContentLoaded', () => {
            // Navigation buttons
            $$('.btn-next').forEach(btn => btn.addEventListener('click', nextStep));
            $$('.btn-prev').forEach(btn => btn.addEventListener('click', prevStep));
            showStep(currentStep);

            const skillsList = $('#skillsList');
            const compList = $('#competenciesList');
            const projList = $('#projectsList');
            const criteriaList = $('#criteriaList');
            const progressMap = $('#progressMap');

            $('#addSkillBtn').addEventListener('click', () => skillsList.appendChild(createSkillRow()));
            $('#addCompetencyBtn').addEventListener('click', () => compList.appendChild(createCompetencyRow()));
            $('#addProjectBtn').addEventListener('click', () => projList.appendChild(createProjectCard()));
            $('#addCriterionBtn').addEventListener('click', () => criteriaList.appendChild(createCriterionRow()));
            $('#addProgressPointBtn').addEventListener('click', () => progressMap.appendChild(createProgressPoint()));
            enableProgressDragAndDrop(progressMap);

            // Load server data
            <?php if ($user): ?>
                const serverData = {
                    skills: <?= json_encode($skills ?? []) ?>,
                    competencies: <?= json_encode($competencies ?? []) ?>,
                    projects: <?= json_encode($projects ?? []) ?>,
                    criteria: <?= json_encode(array_column($criteria ?? [], 'criterion')) ?>,
                    progress_points: <?= json_encode($progress ?? []) ?>
                };
                serverData.skills.forEach(s => skillsList.appendChild(createSkillRow(s.name, s.level)));
                skillIndex = serverData.skills.length;

                serverData.competencies.forEach(c => compList.appendChild(createCompetencyRow(c.name, c.level, c.artifact_url, c.type)));
                compIndex = serverData.competencies.length;

                serverData.projects.forEach(p => projList.appendChild(createProjectCard(p)));
                projectIndex = serverData.projects.length;

                serverData.criteria.forEach(c => criteriaList.appendChild(createCriterionRow(c)));
                criterionIndex = serverData.criteria.length;

                serverData.progress_points.forEach(p => progressMap.appendChild(createProgressPoint(p)));
                progressIndex = serverData.progress_points.length;

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

            // Avatar preview
            // Жёсткий фикс превью аватара — работает даже в Chrome
            const avatarInput = document.getElementById('avatarUpload');
            const avatarPreview = document.getElementById('avatarPreview');

            if (avatarInput && avatarPreview) {
                avatarInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (!file) {
                        // Если файл убрали — возвращаем старую картинку (или плейсхолдер)
                        avatarPreview.src = avatarPreview.dataset.original || "https://placehold.co/120x120/0055A4/FFFFFF?text=Аватар";
                        return;
                    }

                    // Проверка размера
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

                    // САМОЕ ГЛАВНОЕ: принудительно ломаем кэш
                    const newSrc = URL.createObjectURL(file) + "#" + Date.now();

                    // Принудительно сбрасываем src (важно!)
                    avatarPreview.src = "";
                    avatarPreview.src = newSrc;

                    // Очистка памяти
                    avatarPreview.onload = () => {
                        URL.revokeObjectURL(newSrc.split("#")[0]);
                    };
                });

                // Сохраняем оригинальный src, чтобы можно было сбросить
                avatarPreview.dataset.original = avatarPreview.src;
            }

            // Weakness "другое"
            // Найдём все радиокнопки по имени — безопасно, даже если их нет.
            const radios = document.querySelectorAll('input[name="weakness"]');
            const otherInput = document.getElementById('weakness_other');

            // Защита: если нет поля для 'другое', прекращаем выполнение.
            if (!otherInput) return;

            // Функция, которая обновляет видимость/disabled состояния поля 'other'
            function updateOtherVisibility() {
                // Найдём выбранную радиокнопку
                let selected = null;
                if (radios && radios.length > 0) {
                    radios.forEach(r => {
                        if (r.checked) selected = r.value;
                    });
                }

                if (selected === 'другое') {
                    // показать и включить поле
                    otherInput.classList.remove('hidden');
                    otherInput.removeAttribute('disabled');
                    // если поле пустое — можно поставить фокус
                    // otherInput.focus();
                } else {
                    // скрыть и отключить поле, но НЕ затирать value (чтобы не потерять данные до отправки)
                    otherInput.classList.add('hidden');
                    otherInput.setAttribute('disabled', 'disabled');
                }
            }

            // Подвесим обработчики на все радиокнопки — если их нет, всё тихо.
            if (radios && radios.length > 0) {
                radios.forEach(r => r.addEventListener('change', updateOtherVisibility));
            }

            // Вызовем один раз при загрузке, чтобы выставить правильное состояние
            updateOtherVisibility();

        });
    </script>
</body>

</html>