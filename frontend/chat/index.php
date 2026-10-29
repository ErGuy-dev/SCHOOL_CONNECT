<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/includes/security.php';

// Les quatre rôles peuvent accéder à la messagerie.
require_role('administrateur', 'direction', 'enseignant', 'parent');

$uid = (int) $_SESSION['user_id'];
$nom = (string) ($_SESSION['user_nom'] ?? 'Utilisateur');
$role = (string) ($_SESSION['role'] ?? '');

$rolesLibelles = [
    'administrateur' => 'Administrateur',
    'direction' => 'Direction',
    'enseignant' => 'Enseignant',
    'parent' => 'Parent'
];

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e($csrf) ?>">
    <title>Messagerie | SchoolConnect</title>
    <link rel="stylesheet" href="chat.css">
</head>
<body>
<div class="chat-app">
    <aside class="chat-sidebar">
        <header class="sidebar-header">
            <div>
                <?php
                // Déterminer le tableau de bord selon le rôle de l'utilisateur connecté.
                    $role = $_SESSION['role'] ?? '';

                    $dashboards = [
                        'administrateur' => '../admin/tableau-de-bord.php',
                        'direction'      => '../direction/tableau-de-bord.php',
                        'enseignant'     => '../enseignants/tableau-de-bord.php',
                        'parent'         => '../parents/tableau-de-bord.php',
                    ];

                    $dashboardUrl = $dashboards[$role] ?? '../';
                    ?>

                    <a class="back-link" href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>">
                        ← Retour au tableau de bord
                    </a>
                <h1>Messages</h1>
                <p class="user-caption">
                    <?= e($nom) ?> · <?= e($rolesLibelles[$role] ?? $role) ?>
                </p>
            </div>
            <button type="button" id="refresh-button" class="icon-button"
                    aria-label="Actualiser les conversations" title="Actualiser">
                ↻
            </button>
        </header>

        <label class="search-label" for="contact-search">Rechercher un contact</label>
        <input id="contact-search" class="search-input"
               type="search" placeholder="Nom du contact…"
               autocomplete="off">

        <p id="sidebar-status" class="status-text" role="status">
            Chargement des contacts…
        </p>

        <section>
            <h2 class="section-title">Conversations</h2>
            <div id="conversation-list" class="conversation-list"></div>
        </section>

        <section>
            <h2 class="section-title">Nouveau message</h2>
            <div id="contact-list" class="contact-list"></div>
        </section>
    </aside>

    <main class="chat-main">
        <header class="conversation-header" id="conversation-header">
            <div class="avatar" aria-hidden="true">SC</div>
            <div>
                <h2 id="active-contact-name">Bienvenue dans la messagerie</h2>
                <p id="active-contact-role">Sélectionnez une conversation ou un contact.</p>
            </div>
        </header>

        <div id="chat-error" class="chat-error" role="alert" hidden></div>

        <section id="message-list" class="message-list" aria-live="polite">
            <div class="empty-state">
                <div class="empty-icon" aria-hidden="true">✉</div>
                <h3>Vos messages</h3>
                <p>Choisissez un contact pour commencer à discuter.</p>
            </div>
        </section>

        <form id="message-form" class="message-form">
            <label class="sr-only" for="message-input">Votre message</label>
            <textarea id="message-input" name="contenu"
                      rows="1" maxlength="5000"
                      placeholder="Écrire un message…"
                      disabled required></textarea>
            <button id="send-button" type="submit" disabled>Envoyer</button>
        </form>
        <p class="composer-help">Entrée pour envoyer · Maj + Entrée pour une nouvelle ligne</p>
    </main>
</div>

<script>
    // Données d'identité nécessaires à l'interface.
    window.SCHOOL_CHAT = {
        userId: <?= $uid ?>,
        apiBase: '../../backend/api/chat/'
    };
</script>
<script src="chat.js" defer></script>
</body>
</html>
