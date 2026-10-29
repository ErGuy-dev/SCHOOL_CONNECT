<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Statistiques de la Direction
// Fichier : frontend/direction/statistiques.php
//
// Sources : uniquement les notes des évaluations PUBLIÉES des élèves inscrits.
// Notes ramenées sur 20 ; moyennes pondérées par le coefficient de l'évaluation.
// Réussite = note ≥ 10/20.
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';
require_once __DIR__ . '/../../backend/includes/direction.php';

require_role('direction');

$pdo = db();

$annees = $pdo->query('SELECT id, libelle, active FROM school_years ORDER BY debut DESC')->fetchAll();

// Année demandée si elle existe, sinon l'année active, sinon la plus récente.
$anneeId = (int) ($_GET['school_year_id'] ?? 0);
$ids = array_map(static fn($a) => (int) $a['id'], $annees);
if (!in_array($anneeId, $ids, true)) {
    $anneeId = 0;
    foreach ($annees as $a) {
        if ((int) $a['active'] === 1) { $anneeId = (int) $a['id']; break; }
    }
    if ($anneeId === 0 && $annees) { $anneeId = (int) $annees[0]['id']; }
}

$periodes = [];
$classes  = [];
if ($anneeId) {
    $st = $pdo->prepare('SELECT id, libelle FROM evaluation_periods WHERE school_year_id = ? ORDER BY debut, id');
    $st->execute([$anneeId]);
    $periodes = $st->fetchAll();

    $st = $pdo->prepare('SELECT id, nom FROM classes WHERE school_year_id = ? ORDER BY nom');
    $st->execute([$anneeId]);
    $classes = $st->fetchAll();
}

$periodeId = (int) ($_GET['period_id'] ?? 0);
if (!in_array($periodeId, array_map(static fn($p) => (int) $p['id'], $periodes), true)) { $periodeId = 0; }
$classeId = (int) ($_GET['class_id'] ?? 0);
if (!in_array($classeId, array_map(static fn($c) => (int) $c['id'], $classes), true)) { $classeId = 0; }

$resume = ['eleves' => 0, 'notes' => 0, 'moyenne' => null, 'reussite' => null];
$parClasse = $parMatiere = $evolution = [];
$erreur = false;

if ($anneeId) {
    try {
        $base = "FROM grades g
                 JOIN assessments a ON a.id = g.assessment_id
                 JOIN classes c ON c.id = a.class_id
                 JOIN subjects sub ON sub.id = a.subject_id
                 JOIN students s ON s.id = g.student_id AND s.statut = 'inscrit'
                 JOIN evaluation_periods p ON p.id = a.period_id";
        $where  = ['c.school_year_id = ?', "a.statut = 'publiee'"];
        $params = [$anneeId];
        if ($classeId)  { $where[] = 'c.id = ?';        $params[] = $classeId; }
        $whereSansPeriode = $where;
        $paramsSansPeriode = $params;
        if ($periodeId) { $where[] = 'a.period_id = ?'; $params[] = $periodeId; }
        $w = ' WHERE ' . implode(' AND ', $where);

        $st = $pdo->prepare(
            'SELECT COUNT(DISTINCT g.student_id) AS eleves, COUNT(*) AS notes, '
            . DIR_MOYENNE_SQL . ' AS moyenne, ' . DIR_REUSSITE_SQL . ' AS reussite ' . $base . $w
        );
        $st->execute($params);
        $r = $st->fetch();
        $resume = [
            'eleves' => (int) $r['eleves'], 'notes' => (int) $r['notes'],
            'moyenne' => $r['moyenne'] !== null ? (float) $r['moyenne'] : null,
            'reussite' => $r['reussite'] !== null ? (float) $r['reussite'] : null,
        ];

        $st = $pdo->prepare(
            'SELECT c.nom AS libelle, COUNT(DISTINCT g.student_id) AS eleves, '
            . DIR_MOYENNE_SQL . ' AS moyenne, ' . DIR_REUSSITE_SQL . ' AS reussite '
            . $base . $w . ' GROUP BY c.id, c.nom ORDER BY c.nom'
        );
        $st->execute($params);
        $parClasse = $st->fetchAll();

        $st = $pdo->prepare(
            'SELECT sub.nom AS libelle, COUNT(DISTINCT c.id) AS classes, COUNT(*) AS notes, '
            . DIR_MOYENNE_SQL . ' AS moyenne, ' . DIR_REUSSITE_SQL . ' AS reussite '
            . $base . $w . ' GROUP BY sub.id, sub.nom ORDER BY sub.nom'
        );
        $st->execute($params);
        $parMatiere = $st->fetchAll();

        // Analyse comparative : moyenne de chaque classe, période par période.
        $st = $pdo->prepare(
            'SELECT c.nom AS classe, p.id AS pid, ' . DIR_MOYENNE_SQL . ' AS moyenne '
            . $base . ' WHERE ' . implode(' AND ', $whereSansPeriode)
            . ' GROUP BY c.id, c.nom, p.id ORDER BY c.nom'
        );
        $st->execute($paramsSansPeriode);
        foreach ($st->fetchAll() as $r) {
            $evolution[$r['classe']][(int) $r['pid']] = (float) $r['moyenne'];
        }
    } catch (Throwable $e) {
        error_log('SchoolConnect statistiques direction : ' . $e->getMessage());
        $erreur = true;
    }
}

