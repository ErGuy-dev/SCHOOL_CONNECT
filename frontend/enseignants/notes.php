<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Saisie des notes
// Fichier : frontend/enseignants/notes.php
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';
require_once __DIR__ . '/../../backend/includes/enseignant.php';

require_role('enseignant');

$pdo = db();
$uid = (int) $_SESSION['user_id'];
$annee = ens_annee_active($pdo);

function rediriger(string $etat, int $id = 0): void
{
    $q = ['etat' => $etat];
    if ($id > 0) {
        $q = ['id' => $id] + $q;
    }
    header('Location: notes.php?' . http_build_query($q), true, 303);
    exit;
}

$erreursNotes = [];   // élève => message (réaffichage sans perdre la saisie)
$saisie       = [];   // élève => texte saisi
$etatLocal    = '';

/* ---------- Enregistrement / publication ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if (!in_array($action, ['enregistrer', 'publier'], true)) {
        rediriger('action_inconnue');
    }
    if (!$annee) {
        rediriger('annee_absente');
    }

    $notifier = null;

    try {
        $pdo->beginTransaction();

        // Verrou : deux onglets ne peuvent pas publier ou modifier en même temps.
        $ev = ens_evaluation($pdo, $id, $uid, true);
        if (!$ev) {
            throw new InvalidArgumentException('eval_introuvable');
        }
        if ($ev['statut'] !== 'brouillon') {
            throw new InvalidArgumentException('eval_verrouillee');
        }

        // Liste blanche : seuls les élèves inscrits de la classe de l'évaluation.
        $st = $pdo->prepare("SELECT id, nom, prenom FROM students WHERE class_id = ? AND statut = 'inscrit'");
        $st->execute([(int) $ev['class_id']]);
        $eleves = $st->fetchAll();

        $bareme  = (float) $ev['bareme'];
        $postees = (isset($_POST['note']) && is_array($_POST['note'])) ? $_POST['note'] : [];
        $aEcrire = [];     // élève => note
        $aEffacer = [];

        foreach ($eleves as $el) {
            $sid = (int) $el['id'];
            $brut = $postees[$sid] ?? '';
            $brut = is_string($brut) ? trim($brut) : '';
            $saisie[$sid] = $brut;

            if ($brut === '') {
                $aEffacer[] = $sid;
                continue;
            }
            $norm = str_replace(',', '.', $brut);
            if (!is_numeric($norm) || (float) $norm < 0 || (float) $norm > $bareme) {
                $erreursNotes[$sid] = 'Entre 0 et ' . ens_nombre($bareme);
                continue;
            }
            $aEcrire[$sid] = round((float) $norm, 2);
        }

        if ($erreursNotes) {
            $pdo->rollBack();
            $etatLocal = 'notes_invalides';      // on réaffiche la page, rien n'est enregistré
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO grades (assessment_id, student_id, note) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE note = VALUES(note)'
            );
            foreach ($aEcrire as $sid => $note) {
                $ins->execute([$id, $sid, $note]);
            }
            $del = $pdo->prepare('DELETE FROM grades WHERE assessment_id = ? AND student_id = ?');
            foreach ($aEffacer as $sid) {
                $del->execute([$id, $sid]);
            }

            if ($action === 'publier') {
                $n = $pdo->prepare('SELECT COUNT(*) FROM grades WHERE assessment_id = ?');
                $n->execute([$id]);
                if ((int) $n->fetchColumn() < 1) {
                    throw new InvalidArgumentException('publication_vide');
                }
                $pdo->prepare(
                    "UPDATE assessments SET statut = 'publiee', published_at = NOW()
                     WHERE id = ? AND teacher_id = ? AND statut = 'brouillon'"
                )->execute([$id, $uid]);
                audit('Publication des résultats : ' . $ev['titre'] . ' (' . $ev['classe'] . ', ' . $ev['matiere'] . ')');
                $notifier = [(int) $ev['class_id'], 'Nouveau résultat publié : ' . $ev['matiere'],
                    $ev['titre'] . ' est disponible pour '];
            } else {
                audit('Enregistrement du brouillon des notes : ' . $ev['titre']);
            }

            $pdo->commit();

            if ($notifier) {
                ens_notifier_parents($pdo, $notifier[0], null, $notifier[1], $notifier[2], '../parents/resultats.php');
            }
            rediriger($action === 'publier' ? 'eval_publiee' : 'notes_ok', $id);
        }
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        rediriger($e->getMessage(), $e->getMessage() === 'eval_introuvable' ? 0 : $id);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('SchoolConnect notes : ' . $e->getMessage());
        rediriger((int) ($e->errorInfo[1] ?? 0) === 1644 ? 'eval_refusee' : 'erreur', $id);
    }
}

/* ---------- Affichage ---------- */
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$ev = ($id > 0 && $annee) ? ens_evaluation($pdo, $id, $uid) : null;

