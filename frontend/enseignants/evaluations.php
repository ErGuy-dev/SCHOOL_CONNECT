<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Évaluations de l'enseignant
// Fichier : frontend/enseignants/evaluations.php
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';
require_once __DIR__ . '/../../backend/includes/enseignant.php';

require_role('enseignant');   // connexion + rôle + statut revérifiés en base

$pdo = db();
$uid = (int) $_SESSION['user_id'];

$annee        = ens_annee_active($pdo);
$affectations = $annee ? ens_affectations($pdo, $uid, (int) $annee['id']) : [];
$periodes     = $annee ? ens_periodes($pdo, (int) $annee['id']) : [];

function rediriger(string $etat, string $page = 'evaluations.php', array $extra = []): void
{
    $q = http_build_query(['etat' => $etat] + $extra);
    header('Location: ' . $page . '?' . $q, true, 303);
    exit;
}

/** Lit et valide les champs communs à la création et à la modification. */
function lire_evaluation(array $periodes): array
{
    $titre = trim((string) ($_POST['titre'] ?? ''));
    $type  = (string) ($_POST['type'] ?? '');
    $date  = (string) ($_POST['date_eval'] ?? '');
    $periodeId = (int) ($_POST['period_id'] ?? 0);

    $bareme = str_replace(',', '.', trim((string) ($_POST['bareme'] ?? '')));
    $coef   = str_replace(',', '.', trim((string) ($_POST['coefficient'] ?? '')));

    $d = DateTime::createFromFormat('Y-m-d', $date);
    $dateValide = $d && $d->format('Y-m-d') === $date;

    $idsPeriodes = array_map(static fn($p) => (int) $p['id'], $periodes);

    if (
        $titre === '' || mb_strlen($titre) > 120
        || !isset(ENS_TYPES[$type])
        || !$dateValide
        || !is_numeric($bareme) || (float) $bareme <= 0 || (float) $bareme > 100
        || !is_numeric($coef) || (float) $coef < 0.25 || (float) $coef > 10
        || !in_array($periodeId, $idsPeriodes, true)
    ) {
        throw new InvalidArgumentException('eval_invalide');
    }

    return [
        'titre' => $titre, 'type' => $type, 'date_eval' => $date,
        'bareme' => round((float) $bareme, 2), 'coefficient' => round((float) $coef, 2),
        'period_id' => $periodeId,
    ];
}