function barre(?float $valeur, float $max): int
{
    return $valeur === null ? 0 : (int) max(0, min(100, round($valeur / $max * 100)));
}
$filtres = ($periodeId || $classeId);
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Statistiques | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<?php dir_sidebar('statistiques'); ?>

<div class="main">
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main class="content">
<h1>Statistiques</h1>
<p class="lead">Moyennes et taux de réussite des résultats publiés (notes ramenées sur 20, réussite à partir de 10).</p>

<?php if ($erreur): ?>
<div class="mssg err" role="alert">Impossible de calculer les statistiques. Réessayez plus tard.</div>
<?php endif; ?>

<?php if (!$annees): ?>
<div class="mssg warn" role="status">Aucune année scolaire n'existe. Contactez l'administrateur.</div>
<?php else: ?>

<form class="toolbar" method="get" action="statistiques.php">
<select name="school_year_id" aria-label="Année scolaire">
<?php foreach ($annees as $a): ?>
<option value="<?= (int) $a['id'] ?>"<?= (int) $a['id'] === $anneeId ? ' selected' : '' ?>><?= e($a['libelle']) ?><?= (int) $a['active'] === 1 ? ' (active)' : '' ?></option>
<?php endforeach; ?>
</select>
<select name="period_id" aria-label="Période">
<option value="0">Toutes les périodes</option>
<?php foreach ($periodes as $p): ?>
<option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === $periodeId ? ' selected' : '' ?>><?= e($p['libelle']) ?></option>
<?php endforeach; ?>
</select>
<select name="class_id" aria-label="Classe">
<option value="0">Toutes les classes</option>
<?php foreach ($classes as $c): ?>
<option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $classeId ? ' selected' : '' ?>><?= e($c['nom']) ?></option>
<?php endforeach; ?>
</select>
<button class="btn btn-sm" type="submit">Afficher</button>
<?php if ($filtres): ?><a class="btn btn-secondary btn-sm" href="statistiques.php?school_year_id=<?= $anneeId ?>">Réinitialiser</a><?php endif; ?>
</form>

<div class="stats">
<div class="stat"><strong><?= $resume['moyenne'] !== null ? e(dir_nombre($resume['moyenne'])) : '—' ?></strong><span>Moyenne générale / 20</span></div>
<div class="stat"><strong><?= $resume['reussite'] !== null ? e(dir_nombre($resume['reussite'], 1)) . ' %' : '—' ?></strong><span>Taux de réussite</span></div>
<div class="stat"><strong><?= $resume['eleves'] ?></strong><span>Élèves notés</span></div>
<div class="stat"><strong><?= $resume['notes'] ?></strong><span>Notes prises en compte</span></div>
</div>

<?php if ($resume['notes'] === 0 && !$erreur): ?>
<div class="mssg warn" role="status">Aucun résultat publié pour ces filtres.</div>
<?php else: ?>

<div class="cols">
<section class="card">
<h2>Moyenne par classe (/ 20)</h2>
<?php foreach ($parClasse as $r): $m = $r['moyenne'] !== null ? (float) $r['moyenne'] : null; ?>
<div class="bar-row"><span><?= e($r['libelle']) ?></span>
<div class="bar" role="img" aria-label="<?= e(dir_nombre($m)) ?> sur 20"><i style="width:<?= barre($m, 20) ?>%"></i></div>
<strong><?= e(dir_nombre($m, 1)) ?></strong></div>
<?php endforeach; ?>
</section>

<section class="card">
<h2>Taux de réussite par classe</h2>
<?php foreach ($parClasse as $r): $t = $r['reussite'] !== null ? (float) $r['reussite'] : null; ?>
<div class="bar-row"><span><?= e($r['libelle']) ?></span>
<div class="bar" role="img" aria-label="<?= e(dir_nombre($t, 1)) ?> pour cent"><i style="width:<?= barre($t, 100) ?>%"></i></div>
<strong><?= e(dir_nombre($t, 0)) ?> %</strong></div>
<?php endforeach; ?>
<p class="muted">Part des notes égales ou supérieures à 10/20.</p>
</section>
</div>

<section class="card">
<h2>Résultats par matière</h2>
<div class="table-wrap">
<table class="table">
<thead><tr><th scope="col">Matière</th><th scope="col">Moyenne / 20</th><th scope="col">Réussite</th><th scope="col">Classes</th><th scope="col">Notes</th></tr></thead>
<tbody>
<?php foreach ($parMatiere as $r): ?>
<tr>
<td><?= e($r['libelle']) ?></td>
<td><?= e(dir_nombre($r['moyenne'])) ?></td>
<td><?= e(dir_nombre($r['reussite'], 1)) ?> %</td>
<td><?= (int) $r['classes'] ?></td>
<td><?= (int) $r['notes'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<section class="card">
<h2>Évolution par période (moyenne / 20)</h2>
<div class="table-wrap">
<table class="table">
<thead><tr>
<th scope="col">Classe</th>
<?php foreach ($periodes as $p): ?><th scope="col"><?= e($p['libelle']) ?></th><?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach ($evolution as $classe => $parPeriode): ?>
<tr>
<td><?= e((string) $classe) ?></td>
<?php foreach ($periodes as $p): $v = $parPeriode[(int) $p['id']] ?? null; ?>
<td><?= $v !== null ? e(dir_nombre($v)) : '<span class="muted">—</span>' ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<?php endif; ?>
<?php endif; ?>
</main>
</div>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/app.js"></script>
<script src="../notifications/notifications.js"></script>
<script src="../assets/js/chat-global.js" defer></script>
</body>
</html>