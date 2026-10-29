<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Annonces de la Direction
// Fichier : frontend/direction/annonces.php
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';
require_once __DIR__ . '/../../backend/includes/direction.php';

require_role('direction');

$pdo = db();
$uid = (int) $_SESSION['user_id'];
$annee = dir_annee_active($pdo);

$classes = [];
if ($annee) {
    $st = $pdo->prepare('SELECT id, nom FROM classes WHERE school_year_id = ? ORDER BY nom');
    $st->execute([(int) $annee['id']]);
    $classes = $st->fetchAll();
}

function rediriger(string $etat): void
{
    header('Location: annonces.php?etat=' . rawurlencode($etat), true, 303);
    exit;
}

/** Lit et valide le formulaire. Lève InvalidArgumentException('annonce_invalide'). */
function lire_annonce(array $classes): array
{
    $type    = (string) ($_POST['type'] ?? '');
    $titre   = trim((string) ($_POST['titre'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $dest    = (int) ($_POST['class_id'] ?? 0);
    $date    = trim((string) ($_POST['date_evenement'] ?? ''));

    $classeId = null;
    if ($dest > 0) {
        if (!in_array($dest, array_map(static fn($c) => (int) $c['id'], $classes), true)) {
            throw new InvalidArgumentException('annonce_invalide');
        }
        $classeId = $dest;
    }

    $dateSql = null;
    if ($date !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('annonce_invalide');
        }
        $dateSql = $date;
    }

    if (
        !isset(DIR_TYPES_ANNONCE[$type])
        || $titre === '' || mb_strlen($titre) > 120
        || $message === '' || mb_strlen($message) > 5000
    ) {
        throw new InvalidArgumentException('annonce_invalide');
    }
    return ['type' => $type, 'titre' => $titre, 'message' => $message, 'class_id' => $classeId, 'date' => $dateSql];
}

/* ---------- Traitement ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $notif  = null;

    try {
        $pdo->beginTransaction();

        switch ($action) {
            case 'publier':
                $v = lire_annonce($classes);
                $pdo->prepare(
                    "INSERT INTO announcements (auteur_id, type, titre, message, class_id, date_evenement, statut)
                     VALUES (?, ?, ?, ?, ?, ?, 'publiee')"
                )->execute([$uid, $v['type'], $v['titre'], $v['message'], $v['class_id'], $v['date']]);
                audit('Publication de l’annonce : ' . $v['titre']);
                $notif = [$v['class_id'], $v['titre']];
                $etat = 'annonce_ok';
                break;

            case 'modifier':
                $id = (int) ($_POST['id'] ?? 0);
                $v = lire_annonce($classes);
                $st = $pdo->prepare(
                    'UPDATE announcements SET type = ?, titre = ?, message = ?, class_id = ?, date_evenement = ? WHERE id = ?'
                );
                $st->execute([$v['type'], $v['titre'], $v['message'], $v['class_id'], $v['date'], $id]);
                $q = $pdo->prepare('SELECT 1 FROM announcements WHERE id = ?');
                $q->execute([$id]);
                if (!$q->fetchColumn()) {
                    throw new InvalidArgumentException('annonce_introuvable');
                }
                audit('Modification de l’annonce : ' . $v['titre']);
                $etat = 'annonce_modifiee';
                break;

            case 'retirer':
            case 'remettre':
                $id = (int) ($_POST['id'] ?? 0);
                $q = $pdo->prepare('SELECT titre, class_id FROM announcements WHERE id = ? FOR UPDATE');
                $q->execute([$id]);
                $a = $q->fetch();
                if (!$a) {
                    throw new InvalidArgumentException('annonce_introuvable');
                }
                $pdo->prepare('UPDATE announcements SET statut = ? WHERE id = ?')
                    ->execute([$action === 'retirer' ? 'retiree' : 'publiee', $id]);
                audit(($action === 'retirer' ? 'Retrait' : 'Remise en ligne') . ' de l’annonce : ' . $a['titre']);
                $etat = $action === 'retirer' ? 'annonce_retiree' : 'annonce_remise';
                break;

            default:
                throw new InvalidArgumentException('action_inconnue');
        }

        $pdo->commit();

        if ($notif) {
            dir_notifier_parents($pdo, $notif[0], 'Nouvelle annonce', $notif[1], '../parents/annonces.php');
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
        error_log('SchoolConnect annonces : ' . $e->getMessage());
        rediriger('erreur');
    }
}

/* ---------- Affichage : recherche et filtres ---------- */
$q       = trim((string) ($_GET['q'] ?? ''));
$fType   = isset(DIR_TYPES_ANNONCE[$_GET['type'] ?? '']) ? (string) $_GET['type'] : '';
$fStatut = in_array($_GET['statut'] ?? '', ['publiee', 'retiree'], true) ? (string) $_GET['statut'] : '';

$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = '(an.titre LIKE ? OR an.message LIKE ?)';
    $motif = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, $motif, $motif);
}
if ($fType !== '')   { $where[] = 'an.type = ?';   $params[] = $fType; }
if ($fStatut !== '') { $where[] = 'an.statut = ?'; $params[] = $fStatut; }

