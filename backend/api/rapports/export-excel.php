<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Connexion requise.');
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
    http_response_code(403);
    exit('Accès interdit.');
}

function exportId(string $key): ?int
{
    $value = $_GET[$key] ?? '';
    if ($value === '') return null;

    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        http_response_code(400);
        exit('Filtre invalide.');
    }
    return $id;
}

$yearId = exportId('school_year_id');
$classId = exportId('class_id');
$periodId = exportId('period_id');
$subjectId = exportId('subject_id');

if (!$yearId) {
    http_response_code(400);
    exit('L’année scolaire est obligatoire.');
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

$sql = "
    SELECT
        sy.libelle AS annee,
        ep.libelle AS periode,
        c.nom AS classe,
        s.matricule,
        s.nom AS nom_eleve,
        s.prenom,
        sub.nom AS matiere,
        a.titre AS evaluation,
        a.type AS type_evaluation,
        g.note,
        a.bareme,
        ROUND(g.note / a.bareme * 20, 2) AS note_sur_20,
        a.coefficient
    FROM grades g
    JOIN assessments a ON a.id = g.assessment_id
    JOIN students s ON s.id = g.student_id
    JOIN classes c ON c.id = a.class_id
    JOIN subjects sub ON sub.id = a.subject_id
    JOIN evaluation_periods ep ON ep.id = a.period_id
    JOIN school_years sy ON sy.id = c.school_year_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.nom, s.nom, s.prenom, sub.nom, a.date_eval
";

$st = $pdo->prepare($sql);
$st->execute($params);

$filename = 'schoolconnect-rapport-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'wb');
if ($out === false) {
    http_response_code(500);
    exit('Impossible de créer le fichier.');
}

// BOM UTF-8 pour améliorer l'ouverture des accents dans Excel.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Année scolaire', 'Période', 'Classe', 'Matricule',
    'Nom', 'Prénom', 'Matière', 'Évaluation', 'Type',
    'Note obtenue', 'Barème', 'Note sur 20', 'Coefficient'
], ';');

while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    // Préfixe les champs textuels dangereux pour éviter l'injection de formules CSV.
    foreach (['annee', 'periode', 'classe', 'matricule', 'nom_eleve',
              'prenom', 'matiere', 'evaluation', 'type_evaluation'] as $key) {
        $value = (string) $row[$key];
        if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
            $row[$key] = "'" . $value;
        }
    }

    fputcsv($out, array_values($row), ';');
}

fclose($out);
exit;
