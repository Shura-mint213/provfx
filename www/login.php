<?php
require_once '../init.php';

// Если уже авторизован, перенаправляем
if (isset($_SESSION['user_id'])) {
    header('Location: gallery.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login && $password) {
        try {
            $stmt = $pdo->prepare("SELECT student_id, login, password FROM students WHERE login = ?");
            $stmt->execute([$login]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['student_id'];
                $_SESSION['login'] = $user['login'];
                header('Location: gallery.php');
                exit;
            } else {
                $error = "Неверный логин или пароль";
            }
        } catch (Exception $e) {
            $error = "Ошибка базы данных: " . $e->getMessage();
        }
    } else {
        $error = "Заполните все поля";
    }
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Вход — Трек МАИ</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-10 rounded-2xl shadow-2xl w-full max-w-md">
        <h1 class="text-4xl font-bold text-center mb-8 text-indigo-700">Вход</h1>

        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div>
                <label class="block font-semibold mb-2">Логин</label>
                <input type="text" name="login" required class="w-full border-2 rounded-xl px-5 py-3" placeholder="Введите логин">
            </div>
            <div>
                <label class="block font-semibold mb-2">Пароль</label>
                <input type="password" name="password" required class="w-full border-2 rounded-xl px-5 py-3" placeholder="Введите пароль">
            </div>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-4 rounded-xl text-lg">
                Войти
            </button>
        </form>

        <p class="text-center mt-6">
            <a href="form.php?new=1" class="text-indigo-600 hover:underline font-medium">Создать стенд / Зарегистрироваться</a>
        </p>
    </div>
</body>

</html>