<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Téléchargement d'un bulletin en PDF
// Fichier : backend/api/bulletins/telecharger.php
//
//   telecharger.php?id=12
//
// Règles d'accès (toutes vérifiées en base, jamais dans l'URL) :
//   - connexion obligatoire, compte actif, rôle « parent » ;
//   - le bulletin doit être PUBLIÉ (statut « publie ») ;
//   - l'élève doit être lié à ce parent avec un lien VÉRIFIÉ.
// Tout autre cas répond 404, pour ne pas révéler l'existence d'un bulletin.
// Le PDF est généré avec Dompdf (le même que backend/api/rapports/export-pdf.php).
// =====================================================

require_once __DIR__ . '/../rapports/vendor/autoload.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/settings.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function bulletin_refuser(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

function bulletin_h(mixed $valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bulletin_nombre(mixed $valeur): string
{
    $texte = number_format((float) $valeur, 2, ',', '');
    return rtrim(rtrim($texte, '0'), ',');
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
    header('Allow: GET');
    bulletin_refuser(405, 'Méthode non autorisée.');
}

$uid = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$uid || $uid < 1) {
    bulletin_refuser(401, 'Connexion requise.');
}

$pdo = db();

// Compte et rôle revérifiés en base à chaque requête.
$st = $pdo->prepare('SELECT u.nom, u.statut, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
$st->execute([$uid]);
$user = $st->fetch();
if (!$user || $user['statut'] !== 'actif') {
    bulletin_refuser(401, 'Compte inactif ou session expirée.');
}
if ($user['code'] !== 'parent') {
    bulletin_refuser(403, 'Accès refusé.');
}
$_SESSION['user_nom'] = (string) $user['nom'];   // utilisé par audit()

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    bulletin_refuser(404, 'Bulletin introuvable.');
}

$st = $pdo->prepare(
    "SELECT rc.id, rc.moyenne, rc.rang, rc.effectif, rc.appreciation, rc.detail, rc.published_at,
            st.matricule, st.nom AS nom_eleve, st.prenom,
            c.nom AS classe, p.libelle AS periode, sy.libelle AS annee
     FROM report_cards rc
     JOIN students st ON st.id = rc.student_id
     JOIN parent_student ps ON ps.student_id = st.id AND ps.user_id = ? AND ps.verifie = 1
     JOIN classes c ON c.id = rc.class_id
     JOIN evaluation_periods p ON p.id = rc.period_id
     JOIN school_years sy ON sy.id = p.school_year_id
     WHERE rc.id = ? AND rc.statut = 'publie'"
);
$st->execute([$uid, $id]);
$b = $st->fetch();

if (!$b) {
    try {
        audit('Accès refusé : bulletin #' . $id . ' non accessible à ce compte');
    } catch (Throwable $e) {
        error_log('SchoolConnect audit bulletin : ' . $e->getMessage());
    }
    bulletin_refuser(404, 'Bulletin introuvable.');
}

$detail = json_decode((string) $b['detail'], true);
$detail = is_array($detail) ? $detail : [];
$ecole  = (string) school_setting('school_name', 'SchoolConnect');

/* ---------- Contenu du PDF ---------- */
$html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #172033; }
h1 { font-size: 20px; margin: 0 0 4px; color: #1d4ed8; }
h2 { font-size: 14px; margin: 18px 0 6px; }
p { margin: 2px 0; }
.entete { border-bottom: 2px solid #1d4ed8; padding-bottom: 8px; margin-bottom: 12px; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th, td { border: 1px solid #bbb; padding: 6px; text-align: left; }
th { background: #eef2f7; }
.resume td { font-size: 12px; }
.pied { margin-top: 26px; font-size: 9px; color: #64748b; }
</style></head><body>';

$html .= '<div class="entete"><h1>' . bulletin_h($ecole) . '</h1>';
$html .= '<p>Bulletin de notes — ' . bulletin_h($b['periode']) . ' — année scolaire ' . bulletin_h($b['annee']) . '</p></div>';

$html .= '<table><tbody>';
$html .= '<tr><th>Élève</th><td>' . bulletin_h($b['prenom'] . ' ' . $b['nom_eleve']) . '</td>';
$html .= '<th>Matricule</th><td>' . bulletin_h($b['matricule']) . '</td></tr>';
$html .= '<tr><th>Classe</th><td>' . bulletin_h($b['classe']) . '</td>';
$html .= '<th>Publié le</th><td>' . bulletin_h(date('d/m/Y', (int) strtotime((string) $b['published_at']))) . '</td></tr>';
$html .= '</tbody></table>';

$html .= '<h2>Résultats par matière</h2>';
$html .= '<table><thead><tr><th>Matière</th><th>Coefficient</th><th>Moyenne /20</th></tr></thead><tbody>';
foreach ($detail as $d) {
    $html .= '<tr><td>' . bulletin_h($d['matiere'] ?? '') . '</td>'
           . '<td>' . (int) ($d['coefficient'] ?? 1) . '</td>'
           . '<td>' . bulletin_h(bulletin_nombre($d['moyenne'] ?? 0)) . '</td></tr>';
}
if (!$detail) {
    $html .= '<tr><td colspan="3">Aucun détail disponible.</td></tr>';
}
$html .= '</tbody></table>';

$html .= '<h2>Synthèse</h2><table class="resume"><tbody>';
$html .= '<tr><th>Moyenne générale</th><td><strong>' . bulletin_h(bulletin_nombre($b['moyenne'])) . ' / 20</strong></td>';
$html .= '<th>Rang</th><td><strong>' . (int) $b['rang'] . ' sur ' . (int) $b['effectif'] . '</strong></td></tr>';
$html .= '<tr><th>Appréciation</th><td colspan="3">' . bulletin_h($b['appreciation']) . '</td></tr>';
$html .= '</tbody></table>';

$html .= '<p class="pied">Document généré par SchoolConnect le ' . date('d/m/Y à H:i')
       . '. Seul le bulletin publié par l’établissement fait foi.</p>';
$html .= '</body></html>';

/* ---------- Génération ---------- */
$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$pdf = new Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'portrait');
$pdf->render();

try {
    audit('Téléchargement du bulletin #' . (int) $b['id'] . ' (' . $b['periode'] . ')');
} catch (Throwable $e) {
    error_log('SchoolConnect audit bulletin : ' . $e->getMessage());
}

$nomFichier = 'bulletin-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $b['matricule'] . '-' . (string) $b['periode']) . '.pdf';

// Libère le verrou de session avant l'envoi.
session_write_close();

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

echo $pdf->output();
exit;