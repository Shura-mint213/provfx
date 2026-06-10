<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../check_auth.php';

$viewerId = $_SESSION['user_id'] ?? null;
if (!$viewerId) {
    header('Location: /login.php');
    exit;
}

// Получаем список заблокированных пользователей
$stmt = $pdo->prepare("
    SELECT ub.blocked_id, s.login, s.avatar
    FROM user_blocks ub
    JOIN students s ON s.student_id = ub.blocked_id
    WHERE ub.blocker_id = ?
    ORDER BY ub.id DESC
");
$stmt->execute([$viewerId]);
$blockedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Черный список — Трек студента ТС</title>
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'Roboto', sans-serif;
        }

        .fade-out {
            opacity: 0;
            transform: translateY(10px);
            transition: 0.3s ease;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-indigo-50 via-blue-50 to-cyan-50 min-h-screen">

    <!-- HEADER -->
    <header class="bg-indigo-700 text-white shadow-lg">
        <div class="container mx-auto px-6 py-6 flex justify-between items-center">
            <h1 class="text-3xl font-bold">Черный список</h1>
            <div class="flex items-center gap-6">
                <a href="gallery.php" class="bg-white text-indigo-700 px-6 py-3 rounded-lg font-bold hover:bg-gray-100">Галерея</a>
                <?php include __DIR__ . '/header_right.php'; ?>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-6 py-12 max-w-6xl">

        <?php if (empty($blockedUsers)): ?>
            <div class="bg-white rounded-3xl shadow-xl p-12 text-center">
                <h2 class="text-2xl font-semibold text-gray-700 mb-4">Вы никого не заблокировали</h2>
                <p class="text-gray-600">Черный список пуст.</p>
            </div>
        <?php else: ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">

                <?php foreach ($blockedUsers as $user): ?>
                    <div id="user-<?= $user['blocked_id'] ?>"
                        class="bg-white rounded-2xl shadow-xl overflow-hidden hover:shadow-2xl transition transform p-6">

                        <div class="text-center">
                            <img src="/get_avatar.php?id=<?= $user['blocked_id'] ?>"
                                class="w-28 h-28 rounded-full mx-auto border-4 border-white shadow-xl object-cover -mt-4 bg-gray-100"
                                alt="Аватар">

                            <h3 class="text-2xl font-bold mt-4">
                                <a href="/stand.php?id=<?= $user['blocked_id'] ?>"
                                    class="hover:text-indigo-600">
                                    <?= htmlspecialchars($user['login']) ?>
                                </a>
                            </h3>

                            <p class="text-gray-500 text-sm mt-2">Заблокирован полностью</p>
                        </div>

                        <button
                            class="unblockBtn mt-6 w-full py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl font-bold transition"
                            data-id="<?= $user['blocked_id'] ?>">
                            Снять блок
                        </button>
                    </div>
                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </main>

    <script>
        document.querySelectorAll('.unblockBtn').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;

                fetch('/block_user.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-mai-tracker-form-urlencoded'
                        },
                        body: `action=unblock&blocked_id=${id}`
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {

                            const card = document.getElementById('user-' + id);
                            card.classList.add('fade-out');

                            setTimeout(() => card.remove(), 300);

                        } else {
                            alert(data.error || 'Ошибка при снятии блока');
                        }
                    });
            });
        });
    </script>

</body>

</html>