$eleves = [];
$notes  = [];
$stats  = null;
$liste  = [];

if ($ev) {
    $st = $pdo->prepare(
        "SELECT id, matricule, nom, prenom FROM students
         WHERE class_id = ? AND statut = 'inscrit' ORDER BY nom, prenom"
    );
    $st->execute([(int) $ev['class_id']]);
    $eleves = $st->fetchAll();

    $st = $pdo->prepare('SELECT student_id, note FROM grades WHERE assessment_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $g) {
        $notes[(int) $g['student_id']] = (float) $g['note'];
    }

    if ($notes) {
        $vals = array_values($notes);
        $bar  = (float) $ev['bareme'];
        $stats = [
            'nb'      => count($vals),
            'moyenne' => array_sum($vals) / count($vals),
            'min'     => min($vals),
            'max'     => max($vals),
            'reussite' => count(array_filter($vals, static fn($n) => $n >= $bar / 2)) / count($vals) * 100,
        ];
    }
} elseif ($annee) {
    // Aucune évaluation choisie : liste à sélectionner.
    $st = $pdo->prepare(
        'SELECT a.id, a.titre, a.date_eval, a.bareme, a.statut, c.nom AS classe, s.nom AS matiere,
                (SELECT COUNT(*) FROM grades g WHERE g.assessment_id = a.id) AS nb_notes,
                (SELECT COUNT(*) FROM students e WHERE e.class_id = a.class_id AND e.statut = \'inscrit\') AS effectif
         FROM assessments a
         JOIN classes c ON c.id = a.class_id
         JOIN subjects s ON s.id = a.subject_id
         WHERE a.teacher_id = ? AND c.school_year_id = ?
         ORDER BY (a.statut = \'brouillon\') DESC, a.date_eval DESC, a.id DESC
         LIMIT 200'
    );
    $st->execute([$uid, (int) $annee['id']]);
    $liste = $st->fetchAll();
}

$messages    = ens_messages();
$etat        = $etatLocal !== '' ? $etatLocal : (string) ($_GET['etat'] ?? '');
$messagePage = $messages[$etat] ?? null;
$csrf        = csrf_token();
$brouillon   = $ev && $ev['statut'] === 'brouillon';
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Saisie des notes | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<?php ens_sidebar('notes'); ?>

<div class="main">
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main class="content">
<h1>Saisie des notes</h1>

<?php if ($ev): ?>
<p class="lead"><?= e($ev['classe']) ?> — <?= e($ev['matiere']) ?> — <?= e($ev['titre']) ?>
&nbsp;(barème <?= e(ens_nombre($ev['bareme'])) ?>, <?= e(ens_date($ev['date_eval'])) ?>)</p>
<?php else: ?>
<p class="lead">Choisissez l'évaluation dont vous voulez saisir ou consulter les notes.</p>
<?php endif; ?>

<?php if ($messagePage !== null): ?>
<div class="mssg <?= e($messagePage[0]) ?>" role="status"><?= e($messagePage[1]) ?></div>
<?php endif; ?>

<?php if (!$annee): ?>
<div class="mssg warn" role="status">Aucune année scolaire active. Contactez l'administrateur.</div>

<?php elseif ($id > 0 && !$ev): ?>
<div class="mssg err" role="alert">Évaluation introuvable. <a href="notes.php">Revenir à la liste</a>.</div>

<?php elseif (!$ev): ?>
<section class="card">
<h2>Mes évaluations</h2>
<div class="table-wrap">
<table class="table">
<thead><tr>
<th scope="col">Évaluation</th><th scope="col">Classe</th><th scope="col">Matière</th>
<th scope="col">Date</th><th scope="col">Notes</th><th scope="col">Statut</th><th scope="col"></th>
</tr></thead>
<tbody>
<?php if (!$liste): ?>
<tr><td colspan="7">Aucune évaluation. <a href="evaluations.php">Créez-en une</a> d'abord.</td></tr>
<?php endif; ?>
<?php foreach ($liste as $r): $b = $r['statut'] === 'brouillon'; ?>
<tr>
<td><?= e($r['titre']) ?></td>
<td><?= e($r['classe']) ?></td>
<td><?= e($r['matiere']) ?></td>
<td><?= e(ens_date($r['date_eval'])) ?></td>
<td><?= (int) $r['nb_notes'] ?> / <?= (int) $r['effectif'] ?></td>
<td><?= $b ? '<span class="badge warn">Brouillon</span>' : '<span class="badge ok">Publiée</span>' ?></td>
<td class="actions"><a class="btn btn-sm" href="notes.php?id=<?= (int) $r['id'] ?>"><?= $b ? 'Saisir' : 'Consulter' ?></a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<?php else: ?>

<?php if (!$brouillon): ?>
<div class="mssg warn" role="status">Ces résultats sont publiés : les notes sont verrouillées.</div>
<?php endif; ?>

<?php if ($stats): ?>
<div class="stats">
<div class="stat"><strong><?= (int) $stats['nb'] ?> / <?= count($eleves) ?></strong><span>Notes saisies</span></div>
<div class="stat"><strong><?= e(ens_nombre($stats['moyenne'])) ?></strong><span>Moyenne (sur <?= e(ens_nombre($ev['bareme'])) ?>)</span></div>
<div class="stat"><strong><?= e(ens_nombre($stats['min'])) ?> – <?= e(ens_nombre($stats['max'])) ?></strong><span>Plus basse – plus haute</span></div>
<div class="stat"><strong><?= (int) round($stats['reussite']) ?> %</strong><span>Au moins la moitié des points</span></div>
</div>
<?php endif; ?>

<section class="card">
<h2>Notes</h2>

<form method="post" action="notes.php" id="form-notes" novalidate>
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="id" value="<?= (int) $ev['id'] ?>">

<div class="table-wrap">
<table class="table">
<thead><tr>
<th scope="col">Matricule</th><th scope="col">Élève</th>
<th scope="col">Note / <?= e(ens_nombre($ev['bareme'])) ?></th>
</tr></thead>
<tbody>
<?php if (!$eleves): ?>
<tr><td colspan="3">Aucun élève inscrit dans cette classe.</td></tr>
<?php endif; ?>
<?php foreach ($eleves as $el):
    $sid = (int) $el['id'];
    if (isset($saisie[$sid])) {
        $valeur = $saisie[$sid];                                   // réaffichage après erreur
    } else {
        $valeur = isset($notes[$sid]) ? ens_nombre($notes[$sid]) : '';
    }
    $nomComplet = $el['prenom'] . ' ' . $el['nom']; ?>
<tr>
<td><?= e($el['matricule']) ?></td>
<td><?= e($nomComplet) ?></td>
<td>
<?php if ($brouillon): ?>
<input class="cell-input note-input<?= isset($erreursNotes[$sid]) ? ' invalid' : '' ?>"
       type="text" inputmode="decimal" autocomplete="off"
       name="note[<?= $sid ?>]" value="<?= e($valeur) ?>"
       data-max="<?= e((string) (float) $ev['bareme']) ?>"
       aria-label="Note de <?= e($nomComplet) ?>">
<?php if (isset($erreursNotes[$sid])): ?><small style="color:var(--danger)"><?= e($erreursNotes[$sid]) ?></small><?php endif; ?>
<?php else: ?>
<?= isset($notes[$sid]) ? e(ens_nombre($notes[$sid])) : '<span class="muted">—</span>' ?>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php if ($brouillon && $eleves): ?>
<div class="toolbar" style="margin-top:18px">
<button class="btn btn-secondary" type="submit" name="action" value="enregistrer">Enregistrer le brouillon</button>
<button class="btn" type="submit" name="action" value="publier" id="btn-publier">Publier les résultats</button>
</div>
<p class="muted">Après publication, les notes sont verrouillées et les parents sont notifiés. Laissez une case vide si l'élève n'a pas de note.</p>
<?php endif; ?>
</form>
</section>

<p><a href="notes.php">← Toutes les évaluations</a> ·
<a href="copies.php?evaluation_id=<?= (int) $ev['id'] ?>">Copies corrigées de cette évaluation</a></p>

<?php endif; ?>
</main>
</div>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/app.js"></script>
<script src="../notifications/notifications.js"></script>
<script src="../assets/js/chat-global.js" defer></script>
<script>
// Aides visuelles uniquement : le serveur revalide chaque note.
(function () {
  var form = document.getElementById('form-notes');
  if (!form) { return; }
  var champs = form.querySelectorAll('.note-input');
  champs.forEach(function (c) {
    c.addEventListener('input', function () {
      var v = parseFloat(c.value.replace(',', '.')), max = parseFloat(c.dataset.max);
      c.classList.toggle('invalid', c.value.trim() !== '' && (isNaN(v) || v < 0 || v > max));
    });
  });
  var btn = document.getElementById('btn-publier');
  if (btn) {
    btn.addEventListener('click', function (e) {
      var vides = 0;
      champs.forEach(function (c) { if (c.value.trim() === '') { vides++; } });
      var msg = 'Publier les résultats ? Les notes seront verrouillées et les parents notifiés.';
      if (vides > 0) { msg = vides + ' élève(s) n\'ont pas de note. ' + msg; }
      if (!confirm(msg)) { e.preventDefault(); }
    });
  }
})();
</script>

</body>
</html>