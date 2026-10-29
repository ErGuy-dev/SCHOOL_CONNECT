<?php
declare(strict_types=1);

// =====================================================
// SCHOOLCONNECT — GESTION DOCUMENTAIRE
// =====================================================

// Sécurité, session, connexion à la base de données et CSRF.
require_once __DIR__ . '/../../backend/includes/document_security.php';

// Configuration du thème global.
require_once __DIR__ . '/../../backend/includes/theme.php';

// Autoriser uniquement les utilisateurs ayant accès aux documents.
doc_need('documents.view');

// Jeton CSRF pour protéger les formulaires.
$csrf = csrf_token();

// Les administrateurs et la direction peuvent gérer les archives.
$isManager = in_array(
    $_SESSION['role'] ?? '',
    ['administrateur', 'direction'],
    true
);
?>

<!DOCTYPE html>
<html lang="fr"
      data-theme-server="<?= e($themeGlobal ?? 'light') ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Gestion documentaire | SchoolConnect</title>

    <!-- Styles communs de SchoolConnect -->
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/layout.css">
    <link rel="stylesheet" href="../notifications/notifications.css">

    <!-- Styles propres à la gestion documentaire -->
    <style>
        .content {
            width: 100%;
            max-width: 1150px;
            margin: 28px auto;
            padding: 0 16px;
            box-sizing: border-box;
        }

        .content h1 {
            margin-bottom: 6px;
        }

        .content h2 {
            margin-top: 0;
        }

        .muted {
            color: #64748b;
            font-size: 13px;
        }

        .panel {
            background: var(--card, #ffffff);
            color: var(--text, #172033);
            border: 1px solid var(--border, #dce3ee);
            border-radius: 12px;
            padding: 18px;
            margin: 16px 0;
            box-sizing: border-box;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .panel label {
            display: block;
            font-weight: bold;
            margin: 8px 0 5px;
        }

        .panel input,
        .panel select,
        .panel textarea,
        .panel button {
            font: inherit;
        }

        .panel input:not([type="checkbox"]):not([type="file"]),
        .panel select,
        .panel textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            padding: 10px;
        }

        .panel input[type="file"] {
            display: block;
            max-width: 100%;
            margin: 8px 0;
        }

        .panel textarea {
            min-height: 65px;
            resize: vertical;
        }

        .panel input[type="checkbox"] {
            width: auto;
            margin-right: 6px;
        }

        .panel button {
            background: #2457c5;
            color: #ffffff;
            border: 0;
            border-radius: 7px;
            padding: 9px 12px;
            cursor: pointer;
            margin: 2px;
        }

        .panel button:hover {
            filter: brightness(0.93);
        }

        .panel button:focus-visible {
            outline: 2px solid #2457c5;
            outline-offset: 3px;
        }

        .panel button.secondary {
            background: #e8edf6;
            color: #24334e;
        }

        .panel button.danger {
            background: #b42318;
            color: #ffffff;
        }

        .status {
            min-height: 22px;
            margin: 10px 0;
            overflow-wrap: anywhere;
        }

        .tablewrap {
            width: 100%;
            overflow-x: auto;
        }

        .tablewrap table {
            border-collapse: collapse;
            width: 100%;
            min-width: 760px;
        }

        .tablewrap th,
        .tablewrap td {
            text-align: left;
            border-bottom: 1px solid #dce3ee;
            padding: 10px;
            vertical-align: top;
        }

        .tablewrap th {
            color: #64748b;
            font-size: 12px;
            text-transform: uppercase;
        }

        .tablewrap td {
            overflow-wrap: anywhere;
        }

        .actions {
            min-width: 150px;
        }

        .actions button {
            margin-bottom: 5px;
        }

        @media (max-width: 650px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .content {
                margin-top: 16px;
                padding: 0 10px;
            }

            .panel {
                padding: 12px;
            }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/chat-global.css">
</head>

<body data-chat-enabled="true">

<div class="app">

    <!-- ==============================================
         MENU LATÉRAL DE SCHOOLCONNECT
         ============================================== -->
    <aside class="sidebar" id="menu">

        <div class="brand">SchoolConnect</div>

        <div class="role">
            <?= e(ucfirst($_SESSION['role'] ?? 'Utilisateur')) ?>
        </div>

        <nav class="nav" aria-label="Navigation principale">

            <a href="tableau-de-bord.php">
                Tableau de bord
            </a>

            <a href="comptes.php">
                Comptes
            </a>

            <a href="classes-matieres.php">
                Classes et matières
            </a>


            <a href="sauvegardes.php">
                Sauvegardes
            </a>

            <a href="journaux.php">
                Journaux d’audit
            </a>
            <a href="documents.php" aria-current="page">
                Documents
            </a>
            <a href="rapports.php">Rapports et statistiques</a>

            <a href="parametres.php">
                Paramètres
            </a>

        </nav>

        <a class="logout" href="../auth/connexion.html">
            Se déconnecter
        </a>

    </aside>

    <!-- ==============================================
         CONTENU PRINCIPAL
         ============================================== -->
    <div class="main">

        <header class="topbar">

            <button
                class="menu-btn"
                type="button"
                aria-controls="menu"
                aria-expanded="false">
                Menu
            </button>

            <span class="spacer"></span>

            <div class="topbar-right">

                <!-- Centre de notifications -->
                <section id="notifications-app" class="notifications-app"
                         data-api-base="/SCHOOL_CONNECT/backend/api/notifications">

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

                <span class="user">
                    <?= e(
                        $_SESSION['user_nom']
                        ?? $_SESSION['nom']
                        ?? 'Utilisateur'
                    ) ?>
                </span>

            </div>

        </header>

        <main class="content">

            <h1>Gestion documentaire</h1>

            <p class="muted">
                Documents administratifs et pédagogiques —
                limite de 20 Mio par fichier.
            </p>

            <!-- ======================================
                 FORMULAIRE D'AJOUT
                 ====================================== -->
            <section class="panel" id="createPanel">

                <h2>Ajouter un document</h2>

                <form id="createForm" enctype="multipart/form-data">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e($csrf) ?>">

                    <div class="grid">

                        <div>
                            <label for="titre">Titre *</label>

                            <input
                                id="titre"
                                name="titre"
                                maxlength="200"
                                required>
                        </div>

                        <div>
                            <label for="categorie">Catégorie *</label>

                            <select
                                id="categorie"
                                name="categorie"
                                required>

                                <option value="administratif">
                                    Administratif
                                </option>

                                <option value="pedagogique">
                                    Pédagogique
                                </option>

                            </select>
                        </div>

                    </div>

                    <label for="description">Description</label>

                    <textarea
                        id="description"
                        name="description"
                        maxlength="10000"></textarea>

                    <label for="fichier">Fichier *</label>

                    <input
                        id="fichier"
                        name="fichier"
                        type="file"
                        required
                        accept=".pdf,.txt,.csv,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx">

                    <p class="muted">
                        PDF, TXT, CSV, JPG, PNG, Word, Excel ou PowerPoint.
                    </p>

                    <button type="submit">
                        Enregistrer
                    </button>

                </form>

            </section>

            <!-- ======================================
                 RECHERCHE ET LISTE DES DOCUMENTS
                 ====================================== -->
            <section class="panel">

                <h2>Rechercher</h2>

                <form id="searchForm">

                    <div class="grid">

                        <div>
                            <label for="q">Mot-clé</label>

                            <input
                                id="q"
                                placeholder="Titre, description ou nom du fichier">
                        </div>

                        <div>
                            <label for="cat">Catégorie</label>

                            <select id="cat">

                                <option value="">Toutes</option>

                                <option value="administratif">
                                    Administratif
                                </option>

                                <option value="pedagogique">
                                    Pédagogique
                                </option>

                            </select>
                        </div>

                    </div>

                    <?php if ($isManager): ?>

                        <p>
                            <label>
                                <input type="checkbox" id="archives">
                                Inclure les archives
                            </label>
                        </p>

                    <?php endif; ?>

                    <p>
                        <button type="submit">
                            Rechercher
                        </button>

                        <button
                            type="button"
                            class="secondary"
                            id="reset">
                            Réinitialiser
                        </button>
                    </p>

                </form>

                <div
                    id="status"
                    class="status"
                    aria-live="polite">
                </div>

                <div class="tablewrap">

                    <table>

                        <thead>
                            <tr>
                                <th>Document</th>
                                <th>Catégorie</th>
                                <th>Créateur</th>
                                <th>Version</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody id="rows">
                            <tr>
                                <td colspan="6">
                                    Chargement…
                                </td>
                            </tr>
                        </tbody>

                    </table>

                </div>

            </section>

        </main>

    </div>

</div>

<!-- Scripts communs de SchoolConnect -->
<script src="../assets/js/theme.js"></script>
<script src="../assets/js/app.js"></script>
<script src="../notifications/notifications.js"></script>
<script src="../assets/js/chat-global.js" defer></script>

<!-- ================================================
     JAVASCRIPT DE GESTION DOCUMENTAIRE
     ================================================ -->
<script>
(() => {
    'use strict';

    const API = '../../backend/api/documents/';

    const csrf = <?= json_encode(
        $csrf,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;

    const manager = <?= $isManager ? 'true' : 'false' ?>;

    const rows = document.getElementById('rows');
    const status = document.getElementById('status');
    const form = document.getElementById('createForm');

    // Échapper les valeurs avant de les insérer dans le HTML.
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[c]));

    // Afficher un message à l'utilisateur.
    function say(message, error = false) {
        status.textContent = message;
        status.style.color = error ? '#b42318' : '';
    }

    // Appeler l'API et traiter sa réponse JSON.
    async function api(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || data.success === false) {
            throw Error(data.message || 'Erreur serveur');
        }

        return data;
    }

    // Charger les documents depuis le serveur.
    async function load() {
        rows.innerHTML =
            '<tr><td colspan="6">Chargement…</td></tr>';

        const params = new URLSearchParams();

        const keyword = document.getElementById('q').value.trim();
        const category = document.getElementById('cat').value;

        if (keyword) {
            params.set('q', keyword);
        }

        if (category) {
            params.set('categorie', category);
        }

        const archiveCheckbox = document.getElementById('archives');

        if (archiveCheckbox && archiveCheckbox.checked) {
            params.set('archives', '1');
        }

        try {
            const data = await api(API + 'list.php?' + params);

            if (!Array.isArray(data.documents)) {
                throw Error('Format de réponse inattendu.');
            }

            if (!data.documents.length) {
                rows.innerHTML =
                    '<tr><td colspan="6">Aucun document.</td></tr>';

                return;
            }

            rows.innerHTML = data.documents.map(document => {
                const archived = Number(document.est_archive) === 1;
                const actions = [];

                if (document.can_download) {
                    actions.push(
                        `<button data-download="${+document.id}">
                            Télécharger
                        </button>`
                    );
                }

                if (document.can_add_version && !archived) {
                    actions.push(
                        `<button class="secondary"
                            data-version="${+document.id}">
                            Nouvelle version
                        </button>`
                    );
                }

                if (document.can_share && !archived) {
                    actions.push(
                        `<button class="secondary"
                            data-share="${+document.id}">
                            Partager
                        </button>`
                    );
                }

                if (manager) {
                    actions.push(
                        `<button
                            class="${archived ? 'secondary' : 'danger'}"
                            data-archive="${+document.id}"
                            data-state="${archived ? 0 : 1}">
                            ${archived ? 'Désarchiver' : 'Archiver'}
                        </button>`
                    );
                }

                const size = document.taille_octets
                    ? (Number(document.taille_octets) / 1048576).toFixed(2)
                        + ' Mio'
                    : '—';

                const categoryLabel =
                    document.categorie === 'administratif'
                        ? 'Administratif'
                        : 'Pédagogique';

                return `
                    <tr>
                        <td>
                            <b>${esc(document.titre)}</b>

                            <div class="muted">
                                ${esc(document.nom_original || '')}
                                · ${size}
                            </div>

                            <div class="muted">
                                ${esc(document.description || '')}
                            </div>
                        </td>

                        <td>${categoryLabel}</td>

                        <td>${esc(document.createur)}</td>

                        <td>v${+document.version_courante}</td>

                        <td>${archived ? 'Archivé' : 'Actif'}</td>

                        <td class="actions">
                            ${actions.join(' ') || '—'}
                        </td>
                    </tr>
                `;
            }).join('');

        } catch (error) {
            rows.innerHTML =
                '<tr><td colspan="6">Impossible de charger les documents.</td></tr>';

            say(error.message, true);
        }
    }

    // Ajouter un nouveau document.
    form.addEventListener('submit', async event => {
        event.preventDefault();

        const file = document.getElementById('fichier').files[0];

        if (!file) {
            say('Sélectionnez un fichier.', true);
            return;
        }

        if (file.size > 20 * 1024 * 1024) {
            say('Le fichier dépasse 20 Mio.', true);
            return;
        }

        try {
            say('Enregistrement…');

            const data = await api(API + 'create.php', {
                method: 'POST',
                body: new FormData(form)
            });

            say(data.message || 'Document enregistré.');

            form.reset();

            await load();

        } catch (error) {
            say(error.message, true);
        }
    });

    // Effectuer une recherche.
    document.getElementById('searchForm')
        .addEventListener('submit', event => {
            event.preventDefault();
            load();
        });

    // Réinitialiser les filtres.
    document.getElementById('reset')
        .addEventListener('click', () => {
            document.getElementById('searchForm').reset();
            load();
        });

    // Actualiser la liste si le filtre des archives change.
    const archiveCheckbox = document.getElementById('archives');

    if (archiveCheckbox) {
        archiveCheckbox.addEventListener('change', load);
    }

    // Gérer les boutons d'action de chaque document.
    rows.addEventListener('click', async event => {
        const button = event.target.closest('button');

        if (!button) {
            return;
        }

        // Télécharger un document.
        if (button.dataset.download) {
            location.href = API + 'download.php?id='
                + encodeURIComponent(button.dataset.download);

            return;
        }

        // Ajouter une nouvelle version.
        if (button.dataset.version) {
            const input = document.createElement('input');

            input.type = 'file';
            input.accept =
                '.pdf,.txt,.csv,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx';

            input.addEventListener('change', async () => {
                const file = input.files[0];

                if (!file) {
                    return;
                }

                if (file.size > 20 * 1024 * 1024) {
                    say('Fichier supérieur à 20 Mio.', true);
                    return;
                }

                const formData = new FormData();

                formData.append('csrf', csrf);
                formData.append('document_id', button.dataset.version);
                formData.append(
                    'commentaire',
                    prompt('Commentaire (facultatif)') || ''
                );
                formData.append('fichier', file);

                try {
                    const data = await api(API + 'version.php', {
                        method: 'POST',
                        body: formData
                    });

                    say(data.message || 'Nouvelle version ajoutée.');

                    await load();

                } catch (error) {
                    say(error.message, true);
                }
            });

            input.click();

            return;
        }

        // Partager un document avec un utilisateur.
        if (button.dataset.share) {
            const userId = prompt('ID numérique du destinataire :');

            if (!userId) {
                return;
            }

            if (!/^[1-9]\d*$/.test(userId.trim())) {
                say('Veuillez saisir un ID utilisateur numérique valide.', true);
                return;
            }

            const permission = prompt(
                'Droit : lecture, telechargement ou modification',
                'lecture'
            );

            if (!permission) {
                return;
            }

            const allowedPermissions = [
                'lecture',
                'telechargement',
                'modification'
            ];

            if (!allowedPermissions.includes(permission.trim().toLowerCase())) {
                say('Le droit choisi est invalide.', true);
                return;
            }

            const expiration = prompt(
                'Expiration YYYY-MM-DDTHH:MM (facultatif)',
                ''
            ) || '';

            const formData = new FormData();

            formData.append('csrf', csrf);
            formData.append('document_id', button.dataset.share);
            formData.append('user_id', userId.trim());
            formData.append('permission', permission.trim().toLowerCase());
            formData.append('expire_le', expiration);

            try {
                const data = await api(API + 'share.php', {
                    method: 'POST',
                    body: formData
                });

                say(data.message || 'Document partagé.');

                await load();

            } catch (error) {
                say(error.message, true);
            }

            return;
        }

        // Archiver ou désarchiver un document.
        if (button.dataset.archive) {
            if (!confirm('Confirmer cette action ?')) {
                return;
            }

            const formData = new FormData();

            formData.append('csrf', csrf);
            formData.append('document_id', button.dataset.archive);
            formData.append('archive', button.dataset.state);

            try {
                const data = await api(API + 'archive.php', {
                    method: 'POST',
                    body: formData
                });

                say(data.message || 'Statut du document mis à jour.');

                await load();

            } catch (error) {
                say(error.message, true);
            }
        }
    });

    // Chargement initial de la liste.
    load();

})();
</script>

</body>
</html>