<?php
declare(strict_types=1);

/*
 * SchoolConnect
 * Fichier : frontend/admin/sauvegardes.php
 * Fonctions : sauvegarde manuelle et historique.
 * Compatible PHP 8.0 / XAMPP / MariaDB.
 */

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    empty($_SESSION['connecte']) ||
    ($_SESSION['role'] ?? '') !== 'administrateur'
) {
    header('Location: ../auth/connexion.html');
    exit;
}

require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';

const BACKUP_DIRECTORY = 'C:\\xampp\\schoolconnect_backups';
const MYSQLDUMP_PATH = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
const DATABASE_NAME = 'schoolconnect';

if (empty($_SESSION['csrf_sauvegardes'])) {
    $_SESSION['csrf_sauvegardes'] = bin2hex(random_bytes(32));
}

$message = '';
$typeMessage = 'success';


if (isset($_GET['restore'])) {
    switch ($_GET['restore']) {
        case 'success':
            $base = (string) ($_GET['db'] ?? '');

            if (preg_match('/\Aschoolconnect_restore_test_[A-Za-z0-9_]+\z/', $base)) {
                $message = 'Restauration de test réussie. Base créée : ' . $base;
            } else {
                $message = 'Restauration de test terminée.';
            }
            break;

        case 'confirmation':
            $message = 'Saisis exactement RESTAURER pour confirmer.';
            $typeMessage = 'error';
            break;

        case 'invalid_backup':
            $message = 'Sélectionne une sauvegarde valide.';
            $typeMessage = 'error';
            break;

        case 'backup_unavailable':
            $message = 'Cette sauvegarde est indisponible ou ne peut pas être restaurée.';
            $typeMessage = 'error';
            break;

        case 'exec_disabled':
            $message = 'La fonction exec() est désactivée dans PHP.';
            $typeMessage = 'error';
            break;

        case 'error':
            $message = 'La restauration a échoué. Consulte le journal du serveur.';
            $typeMessage = 'error';
            break;
    }
}


