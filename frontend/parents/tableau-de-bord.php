<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Tableau de bord des Parents
// Fichier : frontend/parents/tableau-de-bord.php
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';
require_once __DIR__ . '/../../backend/includes/parents.php';

require_role('parent');   // connexion + rôle + statut revérifiés en base

$pdo = db();
par_forcer_changement_mdp($pdo);

$uid     = (int) $_SESSION['user_id'];
$enfants = par_enfants($pdo, $uid);
$enfant  = par_enfant_courant($enfants);
$topbarGauche = par_topbar_gauche($enfants, $enfant);

$periode   = null;
$calcul    = ['moyenne' => null, 'matieres' => []];
$nouveaux  = $nbCopies = $nbBulletins = 0;
$dernierBulletin = null;
$derniers  = [];
$annonces  = [];
$erreur    = false;

try {
    $annonces = par_annonces($pdo, $uid, 4);

    if ($enfant) {
        $sid = (int) $enfant['id'];

        $periodes = par_periodes($pdo, (int) $enfant['school_year_id']);
        $pid = par_periode_defaut($periodes);
        foreach ($periodes as $p) {
            if ((int) $p['id'] === $pid) {
                $periode = $p;
            }
        }

        if ($pid > 0) {
            $calcul = par_moyenne_periode($pdo, $uid, $sid, $pid);
        }
        $nouveaux = par_compter_nouveaux($pdo, $uid, $sid);
        $nbCopies = count(par_copies($pdo, $uid, $sid));

        $bulletins = par_bulletins($pdo, $uid, $sid);
        $nbBulletins = count($bulletins);
        $dernierBulletin = $bulletins[0] ?? null;

        $derniers = par_resultats($pdo, $uid, $sid, 0, 0, 'date', 'desc', 6);
    }
} catch (Throwable $e) {
    error_log('SchoolConnect tableau de bord parent : ' . $e->getMessage());
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
<link rel="stylesheet" href="../assets/css/parents.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<?php par_sidebar('tableau-de-bord'); ?>

<div class="main">
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main class="content">
<h1>Bonjour</h1>

<?php if ($erreur): ?>
<div class="mssg err" role="alert">Impossible de charger vos informations. Réessayez plus tard.</div>
<?php endif; ?>

<?php if (!$enfant): ?>
<p class="lead">Bienvenue dans votre espace SchoolConnect.</p>
<div class="mssg warn" role="status">
Aucun élève n'est associé à votre compte pour le moment. Contactez l'administration de l'école
pour qu'elle vérifie le lien avec votre enfant.
</div>
<?php else: ?>
<p class="lead">
Résumé pour <strong><?= e($enfant['prenom'] . ' ' . $enfant['nom']) ?></strong>
(<?= e($enfant['classe']) ?>)<?php if ($periode): ?> — <?= e($periode['libelle']) ?><?php endif; ?>.
</p>

<div class="stats">
<div class="stat"><strong><?= $calcul['moyenne'] !== null ? e(par_nombre($calcul['moyenne'])) . ' / 20' : '—' ?></strong><span>Moyenne provisoire<?= $periode ? ' (' . e($periode['libelle']) . ')' : '' ?></span></div>
<div class="stat"><strong><?= $nouveaux ?></strong><span>Nouveaux résultats</span></div>
<div class="stat"><strong><?= $nbCopies ?></strong><span>Copies disponibles</span></div>
<div class="stat"><strong><?= $nbBulletins ?></strong><span>Bulletins publiés</span></div>
</div>

<?php if ($dernierBulletin): ?>
<div class="mssg success" role="status">
Dernier bulletin (<?= e($dernierBulletin['periode']) ?>, <?= e($dernierBulletin['annee']) ?>) :
moyenne <strong><?= e(par_nombre($dernierBulletin['moyenne'])) ?> / 20</strong>,
rang <strong><?= (int) $dernierBulletin['rang'] ?> sur <?= (int) $dernierBulletin['effectif'] ?></strong>,
<?= e($dernierBulletin['appreciation']) ?>.
<a href="bulletins.php">Voir les bulletins</a>
</div>
<?php endif; ?>

<div class="cols">
<section class="card">
<h2>Derniers résultats</h2>
<div class="table-wrap">
<table class="table">
<thead><tr>
<th scope="col">Matière</th><th scope="col">Évaluation</th><th scope="col">Note</th>
</tr></thead>
<tbody>
<?php if (!$derniers): ?>
<tr><td colspan="3">Aucun résultat publié pour le moment.</td></tr>
<?php endif; ?>
<?php foreach ($derniers as $r): ?>
<tr>
<td><?= e($r['matiere']) ?></td>
<td><?= e($r['titre']) ?><?php if ((int) $r['nouveau'] === 1): ?> <span class="badge">Nouveau</span><?php endif; ?></td>
<td><?= e(par_nombre($r['note'])) ?> / <?= e(par_nombre($r['bareme'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="muted" style="margin-top:12px"><a href="resultats.php">Tous les résultats</a></p>
</section>

<section class="card">
<h2>Moyennes par matière</h2>
<?php if (!$calcul['matieres']): ?>
<p class="muted">Les moyennes apparaîtront dès la publication des premières notes.</p>
<?php endif; ?>
<?php foreach ($calcul['matieres'] as $m): $pct = max(0, min(100, (int) round($m['moyenne'] / 20 * 100))); ?>
<div class="bar-row"><span><?= e($m['matiere']) ?></span>
<div class="bar" role="img" aria-label="<?= e(par_nombre($m['moyenne'])) ?> sur 20"><i style="width:<?= $pct ?>%"></i></div>
<strong><?= e(par_nombre($m['moyenne'], 1)) ?></strong></div>
<?php endforeach; ?>
</section>
</div>
<?php endif; ?>

<section class="card">
<h2>Annonces</h2>
<?php if (!$annonces): ?>
<p class="muted">Aucune annonce pour le moment.</p>
<?php else: ?>
<ul class="list">
<?php foreach ($annonces as $a): ?>
<li><strong><?= e($a['titre']) ?></strong>
<span class="badge"><?= e(PAR_TYPES_ANNONCE[$a['type']] ?? $a['type']) ?></span><br>
<time><?= e(par_date($a['created_at'])) ?></time></li>
<?php endforeach; ?>
</ul>
<p class="muted" style="margin-top:12px"><a href="annonces.php">Toutes les annonces</a></p>
<?php endif; ?>
</section>
</main>
</div>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/app.js"></script>
<script src="../assets/js/parents.js"></script>
<script src="../notifications/notifications.js"></script>
<script src="../assets/js/chat-global.js" defer></script>
</body>
</html>