/* ---------- Traitement des formulaires ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if (!$annee) {
        rediriger('annee_absente');
    }

    $fichiersASupprimer = [];

    try {
        $pdo->beginTransaction();

        switch ($action) {
            case 'creer':
                $v = lire_evaluation($periodes);

                // La paire classe/matière doit être affectée à CET enseignant.
                [$classId, $subjectId] = array_pad(
                    array_map('intval', explode(':', (string) ($_POST['cm'] ?? ''))), 2, 0
                );
                $ok = false;
                foreach ($affectations as $a) {
                    if ((int) $a['class_id'] === $classId && (int) $a['subject_id'] === $subjectId) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    throw new InvalidArgumentException('eval_affectation');
                }

                $pdo->prepare(
                    'INSERT INTO assessments (class_id, subject_id, teacher_id, period_id, titre, type, date_eval, bareme, coefficient)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $classId, $subjectId, $uid, $v['period_id'], $v['titre'], $v['type'],
                    $v['date_eval'], $v['bareme'], $v['coefficient'],
                ]);
                $nouvelId = (int) $pdo->lastInsertId();
                audit('Création de l’évaluation : ' . $v['titre']);
                $pdo->commit();
                rediriger('eval_creee', 'notes.php', ['id' => $nouvelId]);

            case 'modifier':
                $id = (int) ($_POST['id'] ?? 0);
                $ev = ens_evaluation($pdo, $id, $uid, true);
                if (!$ev) {
                    throw new InvalidArgumentException('eval_introuvable');
                }
                if ($ev['statut'] !== 'brouillon') {
                    throw new InvalidArgumentException('eval_verrouillee');
                }
                $v = lire_evaluation($periodes);

                $q = $pdo->prepare('SELECT 1 FROM grades WHERE assessment_id = ? AND note > ? LIMIT 1');
                $q->execute([$id, $v['bareme']]);
                if ($q->fetchColumn()) {
                    throw new InvalidArgumentException('bareme_trop_bas');
                }

                $pdo->prepare(
                    'UPDATE assessments
                     SET titre = ?, type = ?, date_eval = ?, bareme = ?, coefficient = ?, period_id = ?
                     WHERE id = ? AND teacher_id = ? AND statut = \'brouillon\''
                )->execute([
                    $v['titre'], $v['type'], $v['date_eval'], $v['bareme'], $v['coefficient'],
                    $v['period_id'], $id, $uid,
                ]);
                audit('Modification de l’évaluation : ' . $v['titre']);
                $etat = 'eval_modifiee';
                break;

            case 'supprimer':
                $id = (int) ($_POST['id'] ?? 0);
                $ev = ens_evaluation($pdo, $id, $uid, true);
                if (!$ev) {
                    throw new InvalidArgumentException('eval_introuvable');
                }
                if ($ev['statut'] !== 'brouillon') {
                    throw new InvalidArgumentException('eval_verrouillee');
                }
                // Les fichiers des copies seront effacés après la validation en base.
                $q = $pdo->prepare('SELECT chemin_stockage FROM school_documents WHERE assessment_id = ?');
                $q->execute([$id]);
                $fichiersASupprimer = $q->fetchAll(PDO::FETCH_COLUMN);

                $pdo->prepare('DELETE FROM assessments WHERE id = ? AND teacher_id = ? AND statut = \'brouillon\'')
                    ->execute([$id, $uid]);
                audit('Suppression de l’évaluation : ' . $ev['titre']);
                $etat = 'eval_supprimee';
                break;

            default:
                throw new InvalidArgumentException('action_inconnue');
        }

        $pdo->commit();
        foreach ($fichiersASupprimer as $f) {
            ens_supprimer_fichier((string) $f);
        }
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
        error_log('SchoolConnect evaluations : ' . $e->getMessage());
        $code = (int) ($e->errorInfo[1] ?? 0);
        if ($code === 1062) {
            rediriger('eval_doublon');
        }
        if ($code === 1644) {            // refus d'un déclencheur d'intégrité
            rediriger('eval_refusee');
        }
        rediriger('erreur');
    }
}

/* ---------- Filtres, recherche et tri (GET) ---------- */
$q         = trim((string) ($_GET['q'] ?? ''));
$fClasse   = (int) ($_GET['class_id'] ?? 0);
$fMatiere  = (int) ($_GET['subject_id'] ?? 0);
$fStatut   = in_array($_GET['statut'] ?? '', ['brouillon', 'publiee'], true) ? (string) $_GET['statut'] : '';
$fType     = isset(ENS_TYPES[$_GET['type'] ?? '']) ? (string) $_GET['type'] : '';

