<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Bulletins (calcul et publication)
// Fichier : frontend/direction/bulletins.php
//
// Le bulletin est une copie figée (report_cards.detail) calculée à partir des
// évaluations PUBLIÉES. Republier recalcule ; retirer le cache aux parents.
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';
require_once __DIR__ . '/../../backend/includes/direction.php';

require_role('direction');

$pdo = db();
$uid = (int) $_SESSION['user_id'];
$annee = dir_annee_active($pdo);

$classes = $periodes = [];
if ($annee) {
    $st = $pdo->prepare('SELECT id, nom FROM classes WHERE school_year_id = ? ORDER BY nom');
    $st->execute([(int) $annee['id']]);
    $classes = $st->fetchAll();

    $st = $pdo->prepare('SELECT id, libelle FROM evaluation_periods WHERE school_year_id = ? ORDER BY debut, id');
    $st->execute([(int) $annee['id']]);
    $periodes = $st->fetchAll();
}

function rediriger(string $etat, int $classId = 0, int $periodId = 0): void
{
    $q = ['etat' => $etat];
    if ($classId > 0 && $periodId > 0) {
        $q = ['class_id' => $classId, 'period_id' => $periodId] + $q;
    }
    header('Location: bulletins.php?' . http_build_query($q), true, 303);
    exit;
}

$classId   = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
$periodId  = (int) ($_POST['period_id'] ?? $_GET['period_id'] ?? 0);
$classeOk  = in_array($classId, array_map(static fn($c) => (int) $c['id'], $classes), true);
$periodeOk = in_array($periodId, array_map(static fn($p) => (int) $p['id'], $periodes), true);

/* ---------- Publication / retrait ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if (!$annee) {
        rediriger('annee_absente');
    }
    if (!in_array($action, ['publier', 'retirer'], true)) {
        rediriger('action_inconnue');
    }
    if (!$classeOk || !$periodeOk) {
        rediriger('bulletins_invalide');
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'publier') {
            $bulletins = dir_calculer_bulletins($pdo, $classId, $periodId);
            if (!$bulletins) {
                throw new InvalidArgumentException('bulletins_vide');
            }
            $up = $pdo->prepare(
                "INSERT INTO report_cards
                    (student_id, class_id, period_id, moyenne, rang, effectif, appreciation, detail, statut, published_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'publie', ?)
                 ON DUPLICATE KEY UPDATE
                    class_id = VALUES(class_id), moyenne = VALUES(moyenne), rang = VALUES(rang),
                    effectif = VALUES(effectif), appreciation = VALUES(appreciation), detail = VALUES(detail),
                    statut = 'publie', published_by = VALUES(published_by), published_at = NOW()"
            );
            foreach ($bulletins as $b) {
                $up->execute([
                    $b['student_id'], $classId, $periodId, $b['moyenne'], $b['rang'], $b['effectif'],
                    $b['appreciation'],
                    json_encode($b['matieres'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $uid,
                ]);
            }
            audit('Publication de ' . count($bulletins) . ' bulletin(s) : classe #' . $classId . ', période #' . $periodId);
            $etat = 'bulletins_ok';
        } else {
            $pdo->prepare("UPDATE report_cards SET statut = 'retire' WHERE class_id = ? AND period_id = ?")
                ->execute([$classId, $periodId]);
            audit('Retrait des bulletins : classe #' . $classId . ', période #' . $periodId);
            $etat = 'bulletins_retires';
        }

        $pdo->commit();

        if ($action === 'publier') {
            dir_notifier_parents($pdo, $classId, 'Bulletin disponible', 'Le bulletin de la période est disponible.', '../parents/bulletins.php');
        }
        rediriger($etat, $classId, $periodId);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        rediriger($e->getMessage(), $classId, $periodId);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('SchoolConnect bulletins : ' . $e->getMessage());
        rediriger('erreur', $classId, $periodId);
    }
}

/* ---------- Aperçu ---------- */
$apercu = [];
$statuts = [];          // student_id => statut du bulletin déjà enregistré
$nbNonPubliees = 0;
if ($annee && $classeOk && $periodeOk) {
    $apercu = dir_calculer_bulletins($pdo, $classId, $periodId);

    $st = $pdo->prepare('SELECT student_id, statut FROM report_cards WHERE class_id = ? AND period_id = ?');
    $st->execute([$classId, $periodId]);
    foreach ($st->fetchAll() as $r) {
        $statuts[(int) $r['student_id']] = $r['statut'];
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM assessments WHERE class_id = ? AND period_id = ? AND statut = 'brouillon'");
    $st->execute([$classId, $periodId]);
    $nbNonPubliees = (int) $st->fetchColumn();
}
$nbPublies = count(array_filter($statuts, static fn($s) => $s === 'publie'));

$messages    = dir_messages();
$etat        = (string) ($_GET['etat'] ?? '');
$messagePage = $messages[$etat] ?? null;
$csrf        = csrf_token();
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bulletins | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<?php dir_sidebar('bulletins'); ?>

<div class="main">
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main class="content">
<h1>Bulletins</h1>
<p class="lead">Calculez les moyennes et le rang de chaque élève à partir des résultats publiés, puis publiez les bulletins aux parents.</p>

<?php if ($messagePage !== null): ?>
<div class="mssg <?= e($messagePage[0]) ?>" role="status"><?= e($messagePage[1]) ?></div>
<?php endif; ?>

<?php if (!$annee): ?>
<div class="mssg warn" role="status">Aucune année scolaire active. Contactez l'administrateur.</div>
<?php elseif (!$classes || !$periodes): ?>
<div class="mssg warn" role="status">Il faut au moins une classe et une période d'évaluation pour l'année active.</div>
<?php else: ?>

<section class="card">
<form class="toolbar" method="get" action="bulletins.php">
<select name="class_id" aria-label="Classe" required>
<option value="">Choisir une classe</option>
<?php foreach ($classes as $c): ?>
<option value="<?= (int) $c['id'] ?>"<?= $classId === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['nom']) ?></option>
<?php endforeach; ?>
</select>
<select name="period_id" aria-label="Période" required>
<option value="">Choisir une période</option>
<?php foreach ($periodes as $p): ?>
<option value="<?= (int) $p['id'] ?>"<?= $periodId === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['libelle']) ?></option>
<?php endforeach; ?>
</select>
<button class="btn btn-sm" type="submit">Calculer</button>
</form>
</section>

