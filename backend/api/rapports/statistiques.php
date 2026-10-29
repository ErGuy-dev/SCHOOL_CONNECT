<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../includes/security.php';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['error' => 'Connexion requise.'], 401);
}

$pdo = db();

$auth = $pdo->prepare(
    'SELECT r.code, u.statut
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.id = ?'
);
$auth->execute([$_SESSION['user_id']]);
$user = $auth->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['statut'] !== 'actif'
    || !in_array($user['code'], ['administrateur', 'direction'], true)) {
    jsonResponse(['error' => 'Accès interdit.'], 403);
}

function optionalId(string $key): ?int
{
    $value = $_GET[$key] ?? '';
    if ($value === '') return null;

    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        jsonResponse(['error' => 'Filtre invalide : ' . $key], 400);
    }
    return $id;
}

try {
    $action = (string) ($_GET['action'] ?? '');

    if ($action === 'periods') {
        $yearId = optionalId('school_year_id');
        if (!$yearId) jsonResponse(['periods' => []]);

        $st = $pdo->prepare(
            'SELECT id, libelle
             FROM evaluation_periods
             WHERE school_year_id = ?
             ORDER BY debut, id'
        );
        $st->execute([$yearId]);
        jsonResponse(['periods' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    $yearId = optionalId('school_year_id');
    $classId = optionalId('class_id');
    $periodId = optionalId('period_id');
    $subjectId = optionalId('subject_id');

    if (!$yearId) {
        jsonResponse(['error' => 'L’année scolaire est obligatoire.'], 400);
    }

    $yearCheck = $pdo->prepare('SELECT id FROM school_years WHERE id = ?');
    $yearCheck->execute([$yearId]);
    if (!$yearCheck->fetchColumn()) {
        jsonResponse(['error' => 'Année scolaire introuvable.'], 404);
    }

    $where = ['c.school_year_id = ?', "a.statut = 'publiee'", "s.statut = 'inscrit'"];
    $params = [$yearId];

    if ($classId !== null) {
        $where[] = 'c.id = ?';
        $params[] = $classId;
    }
    if ($periodId !== null) {
        $where[] = 'a.period_id = ?';
        $params[] = $periodId;
    }
    if ($subjectId !== null) {
        $where[] = 'a.subject_id = ?';
        $params[] = $subjectId;
    }

    $whereSql = implode(' AND ', $where);

    $base = "
        FROM grades g
        JOIN assessments a ON a.id = g.assessment_id
        JOIN students s ON s.id = g.student_id
        JOIN classes c ON c.id = a.class_id
        JOIN subjects sub ON sub.id = a.subject_id
        WHERE $whereSql
    ";

    // Les notes sont normalisées sur 20 avant le calcul.
    // La pondération utilise le coefficient de chaque évaluation.
    $summarySql = "
        SELECT
            COUNT(DISTINCT g.student_id) AS students_with_grades,
            COUNT(*) AS grade_count,
            ROUND(
                SUM((g.note / a.bareme * 20) * a.coefficient)
                / NULLIF(SUM(a.coefficient), 0), 2
            ) AS average_20,
            ROUND(
                100 * SUM(
                    CASE WHEN (g.note / a.bareme * 20) >= 10
                         THEN 1 ELSE 0 END
                ) / NULLIF(COUNT(*), 0), 2
            ) AS pass_rate
        $base
    ";

    $st = $pdo->prepare($summarySql);
    $st->execute($params);
    $summary = $st->fetch(PDO::FETCH_ASSOC);

    $classSql = "
        SELECT
            c.nom AS classe,
            COUNT(DISTINCT g.student_id) AS effectif_notes,
            ROUND(
                SUM((g.note / a.bareme * 20) * a.coefficient)
                / NULLIF(SUM(a.coefficient), 0), 2
            ) AS moyenne_20,
            ROUND(
                100 * SUM(
                    CASE WHEN (g.note / a.bareme * 20) >= 10
                         THEN 1 ELSE 0 END
                ) / NULLIF(COUNT(*), 0), 2
            ) AS taux_reussite
        $base
        GROUP BY c.id, c.nom
        ORDER BY c.nom
    ";

    $st = $pdo->prepare($classSql);
    $st->execute($params);
    $byClass = $st->fetchAll(PDO::FETCH_ASSOC);

    $subjectSql = "
        SELECT
            sub.nom AS matiere,
            COUNT(*) AS nombre_notes,
            ROUND(
                SUM((g.note / a.bareme * 20) * a.coefficient)
                / NULLIF(SUM(a.coefficient), 0), 2
            ) AS moyenne_20,
            ROUND(
                100 * SUM(
                    CASE WHEN (g.note / a.bareme * 20) >= 10
                         THEN 1 ELSE 0 END
                ) / NULLIF(COUNT(*), 0), 2
            ) AS taux_reussite
        $base
        GROUP BY sub.id, sub.nom
        ORDER BY sub.nom
    ";

    $st = $pdo->prepare($subjectSql);
    $st->execute($params);
    $bySubject = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach (['students_with_grades', 'grade_count'] as $key) {
        $summary[$key] = (int) ($summary[$key] ?? 0);
    }
    $summary['average_20'] = $summary['average_20'] !== null
        ? (float) $summary['average_20'] : 0.0;
    $summary['pass_rate'] = $summary['pass_rate'] !== null
        ? (float) $summary['pass_rate'] : 0.0;

    jsonResponse([
        'summary' => $summary,
        'by_class' => $byClass,
        'by_subject' => $bySubject,
        'definitions' => [
            'scale' => '20',
            'pass_threshold' => 10,
            'source' => 'Notes des évaluations publiées des élèves inscrits',
            'weighting' => 'Coefficient de chaque évaluation'
        ]
    ]);
} catch (Throwable $e) {
    error_log('SchoolConnect rapports statistiques : ' . $e->getMessage());
    jsonResponse(['error' => 'Erreur lors du calcul des statistiques.'], 500);
}
