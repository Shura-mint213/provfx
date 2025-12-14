<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../check_auth.php';

// Гарантия, что никакой HTML не попадёт в вывод
ob_start();

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Получение JSON или POST
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST ?? [];
}

$action = $input['action'] ?? null;
$viewerId = $_SESSION['user_id'] ?? null;

// Уровни блокировки:
// 1 — частичная пользовательская
// 2 — полная пользовательская
// 3 — админ частичная
// 4 — админ полная (бан)

function checkBlock($pdo, $blocker, $target)
{
    $stmt = $pdo->prepare("
        SELECT block_level 
        FROM user_blocks 
        WHERE blocker_id = ? AND blocked_id = ?
    ");
    $stmt->execute([$blocker, $target]);
    return $stmt->fetchColumn();
}

try {

    if (!$action) {
        throw new Exception('No action');
    }

    switch ($action) {

        /* ======================
           📌 ОТПРАВКА ПРИГЛАШЕНИЯ
           ====================== */
        case 'invite':

            if (!$viewerId) throw new Exception('AUTH_REQUIRED');

            $projectId = (int)($input['project_id'] ?? 0);
            $receiverId = (int)($input['receiver_id'] ?? 0);

            if (!$projectId || !$receiverId) throw new Exception('Invalid params');
            if ($receiverId === $viewerId) throw new Exception('Cannot invite yourself');

            // Получатель заблокировал отправителя
            $block = checkBlock($pdo, $receiverId, $viewerId);
            if ($block >= 1) {
                throw new Exception('User blocked you');
            }

            // Отправитель полностью заблокировал получателя
            $myBlock = checkBlock($pdo, $viewerId, $receiverId);
            if ($myBlock == 2) {
                throw new Exception('You cannot interact with this user');
            }

            // Проверка: отправитель участник проекта
            $stmt = $pdo->prepare("
                SELECT id 
                FROM project_members 
                WHERE project_id = ? AND student_id = ?
            ");
            $stmt->execute([$projectId, $viewerId]);
            if (!$stmt->fetch()) throw new Exception('Not project member');

            // Проверка: получатель уже участник
            $stmt = $pdo->prepare("
                SELECT id 
                FROM project_members 
                WHERE project_id = ? AND student_id = ?
            ");
            $stmt->execute([$projectId, $receiverId]);
            if ($stmt->fetch()) throw new Exception('Already in project');

            // Проверка существующего приглашения
            $stmt = $pdo->prepare("
                SELECT id, status 
                FROM project_invitations
                WHERE project_id = ? AND sender_id = ? AND receiver_id = ?
                LIMIT 1
            ");
            $stmt->execute([$projectId, $viewerId, $receiverId]);

            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ($existing['status'] === 'pending') {
                    echo json_encode(['success' => false, 'message' => 'Invite already sent']);
                    exit;
                }

                // обновление старого приглашения
                $pdo->prepare("
                    UPDATE project_invitations
                    SET status = 'pending', is_read = 0, created_at = NOW()
                    WHERE id = ?
                ")->execute([$existing['id']]);

                echo json_encode(['success' => true, 'message' => 'Invite resent']);
                exit;
            }

            // новое приглашение
            $stmt = $pdo->prepare("
                INSERT INTO project_invitations 
                (project_id, sender_id, receiver_id, status, is_read, created_at)
                VALUES (?, ?, ?, 'pending', 0, NOW())
            ");
            $stmt->execute([$projectId, $viewerId, $receiverId]);

            echo json_encode(['success' => true, 'message' => 'Invite sent']);
            exit;


            /* ======================
           📌 СПИСОК УЧАСТНИКОВ
           ====================== */
        case 'members':

            $projectId = (int)($input['project_id'] ?? 0);
            if (!$projectId) throw new Exception('Invalid project id');

            $stmt = $pdo->prepare("
                SELECT 
                    pm.student_id,
                    s.login,
                    (SELECT AVG(rating) 
                        FROM project_ratings 
                        WHERE ratee_id = pm.student_id AND project_id = ?) AS rating,
                    EXISTS(
                        SELECT 1 FROM project_ratings 
                        WHERE rater_id = ? AND ratee_id = pm.student_id AND project_id = ?
                    ) AS has_rated
                FROM project_members pm
                JOIN students s ON s.student_id = pm.student_id
                WHERE pm.project_id = ?
            ");
            $stmt->execute([$projectId, $viewerId, $projectId, $projectId]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT 1 
                FROM project_members 
                WHERE project_id = ? AND student_id = ?
            ");
            $stmt->execute([$projectId, $viewerId]);
            $is_member = (bool)$stmt->fetch();

            echo json_encode(['success' => true, 'members' => $members, 'is_viewer_member' => $is_member]);
            exit;


            /* ======================
           📌 ПРИНЯТИЕ / ОТКЛОНЕНИЕ
           ====================== */
        case 'respond':

            if (!$viewerId) throw new Exception('AUTH_REQUIRED');

            $invId = (int)($input['invitation_id'] ?? 0);
            $response = $input['response'] ?? null;

            if (!$invId || !in_array($response, ['accept', 'reject'])) {
                throw new Exception('Invalid params');
            }

            $stmt = $pdo->prepare("SELECT * FROM project_invitations WHERE id = ?");
            $stmt->execute([$invId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inv) throw new Exception('Invite not found');
            if ($inv['receiver_id'] != $viewerId) throw new Exception('Not your invite');
            if ($inv['status'] !== 'pending') throw new Exception('Already processed');

            if ($response === 'reject') {
                $pdo->prepare("
                    UPDATE project_invitations 
                    SET status='rejected', is_read=1 
                    WHERE id = ?
                ")->execute([$invId]);
                echo json_encode(['success' => true]);
                exit;
            }

            // accept → добавляем участника
            $pdo->prepare("
                INSERT INTO project_members (project_id, student_id, added_at, role)
                VALUES (?, ?, NOW(), 'member')
            ")->execute([$inv['project_id'], $viewerId]);

            $pdo->prepare("
                UPDATE project_invitations 
                SET status='accepted', is_read=1 
                WHERE id = ?
            ")->execute([$invId]);

            echo json_encode(['success' => true]);
            exit;


            /* ======================
           📌 ПОЛУЧЕНИЕ РЕЙТИНГА
           ====================== */
        case 'get_rating':

            $projectId = (int)$input['project_id'];
            $studentId = (int)$input['student_id'];

            $stmt = $pdo->prepare("
                SELECT rating, comment 
                FROM project_ratings 
                WHERE project_id = ? AND rater_id = ? AND ratee_id = ?
            ");
            $stmt->execute([$projectId, $viewerId, $studentId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'rating' => $data['rating'] ?? '',
                'comment' => $data['comment'] ?? ''
            ]);
            exit;


            /* ======================
            📌 СОХРАНЕНИЕ РЕЙТИНГА
           ====================== */
        case 'rate_member':
            
            if (empty($input)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Пустые данные запроса'
                ]);
                exit;
            }


            if (!$viewerId) {
                echo json_encode(['success' => false, 'error' => 'Не авторизован']);
                exit;
            }

            $projectId = (int)($input['project_id'] ?? 0);
            $rateeId   = (int)($input['ratee_id'] ?? 0);
            $rating    = (int)($input['rating'] ?? 0);
            $comment   = trim($input['comment'] ?? '');


            // ❌ Запрет самооценки
            if ($viewerId === $rateeId) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Нельзя оценивать самого себя'
                ]);
                exit;
            }

            if ($rating < 1 || $rating > 5) {
                echo json_encode(['success' => false, 'error' => 'Некорректная оценка']);
                exit;
            }

            // Проверяем, что оба — участники проекта
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM project_members 
                WHERE project_id = ? AND student_id IN (?, ?)
            ");
            $stmt->execute([$projectId, $viewerId, $rateeId]);

            if ($stmt->fetchColumn() != 2) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Оценка возможна только между участниками одного проекта'
                ]);
                exit;
            }

            // Проверяем, есть ли уже оценка
            $stmt = $pdo->prepare("
                SELECT id FROM project_ratings
                WHERE project_id = ? AND rater_id = ? AND ratee_id = ?
            ");
            $stmt->execute([$projectId, $viewerId, $rateeId]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                // update
                $stmt = $pdo->prepare("
                UPDATE project_ratings
                SET rating = ?, comment = ?
                WHERE id = ?
            ");
                $stmt->execute([$rating, $comment, $existingId]);
            } else {
                // insert
                $stmt = $pdo->prepare("
                INSERT INTO project_ratings 
                (project_id, rater_id, ratee_id, rating, comment)
                VALUES (?, ?, ?, ?, ?)
            ");
                $stmt->execute([$projectId, $viewerId, $rateeId, $rating, $comment]);
            }

            echo json_encode(['success' => true]);
            exit;


        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {

    ob_end_clean(); // удаляем HTML от любых предупреждений

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}

ob_end_clean();
