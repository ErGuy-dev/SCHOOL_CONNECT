<?php
/*
 * SchoolConnect - Barre supérieure partagée
 * Fichier : frontend/includes/topbar.php
 *
 * À inclure dans les pages enseignant, direction et parents :
 *     <?php require __DIR__ . '/../includes/topbar.php'; ?>
 *
 * Variables facultatives à définir AVANT l'inclusion :
 *   $nomAffichage : nom affiché à droite (par défaut : nom de la session)
 *   $topbarGauche : HTML déjà échappé affiché après le bouton Menu
 *                   (par exemple le sélecteur d'enfant côté parents)
 */

$nomAffichage = (string) ($nomAffichage ?? ($_SESSION['user_nom'] ?? 'Utilisateur'));
$topbarGauche = (string) ($topbarGauche ?? '');

// Racine du projet déduite de l'adresse de la page :
// /SCHOOL_CONNECT/frontend/enseignants/page.php  ->  /SCHOOL_CONNECT
// Aucun nom de dossier n'est écrit en dur : le projet reste déplaçable.
$racineProjet = rtrim(
    str_replace('\\', '/', dirname(dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))))),
    '/'
);
$apiNotifications = $racineProjet . '/backend/api/notifications';

// Respecter le paramètre « Activer les notifications applicatives ».
$afficherCloche = function_exists('school_setting_enabled')
    ? school_setting_enabled('notifications_enabled')
    : true;
?>
<header class="topbar">
    <button class="menu-btn" type="button" aria-controls="menu" aria-expanded="false">Menu</button>

    <?= $topbarGauche ?>

    <span class="spacer"></span>

    <div class="topbar-right">
        <?php if ($afficherCloche): ?>
            <!-- Centre de notifications -->
            <section id="notifications-app" class="notifications-app"
                     data-api-base="<?= htmlspecialchars($apiNotifications, ENT_QUOTES, 'UTF-8') ?>">

                <button type="button" id="notifications-toggle" class="notifications-toggle"
                        aria-expanded="false" aria-controls="notifications-panel"
                        aria-label="Notifications">
                    <svg class="notifications-icon" viewBox="0 0 24 24" width="22" height="22"
                         fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
                        <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
                    </svg>
                    <span id="notifications-count" class="notifications-count" hidden>0</span>
                </button>

                <section id="notifications-panel" class="notifications-panel"
                         aria-label="Centre de notifications" hidden>
                    <header class="notifications-header">
                        <h2>Mes notifications</h2>
                        <button type="button" id="notifications-refresh"
                                class="notifications-refresh">Actualiser</button>
                    </header>

                    <p id="notifications-status" class="notifications-status"
                       role="status" aria-live="polite">Chargement des notifications...</p>

                    <ul id="notifications-list" class="notifications-list"></ul>
                </section>
            </section>
        <?php endif; ?>

        <span class="user"><?= htmlspecialchars($nomAffichage, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
</header>