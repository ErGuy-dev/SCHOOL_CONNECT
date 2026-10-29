<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';

require_role('administrateur');

$pdo = db();

$annees = $pdo->query(
    'SELECT id, libelle FROM school_years ORDER BY debut DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$anneeActive = $pdo->query(
    'SELECT id FROM school_years WHERE active = 1 LIMIT 1'
)->fetchColumn();

$anneeParDefaut = $anneeActive
    ? (string) $anneeActive
    : (string) ($annees[0]['id'] ?? '');

$classes = $pdo->query(
    'SELECT id, nom, school_year_id FROM classes ORDER BY nom'
)->fetchAll(PDO::FETCH_ASSOC);

$matieres = $pdo->query(
    'SELECT id, nom FROM subjects ORDER BY nom'
)->fetchAll(PDO::FETCH_ASSOC);

function rh(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= rh($themeGlobal) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rapports et statistiques | SchoolConnect</title>
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/layout.css">
    <link rel="stylesheet" href="../notifications/notifications.css">
    <style>
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        .filters label { display: grid; gap: .35rem; }
        .filters select, .filters button {
            padding: .65rem;
            max-width: 100%;
        }
        .report-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            margin: 1rem 0;
        }
        .report-actions button, .report-actions a {
            padding: .65rem .9rem;
        }
        .report-message { margin: 1rem 0; }
    </style>
    <link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">
    <aside class="sidebar" id="menu">
        <div class="brand">SchoolConnect</div>
        <div class="role">Administrateur</div>
        <nav class="nav" aria-label="Navigation principale">
            <a href="tableau-de-bord.php">Tableau de bord</a>
            <a href="comptes.php">Comptes</a>
            <a href="classes-matieres.php">Classes et matières</a>
            <a href="sauvegardes.php">Sauvegardes</a>
            <a href="journaux.php">Journaux d'audit</a>
            <a href="documents.php">Documents</a>
            <a href="rapports.php" aria-current="page">Rapports et statistiques</a>
            <a href="parametres.php">Paramètres</a>
        </nav>
        <a class="logout" href="../auth/logout.php">Se déconnecter</a>
    </aside>

    <div class="main">
                <header class="topbar">
            <button class="menu-btn" type="button"
                    aria-controls="menu" aria-expanded="false">Menu</button>

            <span class="spacer"></span>

            <div class="topbar-right">
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

                <span class="user"><?= rh($_SESSION['user_nom'] ?? 'Administrateur') ?></span>
            </div>
        </header>

        <main class="content">
            <h1>Rapports et statistiques</h1>
            <p class="lead">Filtre les résultats publiés puis exporte le rapport.</p>

            <section class="card">
                <h2>Critères du rapport</h2>

                <form id="reportFilters">
                    <div class="filters">
                        <label>
                            Année scolaire
                            <select name="school_year_id" id="schoolYear" required>
                                <option value="">Choisir une année</option>
                                <?php foreach ($annees as $a): ?>
                                    <option value="<?= (int) $a['id'] ?>"
                                        <?= (string) $a['id'] === $anneeParDefaut ? 'selected' : '' ?>>
                                        <?= rh($a['libelle']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            Classe
                            <select name="class_id" id="classId">
                                <option value="">Toutes les classes</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>"
                                            data-year="<?= (int) $c['school_year_id'] ?>">
                                        <?= rh($c['nom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            Période
                            <select name="period_id" id="periodId">
                                <option value="">Toutes les périodes</option>
                            </select>
                        </label>

                        <label>
                            Matière
                            <select name="subject_id" id="subjectId">
                                <option value="">Toutes les matières</option>
                                <?php foreach ($matieres as $m): ?>
                                    <option value="<?= (int) $m['id'] ?>">
                                        <?= rh($m['nom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <button type="submit">Afficher les statistiques</button>
                </form>
            </section>

            <p id="reportMessage" class="report-message" role="status"></p>

            <div class="stats" id="reportStats" aria-live="polite"></div>

            <section class="card">
                <h2>Résultats par classe</h2>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Classe</th>
                            <th>Élèves avec notes</th>
                            <th>Moyenne /20</th>
                            <th>Réussite (≥ 10/20)</th>
                        </tr>
                        </thead>
                        <tbody id="classResults">
                        <tr><td colspan="4">Choisis les filtres puis affiche les statistiques.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <h2>Résultats par matière</h2>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Matière</th>
                            <th>Notes comptabilisées</th>
                            <th>Moyenne /20</th>
                            <th>Réussite (≥ 10/20)</th>
                        </tr>
                        </thead>
                        <tbody id="subjectResults">
                        <tr><td colspan="4">Aucune donnée chargée.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <h2>Exporter</h2>
                <p>Les exports respectent les filtres actuellement sélectionnés.</p>
                <div class="report-actions">
                    <button type="button" id="exportCsv">Exporter pour Excel (CSV)</button>
                    <button type="button" id="exportPdf">Exporter en PDF</button>
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
(() => {
    'use strict';

    const form = document.getElementById('reportFilters');
    const year = document.getElementById('schoolYear');
    const classSelect = document.getElementById('classId');
    const period = document.getElementById('periodId');
    const subject = document.getElementById('subjectId');
    const message = document.getElementById('reportMessage');
    const stats = document.getElementById('reportStats');
    const classResults = document.getElementById('classResults');
    const subjectResults = document.getElementById('subjectResults');

    let lastFilters = {};

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;',
            '"': '&quot;', "'": '&#039;'
        })[c]);
    }

    function filters() {
        const params = new URLSearchParams();
        for (const [key, value] of new FormData(form).entries()) {
            if (value !== '') params.set(key, value);
        }
        return params;
    }

    function filterClasses() {
        for (const option of classSelect.options) {
            if (!option.value) continue;
            option.hidden = option.dataset.year !== year.value;
        }
        const selected = classSelect.selectedOptions[0];
        if (selected && selected.value && selected.hidden) {
            classSelect.value = '';
        }
    }

    async function loadPeriods() {
        period.innerHTML = '<option value="">Toutes les périodes</option>';
        if (!year.value) return;

        try {
            const response = await fetch(
                '../../backend/api/rapports/statistiques.php?action=periods&school_year_id=' +
                encodeURIComponent(year.value),
                { headers: { 'Accept': 'application/json' } }
            );
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Erreur de chargement');

            for (const item of data.periods || []) {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = item.libelle;
                period.appendChild(option);
            }
        } catch (error) {
            message.textContent = error.message;
        }
    }

    function renderRows(target, rows, columns) {
        if (!rows || !rows.length) {
            target.innerHTML = `<tr><td colspan="${columns.length}">Aucun résultat publié pour ces filtres.</td></tr>`;
            return;
        }

        target.innerHTML = rows.map(row =>
            '<tr>' + columns.map(col => `<td>${escapeHtml(row[col] ?? '—')}</td>`).join('') + '</tr>'
        ).join('');
    }

    async function loadReport() {
        if (!year.value) {
            message.textContent = 'Choisis une année scolaire.';
            return;
        }

        lastFilters = Object.fromEntries(filters().entries());
        message.textContent = 'Chargement des statistiques…';

        try {
            const response = await fetch(
                '../../backend/api/rapports/statistiques.php?' + filters().toString(),
                { headers: { 'Accept': 'application/json' } }
            );
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Impossible de charger le rapport.');

            const summary = data.summary;
            stats.innerHTML = [
                ['Élèves avec notes', summary.students_with_grades],
                ['Notes comptabilisées', summary.grade_count],
                ['Moyenne générale /20', summary.average_20],
                ['Réussite (≥ 10/20)', summary.pass_rate + ' %']
            ].map(([label, value]) =>
                `<div class="stat"><strong>${escapeHtml(value)}</strong><span>${escapeHtml(label)}</span></div>`
            ).join('');

            renderRows(classResults, data.by_class, [
                'classe', 'effectif_notes', 'moyenne_20', 'taux_reussite'
            ]);
            renderRows(subjectResults, data.by_subject, [
                'matiere', 'nombre_notes', 'moyenne_20', 'taux_reussite'
            ]);

            message.textContent = 'Statistiques mises à jour. Les moyennes sont normalisées sur 20.';
        } catch (error) {
            message.textContent = error.message;
            stats.innerHTML = '';
        }
    }

    function exportFile(type) {
        if (!year.value) {
            message.textContent = 'Choisis une année scolaire avant l’export.';
            return;
        }
        const endpoint = type === 'pdf' ? 'export-pdf.php' : 'export-excel.php';
        window.location.href =
            '../../backend/api/rapports/' + endpoint + '?' + filters().toString();
    }

    year.addEventListener('change', () => {
        filterClasses();
        loadPeriods();
    });

    form.addEventListener('submit', event => {
        event.preventDefault();
        loadReport();
    });

    document.getElementById('exportCsv').addEventListener('click', () => exportFile('csv'));
    document.getElementById('exportPdf').addEventListener('click', () => exportFile('pdf'));

    filterClasses();
    loadPeriods();
})();
</script>
</body>
</html>
