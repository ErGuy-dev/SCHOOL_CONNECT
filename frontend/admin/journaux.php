<?php
declare(strict_types=1);

// =====================================================
// 1. SÉCURITÉ DE LA SESSION
// =====================================================
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Seul l'administrateur peut consulter les journaux.
if (
    empty($_SESSION['connecte']) ||
    ($_SESSION['role'] ?? '') !== 'administrateur'
) {
    header('Location: ../auth/connexion.html');
    exit;
}

// =====================================================
// 2. CONNEXION À LA BASE DE DONNÉES
// =====================================================
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/includes/security.php';

require_once __DIR__ . '/../../backend/includes/theme.php';


// =====================================================
// 3. FONCTIONS UTILITAIRES
// =====================================================
function h(mixed $valeur): string
{
    return htmlspecialchars(
        (string) ($valeur ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function dateLisible(string $date): string
{
    $timestamp = strtotime($date);

    return $timestamp === false
        ? $date
        : date('d/m/Y H:i:s', $timestamp);
}

// =====================================================
// 4. RÉCUPÉRATION ET VALIDATION DES FILTRES
// =====================================================
$utilisateur = trim((string) ($_GET['utilisateur'] ?? ''));
$typeAction = (string) ($_GET['type_action'] ?? '');
$date = (string) ($_GET['date'] ?? '');

$typesAutorises = [
    '',
    'connexions',
    'notes',
    'sauvegardes',
    'acces_refuses'
];

if (!in_array($typeAction, $typesAutorises, true)) {
    $typeAction = '';
}

// Accepter uniquement une date au format YYYY-MM-DD.
if (
    $date !== '' &&
    !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)
) {
    $date = '';
}

if ($date !== '') {
    $dateValide = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    if (
        $dateValide === false ||
        $dateValide->format('Y-m-d') !== $date
    ) {
        $date = '';
    }
}

// =====================================================
// 5. CONSTRUCTION DE LA REQUÊTE AVEC FILTRES
// =====================================================
$conditions = [];
$parametres = [];

if ($utilisateur !== '') {
    $conditions[] = 'user_nom LIKE :utilisateur';
    $parametres['utilisateur'] = '%' . $utilisateur . '%';
}

if ($date !== '') {
    $conditions[] = 'created_at >= :debut AND created_at < :lendemain';
    $parametres['debut'] = $date . ' 00:00:00';

    $lendemain = (new DateTimeImmutable($date))
        ->modify('+1 day')
        ->format('Y-m-d');

    $parametres['lendemain'] = $lendemain . ' 00:00:00';
}

switch ($typeAction) {
    case 'connexions':
        $conditions[] = "LOWER(action) LIKE '%connexion%'";
        break;

    case 'notes':
        $conditions[] = "LOWER(action) LIKE '%note%'";
        break;

    case 'sauvegardes':
        $conditions[] = "LOWER(action) LIKE '%sauvegarde%'";
        break;

    case 'acces_refuses':
        $conditions[] = "(
            LOWER(action) LIKE '%refus%'
            OR LOWER(action) LIKE '%non autorisé%'
            OR LOWER(action) LIKE '%non autorise%'
        )";
        break;
}

$where = $conditions
    ? 'WHERE ' . implode(' AND ', $conditions)
    : '';

// =====================================================
// 6. PAGINATION
// =====================================================
$parPage = 25;

$page = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$page = $page === false ? 1 : $page;

// Variables initialisées pour gérer proprement une erreur SQL.
$journaux = [];
$total = 0;
$pagesTotal = 1;
$erreur = false;

try {
    // Compter les événements correspondant aux filtres.
    $requeteTotal = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs $where"
    );

    $requeteTotal->execute($parametres);
    $total = (int) $requeteTotal->fetchColumn();

    $pagesTotal = max(1, (int) ceil($total / $parPage));
    $page = min($page, $pagesTotal);

    $debut = ($page - 1) * $parPage;

    // Lire les événements les plus récents en premier.
    $sql = "
        SELECT id, user_id, user_nom, action, ip, created_at
        FROM audit_logs
        $where
        ORDER BY created_at DESC, id DESC
        LIMIT :limite OFFSET :debut
    ";

    $requete = $pdo->prepare($sql);

    foreach ($parametres as $nom => $valeur) {
        $requete->bindValue(':' . $nom, $valeur, PDO::PARAM_STR);
    }

    $requete->bindValue(':limite', $parPage, PDO::PARAM_INT);
    $requete->bindValue(':debut', $debut, PDO::PARAM_INT);

    $requete->execute();
    $journaux = $requete->fetchAll();

} catch (Throwable $e) {
    // Ne pas afficher les détails SQL à l'utilisateur.
    error_log('SchoolConnect - Journaux d’audit : ' . $e->getMessage());
    $erreur = true;
}

