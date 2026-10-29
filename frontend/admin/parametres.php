<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Paramètres du système
// =====================================================

require __DIR__ . '/../../backend/includes/security.php';
require_role('administrateur');

require_once __DIR__ . '/../../backend/includes/settings.php';

$pdo = db();

// =====================================================
// 1. FONCTIONS UTILITAIRES
// =====================================================
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function param_h(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* =====================================================
   2. JETON CSRF ET MESSAGES
   ===================================================== */

if (empty($_SESSION['csrf_parametres'])) {
    $_SESSION['csrf_parametres'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_parametres'];

$message = (string) ($_SESSION['flash_parametres'] ?? '');
$typeMessage = (string) ($_SESSION['flash_type_parametres'] ?? 'success');

unset($_SESSION['flash_parametres'], $_SESSION['flash_type_parametres']);

/* =====================================================
   3. TRAITEMENT DU FORMULAIRE
   ===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Vérifier le jeton CSRF.
    $token = (string) ($_POST['csrf_token'] ?? '');

    if ($token === '' || !hash_equals($csrf, $token)) {
        http_response_code(403);
        exit('Jeton de sécurité invalide. Recharge la page et réessaie.');
    }

    // Récupérer les données envoyées.
    $nomEtablissement = trim((string) ($_POST['school_name'] ?? ''));
    $theme = (string) ($_POST['theme'] ?? '');
    $frequence = (string) ($_POST['auto_backup_frequency'] ?? '');
    $heure = (string) ($_POST['auto_backup_time'] ?? '');

    $retention = filter_var(
        $_POST['backup_retention_days'] ?? null,
        FILTER_VALIDATE_INT
    );

    $dureeSession = filter_var(
        $_POST['session_timeout_minutes'] ?? null,
        FILTER_VALIDATE_INT
    );

    $tentatives = filter_var(
        $_POST['max_login_attempts'] ?? null,
        FILTER_VALIDATE_INT
    );

    $verrouillage = filter_var(
        $_POST['login_lockout_minutes'] ?? null,
        FILTER_VALIDATE_INT
    );

    $sauvegardeAuto = isset($_POST['auto_backup_enabled']) ? '1' : '0';
    $notifications = isset($_POST['notifications_enabled']) ? '1' : '0';

    $erreurs = [];

    // Valider chaque champ.
    if ($nomEtablissement === '' || mb_strlen($nomEtablissement) > 150) {
        $erreurs[] = 'Le nom doit contenir entre 1 et 150 caractères.';
    }

    if (!in_array($theme, ['light', 'dark', 'system'], true)) {
        $erreurs[] = 'Le thème sélectionné est invalide.';
    }

    if (!in_array($frequence, ['daily', 'weekly'], true)) {
        $erreurs[] = 'La fréquence de sauvegarde est invalide.';
    }

    if (!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $heure)) {
        $erreurs[] = 'L’heure de sauvegarde est invalide.';
    }

    if ($retention === false || $retention < 1 || $retention > 3650) {
        $erreurs[] = 'La conservation doit être comprise entre 1 et 3650 jours.';
    }

    if ($dureeSession === false || $dureeSession < 5 || $dureeSession > 1440) {
        $erreurs[] = 'La durée de session doit être comprise entre 5 et 1440 minutes.';
    }

    if ($tentatives === false || $tentatives < 1 || $tentatives > 20) {
        $erreurs[] = 'Le nombre de tentatives doit être compris entre 1 et 20.';
    }

    if ($verrouillage === false || $verrouillage < 1 || $verrouillage > 1440) {
        $erreurs[] = 'Le verrouillage doit être compris entre 1 et 1440 minutes.';
    }

    if ($erreurs) {
        $message = implode(' ', $erreurs);
        $typeMessage = 'error';

    } else {

        // Les paramètres réellement modifiables depuis ce formulaire.
        $valeurs = [
            'school_name' => $nomEtablissement,
            'theme' => $theme,
            'auto_backup_enabled' => $sauvegardeAuto,
            'auto_backup_frequency' => $frequence,
            'auto_backup_time' => $heure,
            'backup_retention_days' => (string) $retention,
            'session_timeout_minutes' => (string) $dureeSession,
            'max_login_attempts' => (string) $tentatives,
            'login_lockout_minutes' => (string) $verrouillage,
            'notifications_enabled' => $notifications,
        ];

        $adminId = isset($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null;

        try {
            $pdo->beginTransaction();

            $update = $pdo->prepare(
                'UPDATE system_settings
                 SET setting_value = :valeur,
                     updated_by = :admin,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE setting_key = :cle'
            );

            $verification = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM system_settings
                 WHERE setting_key = :cle'
            );

            foreach ($valeurs as $cle => $valeur) {
                $update->execute([
                    'valeur' => $valeur,
                    'admin' => $adminId,
                    'cle' => $cle,
                ]);

                // rowCount() peut valoir 0 si la valeur n'a pas changé.
                // On vérifie donc l'existence de la clé dans tous les cas.
                $verification->execute(['cle' => $cle]);

                if ((int) $verification->fetchColumn() === 0) {
                    throw new RuntimeException(
                        'Le paramètre "' . $cle . '" est absent de system_settings.'
                    );
                }
            }

            // Inscrire l'opération dans le journal d'audit.
            $audit = $pdo->prepare(
                'INSERT INTO audit_logs
                    (user_id, user_nom, action, ip)
                 VALUES
                    (:user_id, :nom, :action, :ip)'
            );

            $audit->execute([
                'user_id' => $adminId,
                'nom' => $_SESSION['user_nom'] ?? 'Administrateur',
                'action' => 'Modification des paramètres du système',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'inconnue',
            ]);

            $pdo->commit();

            // Renouveler le jeton CSRF après le traitement réussi.
            $_SESSION['csrf_parametres'] = bin2hex(random_bytes(32));

            // Afficher le message après redirection.
            $_SESSION['flash_parametres'] =
                'Les paramètres ont été enregistrés avec succès.';

            $_SESSION['flash_type_parametres'] = 'success';

            // Éviter une nouvelle soumission au rechargement de la page.
            header('Location: parametres.php', true, 303);
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Les détails restent dans les journaux PHP, pas dans la page.
            error_log(
                'SchoolConnect - Enregistrement des paramètres : '
                . $e->getMessage()
            );

            $message = 'Impossible d’enregistrer les paramètres. '
                . 'Vérifie les journaux PHP et la structure de la base de données.';

            $typeMessage = 'error';
        }
    }
}

/* =====================================================
   4. CHARGER LES VALEURS À AFFICHER
   ===================================================== */

$parametres = school_settings(true);
$nomAdmin = $_SESSION['user_nom'] ?? 'Administrateur';

?>
<?php
$themeGlobal = $parametres['theme'] ?? 'light';

if (!in_array($themeGlobal, ['light', 'dark', 'system'], true)) {
    $themeGlobal = 'light';
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= param_h($themeGlobal) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Paramètres du système | SchoolConnect</title>

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
            <a href="journaux.php">Journaux d'audit</a>
            <a href="documents.php">Documents</a>
            <a href="rapports.php">Rapports et statistiques</a>
            <a href="parametres.php" aria-current="page">Paramètres</a>
        </nav>

        <a class="logout" href="../auth/logout.php">Se déconnecter</a>
    </aside>

    <div class="main">

        <header class="topbar">
            <button
                class="menu-btn"
                type="button"
                aria-controls="menu"
                aria-expanded="false"
            >Menu</button>

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

                <span class="user"><?= param_h($nomAdmin) ?></span>
            </div>
        </header>

        <main class="content">

            <h1>Paramètres du système</h1>

            <p class="lead">
                Configure les principales options de SchoolConnect.
            </p>

            <?php if ($message !== ''): ?>
                <div
                    class="mssg <?= $typeMessage === 'error' ? 'err' : 'success' ?>"
                    role="<?= $typeMessage === 'error' ? 'alert' : 'status' ?>"
                >
                    <?= param_h($message) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="parametres.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= param_h($csrf) ?>"
                >

                <section class="card">
                    <h2>Informations de l’établissement</h2>

                    <div class="field">
                        <label for="school_name">Nom de l’établissement</label>
                        <input
                            type="text"
                            id="school_name"
                            name="school_name"
                            maxlength="150"
                            required
                            value="<?= param_h($parametres['school_name']) ?>"
                        >
                    </div>
                </section>

                <section class="card">
                    <h2>Apparence</h2>

                    <div class="field">
                        <label for="theme">Thème de l’interface</label>

                        <select id="theme" name="theme" required>
                            <option value="light"
                                <?= $parametres['theme'] === 'light' ? 'selected' : '' ?>>
                                Clair
                            </option>

                            <option value="dark"
                                <?= $parametres['theme'] === 'dark' ? 'selected' : '' ?>>
                                Sombre
                            </option>

                            <option value="system"
                                <?= $parametres['theme'] === 'system' ? 'selected' : '' ?>>
                                Suivre le thème du système
                            </option>
                        </select>
                    </div>

                    <p class="lead">
                        Cette option sera appliquée à toutes les interfaces
                        lors de l’intégration globale du thème.
                    </p>
                </section>

                <section class="card">
                    <h2>Sauvegardes automatiques</h2>

                    <div class="field">
                        <label>
                            <input
                                type="checkbox"
                                name="auto_backup_enabled"
                                value="1"
                                <?= $parametres['auto_backup_enabled'] ? 'checked' : '' ?>
                            >
                            Autoriser les sauvegardes automatiques
                        </label>
                    </div>

                    <div class="field">
                        <label for="auto_backup_frequency">Fréquence</label>

                        <select
                            id="auto_backup_frequency"
                            name="auto_backup_frequency"
                        >
                            <option value="daily"
                                <?= $parametres['auto_backup_frequency'] === 'daily' ? 'selected' : '' ?>>
                                Tous les jours
                            </option>

                            <option value="weekly"
                                <?= $parametres['auto_backup_frequency'] === 'weekly' ? 'selected' : '' ?>>
                                Toutes les semaines
                            </option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="auto_backup_time">Heure souhaitée</label>

                        <input
                            type="time"
                            id="auto_backup_time"
                            name="auto_backup_time"
                            required
                            value="<?= param_h($parametres['auto_backup_time']) ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="backup_retention_days">
                            Conservation des sauvegardes (jours)
                        </label>

                        <input
                            type="number"
                            id="backup_retention_days"
                            name="backup_retention_days"
                            min="1"
                            max="3650"
                            required
                            value="<?= (int) $parametres['backup_retention_days'] ?>"
                        >
                    </div>

                    <p class="lead">
                        Enregistrer ces options ne crée pas encore de tâche
                        planifiée. Nous configurerons ensuite le Planificateur
                        de tâches Windows et la politique de conservation.
                    </p>
                </section>

                <section class="card">
                    <h2>Sécurité et sessions</h2>

                    <div class="field">
                        <label for="session_timeout_minutes">
                            Durée d’inactivité de session (minutes)
                        </label>

                        <input
                            type="number"
                            id="session_timeout_minutes"
                            name="session_timeout_minutes"
                            min="5"
                            max="1440"
                            required
                            value="<?= (int) $parametres['session_timeout_minutes'] ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="max_login_attempts">
                            Nombre maximal de tentatives de connexion
                        </label>

                        <input
                            type="number"
                            id="max_login_attempts"
                            name="max_login_attempts"
                            min="1"
                            max="20"
                            required
                            value="<?= (int) $parametres['max_login_attempts'] ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="login_lockout_minutes">
                            Durée du verrouillage (minutes)
                        </label>

                        <input
                            type="number"
                            id="login_lockout_minutes"
                            name="login_lockout_minutes"
                            min="1"
                            max="1440"
                            required
                            value="<?= (int) $parametres['login_lockout_minutes'] ?>"
                        >
                    </div>

                    <div class="field">
                        <label>
                            <input type="checkbox" disabled
                                <?= $parametres['two_factor_enabled'] ? 'checked' : '' ?>>
                            Double authentification par e-mail
                        </label>

                        <p class="lead">
                            Indisponible pour le moment. Nous l’activerons
                            après avoir relié le code de vérification
                            à l’ensemble du processus de connexion.
                        </p>
                    </div>
                </section>

                <section class="card">
                    <h2>Notifications</h2>

                    <div class="field">
                        <label>
                            <input
                                type="checkbox"
                                name="notifications_enabled"
                                value="1"
                                <?= $parametres['notifications_enabled'] ? 'checked' : '' ?>
                            >
                            Activer les notifications applicatives
                        </label>
                    </div>
                </section>

                <div class="toolbar">
                    <button type="submit" class="btn">
                        Enregistrer les paramètres
                    </button>

                    <a href="tableau-de-bord.php" class="btn">
                        Retour au tableau de bord
                    </a>
                </div>

            </form>
        </main>
    </div>
</div>
<script src="../assets/js/theme.js"></script>
<script src="../assets/js/app.js"></script>
<script src="../notifications/notifications.js"></script>
<script src="../assets/js/chat-global.js" defer></script>
</body>
</html>