<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Copies corrigées
// Fichier : frontend/enseignants/copies.php
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';
require_once __DIR__ . '/../../backend/includes/enseignant.php';

require_role('enseignant');

$pdo = db();
$uid = (int) $_SESSION['user_id'];
$annee = ens_annee_active($pdo);

function rediriger(string $etat, int $evaluationId = 0): void
{
    $q = ['etat' => $etat];
    if ($evaluationId > 0) {
        $q = ['evaluation_id' => $evaluationId] + $q;
    }
    header('Location: copies.php?' . http_build_query($q), true, 303);
    exit;
}

/** Une copie qui appartient à CET enseignant (via son évaluation). */
function copie_de_enseignant(PDO $pdo, int $copieId, int $uid): ?array
{
    $st = $pdo->prepare(
        'SELECT sd.*, a.statut AS eval_statut, a.class_id, a.titre AS eval_titre
         FROM school_documents sd
         JOIN assessments a ON a.id = sd.assessment_id
         WHERE sd.id = ? AND a.teacher_id = ?
         FOR UPDATE'
    );
    $st->execute([$copieId, $uid]);
    $r = $st->fetch();
    return $r ?: null;
}

/* ---------- Traitement des formulaires ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Fichier plus gros que post_max_size : PHP vide $_POST et $_FILES.
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES) {
        rediriger('fichier_trop_gros');
    }

    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $evalId = (int) ($_POST['evaluation_id'] ?? 0);

    if (!$annee) {
        rediriger('annee_absente');
    }

    $nouveauFichier  = null;   // à effacer si la base refuse
    $ancienFichier   = null;   // à effacer si la base accepte
    $notifications   = [];     // [classId, studentId, titre, debut]

    try {
        $pdo->beginTransaction();

        switch ($action) {
            case 'importer':
                $ev = ens_evaluation($pdo, $evalId, $uid, true);
                if (!$ev) {
                    throw new InvalidArgumentException('eval_introuvable');
                }
                $studentId = (int) ($_POST['student_id'] ?? 0);
                $q = $pdo->prepare("SELECT id FROM students WHERE id = ? AND class_id = ? AND statut = 'inscrit'");
                $q->execute([$studentId, (int) $ev['class_id']]);
                if (!$q->fetchColumn()) {
                    throw new InvalidArgumentException('copie_eleve');
                }

                $q = $pdo->prepare('SELECT id, statut, chemin_stockage FROM school_documents WHERE assessment_id = ? AND student_id = ? FOR UPDATE');
                $q->execute([$evalId, $studentId]);
                $existante = $q->fetch();
                if ($existante && $existante['statut'] === 'publie') {
                    throw new InvalidArgumentException('copie_deja_publiee');
                }

                $f = ens_upload_copie($_FILES['fichier'] ?? []);
                $nouveauFichier = $f['nom'];

                if ($existante) {      // remplacement d'un brouillon
                    $pdo->prepare(
                        'UPDATE school_documents
                         SET nom_original = ?, chemin_stockage = ?, mime = ?, taille = ?, sha256 = ?, uploaded_by = ?, created_at = NOW()
                         WHERE id = ?'
                    )->execute([$f['original'], $f['nom'], $f['mime'], $f['taille'], $f['sha256'], $uid, (int) $existante['id']]);
                    $ancienFichier = (string) $existante['chemin_stockage'];
                } else {
                    $pdo->prepare(
                        "INSERT INTO school_documents
                         (assessment_id, student_id, nom_original, chemin_stockage, mime, taille, sha256, statut, uploaded_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'brouillon', ?)"
                    )->execute([$evalId, $studentId, $f['original'], $f['nom'], $f['mime'], $f['taille'], $f['sha256'], $uid]);
                }
                audit('Import d’une copie : ' . $ev['titre'] . ' (élève #' . $studentId . ')');
                $etat = 'copie_ok';
                break;

            case 'publier':
            case 'retirer':
            case 'supprimer':
                $copie = copie_de_enseignant($pdo, (int) ($_POST['id'] ?? 0), $uid);
                if (!$copie) {
                    throw new InvalidArgumentException('copie_introuvable');
                }
                $evalId = (int) $copie['assessment_id'];

                if ($action === 'publier') {
                    if ($copie['eval_statut'] !== 'publiee') {
                        throw new InvalidArgumentException('copie_eval_non_publiee');
                    }
                    $pdo->prepare("UPDATE school_documents SET statut = 'publie' WHERE id = ?")->execute([(int) $copie['id']]);
                    audit('Publication d’une copie : ' . $copie['eval_titre'] . ' (élève #' . $copie['student_id'] . ')');
                    $notifications[] = [(int) $copie['class_id'], (int) $copie['student_id']];
                    $etat = 'copie_publiee';
                } elseif ($action === 'retirer') {
                    $pdo->prepare("UPDATE school_documents SET statut = 'brouillon' WHERE id = ?")->execute([(int) $copie['id']]);
                    audit('Retrait d’une copie : ' . $copie['eval_titre'] . ' (élève #' . $copie['student_id'] . ')');
                    $etat = 'copie_retiree';
                } else {
                    if ($copie['statut'] === 'publie') {
                        throw new InvalidArgumentException('copie_a_retirer');
                    }
                    $pdo->prepare('DELETE FROM school_documents WHERE id = ?')->execute([(int) $copie['id']]);
                    audit('Suppression d’une copie : ' . $copie['eval_titre'] . ' (élève #' . $copie['student_id'] . ')');
                    $ancienFichier = (string) $copie['chemin_stockage'];
                    $etat = 'copie_supprimee';
                }
                break;

            case 'publier_toutes':
                $ev = ens_evaluation($pdo, $evalId, $uid, true);
                if (!$ev) {
                    throw new InvalidArgumentException('eval_introuvable');
                }
                if ($ev['statut'] !== 'publiee') {
                    throw new InvalidArgumentException('copie_eval_non_publiee');
                }
                $q = $pdo->prepare("SELECT student_id FROM school_documents WHERE assessment_id = ? AND statut = 'brouillon' FOR UPDATE");
                $q->execute([$evalId]);
                $eleves = $q->fetchAll(PDO::FETCH_COLUMN);
                if ($eleves) {
                    $pdo->prepare("UPDATE school_documents SET statut = 'publie' WHERE assessment_id = ? AND statut = 'brouillon'")
                        ->execute([$evalId]);
                    audit('Publication de ' . count($eleves) . ' copie(s) : ' . $ev['titre']);
                    foreach ($eleves as $sid) {
                        $notifications[] = [(int) $ev['class_id'], (int) $sid];
                    }
                }
                $etat = 'copies_publiees';
                break;

            default:
                throw new InvalidArgumentException('action_inconnue');
        }

        $pdo->commit();
        ens_supprimer_fichier($ancienFichier);

        foreach ($notifications as [$classId, $studentId]) {
            ens_notifier_parents(
                $pdo, $classId, $studentId, 'Copie corrigée disponible',
                'La copie corrigée est disponible pour ', '../parents/copies.php'
            );
        }
        rediriger($etat, $evalId);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        ens_supprimer_fichier($nouveauFichier);
        rediriger($e->getMessage(), $evalId);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        ens_supprimer_fichier($nouveauFichier);
        error_log('SchoolConnect copies : ' . $e->getMessage());
        rediriger('erreur', $evalId);
    }
}

/* ---------- Données d'affichage ---------- */
$evaluations = [];
if ($annee) {
    $st = $pdo->prepare(
        'SELECT a.id, a.titre, a.statut, a.date_eval, c.nom AS classe, s.nom AS matiere
         FROM assessments a
         JOIN classes c ON c.id = a.class_id
         JOIN subjects s ON s.id = a.subject_id
         WHERE a.teacher_id = ? AND c.school_year_id = ?
         ORDER BY a.date_eval DESC, a.id DESC'
    );
    $st->execute([$uid, (int) $annee['id']]);
    $evaluations = $st->fetchAll();
}

