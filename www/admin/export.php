<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../api/oauth_helper.php';

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

// Проверяем, был ли запрошен экспорт
$format = strtolower($_REQUEST['format'] ?? '');
$isExportRequested = in_array($format, ['json', 'xml', 'csv', 'xlsx']);

if ($isExportRequested) {
    // Получаем параметры выбора таблиц
    $export_students = isset($_REQUEST['export_students']);
    $export_skills = isset($_REQUEST['export_skills']);
    $export_competencies = isset($_REQUEST['export_competencies']);
    $export_criteria = isset($_REQUEST['export_criteria']);
    $export_terrell = isset($_REQUEST['export_terrell']);
    $export_reflections = isset($_REQUEST['export_reflections']);
    $export_projects = isset($_REQUEST['export_projects']);
    $export_members = isset($_REQUEST['export_members']);
    $export_ratings = isset($_REQUEST['export_ratings']);
    $export_invitations = isset($_REQUEST['export_invitations']);
    $export_blocks = isset($_REQUEST['export_blocks']);
    
    $export_github_username = isset($_REQUEST['export_github_username']);
    $export_github_token = isset($_REQUEST['export_github_token']);
    $export_github_token_confirm = isset($_REQUEST['export_github_token_confirm']);

    $export_gitlab_username = isset($_REQUEST['export_gitlab_username']);
    $export_gitlab_token = isset($_REQUEST['export_gitlab_token']);
    $export_gitlab_token_confirm = isset($_REQUEST['export_gitlab_token_confirm']);

    // Получаем фильтры
    $filter_date_from = trim($_REQUEST['date_from'] ?? '');
    $filter_date_to = trim($_REQUEST['date_to'] ?? '');
    $filter_published_only = isset($_REQUEST['published_only']);
    $filter_student = trim($_REQUEST['student_query'] ?? '');

    // Конструируем запрос к таблице студентов
    $fields = ['student_id', 'login'];
    if ($export_students) {
        $fields = array_merge($fields, [
            'zachetka', 'direction', 'group_number', 'semester', 
            'department', 'about', 'hobbies', 'soft_skills', 
            'weakness', 'smart_goal', 'deadline', 'is_published', 
            'role', 'created_at'
        ]);
    }
    if ($export_github_username) {
        $fields[] = 'github_username';
    }
    if ($export_github_token && $export_github_token_confirm) {
        $fields[] = 'github_token';
    }

    $fieldsSql = implode(', ', array_unique($fields));

    $where = [];
    $queryParams = [];

    if (!empty($filter_date_from)) {
        $where[] = "created_at >= :date_from";
        $queryParams[':date_from'] = $filter_date_from . ' 00:00:00';
    }
    if (!empty($filter_date_to)) {
        $where[] = "created_at <= :date_to";
        $queryParams[':date_to'] = $filter_date_to . ' 23:59:59';
    }
    if ($filter_published_only) {
        $where[] = "is_published = 1";
    }
    if (!empty($filter_student)) {
        if (is_numeric($filter_student)) {
            $where[] = "(student_id = :student || zachetka = :student)";
        } else {
            $where[] = "zachetka = :student";
        }
        $queryParams[':student'] = $filter_student;
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = "WHERE " . implode(" AND ", $where);
    }

    $sql = "SELECT $fieldsSql FROM students $whereSql ORDER BY student_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($queryParams);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Сбор всех связанных данных
    $studentIds = array_column($students, 'student_id');
    
    $skills = [];
    $competencies = [];
    $criteria = [];
    $progress = [];
    $reflections = [];
    $projects = [];
    $members = [];
    $ratings = [];
    $invitations = [];
    $userBlocks = [];

    if (!empty($studentIds)) {
        $inClause = implode(',', array_map('intval', $studentIds));

        // Загружаем интеграции из новой таблицы
        $stmtInt = $pdo->query("SELECT * FROM user_integrations WHERE student_id IN ($inClause)");
        $integrations = [];
        while ($rowInt = $stmtInt->fetch(PDO::FETCH_ASSOC)) {
            $integrations[$rowInt['student_id']][$rowInt['platform']] = $rowInt;
        }

        if ($export_skills) {
            $skills = $pdo->query("SELECT student_id, name, level FROM skills WHERE student_id IN ($inClause) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($export_competencies) {
            $competencies = $pdo->query("SELECT student_id, name, level, artifact_url, type FROM competencies WHERE student_id IN ($inClause) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($export_criteria) {
            $criteria = $pdo->query("SELECT student_id, criterion FROM criteria WHERE student_id IN ($inClause) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($export_terrell) {
            $progress = $pdo->query("SELECT student_id, name, type, comment, position FROM terrell_points WHERE student_id IN ($inClause) ORDER BY position ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($export_reflections) {
            $reflections = $pdo->query("SELECT student_id, what_worked, what_failed, changes, created_at FROM reflections WHERE student_id IN ($inClause) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($export_projects) {
            $projects = $pdo->query("
                SELECT DISTINCT p.id, p.student_id as owner_id, p.name, p.description, p.tech_stack, p.role, p.repo_url, p.status
                FROM projects p
                LEFT JOIN project_members pm ON p.id = pm.project_id
                WHERE p.student_id IN ($inClause) OR pm.student_id IN ($inClause)
                ORDER BY p.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $projectIds = array_column($projects, 'id');
            if (!empty($projectIds)) {
                $projInClause = implode(',', array_map('intval', $projectIds));

                if ($export_members) {
                    $members = $pdo->query("
                        SELECT pm.project_id, pm.student_id, pm.role, pm.added_at, s.login 
                        FROM project_members pm 
                        LEFT JOIN students s ON pm.student_id = s.student_id 
                        WHERE pm.project_id IN ($projInClause)
                        ORDER BY pm.id ASC
                    ")->fetchAll(PDO::FETCH_ASSOC);
                }

                if ($export_ratings) {
                    $ratings = $pdo->query("
                        SELECT pr.project_id, pr.rater_id, pr.ratee_id, pr.rating, pr.comment, pr.created_at, rater.login as rater_login, ratee.login as ratee_login 
                        FROM project_ratings pr 
                        LEFT JOIN students rater ON pr.rater_id = rater.student_id 
                        LEFT JOIN students ratee ON pr.ratee_id = ratee.student_id 
                        WHERE pr.project_id IN ($projInClause)
                        ORDER BY pr.id ASC
                    ")->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }

        if ($export_invitations) {
            $invWhere = ["pi.sender_id IN ($inClause)", "pi.receiver_id IN ($inClause)"];
            if (isset($projInClause)) {
                $invWhere[] = "pi.project_id IN ($projInClause)";
            }
            $invWhereSql = implode(" OR ", $invWhere);

            $invitations = $pdo->query("
                SELECT pi.project_id, pi.sender_id, pi.receiver_id, pi.status, pi.is_read, pi.created_at, p.name as project_name, sender.login as sender_login, receiver.login as receiver_login 
                FROM project_invitations pi 
                LEFT JOIN projects p ON pi.project_id = p.id 
                LEFT JOIN students sender ON pi.sender_id = sender.student_id 
                LEFT JOIN students receiver ON pi.receiver_id = receiver.student_id 
                WHERE $invWhereSql
                ORDER BY pi.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($export_blocks) {
            $userBlocks = $pdo->query("
                SELECT ub.*, blocker.login as blocker_login, blocked.login as blocked_login 
                FROM user_blocks ub 
                LEFT JOIN students blocker ON ub.blocker_id = blocker.student_id 
                LEFT JOIN students blocked ON ub.blocked_id = blocked.student_id 
                WHERE ub.blocker_id IN ($inClause) OR ub.blocked_id IN ($inClause)
                ORDER BY ub.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Сборка структуры данных
    $studentsMap = [];
    foreach ($students as $s) {
        $sid = (int)$s['student_id'];
        
        // Наполняем данные GitHub
        $githubInt = $integrations[$sid]['github'] ?? null;
        if ($export_github_username) {
            $s['github_username'] = $githubInt['username'] ?? $s['github_username'] ?? '';
        }
        if ($export_github_token && $export_github_token_confirm) {
            if ($githubInt && !empty($githubInt['access_token'])) {
                $s['github_token'] = decryptToken($githubInt['access_token']);
            } else {
                $s['github_token'] = isset($s['github_token']) ? decryptGithubToken($s['github_token']) : '';
            }
        }

        // Наполняем данные GitLab
        $gitlabInt = $integrations[$sid]['gitlab'] ?? null;
        if ($export_gitlab_username) {
            $s['gitlab_username'] = $gitlabInt['username'] ?? '';
        }
        if ($export_gitlab_token && $export_gitlab_token_confirm) {
            if ($gitlabInt && !empty($gitlabInt['access_token'])) {
                $s['gitlab_token'] = decryptToken($gitlabInt['access_token']);
            } else {
                $s['gitlab_token'] = '';
            }
        }
        
        $studentsMap[$sid] = $s;
        
        if ($export_skills) $studentsMap[$sid]['skills'] = [];
        if ($export_competencies) $studentsMap[$sid]['competencies'] = [];
        if ($export_criteria) $studentsMap[$sid]['criteria'] = [];
        if ($export_terrell) $studentsMap[$sid]['progress_points'] = [];
        if ($export_reflections) $studentsMap[$sid]['reflections'] = [];
        if ($export_projects) $studentsMap[$sid]['projects'] = [];
        if ($export_invitations) {
            $studentsMap[$sid]['sent_invitations'] = [];
            $studentsMap[$sid]['received_invitations'] = [];
        }
        if ($export_blocks) {
            $studentsMap[$sid]['blocked_users'] = [];
            $studentsMap[$sid]['blocked_by_users'] = [];
        }
    }

    // Наполнение связей
    if ($export_skills && !empty($skills)) {
        foreach ($skills as $sk) {
            $sid = (int)$sk['student_id'];
            if (isset($studentsMap[$sid])) {
                $studentsMap[$sid]['skills'][] = [
                    'name' => $sk['name'],
                    'level' => (int)$sk['level']
                ];
            }
        }
    }

    if ($export_competencies && !empty($competencies)) {
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
    }

    if ($export_criteria && !empty($criteria)) {
        foreach ($criteria as $cr) {
            $sid = (int)$cr['student_id'];
            if (isset($studentsMap[$sid])) {
                $studentsMap[$sid]['criteria'][] = $cr['criterion'];
            }
        }
    }

    if ($export_terrell && !empty($progress)) {
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
    }

    if ($export_reflections && !empty($reflections)) {
        foreach ($reflections as $r) {
            $sid = (int)$r['student_id'];
            if (isset($studentsMap[$sid])) {
                $studentsMap[$sid]['reflections'][] = [
                    'what_worked' => $r['what_worked'],
                    'what_failed' => $r['what_failed'],
                    'changes' => $r['changes'],
                    'created_at' => $r['created_at']
                ];
            }
        }
    }

    if ($export_projects && !empty($projects)) {
        $projectsMap = [];
        foreach ($projects as $pr) {
            $pid = (int)$pr['id'];
            $projectsMap[$pid] = [
                'project_id' => $pid,
                'owner_id' => (int)$pr['owner_id'],
                'name' => $pr['name'],
                'description' => $pr['description'],
                'tech_stack' => $pr['tech_stack'],
                'role' => $pr['role'],
                'repo_url' => $pr['repo_url'],
                'status' => $pr['status'],
                'members' => [],
                'ratings' => []
            ];
        }

        if ($export_members && !empty($members)) {
            foreach ($members as $m) {
                $pid = (int)$m['project_id'];
                if (isset($projectsMap[$pid])) {
                    $projectsMap[$pid]['members'][] = [
                        'student_id' => (int)$m['student_id'],
                        'login' => $m['login'],
                        'role' => $m['role'],
                        'added_at' => $m['added_at']
                    ];
                }
            }
        }

        if ($export_ratings && !empty($ratings)) {
            foreach ($ratings as $rt) {
                $pid = (int)$rt['project_id'];
                if (isset($projectsMap[$pid])) {
                    $projectsMap[$pid]['ratings'][] = [
                        'rater_id' => (int)$rt['rater_id'],
                        'rater_login' => $rt['rater_login'],
                        'ratee_id' => (int)$rt['ratee_id'],
                        'ratee_login' => $rt['ratee_login'],
                        'rating' => (int)$rt['rating'],
                        'comment' => $rt['comment'],
                        'created_at' => $rt['created_at']
                    ];
                }
            }
        }

        // Связываем проекты с участниками
        if (empty($members) && !empty($projectsMap)) {
            $pIds = array_keys($projectsMap);
            $pIdsClause = implode(',', $pIds);
            $members = $pdo->query("SELECT project_id, student_id FROM project_members WHERE project_id IN ($pIdsClause)")->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($members)) {
            foreach ($members as $m) {
                $pid = (int)$m['project_id'];
                $sid = (int)$m['student_id'];
                if (isset($studentsMap[$sid]) && isset($projectsMap[$pid])) {
                    $pCopy = $projectsMap[$pid];
                    $pCopy['is_owner'] = ($pCopy['owner_id'] === $sid) ? 1 : 0;
                    $studentsMap[$sid]['projects'][] = $pCopy;
                }
            }
        }
    }

    if ($export_invitations && !empty($invitations)) {
        foreach ($invitations as $inv) {
            $senderId = (int)$inv['sender_id'];
            $receiverId = (int)$inv['receiver_id'];

            $item = [
                'project_id' => (int)$inv['project_id'],
                'project_name' => $inv['project_name'],
                'sender_id' => $senderId,
                'sender_login' => $inv['sender_login'],
                'receiver_id' => $receiverId,
                'receiver_login' => $inv['receiver_login'],
                'status' => $inv['status'],
                'is_read' => (bool)$inv['is_read'],
                'created_at' => $inv['created_at']
            ];

            if (isset($studentsMap[$senderId])) {
                $studentsMap[$senderId]['sent_invitations'][] = $item;
            }
            if (isset($studentsMap[$receiverId])) {
                $studentsMap[$receiverId]['received_invitations'][] = $item;
            }
        }
    }

    if ($export_blocks && !empty($userBlocks)) {
        foreach ($userBlocks as $ub) {
            $blockerId = (int)$ub['blocker_id'];
            $blockedId = (int)$ub['blocked_id'];

            $item = [
                'blocker_id' => $blockerId,
                'blocker_login' => $ub['blocker_login'],
                'blocked_id' => $blockedId,
                'blocked_login' => $ub['blocked_login'],
                'block_level' => (int)$ub['block_level'],
                'comment' => $ub['comment'],
                'created_at' => $ub['created_at']
            ];

            if (isset($studentsMap[$blockerId])) {
                $studentsMap[$blockerId]['blocked_users'][] = $item;
            }
            if (isset($studentsMap[$blockedId])) {
                $studentsMap[$blockedId]['blocked_by_users'][] = $item;
            }
        }
    }

    $exportData = array_values($studentsMap);

    // Генерация файлов ответов
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=studtracker_export_' . date('Y-m-d') . '.json');
        echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($format === 'xml') {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename=studtracker_export_' . date('Y-m-d') . '.xml');

        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><students></students>');

        function array_to_xml($array, &$xmlElement) {
            $singularMap = [
                'students' => 'student',
                'skills' => 'skill',
                'competencies' => 'competency',
                'criteria' => 'criterion',
                'progress_points' => 'point',
                'reflections' => 'reflection',
                'projects' => 'project',
                'members' => 'member',
                'ratings' => 'rating',
                'sent_invitations' => 'invitation',
                'received_invitations' => 'invitation',
                'blocked_users' => 'block',
                'blocked_by_users' => 'block',
            ];

            foreach ($array as $key => $value) {
                if (is_array($value)) {
                    if (is_numeric($key)) {
                        $parentName = $xmlElement->getName();
                        $nodeName = $singularMap[$parentName] ?? 'item';
                        $subnode = $xmlElement->addChild($nodeName);
                        array_to_xml($value, $subnode);
                    } else {
                        $subnode = $xmlElement->addChild($key);
                        array_to_xml($value, $subnode);
                    }
                } else {
                    if (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    }
                    $xmlElement->addChild("$key", htmlspecialchars("$value"));
                }
            }
        }

        array_to_xml($exportData, $xml);
        echo $xml->asXML();
        exit;
    }

    // Для плоских форматов (CSV / XLSX) собираем плоские колонки и данные
    $columns = [
        'student_id' => 'ID',
        'login' => 'Логин'
    ];

    if ($export_students) {
        $columns = array_merge($columns, [
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
        ]);
    }

    if ($export_github_username) {
        $columns['github_username'] = 'GitHub Username';
    }

    if ($export_github_token && $export_github_token_confirm) {
        $columns['github_token'] = 'GitHub Токен';
    }

    if ($export_gitlab_username) {
        $columns['gitlab_username'] = 'GitLab Username';
    }

    if ($export_gitlab_token && $export_gitlab_token_confirm) {
        $columns['gitlab_token'] = 'GitLab Токен';
    }

    if ($export_skills) $columns['skills'] = 'Навыки';
    if ($export_competencies) $columns['competencies'] = 'Компетенции';
    if ($export_criteria) $columns['criteria'] = 'Критерии успеха';
    if ($export_terrell) $columns['progress_points'] = 'Точки прогресса';
    if ($export_reflections) $columns['reflections'] = 'Рефлексии';
    if ($export_projects) {
        $columns['projects'] = 'Проекты';
        if ($export_members) $columns['project_members_summary'] = 'Участники проектов';
        if ($export_ratings) $columns['project_ratings_summary'] = 'Оценки проектов';
    }
    if ($export_invitations) $columns['invitations_summary'] = 'Приглашения';
    if ($export_blocks) $columns['blocks_summary'] = 'Блокировки';

    // Формируем плоские строки
    $flatData = [];
    foreach ($exportData as $row) {
        $flatRow = [];
        
        // Базовые поля
        foreach ($row as $k => $v) {
            if (!is_array($v)) {
                $flatRow[$k] = $v;
            }
        }
        
        // Навыки
        if ($export_skills) {
            $skillsStrs = [];
            foreach ($row['skills'] as $sk) {
                $skillsStrs[] = "{$sk['name']} (Уровень {$sk['level']})";
            }
            $flatRow['skills'] = implode("; ", $skillsStrs);
        }

        // Компетенции
        if ($export_competencies) {
            $compStrs = [];
            foreach ($row['competencies'] as $c) {
                $compStrs[] = "{$c['name']} (Уровень {$c['level']}, {$c['type']})" . ($c['artifact_url'] ? " [{$c['artifact_url']}]" : "");
            }
            $flatRow['competencies'] = implode("; ", $compStrs);
        }

        // Критерии успеха
        if ($export_criteria) {
            $flatRow['criteria'] = implode("; ", $row['criteria']);
        }

        // Точки прогресса
        if ($export_terrell) {
            $progStrs = [];
            foreach ($row['progress_points'] as $p) {
                $progStrs[] = "{$p['name']} ({$p['type']})" . ($p['comment'] ? " - {$p['comment']}" : "");
            }
            $flatRow['progress_points'] = implode("; ", $progStrs);
        }

        // Рефлексии
        if ($export_reflections) {
            $refStrs = [];
            foreach ($row['reflections'] as $r) {
                $refStrs[] = "Сработало: {$r['what_worked']} | Ошибки: {$r['what_failed']} | Изменения: {$r['changes']}";
            }
            $flatRow['reflections'] = implode("; ", $refStrs);
        }

        // Проекты
        if ($export_projects) {
            $projStrs = [];
            foreach ($row['projects'] as $p) {
                $projStrs[] = "{$p['name']} (Роль: {$p['role']}, Статус: {$p['status']})" . ($p['is_owner'] ? " [Владелец]" : "");
            }
            $flatRow['projects'] = implode("; ", $projStrs);

            if ($export_members) {
                $memberStrs = [];
                foreach ($row['projects'] as $p) {
                    if (!empty($p['members'])) {
                        $mList = [];
                        foreach ($p['members'] as $m) {
                            $mList[] = "{$m['login']} ({$m['role']})";
                        }
                        $memberStrs[] = "В проекте '{$p['name']}': " . implode(", ", $mList);
                    }
                }
                $flatRow['project_members_summary'] = implode("; ", $memberStrs);
            }

            if ($export_ratings) {
                $ratingStrs = [];
                foreach ($row['projects'] as $p) {
                    if (!empty($p['ratings'])) {
                        $rList = [];
                        foreach ($p['ratings'] as $rt) {
                            $rList[] = "{$rt['rater_login']} -> {$rt['ratee_login']}: {$rt['rating']}★" . ($rt['comment'] ? " ({$rt['comment']})" : "");
                        }
                        $ratingStrs[] = "В проекте '{$p['name']}': " . implode(", ", $rList);
                    }
                }
                $flatRow['project_ratings_summary'] = implode("; ", $ratingStrs);
            }
        }

        // Приглашения
        if ($export_invitations) {
            $invStrs = [];
            if (!empty($row['sent_invitations'])) {
                $sent = [];
                foreach ($row['sent_invitations'] as $inv) {
                    $sent[] = "проект '{$inv['project_name']}' для {$inv['receiver_login']} ({$inv['status']})";
                }
                $invStrs[] = "Отправлено: " . implode(", ", $sent);
            }
            if (!empty($row['received_invitations'])) {
                $rcvd = [];
                foreach ($row['received_invitations'] as $inv) {
                    $rcvd[] = "в проект '{$inv['project_name']}' от {$inv['sender_login']} ({$inv['status']})";
                }
                $invStrs[] = "Получено: " . implode(", ", $rcvd);
            }
            $flatRow['invitations_summary'] = implode("; ", $invStrs);
        }

        // Блокировки
        if ($export_blocks) {
            $blockStrs = [];
            if (!empty($row['blocked_users'])) {
                $blkd = [];
                foreach ($row['blocked_users'] as $ub) {
                    $blkd[] = "{$ub['blocked_login']}" . ($ub['comment'] ? " ({$ub['comment']})" : "");
                }
                $blockStrs[] = "Заблокировал: " . implode(", ", $blkd);
            }
            if (!empty($row['blocked_by_users'])) {
                $blkdBy = [];
                foreach ($row['blocked_by_users'] as $ub) {
                    $blkdBy[] = "{$ub['blocker_login']}" . ($ub['comment'] ? " ({$ub['comment']})" : "");
                }
                $blockStrs[] = "Заблокирован пользователями: " . implode(", ", $blkdBy);
            }
            $flatRow['blocks_summary'] = implode("; ", $blockStrs);
        }

        $flatData[] = $flatRow;
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=studtracker_export_' . date('Y-m-d') . '.csv');
        
        echo "\xEF\xBB\xBF"; // BOM
        $output = fopen('php://output', 'w');
        
        fputcsv($output, array_values($columns));
        
        foreach ($flatData as $row) {
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

    if ($format === 'xlsx') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=studtracker_export_' . date('Y-m-d') . '.xlsx');
        
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
        foreach ($flatData as $row) {
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
}

// По умолчанию (если экспорт не запрошен) рендерим красивый интерфейс селектора
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Панель администратора — Экспорт данных</title>
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
                <a href="users.php" class="bg-white/10 hover:bg-white/20 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition duration-200"><i class="fa-solid fa-users mr-2"></i>Пользователи</a>
                <a href="../logout.php" class="text-white/80 hover:text-white text-sm hover:underline">Выйти</a>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-6 py-10 max-w-4xl">
        <div class="bg-white rounded-3xl shadow-xl border border-slate-100 overflow-hidden">
            <div class="px-8 py-6 border-b border-slate-100 bg-slate-50/50 flex items-center space-x-3">
                <i class="fa-solid fa-file-export text-indigo-600 text-2xl"></i>
                <div>
                    <h2 class="text-xl font-bold text-slate-800">Экспорт данных из базы</h2>
                    <p class="text-xs text-slate-400">Настройте параметры выгрузки информации</p>
                </div>
            </div>

            <form method="POST" action="export.php" class="p-8 space-y-8">
                <!-- Раздел выбора таблиц -->
                <div>
                    <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4"><i class="fa-solid fa-table-list mr-2"></i> Таблицы и разделы</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_students" checked class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">Студенты</span>
                                <p class="text-xs text-slate-400">Основная информация, цели, направления</p>
                            </div>
                        </label>

                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_skills" checked class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">Навыки (skills)</span>
                                <p class="text-xs text-slate-400">Список хард/софт навыков и их уровней</p>
                            </div>
                        </label>

                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_competencies" checked class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">Компетенции (competencies)</span>
                                <p class="text-xs text-slate-400">Компетенции с подтверждающими ссылками</p>
                            </div>
                        </label>

                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_criteria" checked class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">Критерии успеха (criteria)</span>
                                <p class="text-xs text-slate-400">Метрики успешности поставленных целей</p>
                            </div>
                        </label>

                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_terrell" checked class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">Точки прогресса (terrell_points)</span>
                                <p class="text-xs text-slate-400">Достижения и кризисы на карте Тэррелла</p>
                            </div>
                        </label>

                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_reflections" checked class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">Рефлексии (reflections)</span>
                                <p class="text-xs text-slate-400">Отзывы студентов о своей работе</p>
                            </div>
                        </label>

                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_projects" checked class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">Проекты (projects)</span>
                                <p class="text-xs text-slate-400">Название, описание, стек и репозитории</p>
                            </div>
                        </label>

                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_members" checked class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">Участники проектов (project_members)</span>
                                <p class="text-xs text-slate-400">Связи студентов с командными проектами</p>
                            </div>
                        </label>

                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_ratings" checked class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">Оценки проектов (project_ratings)</span>
                                <p class="text-xs text-slate-400">Оценки и отзывы участников друг о друге</p>
                            </div>
                        </label>

                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_invitations" checked class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">Приглашения (project_invitations)</span>
                                <p class="text-xs text-slate-400">Статусы приглашений студентов в команды</p>
                            </div>
                        </label>

                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_blocks" checked class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">Блокировки (user_blocks)</span>
                                <p class="text-xs text-slate-400">Списки заблокированных связей и контактов</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Раздел GitHub данных -->
                <div>
                    <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4"><i class="fa-brands fa-github mr-2"></i> Данные интеграции с GitHub</h3>
                    <div class="space-y-4">
                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_github_username" class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">GitHub username</span>
                                <p class="text-xs text-slate-400">Логины привязанных GitHub аккаунтов</p>
                            </div>
                        </label>

                        <div class="p-3 bg-red-50/50 border border-red-100 rounded-2xl space-y-3">
                            <label class="flex items-start cursor-pointer">
                                <input type="checkbox" id="github_token" name="export_github_token" class="mt-1 rounded text-red-600 focus:ring-red-500 border-slate-300 w-4 h-4">
                                <div class="ml-3">
                                    <span class="text-sm font-bold text-red-700">GitHub токен (конфиденциально!)</span>
                                    <p class="text-xs text-red-500">Токены доступа к GitHub API. Экспортируйте с крайней осторожностью.</p>
                                </div>
                            </label>

                            <!-- Контейнер дополнительного подтверждения (по умолчанию скрыт) -->
                            <div id="github_warning_container" class="hidden pl-7 space-y-2 border-l-2 border-red-300 transition duration-200">
                                <div class="text-xs text-red-600 font-semibold bg-red-100/60 p-3 rounded-xl flex items-start">
                                    <i class="fa-solid fa-triangle-exclamation mr-2 mt-0.5 text-red-700"></i>
                                    <span>Внимание! Токены доступа будут выгружены в расшифрованном виде. Утечка файла экспорта может скомпрометировать профили пользователей.</span>
                                </div>
                                <label class="flex items-center space-x-2 cursor-pointer pt-1">
                                    <input type="checkbox" id="github_token_confirm" name="export_github_token_confirm" class="rounded text-red-600 focus:ring-red-500 border-red-300 w-3.5 h-3.5">
                                    <span class="text-xs font-bold text-red-700">Я понимаю риски безопасности и подтверждаю экспорт токенов</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Раздел GitLab данных -->
                <div>
                    <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4"><i class="fa-brands fa-gitlab mr-2"></i> Данные интеграции с GitLab</h3>
                    <div class="space-y-4">
                        <label class="flex items-start p-3 bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl cursor-pointer transition">
                            <input type="checkbox" name="export_gitlab_username" class="mt-1 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <div class="ml-3">
                                <span class="text-sm font-bold text-slate-700">GitLab username</span>
                                <p class="text-xs text-slate-400">Логины привязанных GitLab аккаунтов</p>
                            </div>
                        </label>

                        <div class="p-3 bg-red-50/50 border border-red-100 rounded-2xl space-y-3">
                            <label class="flex items-start cursor-pointer">
                                <input type="checkbox" id="gitlab_token" name="export_gitlab_token" class="mt-1 rounded text-red-600 focus:ring-red-500 border-slate-300 w-4 h-4">
                                <div class="ml-3">
                                    <span class="text-sm font-bold text-red-700">GitLab токен (конфиденциально!)</span>
                                    <p class="text-xs text-red-500">Токены доступа к GitLab API. Экспортируйте с крайней осторожностью.</p>
                                </div>
                            </label>

                            <!-- Контейнер дополнительного подтверждения (по умолчанию скрыт) -->
                            <div id="gitlab_warning_container" class="hidden pl-7 space-y-2 border-l-2 border-red-300 transition duration-200">
                                <div class="text-xs text-red-600 font-semibold bg-red-100/60 p-3 rounded-xl flex items-start">
                                    <i class="fa-solid fa-triangle-exclamation mr-2 mt-0.5 text-red-700"></i>
                                    <span>Внимание! Токены доступа будут выгружены в расшифрованном виде. Утечка файла экспорта может скомпрометировать профили пользователей.</span>
                                </div>
                                <label class="flex items-center space-x-2 cursor-pointer pt-1">
                                    <input type="checkbox" id="gitlab_token_confirm" name="export_gitlab_token_confirm" class="rounded text-red-600 focus:ring-red-500 border-red-300 w-3.5 h-3.5">
                                    <span class="text-xs font-bold text-red-700">Я понимаю риски безопасности и подтверждаю экспорт токенов</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Фильтры -->
                <div>
                    <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4"><i class="fa-solid fa-filter mr-2"></i> Фильтры</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-50/50 p-6 border border-slate-200/60 rounded-3xl">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Период регистрации (created_at)</label>
                            <div class="flex items-center space-x-2">
                                <input type="date" name="date_from" class="bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <span class="text-slate-400">—</span>
                                <input type="date" name="date_to" class="bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Конкретный студент (ID или зачётка)</label>
                            <input type="text" name="student_query" placeholder="Пример: 15 или 20-35" class="bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div class="md:col-span-2 pt-2">
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="published_only" class="rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                                <span class="ml-2.5 text-sm font-bold text-slate-700">Только опубликованные студенты (is_published=1)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Формат экспорта -->
                <div>
                    <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4"><i class="fa-solid fa-file-lines mr-2"></i> Формат выгрузки</h3>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center bg-white hover:bg-slate-50 px-5 py-3 border border-slate-200 rounded-2xl cursor-pointer transition shadow-sm">
                            <input type="radio" name="format" value="json" checked class="text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <span class="ml-3 font-mono font-bold text-slate-700">JSON</span>
                        </label>
                        
                        <label class="flex items-center bg-white hover:bg-slate-50 px-5 py-3 border border-slate-200 rounded-2xl cursor-pointer transition shadow-sm">
                            <input type="radio" name="format" value="xml" class="text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <span class="ml-3 font-mono font-bold text-slate-700">XML</span>
                        </label>

                        <label class="flex items-center bg-white hover:bg-slate-50 px-5 py-3 border border-slate-200 rounded-2xl cursor-pointer transition shadow-sm">
                            <input type="radio" name="format" value="csv" class="text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <span class="ml-3 font-mono font-bold text-slate-700">CSV</span>
                        </label>

                        <label class="flex items-center bg-white hover:bg-slate-50 px-5 py-3 border border-slate-200 rounded-2xl cursor-pointer transition shadow-sm">
                            <input type="radio" name="format" value="xlsx" class="text-indigo-600 focus:ring-indigo-500 border-slate-300 w-4 h-4">
                            <span class="ml-3 font-mono font-bold text-slate-700">XLSX</span>
                        </label>
                    </div>
                </div>

                <!-- Кнопка сабмита -->
                <div class="pt-4 flex justify-end">
                    <button type="submit" class="bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 text-white font-bold px-8 py-4 rounded-2xl shadow-lg shadow-indigo-100 hover:shadow-indigo-200 hover:-translate-y-0.5 transition duration-200 flex items-center">
                        <i class="fa-solid fa-circle-down mr-2.5 text-lg"></i> Скачать выбранные данные
                    </button>
                </div>
            </form>
        </div>
    </main>

    <footer class="text-center text-xs text-slate-400 py-10 mt-10 border-t border-slate-200 max-w-4xl mx-auto">
        © 2026 МГУТУ проект им МШ 2 • Панель управления треком МАИ
    </footer>

    <script>
        // Интерактивное управление предупреждением о токенах
        document.getElementById('github_token').addEventListener('change', function() {
            const warningContainer = document.getElementById('github_warning_container');
            if (this.checked) {
                warningContainer.classList.remove('hidden');
            } else {
                warningContainer.classList.add('hidden');
                document.getElementById('github_token_confirm').checked = false;
            }
        });

        document.getElementById('gitlab_token').addEventListener('change', function() {
            const warningContainer = document.getElementById('gitlab_warning_container');
            if (this.checked) {
                warningContainer.classList.remove('hidden');
            } else {
                warningContainer.classList.add('hidden');
                document.getElementById('gitlab_token_confirm').checked = false;
            }
        });

        // Блокируем отправку если токен выбран, но нет галочки подтверждения
        document.querySelector('form').addEventListener('submit', function(e) {
            const tokenCheckbox = document.getElementById('github_token');
            const confirmCheckbox = document.getElementById('github_token_confirm');
            
            if (tokenCheckbox.checked && !confirmCheckbox.checked) {
                e.preventDefault();
                alert('Пожалуйста, подтвердите согласие с рисками безопасности экспорта GitHub токенов, установив соответствующую галочку.');
                return;
            }

            const gitlabTokenCheckbox = document.getElementById('gitlab_token');
            const gitlabConfirmCheckbox = document.getElementById('gitlab_token_confirm');
            
            if (gitlabTokenCheckbox.checked && !gitlabConfirmCheckbox.checked) {
                e.preventDefault();
                alert('Пожалуйста, подтвердите согласие с рисками безопасности экспорта GitLab токенов, установив соответствующую галочку.');
            }
        });
    </script>
</body>

</html>