<?php if ($classeOk && $periodeOk): ?>

<?php if ($nbNonPubliees > 0): ?>
<div class="mssg warn" role="status"><?= $nbNonPubliees ?> évaluation(s) de cette classe et de cette période ne sont pas encore publiées par les enseignants : elles ne sont pas comptées.</div>
<?php endif; ?>

<section class="card">
<h2>Aperçu (<?= count($apercu) ?> élève<?= count($apercu) > 1 ? 's' : '' ?>)</h2>

<?php if (!$apercu): ?>
<p class="muted">Aucune note publiée pour cette classe et cette période.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
<thead><tr>
<th scope="col">Rang</th><th scope="col">Élève</th><th scope="col">Matricule</th>
<th scope="col">Moyenne / 20</th><th scope="col">Appréciation</th><th scope="col">Détail par matière</th><th scope="col">Bulletin</th>
</tr></thead>
<tbody>
<?php foreach ($apercu as $b):
    $s = $statuts[$b['student_id']] ?? null; ?>
<tr>
<td><?= (int) $b['rang'] ?> / <?= (int) $b['effectif'] ?></td>
<td><?= e($b['nom'] . ' ' . $b['prenom']) ?></td>
<td><?= e($b['matricule']) ?></td>
<td><strong><?= e(dir_nombre($b['moyenne'])) ?></strong></td>
<td><?= e($b['appreciation']) ?></td>
<td><small class="muted"><?php
    $parts = [];
    foreach ($b['matieres'] as $m) { $parts[] = e($m['matiere']) . ' ' . e(dir_nombre($m['moyenne'])) . ' (coef. ' . (int) $m['coefficient'] . ')'; }
    echo implode(' · ', $parts); ?></small></td>
<td><?php if ($s === 'publie'): ?><span class="badge ok">Publié</span>
<?php elseif ($s === 'retire'): ?><span class="badge ko">Retiré</span>
<?php else: ?><span class="badge warn">Non publié</span><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="toolbar" style="margin-top:18px">
<form method="post" action="bulletins.php"
      onsubmit="return confirm('Publier <?= count($apercu) ?> bulletin(s) ? Les parents seront notifiés. Une nouvelle publication recalcule les bulletins existants.')">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="publier">
<input type="hidden" name="class_id" value="<?= $classId ?>">
<input type="hidden" name="period_id" value="<?= $periodId ?>">
<button class="btn" type="submit"><?= $nbPublies > 0 ? 'Recalculer et publier' : 'Publier les bulletins' ?></button>
</form>
<?php if ($statuts): ?>
<form method="post" action="bulletins.php" onsubmit="return confirm('Retirer ces bulletins de la vue des parents ?')">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="retirer">
<input type="hidden" name="class_id" value="<?= $classId ?>">
<input type="hidden" name="period_id" value="<?= $periodId ?>">
<button class="btn btn-secondary" type="submit">Retirer les bulletins</button>
</form>
<?php endif; ?>
</div>
<p class="muted">Moyenne de matière = moyenne des évaluations publiées (pondérée par leur coefficient). Moyenne générale = moyennes de matière pondérées par le coefficient de la matière. Rang : à égalité, même rang.</p>
<?php endif; ?>
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