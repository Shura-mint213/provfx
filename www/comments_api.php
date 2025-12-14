    <?php
    require_once __DIR__ . '/../init.php';

    header('Content-Type: application/json; charset=utf-8');

    $studentId = (int)($_GET['student_id'] ?? 0);
    $offset = (int)($_GET['offset'] ?? 0);
    $limit  = (int)($_GET['limit'] ?? 10);

    if (!$studentId) {
        echo json_encode(['success' => false]);
        exit;
    }

    // Всего комментариев
    $totalStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM project_ratings 
    WHERE ratee_id = ? AND comment <> ''
");
    $totalStmt->execute([$studentId]);
    $total = (int)$totalStmt->fetchColumn();

    // Порция
    $stmt = $pdo->prepare("
    SELECT 
        pr.comment,
        pr.rating,
        s.login AS author,
        p.name AS project_name
    FROM project_ratings pr
    JOIN students s ON s.student_id = pr.rater_id
    JOIN projects p ON p.id = pr.project_id
    WHERE pr.ratee_id = ?
        AND pr.comment <> ''
    ORDER BY pr.id DESC
    LIMIT $limit OFFSET $offset
");
    $stmt->execute([$studentId]);

    echo json_encode([
        'success' => true,
        'total'   => $total,
        'items'   => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
