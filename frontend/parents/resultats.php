<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Résultats de l'enfant (parents)
// Fichier : frontend/parents/resultats.php
//
// Seuls les résultats PUBLIÉS sont lus. Filtres : période, matière, recherche ;
// tri : date, matière, évaluation, note.
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

$periodes = $matieres = $resultats = [];
$calcul   = ['moyenne' => null, 'matieres' => []];
$periodId = $matiereId = 0;
$q        = '';
$tri      = 'date';
$ordre    = 'desc';
$erreur   = false;

try {
    if ($enfant) {
        $sid = (int) $enfant['id'];
        $periodes = par_periodes($pdo, (int) $enfant['school_year_id']);
        $idsPeriodes = array_map(static fn(array $p): int => (int) $p['id'], $periodes);

        // Période demandée, sinon la période en cours.
        $periodId = (int) ($_GET['period_id'] ?? 0);
        if (!in_array($periodId, $idsPeriodes, true)) {
            $periodId = par_periode_defaut($periodes);
        }

        $calcul   = par_moyenne_periode($pdo, $uid, $sid, $periodId);
        $matieres = $calcul['matieres'];

        $matiereId = (int) ($_GET['matiere_id'] ?? 0);
        if (!in_array($matiereId, array_map(static fn(array $m): int => $m['id'], $matieres), true)) {
            $matiereId = 0;
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) > 80) {
            $q = mb_substr($q, 0, 80);
        }

        $tri = (string) ($_GET['tri'] ?? 'date');
        if (!in_array($tri, ['date', 'matiere', 'titre', 'note'], true)) {
            $tri = 'date';
        }
        $ordre = ($_GET['ordre'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $resultats = par_resultats($pdo, $uid, $sid, $periodId, $matiereId, $tri, $ordre);

        // Recherche sur le titre de l'évaluation (filtre simple, côté affichage).
        if ($q !== '') {
            $resultats = array_values(array_filter(
                $resultats,
                static fn(array $r): bool => mb_stripos((string) $r['titre'], $q) !== false
            ));
        }

        // Les résultats viennent d'être affichés : ils ne sont plus « nouveaux ».
        // (la valeur « nouveau » a été lue AVANT ce marquage, pour pouvoir afficher le badge)
        $aMarquer = true;
    }
} catch (Throwable $e) {
    error_log('SchoolConnect résultats parent : ' . $e->getMessage());
    $erreur = true;
}

$nouveauxAffiches = array_filter($resultats, static fn(array $r): bool => (int) $r['nouveau'] === 1);

if (!empty($aMarquer) && !$erreur && $enfant) {
    par_marquer_resultats_lus($pdo, $uid, (int) $enfant['id'], $periodId);
}

function lien_tri(string $col, string $tri, string $ordre): string
{
    $nouveau = ($tri === $col && $ordre === 'asc') ? 'desc' : 'asc';
    $p = $_GET;
    $p['tri'] = $col;
    $p['ordre'] = $nouveau;
    return 'resultats.php?' . http_build_query($p);
}

function fleche(string $col, string $tri, string $ordre): string
{
    return $tri === $col ? ($ordre === 'asc' ? ' ▲' : ' ▼') : '';
}

$periodeLibelle = '';
foreach ($periodes as $p) {
    if ((int) $p['id'] === $periodId) {
        $periodeLibelle = (string) $p['libelle'];
    }
}
$filtresActifs = ($matiereId > 0 || $q !== '');
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Résultats | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../assets/css/parents.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<?php par_sidebar('resultats'); ?>

<div class="main">
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main class="content">
<h1>Résultats</h1>
<p class="lead">Seuls les résultats publiés par les enseignants sont affichés.</p>

<?php if ($erreur): ?>
<div class="mssg err" role="alert">Impossible de charger les résultats. Réessayez plus tard.</div>
<?php endif; ?>

<?php if (!$enfant): ?>
<div class="mssg warn" role="status">Aucun élève n'est associé à votre compte. Contactez l'administration de l'école.</div>
<?php else: ?>

<section class="card">
<h2>Choisir la période</h2>
<form method="get" class="toolbar">
<select name="period_id" aria-label="Période" data-auto-submit>
<?php foreach ($periodes as $p): ?>
<option value="<?= (int) $p['id'] ?>"<?= $periodId === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['libelle']) ?></option>
<?php endforeach; ?>
</select>
<noscript><button class="btn btn-sm" type="submit">Afficher</button></noscript>
</form>

