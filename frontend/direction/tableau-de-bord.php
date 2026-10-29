<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Tableau de bord de la Direction
// Fichier : frontend/direction/tableau-de-bord.php
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';
require_once __DIR__ . '/../../backend/includes/direction.php';

require_role('direction');   // connexion + rôle + statut revérifiés en base

$pdo = db();
$annee = dir_annee_active($pdo);

$eleves = $enseignants = $brouillons = $bulletins = $annoncesActives = 0;
$moyenne = null;
$publications = [];
$erreur = false;

try {
    $enseignants = (int) $pdo->query(
        "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.code = 'enseignant' AND u.statut = 'actif'"
    )->fetchColumn();

    $annoncesActives = (int) $pdo->query("SELECT COUNT(*) FROM announcements WHERE statut = 'publiee'")->fetchColumn();

    if ($annee) {
        $aid = (int) $annee['id'];

        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM students s JOIN classes c ON c.id = s.class_id
             WHERE c.school_year_id = ? AND s.statut = 'inscrit'"
        );
        $q->execute([$aid]);
        $eleves = (int) $q->fetchColumn();

        // Évaluations dont les notes ne sont pas encore publiées.
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM assessments a JOIN classes c ON c.id = a.class_id
             WHERE c.school_year_id = ? AND a.statut = 'brouillon'"
        );
        $q->execute([$aid]);
        $brouillons = (int) $q->fetchColumn();

        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM report_cards rc JOIN classes c ON c.id = rc.class_id
             WHERE c.school_year_id = ? AND rc.statut = 'publie'"
        );
        $q->execute([$aid]);
        $bulletins = (int) $q->fetchColumn();

        $q = $pdo->prepare(
            'SELECT ' . DIR_MOYENNE_SQL . " AS m
             FROM grades g
             JOIN assessments a ON a.id = g.assessment_id
             JOIN classes c ON c.id = a.class_id
             JOIN students s ON s.id = g.student_id AND s.statut = 'inscrit'
             WHERE c.school_year_id = ? AND a.statut = 'publiee'"
        );
        $q->execute([$aid]);
        $m = $q->fetchColumn();
        $moyenne = $m !== null && $m !== false ? (float) $m : null;

        $q = $pdo->prepare(
            "SELECT a.titre, a.published_at, c.nom AS classe, s.nom AS matiere, u.nom AS enseignant
             FROM assessments a
             JOIN classes c ON c.id = a.class_id
             JOIN subjects s ON s.id = a.subject_id
             JOIN users u ON u.id = a.teacher_id
             WHERE c.school_year_id = ? AND a.statut = 'publiee'
             ORDER BY a.published_at DESC, a.id DESC
             LIMIT 8"
        );
        $q->execute([$aid]);
        $publications = $q->fetchAll();
    }
} catch (Throwable $e) {
    error_log('SchoolConnect tableau de bord direction : ' . $e->getMessage());
    $erreur = true;
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tableau de bord | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<?php dir_sidebar('tableau-de-bord'); ?>

<div class="main">
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main class="content">
<h1>Tableau de bord</h1>
<p class="lead">Suivi de l'établissement<?php if ($annee): ?> — année scolaire <?= e($annee['libelle']) ?><?php endif; ?>.</p>

<?php if ($erreur): ?>
<div class="mssg err" role="alert">Impossible de charger les chiffres. Réessayez plus tard.</div>
<?php elseif (!$annee): ?>
<div class="mssg warn" role="status">Aucune année scolaire active. Contactez l'administrateur.</div>
<?php endif; ?>

<div class="stats">
<div class="stat"><strong><?= $eleves ?></strong><span>Élèves inscrits</span></div>
<div class="stat"><strong><?= $enseignants ?></strong><span>Enseignants actifs</span></div>
<div class="stat"><strong><?= $moyenne !== null ? e(dir_nombre($moyenne)) . ' / 20' : '—' ?></strong><span>Moyenne générale (notes publiées)</span></div>
<div class="stat"><strong><?= $brouillons ?></strong><span>Évaluations non publiées</span></div>
<div class="stat"><strong><?= $bulletins ?></strong><span>Bulletins publiés</span></div>
<div class="stat"><strong><?= $annoncesActives ?></strong><span>Annonces visibles</span></div>
</div>

<section class="card">
<h2>Dernières publications</h2>
<div class="table-wrap">
<table class="table">
<thead><tr>
<th scope="col">Évaluation</th><th scope="col">Classe</th><th scope="col">Matière</th>
<th scope="col">Enseignant</th><th scope="col">Publiée le</th>
</tr></thead>
<tbody>
<?php if (!$publications): ?>
<tr><td colspan="5">Aucun résultat publié pour le moment.</td></tr>
<?php endif; ?>
<?php foreach ($publications as $p): ?>
<tr>
<td><?= e($p['titre']) ?></td>
<td><?= e($p['classe']) ?></td>
<td><?= e($p['matiere']) ?></td>
<td><?= e($p['enseignant']) ?></td>
<td><?= e(dir_date($p['published_at'], true)) ?></td>
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