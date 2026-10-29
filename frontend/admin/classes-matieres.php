<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Classes et matières
// Fichier : frontend/admin/classes-matieres.php
// =====================================================

require __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';

require_role('administrateur');   // connexion + rôle + statut revérifiés en base

$pdo = db();

function rediriger(string $etat): void
{
    header('Location: classes-matieres.php?etat=' . rawurlencode($etat), true, 303);
    exit;
}

/* ---------- Traitement des formulaires ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $etat   = 'action_inconnue';

    try {
        $pdo->beginTransaction();

        switch ($action) {
            case 'ajouter_classe':
                $nom     = trim((string)($_POST['nom_classe'] ?? ''));
                $niveau  = trim((string)($_POST['niveau'] ?? ''));
                $anneeId = (int)($_POST['school_year_id'] ?? 0);
                if ($nom === '' || mb_strlen($nom) > 40 || $niveau === '' || mb_strlen($niveau) > 40 || $anneeId < 1) {
                    throw new InvalidArgumentException('classe_invalide');
                }
                $q = $pdo->prepare('SELECT 1 FROM school_years WHERE id = ?');
                $q->execute([$anneeId]);
                if (!$q->fetchColumn()) {
                    throw new InvalidArgumentException('annee_introuvable');
                }
                $pdo->prepare('INSERT INTO classes (nom, niveau, school_year_id) VALUES (?, ?, ?)')
                    ->execute([$nom, $niveau, $anneeId]);
                audit('Ajout de la classe : ' . $nom);
                $etat = 'classe_ok';
                break;

            case 'ajouter_matiere':
                $nom         = trim((string)($_POST['nom_matiere'] ?? ''));
                $coefficient = (int)($_POST['coefficient'] ?? 0);
                $bareme      = (int)($_POST['bareme'] ?? 20);
                if ($nom === '' || mb_strlen($nom) > 80 || $coefficient < 1 || $coefficient > 10 || $bareme < 1 || $bareme > 100) {
                    throw new InvalidArgumentException('matiere_invalide');
                }
                $pdo->prepare('INSERT INTO subjects (nom, coefficient, bareme) VALUES (?, ?, ?)')
                    ->execute([$nom, $coefficient, $bareme]);
                audit('Ajout de la matière : ' . $nom);
                $etat = 'matiere_ok';
                break;

            case 'supprimer_classe':
                $id = (int)($_POST['id'] ?? 0);
                $q = $pdo->prepare('SELECT nom FROM classes WHERE id = ?');
                $q->execute([$id]);
                $nom = $q->fetchColumn();
                if ($nom === false) {
                    throw new InvalidArgumentException('element_introuvable');
                }
                $pdo->prepare('DELETE FROM classes WHERE id = ?')->execute([$id]);
                audit('Suppression de la classe : ' . $nom);
                $etat = 'classe_supprimee';
                break;

            case 'supprimer_matiere':
                $id = (int)($_POST['id'] ?? 0);
                $q = $pdo->prepare('SELECT nom FROM subjects WHERE id = ?');
                $q->execute([$id]);
                $nom = $q->fetchColumn();
                if ($nom === false) {
                    throw new InvalidArgumentException('element_introuvable');
                }
                $pdo->prepare('DELETE FROM subjects WHERE id = ?')->execute([$id]);
                audit('Suppression de la matière : ' . $nom);
                $etat = 'matiere_supprimee';
                break;

            case 'affecter':
                $teacherId = (int)($_POST['teacher_id'] ?? 0);
                $classId   = (int)($_POST['class_id'] ?? 0);
                $subjectId = (int)($_POST['subject_id'] ?? 0);
                $q = $pdo->prepare(
                    "SELECT u.nom AS ens, c.nom AS classe, s.nom AS matiere
                     FROM users u
                     JOIN roles r ON r.id = u.role_id AND r.code = 'enseignant'
                     JOIN classes c ON c.id = ?
                     JOIN subjects s ON s.id = ?
                     WHERE u.id = ? AND u.statut = 'actif'"
                );
                $q->execute([$classId, $subjectId, $teacherId]);
                $info = $q->fetch();
                if (!$info) {
                    throw new InvalidArgumentException('affectation_invalide');
                }
                $pdo->prepare('INSERT INTO teacher_assignments (teacher_id, class_id, subject_id) VALUES (?, ?, ?)')
                    ->execute([$teacherId, $classId, $subjectId]);
                audit("Affectation de {$info['ens']} : {$info['matiere']} en {$info['classe']}");
                $etat = 'affectation_ok';
                break;

            case 'retirer_affectation':
                $id = (int)($_POST['id'] ?? 0);
                $q = $pdo->prepare(
                    'SELECT ta.class_id, ta.subject_id, u.nom AS ens, c.nom AS classe, s.nom AS matiere
                     FROM teacher_assignments ta
                     JOIN users u ON u.id = ta.teacher_id
                     JOIN classes c ON c.id = ta.class_id
                     JOIN subjects s ON s.id = ta.subject_id
                     WHERE ta.id = ?'
                );
                $q->execute([$id]);
                $aff = $q->fetch();
                if (!$aff) {
                    throw new InvalidArgumentException('element_introuvable');
                }
                // Des évaluations existent déjà : on garde l'affectation pour préserver l'historique.
                $q = $pdo->prepare('SELECT COUNT(*) FROM assessments WHERE class_id = ? AND subject_id = ?');
                $q->execute([$aff['class_id'], $aff['subject_id']]);
                if ((int)$q->fetchColumn() > 0) {
                    throw new InvalidArgumentException('affectation_utilisee');
                }
                $pdo->prepare('DELETE FROM teacher_assignments WHERE id = ?')->execute([$id]);
                audit("Retrait de l'affectation de {$aff['ens']} : {$aff['matiere']} en {$aff['classe']}");
                $etat = 'affectation_retiree';
                break;

            default:
                throw new InvalidArgumentException('action_inconnue');
        }

        $pdo->commit();
        rediriger($etat);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        rediriger($e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('SchoolConnect classes-matieres : ' . $e->getMessage());
        $code = (int)($e->errorInfo[1] ?? 0);

        if ($code === 1062) {            // doublon (UNIQUE)
            rediriger(match ($action) {
                'ajouter_classe'  => 'classe_doublon',
                'ajouter_matiere' => 'matiere_doublon',
                'affecter'        => 'affectation_doublon',
                default           => 'erreur',
            });
        }
        if ($code === 1451) {            // encore référencé ailleurs (clé étrangère)
            rediriger($action === 'supprimer_classe' ? 'classe_utilisee' : 'matiere_utilisee');
        }
        if ($code === 1644) {            // refus d'un déclencheur d'intégrité
            rediriger('affectation_refusee');
        }
        rediriger('erreur');
    }
}

/* ---------- Données d'affichage ---------- */
$annees = $pdo->query('SELECT id, libelle, active FROM school_years ORDER BY debut DESC')->fetchAll();