// Préserver les filtres dans les liens de pagination.
function urlPage(int $numero): string
{
    $filtres = $_GET;
    $filtres['page'] = $numero;

    return '?' . http_build_query($filtres);
}

$nomAdmin = $_SESSION['nom'] ?? 'Administrateur';
?>

<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= htmlspecialchars($themeGlobal, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Journaux d'audit | SchoolConnect</title>

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
            <a href="tableau-de-bord.php">Tableau de bord</a>
            <a href="comptes.php">Comptes</a>
            <a href="classes-matieres.php">Classes et matières</a>
            <a href="sauvegardes.php">Sauvegardes</a>
            <a href="journaux.php" aria-current="page">Journaux d'audit</a>
            <a href="documents.php">Documents</a>
            <a href="rapports.php">Rapports et statistiques</a>
            <a href="parametres.php">Paramètres</a>
        </nav>

        <a class="logout" href="../auth/connexion.html">Se déconnecter</a>
    </aside>

    <div class="main">

        <header class="topbar">
            <button
                class="menu-btn"
                type="button"
                aria-controls="menu"
                aria-expanded="false"
            >
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

                <span class="user"><?= h($nomAdmin) ?></span>
            </div>
        </header>

        <main class="content">
            <h1>Journaux d'audit</h1>

            <p class="lead">
                Consultez les actions enregistrées par SchoolConnect.
                Les événements les plus récents apparaissent en premier.
            </p>

            <?php if ($erreur): ?>
                <div class="mssg err" role="alert">
                    Impossible de charger les journaux.
                    Vérifiez la connexion à la base de données
                    et les journaux d'erreurs PHP.
                </div>
            <?php else: ?>

                <section class="card">
                    <h2>Filtrer les journaux</h2>

                    <form method="get" action="journaux.php">
                        <div class="toolbar">

                            <input
                                type="search"
                                name="utilisateur"
                                placeholder="Rechercher un utilisateur"
                                aria-label="Utilisateur"
                                value="<?= h($utilisateur) ?>"
                            >

                            <select
                                name="type_action"
                                aria-label="Type d'action"
                            >
                                <option value="">Toutes les actions</option>

                                <option value="connexions"
                                    <?= $typeAction === 'connexions' ? 'selected' : '' ?>>
                                    Connexions
                                </option>

                                <option value="notes"
                                    <?= $typeAction === 'notes' ? 'selected' : '' ?>>
                                    Notes
                                </option>

                                <option value="sauvegardes"
                                    <?= $typeAction === 'sauvegardes' ? 'selected' : '' ?>>
                                    Sauvegardes
                                </option>

                                <option value="acces_refuses"
                                    <?= $typeAction === 'acces_refuses' ? 'selected' : '' ?>>
                                    Accès refusés
                                </option>
                            </select>

                            <input
                                type="date"
                                name="date"
                                aria-label="Date"
                                value="<?= h($date) ?>"
                            >

                            <button type="submit" class="btn">
                                Filtrer
                            </button>

                            <a href="journaux.php" class="btn">
                                Réinitialiser
                            </a>
                        </div>
                    </form>

                    <p class="lead">
                        <?= $total ?> événement(s) trouvé(s).
                        Page <?= $page ?> sur <?= $pagesTotal ?>.
                    </p>

                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Utilisateur</th>
                                    <th scope="col">Action</th>
                                    <th scope="col">Adresse IP</th>
                                </tr>
                            </thead>

                            <tbody>
                            <?php if (!$journaux): ?>
                                <tr>
                                    <td colspan="4">
                                        Aucun événement ne correspond
                                        à ces critères.
                                    </td>
                                </tr>
                            <?php else: ?>

                                <?php foreach ($journaux as $journal): ?>
                                    <tr>
                                        <td>
                                            <?= h(dateLisible((string) $journal['created_at'])) ?>
                                        </td>

                                        <td>
                                            <?= h($journal['user_nom']) ?>
                                        </td>

                                        <td>
                                            <?= h($journal['action']) ?>
                                        </td>

                                        <td>
                                            <?= h($journal['ip']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($pagesTotal > 1): ?>
                        <nav aria-label="Pagination des journaux">
                            <div class="toolbar">
                                <?php if ($page > 1): ?>
                                    <a class="btn" href="<?= h(urlPage($page - 1)) ?>">
                                        Précédent
                                    </a>
                                <?php endif; ?>

                                <?php if ($page < $pagesTotal): ?>
                                    <a class="btn" href="<?= h(urlPage($page + 1)) ?>">
                                        Suivant
                                    </a>
                                <?php endif; ?>
                            </div>
                        </nav>
                    <?php endif; ?>

                </section>

            <?php endif; ?>
        </main>
    </div>
</div>
<script src="../assets/js/theme.js"></script>
<script src="../assets/js/app.js"></script>
<script src="../notifications/notifications.js"></script>
<script src="../assets/js/chat-global.js" defer></script>
</body>
</html>