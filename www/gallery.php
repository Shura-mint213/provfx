<?php
require_once '../init.php';
require_once '../check_auth.php';

$viewerId = $_SESSION['user_id'] ?? null;
if (!$viewerId) {
    header('Location: login.php');
    exit;
}

/* ===============================
   Проверка публикации стенда
   =============================== */
$stmt = $pdo->prepare("
    SELECT is_published 
    FROM students 
    WHERE student_id = :id 
    LIMIT 1
");
$stmt->execute(['id' => $viewerId]);
$userPublished = (int)$stmt->fetchColumn();

/* ===============================
   Если стенд НЕ опубликован — СТОП
   =============================== */
if (!$userPublished) {
?>
    <!DOCTYPE html>
    <html lang="ru">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Стенд не опубликован</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>

    <body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">

        <!-- Header -->
        <header class="bg-indigo-700 text-white shadow-lg">
            <div class="container mx-auto px-6 py-6 flex justify-between items-center">
                <h1 class="text-3xl font-bold">Трек студента МАИ</h1>
                <div class="flex items-center space-x-4">
                    <?php include __DIR__ . '/header_right.php'; ?>
                </div>
            </div>
        </header>

        <main class="container mx-auto px-6 py-16">
            <div class="max-w-3xl mx-auto bg-white shadow-xl rounded-2xl p-10 text-center">
                <h2 class="text-3xl font-bold text-indigo-700 mb-4">
                    Ваш стенд пока не опубликован
                </h2>
                <p class="text-lg text-gray-600 mb-6">
                    Чтобы просматривать стенды других студентов, нужно опубликовать свой стенд.
                </p>
                <a href="form.php"
                    class="inline-block bg-indigo-600 text-white px-8 py-3 rounded-xl font-bold hover:bg-indigo-700 text-lg">
                    Перейти к редактированию стенда
                </a>
            </div>
        </main>

    </body>

    </html>
<?php
    exit;
}

/* ===============================
   Поиск (только если опубликован)
   =============================== */
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT 
        s.student_id,
        s.login,
        GROUP_CONCAT(DISTINCT sk.name ORDER BY sk.name SEPARATOR ', ') AS skills,
        ROUND(AVG(pr.rating), 1) AS rating
    FROM students s
    LEFT JOIN skills sk ON sk.student_id = s.student_id
    LEFT JOIN competencies c ON c.student_id = s.student_id
    LEFT JOIN project_ratings pr ON pr.ratee_id = s.student_id
    WHERE s.is_published = 1
";

$params = [];

if ($search !== '') {
    $keywords = array_filter(explode(' ', $search));
    if ($keywords) {
        $likes = [];
        foreach ($keywords as $i => $kw) {
            $key = ":kw$i";
            $likes[] = "(sk.name LIKE $key OR c.name LIKE $key)";
            $params[$key] = "%$kw%";
        }
        $sql .= " AND (" . implode(' OR ', $likes) . ")";
    }
}

$sql .= " GROUP BY s.student_id, s.login ORDER BY s.login";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Галерея стендов</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">

    <!-- Header -->
    <header class="bg-indigo-700 text-white shadow-lg">
        <div class="container mx-auto px-6 py-6 flex justify-between items-center">
            <h1 class="text-3xl font-bold">Трек студента МАИ</h1>
            <div class="flex items-center space-x-4">
                <?php include __DIR__ . '/header_right.php'; ?>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-6 py-12">

        <!-- Поиск -->
        <div class="max-w-2xl mx-auto mb-10">
            <form method="GET" class="flex gap-4">
                <input type="text"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="Поиск по навыкам: Python, Git, React..."
                    class="flex-1 px-6 py-4 rounded-xl border-2 text-lg focus:outline-none focus:border-indigo-500">
                <button class="bg-indigo-600 text-white px-8 py-4 rounded-xl font-bold hover:bg-indigo-700">
                    Найти
                </button>
            </form>
        </div>

        <!-- Галерея -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">

            <?php if ($students): ?>
                <?php foreach ($students as $s): ?>
                    <?php
                    $studentId = (int)$s['student_id'];
                    $login = htmlspecialchars($s['login']);
                    $skills = htmlspecialchars($s['skills'] ?? '');
                    $rating = $s['rating'];
                    ?>
                    <div class="bg-white rounded-2xl shadow-xl overflow-hidden hover:shadow-2xl transition transform hover:-translate-y-2 p-6 text-center">

                        <div class="bg-gradient-to-br from-indigo-500 to-blue-600 h-24 -mx-6 -mt-6"></div>

                        <img src="get_avatar.php?id=<?= $studentId ?>"
                            class="w-28 h-28 rounded-full mx-auto border-4 border-white shadow-xl object-cover -mt-12"
                            alt="Аватар">

                        <h3 class="text-2xl font-bold mt-4"><?= $login ?></h3>

                        <?php if ($skills): ?>
                            <p class="mt-3 text-gray-700 text-sm truncate"><?= $skills ?></p>
                        <?php endif; ?>

                        <div class="mt-4 text-xl text-yellow-500">
                            <?php
                            $r = (float)$rating;
                            $full = floor($r);
                            $half = ($r - $full) >= 0.5;
                            $empty = 5 - $full - $half;
                            echo str_repeat('★', $full);
                            if ($half) echo '⯨';
                            echo str_repeat('☆', $empty);
                            ?>
                            <span class="text-gray-600 text-sm">
                                (<?= $rating !== null ? number_format($rating, 1) : '—' ?>)
                            </span>
                        </div>

                        <a href="stand.php?id=<?= $studentId ?>"
                            class="mt-6 inline-block bg-indigo-600 text-white px-6 py-2 rounded-full font-bold hover:bg-indigo-700">
                            Открыть стенд
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-span-full text-center py-20">
                    <p class="text-3xl text-gray-600">Стенды не найдены</p>
                    <p class="text-lg text-gray-500 mt-4">Попробуйте изменить запрос</p>
                </div>
            <?php endif; ?>

        </div>

    </main>

</body>

</html>