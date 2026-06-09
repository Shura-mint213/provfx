<?php
require_once __DIR__ . '/../../init.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];

// Получаем роль текущего пользователя
$stmt = $pdo->prepare("SELECT role FROM students WHERE student_id = ?");
$stmt->execute([$currentUserId]);
$currentUserRole = $stmt->fetchColumn();

if ($currentUserRole !== 'admin') {
    http_response_code(403);
    die("Доступ ограничен");
}

$format = strtolower($_GET['format'] ?? '');
if (!in_array($format, ['xlsx', 'xls', 'csv', 'json', 'xml'])) {
    http_response_code(400);
    die("Некорректный формат экспорта");
}

// Загрузка всех необходимых данных без паролей и аватаров
// Студенты
$stmt = $pdo->query("SELECT student_id, zachetka, direction, group_number, semester, department, about, hobbies, soft_skills, weakness, smart_goal, deadline, is_published, role, created_at, login FROM students ORDER BY student_id ASC");
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Индексируем студентов по id для сборки связей
$studentsMap = [];
foreach ($students as $s) {
    $s['skills'] = [];
    $s['competencies'] = [];
    $s['criteria'] = [];
    $s['projects'] = [];
    $s['progress_points'] = [];
    $s['reflections'] = [];
    $studentsMap[(int)$s['student_id']] = $s;
}

if (!empty($studentsMap)) {
    $studentIds = array_keys($studentsMap);
    $inClause = implode(',', $studentIds);

    // Навыки
    $skills = $pdo->query("SELECT student_id, name, level FROM skills WHERE student_id IN ($inClause) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($skills as $sk) {
        $sid = (int)$sk['student_id'];
        if (isset($studentsMap[$sid])) {
            $studentsMap[$sid]['skills'][] = [
                'name' => $sk['name'],
                'level' => (int)$sk['level']
            ];
        }
    }

    // Компетенции
    $competencies = $pdo->query("SELECT student_id, name, level, artifact_url, type FROM competencies WHERE student_id IN ($inClause) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($competencies as $c) {
        $sid = (int)$c['student_id'];
        if (isset($studentsMap[$sid])) {
            $studentsMap[$sid]['competencies'][] = [
                'name' => $c['name'],
                'level' => (int)$c['level'],
                'type' => $c['type'],
                'artifact_url' => $c['artifact_url']
            ];
        }
    }

    // Критерии успеха
    $criteria = $pdo->query("SELECT student_id, criterion FROM criteria WHERE student_id IN ($inClause) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($criteria as $cr) {
        $sid = (int)$cr['student_id'];
        if (isset($studentsMap[$sid])) {
            $studentsMap[$sid]['criteria'][] = $cr['criterion'];
        }
    }

    // Прогресс (Карта Тэррелл)
    $progress = $pdo->query("SELECT student_id, name, type, comment, position FROM terrell_points WHERE student_id IN ($inClause) ORDER BY position ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($progress as $p) {
        $sid = (int)$p['student_id'];
        if (isset($studentsMap[$sid])) {
            $studentsMap[$sid]['progress_points'][] = [
                'name' => $p['name'],
                'type' => $p['type'],
                'comment' => $p['comment'],
                'position' => (int)$p['position']
            ];
        }
    }

    // Рефлексия
    $reflections = $pdo->query("SELECT student_id, what_worked, what_failed, changes FROM reflections WHERE student_id IN ($inClause) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reflections as $r) {
        $sid = (int)$r['student_id'];
        if (isset($studentsMap[$sid])) {
            $studentsMap[$sid]['reflections'][] = [
                'what_worked' => $r['what_worked'],
                'what_failed' => $r['what_failed'],
                'changes' => $r['changes']
            ];
        }
    }

    // Проекты
    $projects = $pdo->query("
        SELECT p.id as project_id, p.student_id as owner_id, p.name, p.description, p.tech_stack, p.role, p.repo_url, p.status, pm.student_id as member_id
        FROM projects p
        INNER JOIN project_members pm ON p.id = pm.project_id
        WHERE pm.student_id IN ($inClause)
        ORDER BY p.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($projects as $pr) {
        $mid = (int)$pr['member_id'];
        if (isset($studentsMap[$mid])) {
            // Добавляем проект в список проектов студента
            $studentsMap[$mid]['projects'][] = [
                'project_id' => (int)$pr['project_id'],
                'name' => $pr['name'],
                'description' => $pr['description'],
                'tech_stack' => $pr['tech_stack'],
                'role' => $pr['role'],
                'repo_url' => $pr['repo_url'],
                'status' => $pr['status'],
                'is_owner' => ((int)$pr['owner_id'] === $mid)
            ];
        }
    }
}

$data = array_values($studentsMap);

// Генерация ответа в зависимости от формата
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=studtracker_export_' . date('Y-m-d') . '.json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($format === 'xml') {
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename=studtracker_export_' . date('Y-m-d') . '.xml');

    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><students></students>');
    
    function array_to_xml($array, &$xmlElement) {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (is_numeric($key)) {
                    $subnode = $xmlElement->addChild('item');
                    array_to_xml($value, $subnode);
                } else {
                    $subnode = $xmlElement->addChild($key);
                    array_to_xml($value, $subnode);
                }
            } else {
                $xmlElement->addChild("$key", htmlspecialchars("$value"));
            }
        }
    }

    array_to_xml($data, $xml);
    echo $xml->asXML();
    exit;
}

// Плоские форматы: CSV, XLS, XLSX
$columns = [
    'student_id' => 'ID',
    'login' => 'Логин',
    'zachetka' => 'Номер зачётки',
    'direction' => 'Направление',
    'group_number' => 'Группа',
    'semester' => 'Семестр',
    'department' => 'Кафедра',
    'about' => 'О себе',
    'hobbies' => 'Хобби',
    'soft_skills' => 'Софт-скиллы',
    'weakness' => 'Слабая сторона',
    'smart_goal' => 'SMART-цель',
    'deadline' => 'Дедлайн цели',
    'is_published' => 'Опубликован',
    'role' => 'Роль в системе',
    'created_at' => 'Дата создания'
];

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=studtracker_export_' . date('Y-m-d') . '.csv');
    
    // Вывод BOM для Excel
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    // Заголовки
    fputcsv($output, array_values($columns));
    
    // Данные
    foreach ($data as $row) {
        $csvRow = [];
        foreach (array_keys($columns) as $col) {
            $val = $row[$col] ?? '';
            if ($col === 'is_published') {
                $val = $val ? 'Да' : 'Нет';
            }
            $csvRow[] = $val;
        }
        fputcsv($output, $csvRow);
    }
    fclose($output);
    exit;
}

if ($format === 'xls' || $format === 'xlsx') {
    // XLSX/XLS генерируется как HTML Excel-таблица
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=studtracker_export_' . date('Y-m-d') . '.' . $format);
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8" /></head>';
    echo '<body>';
    echo '<table border="1">';
    
    // Шапка
    echo '<tr style="background-color: #f1f5f9; font-weight: bold;">';
    foreach ($columns as $label) {
        echo '<th>' . htmlspecialchars($label) . '</th>';
    }
    echo '</tr>';
    
    // Строки
    foreach ($data as $row) {
        echo '<tr>';
        foreach (array_keys($columns) as $col) {
            $val = $row[$col] ?? '';
            if ($col === 'is_published') {
                $val = $val ? 'Да' : 'Нет';
            }
            echo '<td>' . htmlspecialchars($val) . '</td>';
        }
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit;
}