$evalChoisie = null;
$idChoisi = (int) ($_GET['evaluation_id'] ?? 0);
foreach ($evaluations as $x) {
    if ((int) $x['id'] === $idChoisi) {
        $evalChoisie = $x;
        break;
    }
}
$idChoisi = $evalChoisie ? (int) $evalChoisie['id'] : 0;

// Élèves de l'évaluation choisie + copie déjà importée.
$elevesChoix = [];
if ($evalChoisie) {
    $st = $pdo->prepare(
        "SELECT st.id, st.matricule, st.nom, st.prenom, sd.statut AS copie_statut
         FROM assessments a
         JOIN students st ON st.class_id = a.class_id AND st.statut = 'inscrit'
         LEFT JOIN school_documents sd ON sd.assessment_id = a.id AND sd.student_id = st.id
         WHERE a.id = ? AND a.teacher_id = ?
         ORDER BY st.nom, st.prenom"
    );
    $st->execute([$idChoisi, $uid]);
    $elevesChoix = $st->fetchAll();
}

// Liste des copies : recherche et filtres.
$q       = trim((string) ($_GET['q'] ?? ''));
$fStatut = in_array($_GET['statut'] ?? '', ['brouillon', 'publie'], true) ? (string) $_GET['statut'] : '';

$copies = [];
if ($annee) {
    $where  = ['a.teacher_id = ?', 'c.school_year_id = ?'];
    $params = [$uid, (int) $annee['id']];
    if ($idChoisi > 0) { $where[] = 'a.id = ?'; $params[] = $idChoisi; }
    if ($fStatut !== '') { $where[] = 'sd.statut = ?'; $params[] = $fStatut; }
    if ($q !== '') {
        $where[]  = "(st.nom LIKE ? OR st.prenom LIKE ? OR st.matricule LIKE ? OR CONCAT(st.prenom, ' ', st.nom) LIKE ?)";
        $motif    = '%' . addcslashes($q, '%_\\') . '%';
        array_push($params, $motif, $motif, $motif, $motif);
    }
    $st = $pdo->prepare(
        'SELECT sd.id, sd.nom_original, sd.taille, sd.statut, sd.created_at, sd.mime,
                st.matricule, st.nom, st.prenom, c.nom AS classe,
                a.id AS eval_id, a.titre AS eval_titre, a.statut AS eval_statut
         FROM school_documents sd
         JOIN assessments a ON a.id = sd.assessment_id
         JOIN classes c ON c.id = a.class_id
         JOIN students st ON st.id = sd.student_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY sd.created_at DESC, sd.id DESC
         LIMIT 300'
    );
    $st->execute($params);
    $copies = $st->fetchAll();
}

