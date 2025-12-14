<?php
// notifications.php — компонент колокольчика + обработка уведомлений (accept/reject/block)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../init.php';

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) return; // если неавторизован — при встраивании в header ничего не выводим

// ----------------- POST API (accept/reject/block) -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invitation_id'], $_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $invId = (int)$_POST['invitation_id'];
    $action = $_POST['action'];

    if (!in_array($action, ['accept', 'reject', 'block'])) {
        echo json_encode(['success' => false, 'message' => 'Неверное действие']);
        exit;
    }

    // Получаем приглашение (только к текущему пользователю)
    $stmt = $pdo->prepare("SELECT * FROM project_invitations WHERE id = ? AND receiver_id = ?");
    $stmt->execute([$invId, $userId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        echo json_encode(['success' => false, 'message' => 'Приглашение не найдено']);
        exit;
    }

    try {
        if ($action === 'reject') {
            $upd = $pdo->prepare("UPDATE project_invitations SET status='rejected', is_read=1 WHERE id = ?");
            $upd->execute([$invId]);
        } elseif ($action === 'accept') {
            // Добавляем участника проекта — таблица project_members не содержит rating, поэтому вставляем только project_id, student_id, added_at, role
            $stmt = $pdo->prepare("SELECT id FROM project_members WHERE project_id = ? AND student_id = ?");
            $stmt->execute([$inv['project_id'], $userId]);
            if (!$stmt->fetch()) {
                $ins = $pdo->prepare("
                    INSERT INTO project_members (project_id, student_id, added_at, role)
                    VALUES (?, ?, NOW(), 'member')
                ");
                $ins->execute([$inv['project_id'], $userId]);
            }

            $pdo->prepare("UPDATE project_invitations SET status='accepted', is_read=1 WHERE id = ?")
                ->execute([$invId]);
        } elseif ($action === 'block') {
            // Блокируем отправителя (полный пользовательский блок = 2)
            $block = $pdo->prepare("
                INSERT INTO user_blocks (blocker_id, blocked_id, block_level, comment, created_at)
                VALUES (?, ?, 2, 'Заблокирован через уведомление', NOW())
                ON DUPLICATE KEY UPDATE block_level = VALUES(block_level)
            ");
            $block->execute([$userId, $inv['sender_id']]);

            // отмечаем уведомление как прочитанное
            $pdo->prepare("UPDATE project_invitations SET is_read=1 WHERE id = ?")->execute([$invId]);
        }

        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $ex) {
        // Для разработки можно вернуть текст ошибки, в продакшне лучше логировать и вернуть generic
        echo json_encode(['success' => false, 'message' => 'DB error', 'error' => $ex->getMessage()]);
        exit;
    }
}

// ----------------- Функция получения уведомлений (для встраивания в шаблон) -----------------
function getNotifications($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT pi.id, pi.project_id, pi.sender_id, pi.status, pi.is_read, pi.created_at, 
               p.name AS project_name, s.login AS sender_login
        FROM project_invitations pi
        JOIN projects p ON pi.project_id = p.id
        JOIN students s ON pi.sender_id = s.student_id
        WHERE pi.receiver_id = :user_id AND pi.is_read = 0
        ORDER BY pi.created_at DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$notifications = getNotifications($pdo, $userId);
$notifCount = count($notifications);
?>

<div class="relative inline-block">
    <button id="notifBell" class="relative p-2 bg-white text-indigo-700 rounded-full hover:bg-gray-100 shadow">
        <!-- колокольчик SVG -->
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        <?php if ($notifCount > 0): ?>
            <span id="notifCount" class="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-600 rounded-full shadow">
                <?= $notifCount ?>
            </span>
        <?php endif; ?>
    </button>

    <div id="notifList" class="hidden absolute right-0 mt-2 w-96 bg-white shadow-lg rounded-xl z-50 max-h-96 overflow-y-auto border border-gray-200">
        <?php if ($notifCount === 0): ?>
            <div class="p-6 text-center text-gray-500 text-lg font-medium">
                У вас нет новых уведомлений
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
                <div class="p-4 border-b last:border-b-0 hover:bg-gray-50 transition">
                    <p class="text-gray-800 text-base mb-3">
                        <strong><?= htmlspecialchars($notif['sender_login']) ?></strong> пригласил вас в проект
                        <strong><?= htmlspecialchars($notif['project_name']) ?></strong>
                    </p>
                    <?php if ($notif['status'] === 'pending'): ?>
                        <div class="flex flex-wrap gap-2">
                            <button class="notifBtn acceptBtn px-4 py-1 bg-green-500 text-white rounded-lg text-sm hover:bg-green-600 transition" data-id="<?= $notif['id'] ?>" data-action="accept">Принять</button>
                            <button class="notifBtn rejectBtn px-4 py-1 bg-red-500 text-white rounded-lg text-sm hover:bg-red-600 transition" data-id="<?= $notif['id'] ?>" data-action="reject">Отклонить</button>
                            <button class="notifBtn blockBtn px-4 py-1 bg-gray-600 text-white rounded-lg text-sm hover:bg-gray-700 transition" data-id="<?= $notif['id'] ?>" data-action="block">Заблокировать</button>
                        </div>
                    <?php else: ?>
                        <span class="text-gray-500 text-sm">Вы уже <?= $notif['status'] === 'accepted' ? 'приняли' : 'отклонили' ?> приглашение</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    const notifBtn = document.getElementById('notifBell');
    const notifList = document.getElementById('notifList');

    notifBtn.addEventListener('click', e => {
        e.stopPropagation();
        notifList.classList.toggle('hidden');
    });

    document.addEventListener('click', e => {
        if (!notifList.contains(e.target) && !notifBtn.contains(e.target)) {
            notifList.classList.add('hidden');
        }
    });

    document.querySelectorAll('.notifBtn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const invitationId = btn.dataset.id;
            const action = btn.dataset.action;

            fetch('notifications.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `invitation_id=${encodeURIComponent(invitationId)}&action=${encodeURIComponent(action)}`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        // удаляем карточку уведомления
                        const card = btn.closest('div.border-b') || btn.closest('div.p-4');
                        if (card) card.remove();

                        const badge = document.querySelector('#notifBell span');
                        if (badge) {
                            let count = parseInt(badge.textContent) - 1;
                            if (count <= 0) badge.remove();
                            else badge.textContent = count;
                        }

                        // если пусто — заменить содержимое
                        if (!notifList.querySelector('div.p-4')) {
                            notifList.innerHTML = '<div class="p-6 text-center text-gray-500 text-lg font-medium">У вас нет новых уведомлений</div>';
                        }
                    } else {
                        alert(data.message || 'Ошибка при обработке');
                    }
                }).catch(err => {
                    console.error(err);
                    alert('Сетевая ошибка');
                });
        });
    });
</script>