function h($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function enregistrerAudit(PDO $pdo, string $action): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, user_nom, action, ip)
         VALUES (:user_id, :user_nom, :action, :ip)'
    );

    $stmt->execute([
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_nom' => $_SESSION['nom'] ?? 'Administrateur',
        'action' => $action,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'inconnue',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jeton = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_sauvegardes'], $jeton)) {
        http_response_code(403);
        $message = 'La demande a expiré ou le jeton de sécurité est invalide.';
        $typeMessage = 'error';
    } elseif (($_POST['action'] ?? '') !== 'creer_sauvegarde') {
        http_response_code(400);
        $message = 'Action non autorisée.';
        $typeMessage = 'error';
    } elseif (!function_exists('exec')) {
        $message = 'La fonction exec() est désactivée dans PHP. Vérifie la configuration de XAMPP.';
        $typeMessage = 'error';
    } elseif (!is_file(MYSQLDUMP_PATH)) {
        $message = 'mysqldump.exe est introuvable. Vérifie son chemin dans le fichier PHP.';
        $typeMessage = 'error';
    } else {
        $fichier = '';
        $fichierErreur = '';

        try {
            if (!is_dir(BACKUP_DIRECTORY)) {
                if (!mkdir(BACKUP_DIRECTORY, 0700, true) && !is_dir(BACKUP_DIRECTORY)) {
                    throw new RuntimeException(
                        'Impossible de créer le dossier privé des sauvegardes.'
                    );
                }
            }

            if (!is_writable(BACKUP_DIRECTORY)) {
                throw new RuntimeException(
                    'Le dossier de sauvegarde n’est pas accessible en écriture.'
                );
            }

            // Nom produit par le serveur : aucun nom fourni par le navigateur.
            $nomFichier = 'schoolconnect_' . date('Y-m-d_H-i-s')
                . '_' . bin2hex(random_bytes(4)) . '.sql';

            $fichier = BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . $nomFichier;
            $fichierErreur = BACKUP_DIRECTORY . DIRECTORY_SEPARATOR
                . 'erreur_' . bin2hex(random_bytes(6)) . '.log';

            // Paramètres fixes. Les chemins sont échappés avant exécution.
            $commande = escapeshellarg(MYSQLDUMP_PATH)
                . ' --user=root'
                . ' --host=localhost'
                . ' --port=3306'
                . ' --default-character-set=utf8mb4'
                . ' --single-transaction'
                . ' --routines --triggers --events'
                . ' ' . escapeshellarg(DATABASE_NAME)
                . ' > ' . escapeshellarg($fichier)
                . ' 2> ' . escapeshellarg($fichierErreur);

            $sortie = [];
            $codeRetour = 1;

            exec($commande, $sortie, $codeRetour);

            clearstatcache(true, $fichier);

            if (
                $codeRetour !== 0 ||
                !is_file($fichier) ||
                filesize($fichier) === 0
            ) {
                throw new RuntimeException(
                    'La commande de sauvegarde a échoué. Consulte le journal PHP pour le diagnostic.'
                );
            }

            // Vérification minimale de la structure du fichier SQL.
            $debut = file_get_contents($fichier, false, null, 0, 4096);

            if ($debut === false || stripos($debut, 'MariaDB dump') === false
                && stripos($debut, 'MySQL dump') === false) {
                throw new RuntimeException(
                    'Le fichier produit ne semble pas être un dump SQL valide.'
                );
            }

            $taille = filesize($fichier);
            $sha256 = hash_file('sha256', $fichier);

            if ($sha256 === false) {
                throw new RuntimeException(
                    'Impossible de calculer l’empreinte SHA-256.'
                );
            }

            // Enregistrer l’opération dans l’historique.
            $stmt = $pdo->prepare(
                "INSERT INTO backup_logs
                    (fichier, taille, sha256, type, statut, message)
                 VALUES
                    (:fichier, :taille, :sha256, 'manuelle', 'reussie', :message)"
            );

            $stmt->execute([
                'fichier' => $nomFichier,
                'taille' => $taille,
                'sha256' => $sha256,
                'message' => 'Dump SQL créé et empreinte SHA-256 calculée.',
            ]);

            enregistrerAudit(
                $pdo,
                'Création d’une sauvegarde manuelle : ' . $nomFichier
            );

            $message = 'Sauvegarde créée avec succès : ' . $nomFichier;
            $typeMessage = 'success';
        } catch (Throwable $e) {
            error_log('SchoolConnect sauvegarde : ' . $e->getMessage());

            // Un fichier incomplet ne doit pas être conservé comme sauvegarde valide.
            if ($fichier !== '' && is_file($fichier)) {
                @unlink($fichier);
            }

            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO backup_logs
                        (fichier, taille, sha256, type, statut, message)
                     VALUES
                        (:fichier, NULL, NULL, 'manuelle', 'echec', :message)"
                );

                $stmt->execute([
                    'fichier' => $fichier !== ''
                        ? basename($fichier)
                        : 'sauvegarde_non_creee_' . date('Y-m-d_H-i-s'),
                    'message' => 'Échec de la sauvegarde. Consulte le journal du serveur.',
                ]);
            } catch (Throwable $logError) {
                error_log(
                    'SchoolConnect journal sauvegarde : ' . $logError->getMessage()
                );
            }

            $message = 'La sauvegarde a échoué. Vérifie les chemins et les droits du dossier, puis consulte le journal PHP.';
            $typeMessage = 'error';
        } finally {
            if ($fichierErreur !== '' && is_file($fichierErreur)) {
                @unlink($fichierErreur);
            }
        }

        // Empêcher une nouvelle sauvegarde lors d’un rafraîchissement.
        $_SESSION['csrf_sauvegardes'] = bin2hex(random_bytes(32));
    }
}

try {
    $stmt = $pdo->query(
        'SELECT id, fichier, taille, sha256, type, statut, message, created_at
         FROM backup_logs
         ORDER BY created_at DESC, id DESC
         LIMIT 100'
    );

    $sauvegardes = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('SchoolConnect lecture historique : ' . $e->getMessage());
    $sauvegardes = [];
    $message = 'Impossible de charger l’historique des sauvegardes.';
    $typeMessage = 'error';
}

function tailleLisible($octets): string
{
    if ($octets === null) {
        return '—';
    }

    $taille = (float) $octets;

    if ($taille >= 1073741824) {
        return number_format($taille / 1073741824, 2, ',', ' ') . ' Go';
    }

    if ($taille >= 1048576) {
        return number_format($taille / 1048576, 2, ',', ' ') . ' Mo';
    }

    if ($taille >= 1024) {
        return number_format($taille / 1024, 2, ',', ' ') . ' Ko';
    }

    return number_format($taille, 0, ',', ' ') . ' octets';
}

$dernierSucces = null;

