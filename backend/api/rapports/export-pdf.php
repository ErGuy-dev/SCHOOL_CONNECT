<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
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

use Dompdf\Dompdf;
use Dompdf\Options;

function pdfId(string $key): ?int
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

function pdfEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$yearId = pdfId('school_year_id');
$classId = pdfId('class_id');
$periodId = pdfId('period_id');
$subjectId = pdfId('subject_id');

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
    SELECT sy.libelle AS annee, ep.libelle AS periode,
           c.nom AS classe, s.matricule, s.nom AS nom_eleve,
           s.prenom, sub.nom AS matiere, a.titre AS evaluation,
           g.note, a.bareme,
           ROUND(g.note / a.bareme * 20, 2) AS note_sur_20
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
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$anneeLabel = $pdo->prepare('SELECT libelle FROM school_years WHERE id = ?');
$anneeLabel->execute([$yearId]);
$yearName = $anneeLabel->fetchColumn();

$html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
h1 { font-size: 18px; }
p { font-size: 10px; }
table { width: 100%; border-collapse: collapse; margin-top: 12px; }
th, td { border: 1px solid #bbb; padding: 5px; text-align: left; }
th { background: #eee; }
</style></head><body>';

$html .= '<h1>SchoolConnect — Rapport scolaire</h1>';
$html .= '<p>Année scolaire : ' . pdfEscape($yearName ?: '') . '</p>';
$html .= '<p>Généré le : ' . date('d/m/Y H:i') . '</p>';
$html .= '<p>Les notes sont également présentées sur 20 pour faciliter la comparaison.</p>';

$html .= '<table><thead><tr>
<th>Classe</th><th>Matricule</th><th>Élève</th><th>Période</th>
<th>Matière</th><th>Évaluation</th><th>Note</th><th>Barème</th><th>/20</th>
</tr></thead><tbody>';

foreach ($rows as $r) {
    $html .= '<tr>';
    foreach ([
        $r['classe'],
        $r['matricule'],
        $r['nom_eleve'] . ' ' . $r['prenom'],
        $r['periode'],
        $r['matiere'],
        $r['evaluation'],
        $r['note'],
        $r['bareme'],
        $r['note_sur_20']
    ] as $value) {
        $html .= '<td>' . pdfEscape($value) . '</td>';
    }
    $html .= '</tr>';
}

if (!$rows) {
    $html .= '<tr><td colspan="9">Aucun résultat publié pour ces filtres.</td></tr>';
}

$html .= '</tbody></table></body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$pdf = new Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'landscape');
$pdf->render();

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="schoolconnect-rapport-' . date('Y-m-d') . '.pdf"');
header('Cache-Control: no-store');

echo $pdf->output();
exit;