<div class="stats" style="margin-top:18px">
<div class="stat"><strong><?= $calcul['moyenne'] !== null ? e(par_nombre($calcul['moyenne'])) . ' / 20' : '—' ?></strong><span>Moyenne provisoire</span></div>
<div class="stat"><strong><?= count($calcul['matieres']) ?></strong><span>Matières évaluées</span></div>
<div class="stat"><strong><?= count($nouveauxAffiches) ?></strong><span>Nouveaux résultats</span></div>
</div>
<p class="muted">Moyenne provisoire de <?= e($periodeLibelle) ?> : calculée avec les seuls résultats publiés. Le bulletin publié par l'école fait foi.</p>
</section>

<?php if ($calcul['matieres']): ?>
<section class="card">
<h2>Moyenne par matière</h2>
<?php foreach ($calcul['matieres'] as $m): $pct = max(0, min(100, (int) round($m['moyenne'] / 20 * 100))); ?>
<div class="bar-row"><span><?= e($m['matiere']) ?></span>
<div class="bar" role="img" aria-label="<?= e(par_nombre($m['moyenne'])) ?> sur 20"><i style="width:<?= $pct ?>%"></i></div>
<strong><?= e(par_nombre($m['moyenne'], 1)) ?></strong></div>
<?php endforeach; ?>
<p class="muted">Coefficients : <?= e(implode(' · ', array_map(static fn(array $m): string => $m['matiere'] . ' ×' . $m['coefficient'], $calcul['matieres']))) ?>.</p>
</section>
<?php endif; ?>

<section class="card">
<h2>Détail des évaluations</h2>

<form method="get" class="toolbar">
<input type="hidden" name="period_id" value="<?= (int) $periodId ?>">
<input type="search" name="q" placeholder="Rechercher une évaluation" value="<?= e($q) ?>" aria-label="Rechercher une évaluation">
<select name="matiere_id" aria-label="Matière">
<option value="0">Toutes les matières</option>
<?php foreach ($matieres as $m): ?>
<option value="<?= (int) $m['id'] ?>"<?= $matiereId === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['matiere']) ?></option>
<?php endforeach; ?>
</select>
<input type="hidden" name="tri" value="<?= e($tri) ?>">
<input type="hidden" name="ordre" value="<?= e($ordre) ?>">
<button class="btn btn-sm" type="submit">Filtrer</button>
<?php if ($filtresActifs): ?><a class="btn btn-secondary btn-sm" href="resultats.php?period_id=<?= (int) $periodId ?>">Réinitialiser</a><?php endif; ?>
</form>

<div class="table-wrap">
<table class="table">
<thead><tr>
<th scope="col"><a href="<?= e(lien_tri('matiere', $tri, $ordre)) ?>">Matière<?= fleche('matiere', $tri, $ordre) ?></a></th>
<th scope="col"><a href="<?= e(lien_tri('titre', $tri, $ordre)) ?>">Évaluation<?= fleche('titre', $tri, $ordre) ?></a></th>
<th scope="col"><a href="<?= e(lien_tri('date', $tri, $ordre)) ?>">Date<?= fleche('date', $tri, $ordre) ?></a></th>
<th scope="col"><a href="<?= e(lien_tri('note', $tri, $ordre)) ?>">Note<?= fleche('note', $tri, $ordre) ?></a></th>
<th scope="col">Sur 20</th>
<th scope="col">Coef.</th>
<th scope="col">Appréciation</th>
</tr></thead>
<tbody>
<?php if (!$resultats): ?>
<tr><td colspan="7"><?= $filtresActifs ? 'Aucun résultat ne correspond à ces filtres.' : 'Aucun résultat publié pour cette période.' ?></td></tr>
<?php endif; ?>
<?php foreach ($resultats as $r):
    $sur20 = (float) $r['bareme'] > 0 ? (float) $r['note'] / (float) $r['bareme'] * 20 : 0.0; ?>
<tr>
<td><?= e($r['matiere']) ?></td>
<td><?= e($r['titre']) ?><?php if ((int) $r['nouveau'] === 1): ?> <span class="badge">Nouveau</span><?php endif; ?>
<br><small class="muted"><?= e(PAR_TYPES_EVALUATION[$r['type']] ?? (string) $r['type']) ?></small></td>
<td><?= e(par_date($r['date_eval'])) ?></td>
<td><strong><?= e(par_nombre($r['note'])) ?> / <?= e(par_nombre($r['bareme'])) ?></strong></td>
<td><span class="badge <?= e(par_badge_note($sur20)) ?>"><?= e(par_nombre($sur20)) ?></span></td>
<td><?= e(par_nombre($r['coef_eval'])) ?></td>
<td><?= e(par_appreciation($sur20)) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
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