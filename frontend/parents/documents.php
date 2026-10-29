<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect — Documents de l'espace parent
// Fichier : frontend/parents/documents.php
//
// Les documents disponibles dans le modèle actuel sont les
// copies publiées associées aux évaluations.
// Le téléchargement passe par une API qui revérifie les droits.
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';
require_once __DIR__ . '/../../backend/includes/parents.php';

require_role('parent');

$pdo = db();
par_forcer_changement_mdp($pdo);

$uid = (int) $_SESSION['user_id'];
$enfants = par_enfants($pdo, $uid);
$enfant = par_enfant_courant($enfants);
$topbarGauche = par_topbar_gauche($enfants, $enfant);

$documents = [];
$erreur = false;
$q = '';
$matiereId = 0;
$matieres = [];

try {
    if ($enfant) {
        $sid = (int) $enfant['id'];

        // Charger les documents publiés accessibles à cet enfant.
        $tousLesDocuments = par_copies($pdo, $uid, $sid);

        // Construire la liste des matières disponibles pour le filtre.
        foreach ($tousLesDocuments as $doc) {
            $matieres[(int) $doc['matiere_id']] = (string) $doc['matiere'];
        }
        asort($matieres);

        // Lire et valider les filtres de recherche.
        $q = trim((string) ($_GET['q'] ?? ''));

        if (function_exists('mb_strlen') && mb_strlen($q) > 80) {
            $q = mb_substr($q, 0, 80);
        } else {
            $q = substr($q, 0, 80);
        }

        $matiereId = (int) ($_GET['matiere_id'] ?? 0);

        // N'accepter qu'une matière réellement disponible pour cet enfant.
        if ($matiereId !== 0 && !isset($matieres[$matiereId])) {
            $matiereId = 0;
        }

        $documents = par_copies($pdo, $uid, $sid, $matiereId, $q);
    }
} catch (Throwable $e) {
    error_log('SchoolConnect documents parent : ' . $e->getMessage());
    $erreur = true;
}

$api = '../../backend/api/copies/telecharger.php';
$filtresActifs = $q !== '' || $matiereId > 0;
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents | SchoolConnect</title>

    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/layout.css">
    <link rel="stylesheet" href="../assets/css/parents.css">
    <link rel="stylesheet" href="../notifications/notifications.css">
    <link rel="stylesheet" href="../assets/css/chat-global.css">
</head>

<body data-chat-enabled="true">
<div class="app">

    <?php par_sidebar('documents'); ?>

    <div class="main">
        <?php require __DIR__ . '/../includes/topbar.php'; ?>

        <main class="content">
            <h1>Documents</h1>

            <p class="lead">
                Consultez les documents pédagogiques publiés pour
                <?= $enfant
                    ? '<strong>' . e($enfant['prenom'] . ' ' . $enfant['nom']) . '</strong>'
                    : 'votre enfant' ?>.
            </p>

            <?php if ($erreur): ?>
                <div class="mssg err" role="alert">
                    Impossible de charger les documents. Réessayez plus tard.
                </div>
            <?php endif; ?>

            <?php if (!$enfant): ?>

                <div class="mssg warn" role="status">
                    Aucun élève n'est associé à votre compte avec un lien vérifié.
                    Contactez l'administration de l'école.
                </div>

            <?php else: ?>

                <section class="card">
                    <h2>Documents disponibles</h2>

                    <p class="muted">
                        Seuls les documents publiés par l'école sont affichés.
                    </p>

                    <form method="get" class="toolbar">
                        <input
                            type="search"
                            name="q"
                            placeholder="Rechercher un document"
                            value="<?= e($q) ?>"
                            aria-label="Rechercher un document">

                        <select name="matiere_id" aria-label="Filtrer par matière">
                            <option value="0">Toutes les matières</option>
                            <?php foreach ($matieres as $id => $nomMatiere): ?>
                                <option
                                    value="<?= (int) $id ?>"
                                    <?= $matiereId === (int) $id ? 'selected' : '' ?>>
                                    <?= e($nomMatiere) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button class="btn btn-sm" type="submit">Rechercher</button>

                        <?php if ($filtresActifs): ?>
                            <a class="btn btn-secondary btn-sm" href="documents.php">
                                Réinitialiser
                            </a>
                        <?php endif; ?>
                    </form>

                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th scope="col">Document</th>
                                <th scope="col">Matière</th>
                                <th scope="col">Évaluation</th>
                                <th scope="col">Date</th>
                                <th scope="col">Taille</th>
                                <th scope="col">Action</th>
                            </tr>
                            </thead>

                            <tbody>
                            <?php if (!$documents): ?>
                                <tr>
                                    <td colspan="6">
                                        <?= $filtresActifs
                                            ? 'Aucun document ne correspond à votre recherche.'
                                            : 'Aucun document publié pour le moment.' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($documents as $doc): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?= e($doc['nom_original'] ?: $doc['titre']) ?>
                                        </strong>
                                        <br>
                                        <small class="muted">
                                            <?= $doc['mime'] === 'application/pdf'
                                                ? 'Document PDF'
                                                : 'Document image' ?>
                                        </small>
                                    </td>

                                    <td><?= e($doc['matiere']) ?></td>
                                    <td><?= e($doc['titre']) ?></td>
                                    <td><?= e(par_date($doc['date_eval'])) ?></td>
                                    <td><?= e(par_taille((int) $doc['taille'])) ?></td>

                                    <td class="actions">
                                        <a
                                            class="btn btn-sm"
                                            href="<?= e($api . '?id=' . (int) $doc['id'] . '&dl=1') ?>">
                                            Télécharger
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <p class="muted">
                    Pour visualiser une copie corrigée directement dans le navigateur,
                    consultez aussi la page
                    <a href="copies.php">Copies corrigées</a>.
                </p>

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