$st = $pdo->prepare(
    'SELECT an.id, an.type, an.titre, an.message, an.class_id, an.date_evenement, an.statut, an.created_at,
            c.nom AS classe, u.nom AS auteur
     FROM announcements an
     LEFT JOIN classes c ON c.id = an.class_id
     JOIN users u ON u.id = an.auteur_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY an.created_at DESC, an.id DESC LIMIT 200'
);
$st->execute($params);
$annonces = $st->fetchAll();

// Mode modification (?modifier=ID).
$aModifier = null;
$idModif = (int) ($_GET['modifier'] ?? 0);
if ($idModif > 0) {
    $s = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
    $s->execute([$idModif]);
    $aModifier = $s->fetch() ?: null;
}

$val = [
    'type'    => $aModifier['type'] ?? 'annonce',
    'titre'   => $aModifier['titre'] ?? '',
    'message' => $aModifier['message'] ?? '',
    'class_id' => (int) ($aModifier['class_id'] ?? 0),
    'date'    => $aModifier['date_evenement'] ?? '',
];

$messages    = dir_messages();
$etat        = (string) ($_GET['etat'] ?? '');
$messagePage = $messages[$etat] ?? null;
$csrf        = csrf_token();
$filtres     = ($q !== '' || $fType !== '' || $fStatut !== '');
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Annonces | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<?php dir_sidebar('annonces'); ?>

<div class="main">
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main class="content">
<h1>Annonces</h1>
<p class="lead">Messages officiels destinés aux parents : annonces, réunions et événements.</p>

<?php if ($messagePage !== null): ?>
<div class="mssg <?= e($messagePage[0]) ?>" role="status"><?= e($messagePage[1]) ?></div>
<?php endif; ?>

<section class="card" id="formulaire">
<h2><?= $aModifier ? 'Modifier l’annonce' : 'Nouvelle annonce' ?></h2>
<form method="post" action="annonces.php">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="action" value="<?= $aModifier ? 'modifier' : 'publier' ?>">
<?php if ($aModifier): ?><input type="hidden" name="id" value="<?= (int) $aModifier['id'] ?>"><?php endif; ?>

<div class="form-grid">
<div class="field"><label for="type">Type</label>
<select id="type" name="type" required>
<?php foreach (DIR_TYPES_ANNONCE as $code => $lib): ?>
<option value="<?= e($code) ?>"<?= $val['type'] === $code ? ' selected' : '' ?>><?= e($lib) ?></option>
<?php endforeach; ?>
</select></div>

<div class="field"><label for="titre">Titre</label>
<input type="text" id="titre" name="titre" maxlength="120" required value="<?= e($val['titre']) ?>"></div>

