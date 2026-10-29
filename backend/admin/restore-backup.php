<?php
declare(strict_types=1);

/*
 * SchoolConnect
 * Fichier : backend/admin/restore-backup.php
 * Restauration de test uniquement.
 */

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function retour(string $parametres): void
{
    header('Location: ../../frontend/admin/sauvegardes.php?' . $parametres);
    exit;
}

if (
    empty($_SESSION['connecte']) ||
    ($_SESSION['role'] ?? '') !== 'administrateur'
) {
    header('Location: ../../frontend/auth/connexion.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Méthode non autorisée.');
}

$jeton = (string) ($_POST['csrf_token'] ?? '');

if (
    empty($_SESSION['csrf_sauvegardes']) ||
    !hash_equals($_SESSION['csrf_sauvegardes'], $jeton)
) {
    http_response_code(403);
    exit('Jeton de sécurité invalide. Recharge la page et réessaie.');
}

if (($_POST['confirmation'] ?? '') !== 'RESTAURER') {
    retour('restore=confirmation');
}

if (!function_exists('exec')) {
    retour('restore=exec_disabled');
}

require_once __DIR__ . '/../config/database.php';

const RESTORE_BACKUP_DIRECTORY = 'C:\\xampp\\schoolconnect_backups';
const MYSQL_CLIENT_PATH = 'C:\\xampp\\mysql\\bin\\mysql.exe';

$fichierSource = '';
$baseTest = 'schoolconnect_restore_test_' . date('Ymd_His')
    . '_' . bin2hex(random_bytes(3));

try {
    $backupId = filter_var(
        $_POST['backup_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$backupId) {
        retour('restore=invalid_backup');
    }

    if (!is_file(MYSQL_CLIENT_PATH)) {
        throw new RuntimeException('Client mysql.exe introuvable.');
    }

    /*
     * Ne jamais accepter un chemin de fichier venant du navigateur.
     * L'identifiant permet de retrouver les métadonnées en base.
     */
    $stmt = $pdo->prepare(
        "SELECT id, fichier, taille, sha256, type, statut
         FROM backup_logs
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $backupId]);
    $backup = $stmt->fetch();

    if (
        !$backup ||
        $backup['statut'] !== 'reussie' ||
        !in_array($backup['type'], ['auto', 'manuelle'], true)
    ) {
        retour('restore=backup_unavailable');
    }

    $nomFichier = (string) $backup['fichier'];

    // Refuser tout nom contenant un chemin ou des caractères inattendus.
    if (!preg_match('/\Aschoolconnect_[A-Za-z0-9_-]+\.sql\z/', $nomFichier)) {
        throw new RuntimeException('Nom de fichier de sauvegarde non autorisé.');
    }

    $fichierSource = RESTORE_BACKUP_DIRECTORY
        . DIRECTORY_SEPARATOR . $nomFichier;

    if (!is_file($fichierSource) || !is_readable($fichierSource)) {
        throw new RuntimeException('Fichier de sauvegarde absent ou illisible.');
    }

    if (empty($backup['sha256'])) {
        throw new RuntimeException('Cette sauvegarde ne possède pas d’empreinte vérifiable.');
    }

    $empreinteActuelle = hash_file('sha256', $fichierSource);

    if (
        $empreinteActuelle === false ||
        !hash_equals((string) $backup['sha256'], $empreinteActuelle)
    ) {
        throw new RuntimeException('L’empreinte SHA-256 ne correspond pas. Restauration annulée.');
    }

    /*
     * Créer une base de test neuve et unique.
     * On ne supprime aucune base existante et on ne touche pas à schoolconnect.
     */
    $pdo->exec(
        'CREATE DATABASE `' . $baseTest
        . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $fichierErreur = RESTORE_BACKUP_DIRECTORY
        . DIRECTORY_SEPARATOR . 'restore_error_'
        . bin2hex(random_bytes(8)) . '.log';

    $commande = escapeshellarg(MYSQL_CLIENT_PATH)
        . ' --user=root'
        . ' --host=localhost'
        . ' --port=3306'
        . ' --default-character-set=utf8mb4'
        . ' ' . escapeshellarg($baseTest)
        . ' < ' . escapeshellarg($fichierSource)
        . ' 2> ' . escapeshellarg($fichierErreur);

    $sortie = [];
    $codeRetour = 1;

    exec($commande, $sortie, $codeRetour);

    if ($codeRetour !== 0) {
        error_log(
            'SchoolConnect restauration échouée. Base de test : '
            . $baseTest . '. Consulte le journal serveur.'
        );

        throw new RuntimeException('Le client MySQL a signalé un échec de restauration.');
    }

    // Vérification de base : la base restaurée doit contenir des tables.
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = ' . $pdo->quote($baseTest)
    );

    if ((int) $stmt->fetchColumn() < 1) {
        throw new RuntimeException('La base de test ne contient aucune table.');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO backup_logs
            (fichier, taille, sha256, type, statut, message)
         VALUES
            (:fichier, :taille, :sha256, 'restauration', 'reussie', :message)"
    );

    $stmt->execute([
        'fichier' => $nomFichier,
        'taille' => filesize($fichierSource),
        'sha256' => $empreinteActuelle,
        'message' => 'Restauration de test réussie dans ' . $baseTest,
    ]);

    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, user_nom, action, ip)
         VALUES (:user_id, :nom, :action, :ip)'
    );

    $stmt->execute([
        'user_id' => $_SESSION['user_id'] ?? null,
        'nom' => $_SESSION['nom'] ?? 'Administrateur',
        'action' => 'Restauration de test : ' . $nomFichier . ' vers ' . $baseTest,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'inconnue',
    ]);

    if (isset($fichierErreur) && is_file($fichierErreur)) {
        @unlink($fichierErreur);
    }

    retour('restore=success&db=' . rawurlencode($baseTest));
} catch (Throwable $e) {
    error_log('SchoolConnect restauration : ' . $e->getMessage());

    if (isset($fichierErreur) && is_file($fichierErreur)) {
        @unlink($fichierErreur);
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO backup_logs
                (fichier, taille, sha256, type, statut, message)
             VALUES
                (:fichier, NULL, NULL, 'restauration', 'echec', :message)"
        );

        $stmt->execute([
            'fichier' => $fichierSource !== ''
                ? basename($fichierSource)
                : 'restauration_non_demarre_' . date('Y-m-d_H-i-s'),
            'message' => 'Échec de restauration. Consulter le journal serveur.',
        ]);
    } catch (Throwable $logError) {
        error_log('SchoolConnect journal restauration : ' . $logError->getMessage());
    }

    retour('restore=error');
}