$nbBrouillons = 0;
foreach ($copies as $cp) {
    if ($cp['statut'] === 'brouillon') { $nbBrouillons++; }
}

$messages    = ens_messages();
$etat        = (string) ($_GET['etat'] ?? '');
$messagePage = $messages[$etat] ?? null;
$csrf        = csrf_token();
$apiCopie    = '../../backend/api/copies/telecharger.php';
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Copies corrigées | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<?php ens_sidebar('copies'); ?>

<div class="main">
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main class="content">
<h1>Copies corrigées</h1>
<p class="lead">Importez des fichiers PDF, JPG ou PNG (5 Mo maximum) et associez-les aux élèves.</p>

<?php if ($messagePage !== null): ?>
<div class="mssg <?= e($messagePage[0]) ?>" role="status"><?= e($messagePage[1]) ?></div>
<?php endif; ?>

<?php if (!$annee): ?>
<div class="mssg warn" role="status">Aucune année scolaire active. Contactez l'administrateur.</div>
<?php elseif (!$evaluations): ?>
<div class="mssg warn" role="status">Vous n'avez encore aucune évaluation. <a href="evaluations.php">Créez-en une</a> d'abord.</div>
<?php else: ?>

<section class="card">
<h2>Importer une copie</h2>

<form class="toolbar" method="get" action="copies.php">
<select name="evaluation_id" aria-label="Évaluation" required>
<option value="">Choisir une évaluation</option>
<?php foreach ($evaluations as $x): ?>
<option value="<?= (int) $x['id'] ?>"<?= $idChoisi === (int) $x['id'] ? ' selected' : '' ?>>
<?= e($x['classe']) ?> — <?= e($x['matiere']) ?> — <?= e($x['titre']) ?><?= $x['statut'] === 'brouillon' ? ' (brouillon)' : '' ?></option>
<?php endforeach; ?>
</select>
<button class="btn btn-sm btn-secondary" type="submit">Choisir</button>
</form>

<?php if ($evalChoisie): ?>
<form method="post" action="copies.php" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="importer">
<input type="hidden" name="evaluation_id" value="<?= $idChoisi ?>">
<input type="hidden" name="MAX_FILE_SIZE" value="<?= COPIE_MAX_BYTES ?>">
<div class="form-grid">
<div class="field"><label for="student_id">Élève</label>
<select id="student_id" name="student_id" required>
<option value="">Choisir un élève</option>
<?php foreach ($elevesChoix as $el): ?>
<option value="<?= (int) $el['id'] ?>">
<?= e($el['nom'] . ' ' . $el['prenom']) ?> (<?= e($el['matricule']) ?>)<?php
    if ($el['copie_statut'] === 'brouillon') { echo ' — copie en brouillon (sera remplacée)'; }
    elseif ($el['copie_statut'] === 'publie') { echo ' — copie publiée'; } ?></option>
<?php endforeach; ?>
</select></div>
<div class="field"><label for="fichier">Fichier</label>
<input type="file" id="fichier" name="fichier" accept=".pdf,.jpg,.jpeg,.png" required>
<small>PDF, JPG ou PNG, 5 Mo maximum.</small></div>
</div>
<button class="btn" type="submit">Importer la copie</button>
</form>
<?php else: ?>
<p class="muted">Choisissez d'abord l'évaluation pour voir la liste de ses élèves.</p>
<?php endif; ?>
</section>