<div class="field"><label for="class_id">Destinataires</label>
<select id="class_id" name="class_id">
<option value="0">Tous les parents</option>
<?php foreach ($classes as $c): ?>
<option value="<?= (int) $c['id'] ?>"<?= $val['class_id'] === (int) $c['id'] ? ' selected' : '' ?>>Parents de <?= e($c['nom']) ?></option>
<?php endforeach; ?>
</select></div>

<div class="field"><label for="date_evenement">Date de l'événement (facultatif)</label>
<input type="date" id="date_evenement" name="date_evenement" value="<?= e((string) $val['date']) ?>"></div>
</div>

<div class="form-grid">
<div class="field" style="grid-column:1/-1"><label for="message">Message</label>
<textarea id="message" name="message" maxlength="5000" required><?= e($val['message']) ?></textarea></div>
</div>

<div class="toolbar">
<button class="btn" type="submit"><?= $aModifier ? 'Enregistrer les modifications' : 'Publier l’annonce' ?></button>
<?php if ($aModifier): ?><a class="btn btn-secondary" href="annonces.php">Annuler</a><?php endif; ?>
</div>
<?php if (!$aModifier): ?><p class="muted">Les parents concernés reçoivent une notification dès la publication.</p><?php endif; ?>
</form>
</section>

<section class="card">
<h2>Annonces (<?= count($annonces) ?>)</h2>

<form class="toolbar" method="get" action="annonces.php" role="search">
<input type="search" name="q" placeholder="Rechercher dans les annonces" value="<?= e($q) ?>" aria-label="Rechercher">
<select name="type" aria-label="Type">
<option value="">Tous les types</option>
<?php foreach (DIR_TYPES_ANNONCE as $code => $lib): ?>
<option value="<?= e($code) ?>"<?= $fType === $code ? ' selected' : '' ?>><?= e($lib) ?></option>
<?php endforeach; ?>
</select>
<select name="statut" aria-label="Statut">
<option value="">Tous les statuts</option>
<option value="publiee"<?= $fStatut === 'publiee' ? ' selected' : '' ?>>Visibles</option>
<option value="retiree"<?= $fStatut === 'retiree' ? ' selected' : '' ?>>Retirées</option>
</select>
<button class="btn btn-sm" type="submit">Filtrer</button>
<?php if ($filtres): ?><a class="btn btn-secondary btn-sm" href="annonces.php">Réinitialiser</a><?php endif; ?>
</form>

<?php if (!$annonces): ?>
<p class="muted"><?= $filtres ? 'Aucune annonce ne correspond à ces filtres.' : 'Aucune annonce publiée pour le moment.' ?></p>
<?php else: ?>
<ul class="list">
<?php foreach ($annonces as $an): $visible = $an['statut'] === 'publiee'; ?>
<li>
<strong><?= e($an['titre']) ?></strong>
<span class="badge"><?= e(DIR_TYPES_ANNONCE[$an['type']] ?? $an['type']) ?></span>
<?= $visible ? '<span class="badge ok">Visible</span>' : '<span class="badge ko">Retirée</span>' ?>
<br>
<time><?= e(dir_date($an['created_at'])) ?></time>
<span class="muted"> · par <?= e($an['auteur']) ?> · <?= $an['classe'] ? 'Parents de ' . e($an['classe']) : 'Tous les parents' ?>
<?php if ($an['date_evenement']): ?> · événement le <?= e(dir_date($an['date_evenement'])) ?><?php endif; ?></span>
<p><?= nl2br(e($an['message'])) ?></p>
<div class="toolbar">
<a class="btn btn-secondary btn-sm" href="annonces.php?modifier=<?= (int) $an['id'] ?>#formulaire">Modifier</a>
<form method="post" action="annonces.php">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="id" value="<?= (int) $an['id'] ?>">
<?php if ($visible): ?>
<button class="btn btn-secondary btn-sm" type="submit" name="action" value="retirer"
        onclick="return confirm('Retirer cette annonce ? Les parents ne la verront plus.')">Retirer</button>
<?php else: ?>
<button class="btn btn-secondary btn-sm" type="submit" name="action" value="remettre">Remettre en ligne</button>
<?php endif; ?>
</form>
</div>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
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