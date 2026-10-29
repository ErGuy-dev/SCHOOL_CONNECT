<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect — Annonces de l'espace parent
// Fichier : frontend/parents/annonces.php
//
// Un parent voit uniquement :
// - les annonces générales publiées ;
// - les annonces publiées destinées aux classes de ses enfants
//   dont le rattachement au parent a été vérifié.
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

$annonces = [];
$erreur = false;
$q = '';
$type = '';

try {
    $q = trim((string) ($_GET['q'] ?? ''));

    if (function_exists('mb_strlen') && mb_strlen($q) > 100) {
        $q = mb_substr($q, 0, 100);
    } else {
        $q = substr($q, 0, 100);
    }

    $typeDemande = (string) ($_GET['type'] ?? '');

    // N'accepter que les types définis dans le projet.
    if (isset(PAR_TYPES_ANNONCE[$typeDemande])) {
        $type = $typeDemande;
    }

    // La fonction vérifie elle-même les annonces publiées et
    // les classes des enfants rattachés au parent.
    $annonces = par_annonces($pdo, $uid, 100, $q, $type);

} catch (Throwable $e) {
    error_log('SchoolConnect annonces parent : ' . $e->getMessage());
    $erreur = true;
}

$filtresActifs = $q !== '' || $type !== '';
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annonces | SchoolConnect</title>

    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/layout.css">
    <link rel="stylesheet" href="../assets/css/parents.css">
    <link rel="stylesheet" href="../notifications/notifications.css">
    <link rel="stylesheet" href="../assets/css/chat-global.css">
</head>

<body data-chat-enabled="true">
<div class="app">

    <?php par_sidebar('annonces'); ?>

    <div class="main">
        <?php require __DIR__ . '/../includes/topbar.php'; ?>

        <main class="content">
            <h1>Annonces de l'école</h1>

            <p class="lead">
                Retrouvez les communications publiées par l'établissement,
                notamment les réunions, les événements et les annonces générales.
            </p>

            <?php if ($erreur): ?>
                <div class="mssg err" role="alert">
                    Impossible de charger les annonces. Réessayez plus tard.
                </div>
            <?php endif; ?>

            <section class="card">
                <h2>Rechercher une annonce</h2>

                <form method="get" class="toolbar">
                    <input
                        type="search"
                        name="q"
                        placeholder="Titre ou contenu"
                        value="<?= e($q) ?>"
                        aria-label="Rechercher dans les annonces">

                    <select name="type" aria-label="Type d'annonce">
                        <option value="">Tous les types</option>

                        <?php foreach (PAR_TYPES_ANNONCE as $code => $libelle): ?>
                            <option
                                value="<?= e($code) ?>"
                                <?= $type === $code ? 'selected' : '' ?>>
                                <?= e($libelle) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button class="btn btn-sm" type="submit">
                        Rechercher
                    </button>

                    <?php if ($filtresActifs): ?>
                        <a class="btn btn-secondary btn-sm" href="annonces.php">
                            Réinitialiser
                        </a>
                    <?php endif; ?>
                </form>
            </section>

            <section class="card">
                <h2>Communications publiées</h2>

                <?php if (!$annonces): ?>

                    <p class="muted">
                        <?= $filtresActifs
                            ? 'Aucune annonce ne correspond à votre recherche.'
                            : 'Aucune annonce publiée pour le moment.' ?>
                    </p>

                <?php else: ?>

                    <ul class="list">
                        <?php foreach ($annonces as $annonce): ?>
                            <li>
                                <div>
                                    <strong><?= e($annonce['titre']) ?></strong>

                                    <span class="badge">
                                        <?= e(
                                            PAR_TYPES_ANNONCE[$annonce['type']]
                                            ?? (string) $annonce['type']
                                        ) ?>
                                    </span>
                                </div>

                                <p>
                                    <?= nl2br(e($annonce['message'])) ?>
                                </p>

                                <p class="muted">
                                    <?php if (!empty($annonce['classe'])): ?>
                                        Classe : <?= e($annonce['classe']) ?>
                                        ·
                                    <?php else: ?>
                                        Annonce générale ·
                                    <?php endif; ?>

                                    Publiée le
                                    <?= e(par_date($annonce['created_at'])) ?>

                                    <?php if (!empty($annonce['auteur'])): ?>
                                        · Par <?= e($annonce['auteur']) ?>
                                    <?php endif; ?>
                                </p>

                                <?php if (!empty($annonce['date_evenement'])): ?>
                                    <p>
                                        <strong>Date de l'événement :</strong>
                                        <?= e(par_date($annonce['date_evenement'], true)) ?>
                                    </p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                <?php endif; ?>
            </section>

            <p>
                <a href="tableau-de-bord.php">
                    Retour au tableau de bord
                </a>
            </p>
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