<?php endif; ?>

<section class="card">
<h2>Copies importées (<?= count($copies) ?>)</h2>

<form class="toolbar" method="get" action="copies.php" role="search">
<input type="search" name="q" placeholder="Nom, prénom ou matricule" value="<?= e($q) ?>" aria-label="Rechercher un élève">
<select name="evaluation_id" aria-label="Évaluation">
<option value="0">Toutes les évaluations</option>
<?php foreach ($evaluations as $x): ?>
<option value="<?= (int) $x['id'] ?>"<?= $idChoisi === (int) $x['id'] ? ' selected' : '' ?>><?= e($x['classe']) ?> — <?= e($x['titre']) ?></option>
<?php endforeach; ?>
</select>
<select name="statut" aria-label="Statut">
<option value="">Tous les statuts</option>
<option value="brouillon"<?= $fStatut === 'brouillon' ? ' selected' : '' ?>>Brouillon</option>
<option value="publie"<?= $fStatut === 'publie' ? ' selected' : '' ?>>Publiée</option>
</select>
<button class="btn btn-sm" type="submit">Filtrer</button>
<?php if ($q !== '' || $idChoisi > 0 || $fStatut !== ''): ?><a class="btn btn-secondary btn-sm" href="copies.php">Réinitialiser</a><?php endif; ?>
</form>

<?php if ($evalChoisie && $nbBrouillons > 0 && $evalChoisie['statut'] === 'publiee'): ?>
<form method="post" action="copies.php" style="margin-bottom:16px"
      onsubmit="return confirm('Publier toutes les copies en brouillon de cette évaluation ?')">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="publier_toutes">
<input type="hidden" name="evaluation_id" value="<?= $idChoisi ?>">
<button class="btn btn-sm" type="submit">Publier les <?= $nbBrouillons ?> copie(s) en brouillon</button>
</form>
<?php elseif ($evalChoisie && $evalChoisie['statut'] !== 'publiee'): ?>
<div class="mssg warn" role="status">Les résultats de cette évaluation ne sont pas encore publiés : les copies ne pourront être publiées qu'après les notes.</div>
<?php endif; ?>

<div class="table-wrap">
<table class="table">
<thead><tr>
<th scope="col">Élève</th><th scope="col">Classe</th><th scope="col">Évaluation</th>
<th scope="col">Fichier</th><th scope="col">Importée le</th><th scope="col">Statut</th><th scope="col"></th>
</tr></thead>
<tbody>
<?php if (!$copies): ?>
<tr><td colspan="7">Aucune copie importée.</td></tr>
<?php endif; ?>
<?php foreach ($copies as $cp): $pub = $cp['statut'] === 'publie'; ?>
<tr>
<td><?= e($cp['nom'] . ' ' . $cp['prenom']) ?><br><small class="muted"><?= e($cp['matricule']) ?></small></td>
<td><?= e($cp['classe']) ?></td>
<td><?= e($cp['eval_titre']) ?></td>
<td><?= e($cp['nom_original']) ?><br><small class="muted"><?= e(ens_taille((int) $cp['taille'])) ?></small></td>
<td><?= e(ens_date($cp['created_at'])) ?></td>
<td><?= $pub ? '<span class="badge ok">Publiée</span>' : '<span class="badge warn">Brouillon</span>' ?></td>
<td class="actions">
<a class="btn btn-secondary btn-sm" href="<?= e($apiCopie) ?>?id=<?= (int) $cp['id'] ?>" target="_blank" rel="noopener">Voir</a>
<a class="btn btn-secondary btn-sm" href="<?= e($apiCopie) ?>?id=<?= (int) $cp['id'] ?>&amp;dl=1">Télécharger</a>
<form method="post" action="copies.php" style="display:inline">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="id" value="<?= (int) $cp['id'] ?>">
<input type="hidden" name="evaluation_id" value="<?= (int) $cp['eval_id'] ?>">
<?php if ($pub): ?>
<button type="submit" name="action" value="retirer" class="btn btn-secondary btn-sm">Retirer</button>
<?php else: ?>
<button type="submit" name="action" value="publier" class="btn btn-sm"<?= $cp['eval_statut'] !== 'publiee' ? ' disabled title="Publiez d’abord les résultats de l’évaluation"' : '' ?>>Publier</button>
<button type="submit" name="action" value="supprimer" class="btn btn-secondary btn-sm"
        onclick="return confirm('Supprimer définitivement cette copie ?')">Supprimer</button>
<?php endif; ?>
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