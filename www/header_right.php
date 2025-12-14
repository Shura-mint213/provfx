<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/notifications.php'; // функция getNotifications()

$viewerId = $_SESSION['user_id'] ?? null;
$is_logged_in = !!$viewerId;

// Получаем данные пользователя
$user = null;
if ($is_logged_in) {
    $stmt = $pdo->prepare("SELECT student_id, login FROM students WHERE student_id = ?");
    $stmt->execute([$viewerId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<div class="flex items-center space-x-4">
    <!-- Уведомления (подключаем отдельный компонент) -->
    <?php if ($is_logged_in): ?>
        <?php
        // notifications.php уже содержит весь HTML и JavaScript для колокольчика
        // Просто выводим его
        ?>
    <?php endif; ?>

    <!-- Аватар / Меню пользователя -->
    <?php if ($is_logged_in && $user): ?>
        <div class="relative inline-block">
            <button id="userAvatarBtn" class="block w-10 h-10 rounded-full overflow-hidden border-2 border-gray-300 hover:border-indigo-500">
                <img src="/get_avatar.php?id=<?= $user['student_id'] ?>" alt="Аватар" class="w-full h-full object-cover">
            </button>

            <div id="userMenu" class="hidden absolute right-0 mt-2 w-56 bg-white shadow-lg rounded-lg z-50 overflow-hidden text-sm">
                <div class="p-2 border-b">
                    <span class="text-gray-900 font-medium"><?= htmlspecialchars($user['login']) ?></span>
                </div>

                <a href="/stand.php?id=<?= $user['student_id'] ?>"
                    class="block px-4 py-2 text-gray-900 hover:bg-gray-100 hover:text-gray-900">
                    Профиль
                </a>

                <a href="/form.php"
                    class="block px-4 py-2 text-gray-900 hover:bg-gray-100 hover:text-gray-900">
                    Редактировать профиль
                </a>

                <a href="/blacklist.php"
                    class="block px-4 py-2 text-gray-900 hover:bg-gray-100 hover:text-gray-900">
                    Черный список
                </a>

                <a href="/logout.php"
                    class="block px-4 py-2 text-red-600 hover:bg-gray-100 hover:text-red-600">
                    Выйти
                </a>
            </div>

        </div>
    <?php else: ?>
        <a href="/login.php" class="px-4 py-2 bg-indigo-500 text-white rounded hover:bg-indigo-600 text-sm">Войти</a>
    <?php endif; ?>
</div>

<script>
    (function() {
        const userBtn = document.getElementById('userAvatarBtn');
        const userMenu = document.getElementById('userMenu');

        // Показ/скрытие меню пользователя
        if (userBtn && userMenu) {
            userBtn.addEventListener('click', e => {
                e.stopPropagation();
                userMenu.classList.toggle('hidden');
            });
        }

        // Скрытие меню пользователя при клике вне
        document.addEventListener('click', (e) => {
            if (!userBtn?.contains(e.target) && !userMenu?.contains(e.target)) {
                userMenu?.classList.add('hidden');
            }
        });

    })();
</script>