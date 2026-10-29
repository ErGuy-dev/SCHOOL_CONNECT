<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Copies corrigées (parents)
// Fichier : frontend/parents/copies.php
//
// Les fichiers ne sont JAMAIS servis directement : l'aperçu et le téléchargement passent par
// backend/api/copies/telecharger.php, qui revérifie en base le lien parent-élève.
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

$copies    = [];
$matieres  = [];
$choisie   = null;
$matiereId = 0;
$q         = '';
$erreur    = false;

try {
    if ($enfant) {
        $sid = (int) $enfant['id'];

        // Matières proposées dans le filtre : celles des copies publiées.
        foreach (par_copies($pdo, $uid, $sid) as $c) {
            $matieres[(int) $c['matiere_id']] = (string) $c['matiere'];
        }
        asort($matieres);

        $matiereId = (int) ($_GET['matiere_id'] ?? 0);
        if (!isset($matieres[$matiereId])) {
            $matiereId = 0;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) > 80) {
            $q = mb_substr($q, 0, 80);
        }

        $copies = par_copies($pdo, $uid, $sid, $matiereId, $q);

        // Copie affichée dans l'aperçu : elle doit figurer dans la liste de CE parent.
        $demande = (int) ($_GET['copie'] ?? 0);
        foreach ($copies as $c) {
            if ($demande > 0 && (int) $c['id'] === $demande) {
                $choisie = $c;
            }
        }
        if ($choisie === null && $copies) {
            $choisie = $copies[0];
        }
    }
} catch (Throwable $e) {
    error_log('SchoolConnect copies parent : ' . $e->getMessage());
    $erreur = true;
}

$api = '../../backend/api/copies/telecharger.php';
$filtresActifs = ($matiereId > 0 || $q !== '');

function lien_apercu(int $copieId): string
{
    $p = $_GET;
    $p['copie'] = $copieId;
    return 'copies.php?' . http_build_query($p) . '#apercu';
}

$estPdf = $choisie !== null && $choisie['mime'] === 'application/pdf';
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Copies corrigées | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../assets/css/parents.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

<?php par_sidebar('copies'); ?>

<div class="main">
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main class="content">
<h1>Copies corrigées</h1>
<p class="lead">Copies publiées par les enseignants.</p>

<?php if ($erreur): ?>
<div class="mssg err" role="alert">Impossible de charger les copies. Réessayez plus tard.</div>
<?php endif; ?>

<?php if (!$enfant): ?>
<div class="mssg warn" role="status">Aucun élève n'est associé à votre compte. Contactez l'administration de l'école.</div>
<?php else: ?>

<div class="cols">
<section class="card">
<h2>Liste</h2>

<form method="get" class="toolbar">
<input type="search" name="q" placeholder="Rechercher" value="<?= e($q) ?>" aria-label="Rechercher une copie">
<select name="matiere_id" aria-label="Matière" data-auto-submit>
<option value="0">Toutes les matières</option>
<?php foreach ($matieres as $mid => $mnom): ?>
<option value="<?= (int) $mid ?>"<?= $matiereId === (int) $mid ? ' selected' : '' ?>><?= e($mnom) ?></option>
<?php endforeach; ?>
</select>
<button class="btn btn-sm" type="submit">Filtrer</button>
<?php if ($filtresActifs): ?><a class="btn btn-secondary btn-sm" href="copies.php">Réinitialiser</a><?php endif; ?>
</form>

<div class="table-wrap">
<table class="table">
<thead><tr>
<th scope="col">Évaluation</th><th scope="col">Date</th><th scope="col">Note</th><th scope="col"></th>
</tr></thead>
<tbody>
<?php if (!$copies): ?>
<tr><td colspan="4"><?= $filtresActifs ? 'Aucune copie ne correspond à ces filtres.' : 'Aucune copie publiée pour le moment.' ?></td></tr>
<?php endif; ?>
<?php foreach ($copies as $c): $active = $choisie !== null && (int) $c['id'] === (int) $choisie['id']; ?>
<tr<?= $active ? ' class="ligne-active"' : '' ?>>
<td><?= e($c['titre']) ?>, <?= e($c['matiere']) ?>
<br><small class="muted"><?= $c['mime'] === 'application/pdf' ? 'PDF' : 'Image' ?> · <?= e(par_taille((int) $c['taille'])) ?></small></td>
<td><?= e(par_date($c['date_eval'])) ?></td>
<td><?= $c['note'] !== null ? e(par_nombre($c['note'])) . ' / ' . e(par_nombre($c['bareme'])) : '—' ?></td>
<td class="actions">
<a class="btn btn-secondary btn-sm" href="<?= e(lien_apercu((int) $c['id'])) ?>">Aperçu</a>
<a class="btn btn-sm" href="<?= e($api . '?id=' . (int) $c['id'] . '&dl=1') ?>">Télécharger</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<section class="card" id="apercu">
<h2>Aperçu</h2>
<?php if ($choisie === null): ?>
<p class="muted">Sélectionnez une copie dans la liste pour l'afficher ici.</p>
<?php else: ?>
<p class="muted"><?= e($choisie['titre']) ?>, <?= e($choisie['matiere']) ?></p>

<?php if (!$estPdf): ?>
<div class="zoom">
<button type="button" class="btn btn-secondary btn-sm" data-zoom="out">Zoom -</button>
<button type="button" class="btn btn-secondary btn-sm" data-zoom="in">Zoom +</button>
<button type="button" class="btn btn-secondary btn-sm" data-zoom="reset">Taille réelle</button>
</div>
<div class="viewer" tabindex="0">
<img src="<?= e($api . '?id=' . (int) $choisie['id']) ?>" alt="Copie corrigée : <?= e($choisie['titre']) ?>">
</div>
<?php else: ?>
<div class="viewer viewer-pdf">
<iframe src="<?= e($api . '?id=' . (int) $choisie['id']) ?>" title="Copie corrigée : <?= e($choisie['titre']) ?>"></iframe>
</div>
<p class="muted">Le lecteur PDF de votre navigateur permet de zoomer.
<a href="<?= e($api . '?id=' . (int) $choisie['id'] . '&dl=1') ?>">Télécharger le fichier</a></p>
<?php endif; ?>
<?php endif; ?>
</section>
</div>
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