$colonnes = [
    'date' => 'a.date_eval', 'titre' => 'a.titre', 'classe' => 'c.nom',
    'matiere' => 's.nom', 'bareme' => 'a.bareme', 'statut' => 'a.statut',
];
$tri   = isset($colonnes[$_GET['tri'] ?? '']) ? (string) $_GET['tri'] : 'date';
$ordre = (($_GET['ordre'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

$evaluations = [];
if ($annee) {
    $where  = ['a.teacher_id = ?', 'c.school_year_id = ?'];
    $params = [$uid, (int) $annee['id']];
    if ($q !== '') {
        $where[]  = 'a.titre LIKE ?';
        $params[] = '%' . addcslashes($q, '%_\\') . '%';
    }
    if ($fClasse > 0)  { $where[] = 'a.class_id = ?';   $params[] = $fClasse; }
    if ($fMatiere > 0) { $where[] = 'a.subject_id = ?'; $params[] = $fMatiere; }
    if ($fStatut !== '') { $where[] = 'a.statut = ?'; $params[] = $fStatut; }
    if ($fType !== '')   { $where[] = 'a.type = ?';   $params[] = $fType; }

    // Les colonnes de tri viennent d'une liste blanche : jamais directement de l'URL.
    $st = $pdo->prepare(
        'SELECT a.id, a.titre, a.type, a.date_eval, a.bareme, a.coefficient, a.statut,
                c.nom AS classe, s.nom AS matiere, p.libelle AS periode,
                (SELECT COUNT(*) FROM grades g WHERE g.assessment_id = a.id) AS nb_notes,
                (SELECT COUNT(*) FROM students e WHERE e.class_id = a.class_id AND e.statut = \'inscrit\') AS effectif
         FROM assessments a
         JOIN classes c  ON c.id = a.class_id
         JOIN subjects s ON s.id = a.subject_id
         JOIN evaluation_periods p ON p.id = a.period_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY ' . $colonnes[$tri] . ' ' . $ordre . ', a.id DESC
         LIMIT 300'
    );
    $st->execute($params);
    $evaluations = $st->fetchAll();
}

/* Mode modification (?modifier=ID) : seulement un brouillon à soi. */
$aModifier = null;
$idModif = (int) ($_GET['modifier'] ?? 0);
if ($idModif > 0 && $annee) {
    $aModifier = ens_evaluation($pdo, $idModif, $uid);
    if ($aModifier && $aModifier['statut'] !== 'brouillon') {
        $aModifier = null;
    }
}

function lien_tri(string $col, string $tri, string $ordre): string
{
    $nouveau = ($tri === $col && $ordre === 'asc') ? 'desc' : 'asc';
    $p = $_GET;
    unset($p['etat'], $p['modifier']);
    $p['tri'] = $col;
    $p['ordre'] = $nouveau;
    return 'evaluations.php?' . http_build_query($p);
}
function fleche(string $col, string $tri, string $ordre): string
{
    return $tri === $col ? ($ordre === 'asc' ? ' ▲' : ' ▼') : '';
}

$messages    = ens_messages();
$etat        = (string) ($_GET['etat'] ?? '');
$messagePage = $messages[$etat] ?? null;
$csrf        = csrf_token();
$filtresActifs = ($q !== '' || $fClasse > 0 || $fMatiere > 0 || $fStatut !== '' || $fType !== '');

// Valeurs affichées dans le formulaire (création ou modification).
$val = [
    'titre'       => $aModifier['titre'] ?? '',
    'type'        => $aModifier['type'] ?? 'interrogation',
    'date_eval'   => $aModifier['date_eval'] ?? date('Y-m-d'),
    'bareme'      => isset($aModifier['bareme']) ? ens_nombre($aModifier['bareme']) : '',
    'coefficient' => isset($aModifier['coefficient']) ? ens_nombre($aModifier['coefficient']) : '1',
    'period_id'   => (int) ($aModifier['period_id'] ?? 0),
];
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Évaluations | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<?php ens_sidebar('evaluations'); ?>

<div class="main">
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main class="content">
<h1>Évaluations</h1>
<p class="lead">
Créez une évaluation avant de saisir les notes
<?php if ($annee): ?>— année scolaire <?= e($annee['libelle']) ?><?php endif; ?>.
</p>

<?php if ($messagePage !== null): ?>
<div class="mssg <?= e($messagePage[0]) ?>" role="status"><?= e($messagePage[1]) ?></div>
<?php endif; ?>

<?php if (!$annee): ?>
<div class="mssg warn" role="status">Aucune année scolaire active. Contactez l'administrateur.</div>
<?php elseif (!$affectations): ?>
<div class="mssg warn" role="status">Aucune classe ne vous est encore affectée. L'administrateur peut le faire dans « Classes et matières ».</div>
<?php elseif (!$periodes): ?>
<div class="mssg warn" role="status">Aucune période d'évaluation n'existe pour cette année (table <code>evaluation_periods</code>). Demandez à l'administrateur de la créer.</div>
<?php else: ?>

<section class="card" id="formulaire">
<h2><?= $aModifier ? 'Modifier l’évaluation' : 'Nouvelle évaluation' ?></h2>
<form method="post" action="evaluations.php">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<?php if ($aModifier): ?>
<input type="hidden" name="action" value="modifier">
<input type="hidden" name="id" value="<?= (int) $aModifier['id'] ?>">
<?php else: ?>
<input type="hidden" name="action" value="creer">
<?php endif; ?>

<div class="form-grid">
<?php if ($aModifier): ?>
<div class="field"><label>Classe et matière</label>
<input type="text" value="<?= e($aModifier['classe'] . ' — ' . $aModifier['matiere']) ?>" disabled></div>
<?php else: ?>
<div class="field"><label for="cm">Classe et matière</label>
<select id="cm" name="cm" required>
<?php foreach ($affectations as $a):
    $sel = ((int) $a['class_id'] === $fClasse && (int) $a['subject_id'] === $fMatiere) ? ' selected' : ''; ?>
<option value="<?= (int) $a['class_id'] ?>:<?= (int) $a['subject_id'] ?>"
        data-bareme="<?= e(ens_nombre($a['bareme_matiere'])) ?>"<?= $sel ?>>
<?= e($a['classe']) ?> — <?= e($a['matiere']) ?></option>
<?php endforeach; ?>
</select>
</div>
<?php endif; ?>

<div class="field"><label for="titre">Titre</label>
<input type="text" id="titre" name="titre" maxlength="120" required placeholder="Interrogation 3"
       value="<?= e($val['titre']) ?>"></div>

<div class="field"><label for="type">Type</label>
<select id="type" name="type" required>
<?php foreach (ENS_TYPES as $code => $lib): ?>
<option value="<?= e($code) ?>"<?= $val['type'] === $code ? ' selected' : '' ?>><?= e($lib) ?></option>
<?php endforeach; ?>
</select></div>

<div class="field"><label for="period_id">Période</label>
<select id="period_id" name="period_id" required>
<?php foreach ($periodes as $p): ?>
<option value="<?= (int) $p['id'] ?>"<?= $val['period_id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['libelle']) ?></option>
<?php endforeach; ?>
</select></div>

<div class="field"><label for="date_eval">Date</label>
<input type="date" id="date_eval" name="date_eval" required value="<?= e($val['date_eval']) ?>"></div>

<div class="field"><label for="bareme">Barème (note maximale)</label>
<input type="text" inputmode="decimal" id="bareme" name="bareme" required
       value="<?= e($val['bareme'] !== '' ? $val['bareme'] : ens_nombre($affectations[0]['bareme_matiere'])) ?>">
<small>Entre 1 et 100.</small></div>

<div class="field"><label for="coefficient">Coefficient</label>
<input type="text" inputmode="decimal" id="coefficient" name="coefficient" required value="<?= e($val['coefficient']) ?>">
<small>Entre 0,25 et 10.</small></div>
</div>

<div class="toolbar">
<button class="btn" type="submit"><?= $aModifier ? 'Enregistrer les modifications' : 'Créer l’évaluation' ?></button>
<?php if ($aModifier): ?><a class="btn btn-secondary" href="evaluations.php">Annuler</a><?php endif; ?>
</div>
</form>
</section>
<?php endif; ?>

<section class="card">
<h2>Mes évaluations (<?= count($evaluations) ?>)</h2>

<form class="toolbar" method="get" action="evaluations.php" role="search">
<input type="search" name="q" placeholder="Rechercher un titre" value="<?= e($q) ?>" aria-label="Rechercher un titre">
<select name="class_id" aria-label="Classe">
<option value="0">Toutes les classes</option>
<?php
$classesVues = [];
foreach ($affectations as $a) {
    if (isset($classesVues[$a['class_id']])) { continue; }
    $classesVues[$a['class_id']] = true; ?>
<option value="<?= (int) $a['class_id'] ?>"<?= $fClasse === (int) $a['class_id'] ? ' selected' : '' ?>><?= e($a['classe']) ?></option>
<?php } ?>
</select>
<select name="subject_id" aria-label="Matière">
<option value="0">Toutes les matières</option>
<?php
$matieresVues = [];
foreach ($affectations as $a) {
    if (isset($matieresVues[$a['subject_id']])) { continue; }
    $matieresVues[$a['subject_id']] = true; ?>
<option value="<?= (int) $a['subject_id'] ?>"<?= $fMatiere === (int) $a['subject_id'] ? ' selected' : '' ?>><?= e($a['matiere']) ?></option>
<?php } ?>
</select>
<select name="type" aria-label="Type">
<option value="">Tous les types</option>
<?php foreach (ENS_TYPES as $code => $lib): ?>
<option value="<?= e($code) ?>"<?= $fType === $code ? ' selected' : '' ?>><?= e($lib) ?></option>
<?php endforeach; ?>
</select>
<select name="statut" aria-label="Statut">
<option value="">Tous les statuts</option>
<option value="brouillon"<?= $fStatut === 'brouillon' ? ' selected' : '' ?>>Brouillon</option>
<option value="publiee"<?= $fStatut === 'publiee' ? ' selected' : '' ?>>Publiée</option>
</select>
<input type="hidden" name="tri" value="<?= e($tri) ?>">
<input type="hidden" name="ordre" value="<?= e($ordre) ?>">
<button class="btn btn-sm" type="submit">Filtrer</button>
<?php if ($filtresActifs): ?><a class="btn btn-secondary btn-sm" href="evaluations.php">Réinitialiser</a><?php endif; ?>
</form>

<div class="table-wrap">
<table class="table">
<thead>
<tr>
<th scope="col"><a href="<?= e(lien_tri('titre', $tri, $ordre)) ?>">Évaluation<?= fleche('titre', $tri, $ordre) ?></a></th>
<th scope="col"><a href="<?= e(lien_tri('classe', $tri, $ordre)) ?>">Classe<?= fleche('classe', $tri, $ordre) ?></a></th>
<th scope="col"><a href="<?= e(lien_tri('matiere', $tri, $ordre)) ?>">Matière<?= fleche('matiere', $tri, $ordre) ?></a></th>
<th scope="col"><a href="<?= e(lien_tri('date', $tri, $ordre)) ?>">Date<?= fleche('date', $tri, $ordre) ?></a></th>
<th scope="col"><a href="<?= e(lien_tri('bareme', $tri, $ordre)) ?>">Barème<?= fleche('bareme', $tri, $ordre) ?></a></th>
<th scope="col">Notes</th>
<th scope="col"><a href="<?= e(lien_tri('statut', $tri, $ordre)) ?>">Statut<?= fleche('statut', $tri, $ordre) ?></a></th>
<th scope="col"></th>
</tr>
</thead>
<tbody>
<?php if (!$evaluations): ?>
<tr><td colspan="8"><?= $filtresActifs ? 'Aucune évaluation ne correspond à ces filtres.' : 'Aucune évaluation créée pour le moment.' ?></td></tr>
<?php endif; ?>
<?php foreach ($evaluations as $r): $brouillon = $r['statut'] === 'brouillon'; ?>
<tr>
<td><?= e($r['titre']) ?><br><small class="muted"><?= e(ENS_TYPES[$r['type']] ?? $r['type']) ?> · <?= e($r['periode']) ?> · coef. <?= e(ens_nombre($r['coefficient'])) ?></small></td>
<td><?= e($r['classe']) ?></td>
<td><?= e($r['matiere']) ?></td>
<td><?= e(ens_date($r['date_eval'])) ?></td>
<td><?= e(ens_nombre($r['bareme'])) ?></td>
<td><?= (int) $r['nb_notes'] ?> / <?= (int) $r['effectif'] ?></td>
<td><?= $brouillon ? '<span class="badge warn">Brouillon</span>' : '<span class="badge ok">Publiée</span>' ?></td>
<td class="actions">
<a class="btn btn-sm" href="notes.php?id=<?= (int) $r['id'] ?>"><?= $brouillon ? 'Saisir les notes' : 'Consulter' ?></a>
<?php if ($brouillon): ?>
<a class="btn btn-secondary btn-sm" href="evaluations.php?modifier=<?= (int) $r['id'] ?>#formulaire">Modifier</a>
<form method="post" action="evaluations.php" style="display:inline"
      onsubmit="return confirm('Supprimer cette évaluation, ses notes et ses copies importées ?')">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="supprimer">
<input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
<button type="submit" class="btn btn-secondary btn-sm">Supprimer</button>
</form>
<?php endif; ?>
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
<script>
// Amélioration facultative : propose le barème de la matière choisie.
(function () {
  var cm = document.getElementById('cm');
  if (!cm) { return; }
  var bareme = document.getElementById('bareme');
  cm.addEventListener('change', function () {
    var opt = cm.options[cm.selectedIndex];
    if (opt && opt.dataset.bareme) { bareme.value = opt.dataset.bareme; }
  });
})();
</script>
</body>
</html>