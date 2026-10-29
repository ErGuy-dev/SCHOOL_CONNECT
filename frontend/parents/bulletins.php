<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Bulletins de l'enfant (parents)
// Fichier : frontend/parents/bulletins.php
//
// Le bulletin est la copie figée (report_cards.detail) publiée par la Direction.
// Un bulletin retiré par la Direction disparaît immédiatement de cette page.
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';
require_once __DIR__ . '/../../backend/includes/parents.php';

require_role('parent');

$pdo = db();
par_forcer_changement_mdp($pdo);

$uid     = (int) $_SESSION['user_id'];
$enfants = par_enfants($pdo, $uid);
$enfant  = par_enfant_courant($enfants);
$topbarGauche = par_topbar_gauche($enfants, $enfant);

$bulletins = [];
$annees    = [];
$fAnnee    = '';
$erreur    = false;

try {
    if ($enfant) {
        $bulletins = par_bulletins($pdo, $uid, (int) $enfant['id']);

        foreach ($bulletins as $b) {
            $annees[(string) $b['annee']] = true;
        }
        $annees = array_keys($annees);

        $fAnnee = (string) ($_GET['annee'] ?? '');
        if (!in_array($fAnnee, $annees, true)) {
            $fAnnee = '';
        }
        if ($fAnnee !== '') {
            $bulletins = array_values(array_filter(
                $bulletins,
                static fn(array $b): bool => (string) $b['annee'] === $fAnnee
            ));
        }
    }
} catch (Throwable $e) {
    error_log('SchoolConnect bulletins parent : ' . $e->getMessage());
    $erreur = true;
}

// Lecture sûre du détail figé : [{matiere, coefficient, moyenne}, ...]
function detail_bulletin(?string $json): array
{
    $liste = json_decode((string) $json, true);
    return is_array($liste) ? $liste : [];
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bulletins | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../assets/css/parents.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<?php par_sidebar('bulletins'); ?>

<div class="main">
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main class="content">
<h1>Bulletins</h1>
<p class="lead">Bulletins validés et publiés par l'école.</p>

<?php if ($erreur): ?>
<div class="mssg err" role="alert">Impossible de charger les bulletins. Réessayez plus tard.</div>
<?php endif; ?>

<?php if (!$enfant): ?>
<div class="mssg warn" role="status">Aucun élève n'est associé à votre compte. Contactez l'administration de l'école.</div>
<?php else: ?>

<section class="card">
<h2>Bulletins de <?= e($enfant['prenom'] . ' ' . $enfant['nom']) ?></h2>

<?php if (count($annees) > 1): ?>
<form method="get" class="toolbar">
<select name="annee" aria-label="Année scolaire" data-auto-submit>
<option value="">Toutes les années</option>
<?php foreach ($annees as $a): ?>
<option value="<?= e($a) ?>"<?= $fAnnee === $a ? ' selected' : '' ?>><?= e($a) ?></option>
<?php endforeach; ?>
</select>
<noscript><button class="btn btn-sm" type="submit">Filtrer</button></noscript>
</form>
<?php endif; ?>

<div class="table-wrap">
<table class="table">
<thead><tr>
<th scope="col">Période</th>
<th scope="col">Année</th>
<th scope="col">Moyenne</th>
<th scope="col">Rang</th>
<th scope="col">Appréciation</th>
<th scope="col"></th>
</tr></thead>
<tbody>
<?php if (!$bulletins): ?>
<tr><td colspan="6">Aucun bulletin publié pour le moment. Vous serez notifié dès sa publication.</td></tr>
<?php endif; ?>
<?php foreach ($bulletins as $b): $detail = detail_bulletin($b['detail']); ?>
<tr>
<td>
<?= e($b['periode']) ?>
<?php if ($detail): ?>
<details class="bulletin-detail">
<summary>Détail par matière</summary>
<table class="table">
<thead><tr><th scope="col">Matière</th><th scope="col">Coef.</th><th scope="col">Moyenne</th></tr></thead>
<tbody>
<?php foreach ($detail as $d): ?>
<tr>
<td><?= e((string) ($d['matiere'] ?? '')) ?></td>
<td><?= (int) ($d['coefficient'] ?? 1) ?></td>
<td><?= e(par_nombre($d['moyenne'] ?? 0)) ?> / 20</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</details>
<?php endif; ?>
</td>
<td><?= e($b['annee']) ?></td>
<td><strong><?= e(par_nombre($b['moyenne'])) ?> / 20</strong></td>
<td><?= (int) $b['rang'] ?> sur <?= (int) $b['effectif'] ?></td>
<td><span class="badge <?= e(par_badge_note((float) $b['moyenne'])) ?>"><?= e($b['appreciation']) ?></span></td>
<td class="actions"><a class="btn btn-sm" href="../../backend/api/bulletins/telecharger.php?id=<?= (int) $b['id'] ?>">Télécharger le PDF</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="muted">Publié le <?= $bulletins ? e(par_date($bulletins[0]['published_at'], true)) : '—' ?> (dernier bulletin affiché).</p>
</section>
<?php endif; ?>
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