$classes = $pdo->query(
    'SELECT c.id, c.nom, c.niveau, sy.libelle AS annee
     FROM classes c JOIN school_years sy ON sy.id = c.school_year_id
     ORDER BY sy.debut DESC, c.nom'
)->fetchAll();

$matieres = $pdo->query('SELECT id, nom, coefficient, bareme FROM subjects ORDER BY nom')->fetchAll();

$enseignants = $pdo->query(
    "SELECT u.id, u.nom FROM users u JOIN roles r ON r.id = u.role_id
     WHERE r.code = 'enseignant' AND u.statut = 'actif' ORDER BY u.nom"
)->fetchAll();

$affectations = $pdo->query(
    'SELECT ta.id, u.nom AS enseignant, c.nom AS classe, sy.libelle AS annee, s.nom AS matiere, s.coefficient
     FROM teacher_assignments ta
     JOIN users u ON u.id = ta.teacher_id
     JOIN classes c ON c.id = ta.class_id
     JOIN school_years sy ON sy.id = c.school_year_id
     JOIN subjects s ON s.id = ta.subject_id
     ORDER BY u.nom, c.nom, s.nom'
)->fetchAll();

$messages = [
    'classe_ok'            => ['success', 'La classe a été ajoutée.'],
    'matiere_ok'           => ['success', 'La matière a été ajoutée.'],
    'classe_supprimee'     => ['success', 'La classe a été supprimée.'],
    'matiere_supprimee'    => ['success', 'La matière a été supprimée.'],
    'affectation_ok'       => ['success', 'L’enseignant a été affecté.'],
    'affectation_retiree'  => ['success', 'L’affectation a été retirée.'],
    'classe_invalide'      => ['err', 'Vérifiez le nom, le niveau et l’année de la classe.'],
    'matiere_invalide'     => ['err', 'Vérifiez le nom, le coefficient (1 à 10) et le barème (1 à 100).'],
    'annee_introuvable'    => ['err', 'Cette année scolaire n’existe pas. Rechargez la page.'],
    'classe_doublon'       => ['err', 'Cette classe existe déjà pour cette année scolaire.'],
    'matiere_doublon'      => ['err', 'Cette matière existe déjà.'],
    'affectation_doublon'  => ['err', 'Cette matière a déjà un enseignant dans cette classe. Retirez d’abord l’affectation existante.'],
    'affectation_invalide' => ['err', 'Choisissez un enseignant actif, une classe et une matière valides.'],
    'affectation_refusee'  => ['err', 'Affectation refusée : seul un compte enseignant peut être affecté.'],
    'affectation_utilisee' => ['err', 'Impossible de retirer cette affectation : des évaluations existent déjà pour cette classe et cette matière.'],
    'classe_utilisee'      => ['err', 'Impossible de supprimer cette classe : elle contient des élèves, des affectations ou des évaluations.'],
    'matiere_utilisee'     => ['err', 'Impossible de supprimer cette matière : elle est utilisée (affectations ou évaluations).'],
    'element_introuvable'  => ['err', 'Élément introuvable. Rechargez la page.'],
    'action_inconnue'      => ['err', 'Action inconnue.'],
    'erreur'               => ['err', 'Une erreur est survenue. Consultez les journaux PHP.'],
];
$etat = (string)($_GET['etat'] ?? '');
$messagePage = $messages[$etat] ?? null;
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= htmlspecialchars($themeGlobal, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Classes et matières | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<aside class="sidebar" id="menu">
<div class="brand">SchoolConnect</div>
<div class="role">Administrateur</div>
<nav class="nav" aria-label="Navigation principale">
<a href="tableau-de-bord.php">Tableau de bord</a>
<a href="comptes.php">Comptes</a>
<a href="classes-matieres.php" aria-current="page">Classes et matières</a>
<a href="sauvegardes.php">Sauvegardes</a>
<a href="journaux.php">Journaux d'audit</a>
<a href="documents.php">Documents</a>
<a href="rapports.php">Rapports et statistiques</a>
<a href="parametres.php">Paramètres</a>
</nav>
<a class="logout" href="../auth/logout.php">Se déconnecter</a>
</aside>

<div class="main">
<header class="topbar">
<button class="menu-btn" type="button" aria-controls="menu" aria-expanded="false">Menu</button>

<span class="spacer"></span>

<div class="topbar-right">
    <!-- Centre de notifications -->
    <section id="notifications-app" class="notifications-app"
             data-api-base="/SCHOOL_CONNECT/backend/api/notifications">

        <button type="button" id="notifications-toggle" class="notifications-toggle"
                aria-expanded="false" aria-controls="notifications-panel"
                aria-label="Notifications">
            <svg class="notifications-icon" viewBox="0 0 24 24" width="22" height="22"
                 fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
                <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
            </svg>
            <span id="notifications-count" class="notifications-count" hidden>0</span>
        </button>

        <section id="notifications-panel" class="notifications-panel"
                 aria-label="Centre de notifications" hidden>
            <header class="notifications-header">
                <h2>Mes notifications</h2>
                <button type="button" id="notifications-refresh"
                        class="notifications-refresh">Actualiser</button>
            </header>

            <p id="notifications-status" class="notifications-status"
               role="status" aria-live="polite">Chargement des notifications...</p>

            <ul id="notifications-list" class="notifications-list"></ul>
        </section>
    </section>

    <span class="user"><?= e($_SESSION['user_nom']) ?></span>
</div>
</header>

<main class="content">
<h1>Classes et matières</h1>
<p class="lead">Années scolaires, classes, matières et affectations des enseignants.</p>

<?php if ($messagePage !== null): ?>
<div class="mssg <?= e($messagePage[0]) ?>" role="status"><?= e($messagePage[1]) ?></div>
<?php endif; ?>

<?php if (count($annees) === 0): ?>
<section class="card">
<h2>Aucune année scolaire</h2>
<p>Ajoutez d'abord une année scolaire dans la table <code>school_years</code> pour pouvoir créer des classes.</p>
</section>
<?php endif; ?>

<div class="cols">

<section class="card">
<h2>Ajouter une classe</h2>
<form method="post" action="classes-matieres.php">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="ajouter_classe">
<div class="form-grid">
<div class="field"><label for="nom_classe">Nom de la classe</label>
<input type="text" id="nom_classe" name="nom_classe" placeholder="3e A" maxlength="40" required></div>
<div class="field"><label for="niveau">Niveau</label>
<input type="text" id="niveau" name="niveau" placeholder="3e secondaire" maxlength="40" required></div>
<div class="field"><label for="school_year_id">Année scolaire</label>
<select id="school_year_id" name="school_year_id" required <?= count($annees) === 0 ? 'disabled' : '' ?>>
<option value="">Choisir une année</option>
<?php foreach ($annees as $a): ?>
<option value="<?= (int)$a['id'] ?>"><?= e($a['libelle']) ?><?= (int)$a['active'] === 1 ? ' (active)' : '' ?></option>
<?php endforeach; ?>
</select></div>
</div>
<button class="btn" type="submit" <?= count($annees) === 0 ? 'disabled' : '' ?>>Ajouter la classe</button>
</form>
</section>

<section class="card">
<h2>Ajouter une matière</h2>
<form method="post" action="classes-matieres.php">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="ajouter_matiere">
<div class="form-grid">
<div class="field"><label for="nom_matiere">Nom de la matière</label>
<input type="text" id="nom_matiere" name="nom_matiere" placeholder="Mathématiques" maxlength="80" required></div>
<div class="field"><label for="coefficient">Coefficient (1 à 10)</label>
<input type="number" id="coefficient" name="coefficient" value="1" min="1" max="10" step="1" required></div>
<div class="field"><label for="bareme">Barème</label>
<input type="number" id="bareme" name="bareme" value="20" min="1" max="100" step="1" required></div>
</div>
<button class="btn" type="submit">Ajouter la matière</button>
</form>
</section>

</div>

<section class="card">
<h2>Affecter un enseignant</h2>
<?php if (!$enseignants || !$classes || !$matieres): ?>
<p>Pour affecter un enseignant, il faut au moins un compte enseignant actif (page Comptes), une classe et une matière.</p>
<?php else: ?>
<form method="post" action="classes-matieres.php">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="affecter">
<div class="form-grid">
<div class="field"><label for="teacher_id">Enseignant</label>
<select id="teacher_id" name="teacher_id" required>
<option value="">Choisir</option>
<?php foreach ($enseignants as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['nom']) ?></option><?php endforeach; ?>
</select></div>
<div class="field"><label for="class_id">Classe</label>
<select id="class_id" name="class_id" required>
<option value="">Choisir</option>
<?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nom']) ?> (<?= e($c['annee']) ?>)</option><?php endforeach; ?>
</select></div>
<div class="field"><label for="subject_id">Matière</label>
<select id="subject_id" name="subject_id" required>
<option value="">Choisir</option>
<?php foreach ($matieres as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['nom']) ?></option><?php endforeach; ?>
</select></div>
</div>
<button class="btn" type="submit">Affecter</button>
</form>
<?php endif; ?>
</section>

<section class="card">
<h2>Classes enregistrées (<?= count($classes) ?>)</h2>
<div class="table-wrap">
<table class="table">
<thead><tr><th scope="col">Nom</th><th scope="col">Niveau</th><th scope="col">Année scolaire</th><th scope="col"></th></tr></thead>
<tbody>
<?php if (!$classes): ?>
<tr><td colspan="4">Aucune classe enregistrée.</td></tr>
<?php endif; ?>
<?php foreach ($classes as $c): ?>
<tr>
<td><?= e($c['nom']) ?></td>
<td><?= e($c['niveau']) ?></td>
<td><?= e($c['annee']) ?></td>
<td class="actions">
<form method="post" action="classes-matieres.php" onsubmit="return confirm('Confirmer la suppression de cette classe ?')">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="supprimer_classe">
<input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
<button type="submit" class="btn btn-secondary btn-sm">Supprimer</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<section class="card">
<h2>Matières enregistrées (<?= count($matieres) ?>)</h2>
<div class="table-wrap">
<table class="table">
<thead><tr><th scope="col">Matière</th><th scope="col">Coefficient</th><th scope="col">Barème</th><th scope="col"></th></tr></thead>
<tbody>
<?php if (!$matieres): ?>
<tr><td colspan="4">Aucune matière enregistrée.</td></tr>
<?php endif; ?>
<?php foreach ($matieres as $m): ?>
<tr>
<td><?= e($m['nom']) ?></td>
<td><?= (int)$m['coefficient'] ?></td>
<td><?= (int)$m['bareme'] ?></td>
<td class="actions">
<form method="post" action="classes-matieres.php" onsubmit="return confirm('Confirmer la suppression de cette matière ?')">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="supprimer_matiere">
<input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
<button type="submit" class="btn btn-secondary btn-sm">Supprimer</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<section class="card">
<h2>Affectations des enseignants (<?= count($affectations) ?>)</h2>
<div class="table-wrap">
<table class="table">
<thead><tr><th scope="col">Enseignant</th><th scope="col">Classe</th><th scope="col">Matière</th><th scope="col">Coef.</th><th scope="col"></th></tr></thead>
<tbody>
<?php if (!$affectations): ?>
<tr><td colspan="5">Aucune affectation enregistrée.</td></tr>
<?php endif; ?>
<?php foreach ($affectations as $a): ?>
<tr>
<td><?= e($a['enseignant']) ?></td>
<td><?= e($a['classe']) ?> <small>(<?= e($a['annee']) ?>)</small></td>
<td><?= e($a['matiere']) ?></td>
<td><?= (int)$a['coefficient'] ?></td>
<td class="actions">
<form method="post" action="classes-matieres.php" onsubmit="return confirm('Retirer cette affectation ?')">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="retirer_affectation">
<input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
<button type="submit" class="btn btn-secondary btn-sm">Retirer</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

</main>
</div>
</div>
<script src="../assets/js/theme.js"></script>
<script src="../assets/js/app.js"></script>
<script src="../notifications/notifications.js"></script>
<script src="../assets/js/chat-global.js" defer></script>
</body>
</html>