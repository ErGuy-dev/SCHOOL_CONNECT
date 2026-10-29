<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';

require_role('administrateur');

$pdo = db();

function dashboardCount(PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function dashboardDate(?string $date): string
{
    if (!$date) {
        return '—';
    }

    $d = date_create($date);
    return $d ? $d->format('d/m/Y H:i') : '—';
}

$annee = $pdo->query(
    'SELECT id, libelle FROM school_years WHERE active = 1 LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

$anneeId = $annee ? (int) $annee['id'] : null;

$comptesActifs = dashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM users WHERE statut = 'actif'"
);

$eleves = $anneeId !== null
    ? dashboardCount(
        $pdo,
        "SELECT COUNT(*)
         FROM students s
         JOIN classes c ON c.id = s.class_id
         WHERE c.school_year_id = ? AND s.statut = 'inscrit'",
        [$anneeId]
    )
    : 0;

$classes = $anneeId !== null
    ? dashboardCount(
        $pdo,
        'SELECT COUNT(*) FROM classes WHERE school_year_id = ?',
        [$anneeId]
    )
    : 0;

$evaluations = $anneeId !== null
    ? dashboardCount(
        $pdo,
        "SELECT COUNT(*)
         FROM assessments a
         JOIN classes c ON c.id = a.class_id
         WHERE c.school_year_id = ? AND a.statut = 'publiee'",
        [$anneeId]
    )
    : 0;

$bulletins = $anneeId !== null
    ? dashboardCount(
        $pdo,
        "SELECT COUNT(*)
         FROM report_cards rc
         JOIN evaluation_periods ep ON ep.id = rc.period_id
         WHERE ep.school_year_id = ? AND rc.statut = 'publie'",
        [$anneeId]
    )
    : 0;

$liensAVerifier = dashboardCount(
    $pdo,
    'SELECT COUNT(*) FROM parent_student WHERE verifie = 0'
);

$derniereSauvegarde = $pdo->query(
    "SELECT created_at
     FROM backup_logs
     WHERE statut = 'reussie'
     ORDER BY created_at DESC
     LIMIT 1"
)->fetchColumn();

$echecsSauvegarde = dashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM backup_logs
     WHERE statut = 'echec'
       AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
);

$activites = $pdo->query(
    'SELECT user_nom, action, created_at
     FROM audit_logs
     ORDER BY created_at DESC, id DESC
     LIMIT 10'
)->fetchAll(PDO::FETCH_ASSOC);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= h($themeGlobal) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tableau de bord | SchoolConnect</title>
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/layout.css">
    <link rel="stylesheet" href="../notifications/notifications.css">
    <link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">
    <aside class="sidebar" id="menu">
        <div class="brand">SchoolConnect</div>
        <div class="role">Administrateur</div>

        <nav class="nav" aria-label="Navigation principale">
            <a href="tableau-de-bord.php" aria-current="page">Tableau de bord</a>
            <a href="comptes.php">Comptes</a>
            <a href="classes-matieres.php">Classes et matières</a>
            <a href="sauvegardes.php">Sauvegardes</a>
            <a href="journaux.php">Journaux d'audit</a>
            <a href="documents.php">Documents</a>
            <a href="rapports.php">Rapports et statistiques</a>
            <a href="parametres.php">Paramètres</a>
        </nav>

        <a class="logout" href="../auth/logout.php">Se déconnecter</a>
    </aside>
    

  <div class="main">
    <header class="topbar">
        <button class="menu-btn" type="button" aria-controls="menu" aria-expanded="false">
            Menu
        </button>

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

            <span class="user"><?= h($_SESSION['user_nom'] ?? 'Administrateur') ?></span>
        </div>
    </header>

    <main class="content">
            <h1>Tableau de bord</h1>

            <p class="lead">
                Vue générale de l'établissement —
                année scolaire <?= h($annee['libelle'] ?? 'non configurée') ?>.
            </p>

            <?php if (!$annee): ?>
                <p role="status">
                    Aucune année scolaire active. Configure une année scolaire
                    pour afficher les statistiques pédagogiques.
                </p>
            <?php endif; ?>

            <div class="stats">
                <div class="stat">
                    <strong><?= $comptesActifs ?></strong>
                    <span>Comptes actifs</span>
                </div>

                <div class="stat">
                    <strong><?= $eleves ?></strong>
                    <span>Élèves inscrits</span>
                </div>

                <div class="stat">
                    <strong><?= $classes ?></strong>
                    <span>Classes</span>
                </div>

                <div class="stat">
                    <strong><?= $evaluations ?></strong>
                    <span>Évaluations publiées</span>
                </div>

                <div class="stat">
                    <strong><?= $bulletins ?></strong>
                    <span>Bulletins publiés</span>
                </div>

                <div class="stat">
                    <strong><?= $liensAVerifier ?></strong>
                    <span>Liens parent-élève à vérifier</span>
                </div>

                <div class="stat">
                    <strong><?= h(dashboardDate($derniereSauvegarde ?: null)) ?></strong>
                    <span>Dernière sauvegarde réussie</span>
                </div>

                <div class="stat">
                    <strong><?= $echecsSauvegarde ?></strong>
                    <span>Échecs de sauvegarde (7 jours)</span>
                </div>
            </div>

            <section class="card">
                <h2>Activité récente</h2>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Utilisateur</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$activites): ?>
                            <tr>
                                <td colspan="3">Aucune activité enregistrée.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($activites as $a): ?>
                                <tr>
                                    <td><?= h(dashboardDate($a['created_at'])) ?></td>
                                    <td><?= h($a['user_nom']) ?></td>
                                    <td><?= h($a['action']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p><a href="journaux.php">Consulter le journal complet</a></p>
            </section>

            <section class="card">
                <h2>Rapports scolaires</h2>
                <p>Consulter les statistiques, filtrer les résultats et exporter des rapports.</p>
                <p><a href="rapports.php">Ouvrir les rapports et statistiques</a></p>
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