foreach ($sauvegardes as $s) {
    if ($s['statut'] === 'reussie') {
        $dernierSucces = $s;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= htmlspecialchars($themeGlobal, ENT_QUOTES, 'UTF-8') ?>">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sauvegardes | SchoolConnect</title>
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
            <a href="sauvegardes.php" aria-current="page">Sauvegardes</a>
            <a href="journaux.php">Journaux d’audit</a>
            <a href="documents.php">Documents</a>
            <a href="rapports.php">Rapports et statistiques</a>
            <a href="parametres.php">Paramètres</a>
        </nav>

        <a class="logout" href="../auth/connexion.html">Se déconnecter</a>
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

                <span class="user"><?= h($_SESSION['nom'] ?? 'Administrateur') ?></span>
            </div>
        </header>

        <main class="content">
            <h1>Sauvegardes et restauration</h1>
            <p class="lead">
                Crée et surveille les sauvegardes de la base de données SchoolConnect.
            </p>

            <?php if ($message !== ''): ?>
                <div class="mssg <?= h($typeMessage === 'error' ? 'err' : 'success') ?>"
                     role="status">
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <div class="stats">
                <div class="stat">
                    <strong>Manuelle</strong>
                    <span>Création à la demande</span>
                </div>
                <div class="stat">
                    <strong><?= count($sauvegardes) ?></strong>
                    <span>Opérations récentes</span>
                </div>
                <div class="stat">
                    <strong>
                        <?= $dernierSucces
                            ? h(date('d/m/Y H:i', strtotime($dernierSucces['created_at'])))
                            : 'Aucune' ?>
                    </strong>
                    <span>Dernière sauvegarde réussie</span>
                </div>
            </div>

            <section class="card">
                <h2>Créer une sauvegarde</h2>
                <p>
                    La sauvegarde SQL sera stockée dans un dossier privé de XAMPP.
                    Cette opération ne chiffre pas encore le fichier.
                </p>

                <form method="post"
                      onsubmit="return confirm('Créer une nouvelle sauvegarde de la base SchoolConnect ?');">
                    <input type="hidden" name="csrf_token"
                           value="<?= h($_SESSION['csrf_sauvegardes']) ?>">
                    <input type="hidden" name="action" value="creer_sauvegarde">

                    <button type="submit" class="btn">
                        Lancer une sauvegarde
                    </button>
                </form>
            </section>

            <section class="card">
                <h2>Historique des opérations</h2>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th scope="col">Fichier</th>
                            <th scope="col">Date</th>
                            <th scope="col">Taille</th>
                            <th scope="col">Type</th>
                            <th scope="col">Résultat</th>
                            <th scope="col">Détails</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$sauvegardes): ?>
                            <tr>
                                <td colspan="6">Aucune opération enregistrée.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sauvegardes as $s): ?>
                                <tr>
                                    <td><?= h($s['fichier']) ?></td>
                                    <td><?= h(date(
                                        'd/m/Y H:i',
                                        strtotime($s['created_at'])
                                    )) ?></td>
                                    <td><?= h(tailleLisible($s['taille'])) ?></td>
                                    <td><?= h(ucfirst($s['type'])) ?></td>
                                    <td>
                                        <?php if ($s['statut'] === 'reussie'): ?>
                                            <span class="badge ok">Réussie</span>
                                        <?php else: ?>
                                            <span class="badge ko">Échec</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= h($s['message'] ?? '—') ?>
                                        <?php if (!empty($s['sha256'])): ?>
                                            <details>
                                                <summary>Empreinte SHA-256</summary>
                                                <code><?= h($s['sha256']) ?></code>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            
<section class="card">
    <h2>Restaurer une sauvegarde</h2>

    <div class="mssg warn">
        Cette opération restaure la sauvegarde dans une nouvelle base de test.
        Elle ne remplace pas la base scolaire actuelle.
    </div>

    <form method="post"
          action="../../backend/admin/restore-backup.php"
          onsubmit="return confirm('Confirmer la restauration dans une nouvelle base de test ?');">

        <input type="hidden" name="csrf_token"
               value="<?= h($_SESSION['csrf_sauvegardes']) ?>">

        <div class="form-grid">
            <div class="field">
                <label for="backup_id">Sauvegarde à restaurer</label>
                <select id="backup_id" name="backup_id" required>
                    <option value="">Choisir une sauvegarde</option>

                    <?php foreach ($sauvegardes as $s): ?>
                        <?php if (
                            $s['statut'] === 'reussie'
                            && in_array($s['type'], ['auto', 'manuelle'], true)
                        ): ?>
                            <option value="<?= (int) $s['id'] ?>">
                                <?= h($s['fichier']) ?>
                                — <?= h(date(
                                    'd/m/Y H:i',
                                    strtotime($s['created_at'])
                                )) ?>
                                — <?= h(tailleLisible($s['taille'])) ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="confirmation">
                    Tapez RESTAURER pour confirmer
                </label>
                <input
                    id="confirmation"
                    name="confirmation"
                    type="text"
                    autocomplete="off"
                    required
                    pattern="RESTAURER"
                    data-confirm-input="#btn-restaurer"
                    data-word="RESTAURER"
                    placeholder="RESTAURER">
            </div>
        </div>

        <button
            id="btn-restaurer"
            type="submit"
            class="btn btn-danger"
            disabled>
            Restaurer dans une base de test
        </button>
    </form>
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
