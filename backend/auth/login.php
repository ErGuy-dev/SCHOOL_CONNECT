<?php
declare(strict_types=1);

// ======================================================
// SchoolConnect - Authentification
// Fichier : backend/auth/login.php
// ======================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once __DIR__ . '/../config/database.php';

// Réponse JSON standardisée.
function repondre(int $code, array $donnees): void
{
    http_response_code($code);

    echo json_encode(
        $donnees,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

// Autoriser uniquement les requêtes POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    repondre(405, [
        'success' => false,
        'message' => 'Méthode non autorisée.',
    ]);
}

// Récupérer les données envoyées par le frontend.
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$motDePasse = (string) ($_POST['password'] ?? '');

if (
    $email === ''
    || strlen($email) > 190
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || $motDePasse === ''
    || strlen($motDePasse) > 4096
) {
    repondre(400, [
        'success' => false,
        'message' => 'Veuillez vérifier vos identifiants.',
    ]);
}

$ip = substr(
    (string) ($_SERVER['REMOTE_ADDR'] ?? 'inconnue'),
    0,
    45
);

try {
    // Limiter les tentatives par adresse e-mail et par IP.
    $limite = $pdo->prepare(
        "SELECT
            SUM(email = :email) AS tentatives_email,
            SUM(ip = :ip) AS tentatives_ip
         FROM login_attempts
         WHERE success = 0
           AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
           AND (email = :email_filtre OR ip = :ip_filtre)"
    );

    $limite->execute([
        'email' => $email,
        'ip' => $ip,
        'email_filtre' => $email,
        'ip_filtre' => $ip,
    ]);

    $tentatives = $limite->fetch();

    if (
        (int) ($tentatives['tentatives_email'] ?? 0) >= 5
        || (int) ($tentatives['tentatives_ip'] ?? 0) >= 20
    ) {
        repondre(429, [
            'success' => false,
            'message' => 'Trop de tentatives. Réessayez dans 15 minutes.',
        ]);
    }

    // Chercher l'utilisateur et son rôle.
    $requete = $pdo->prepare(
        "SELECT
            u.id,
            u.nom,
            u.email,
            u.password_hash,
            u.statut,
            u.doit_changer_mdp,
            r.code AS role_code
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.email = :email
         LIMIT 1"
    );

    $requete->execute(['email' => $email]);

    $utilisateur = $requete->fetch();

    // Un seul message pour éviter de révéler si le compte existe.
    $identifiantsValides = $utilisateur
        && $utilisateur['statut'] === 'actif'
        && password_verify($motDePasse, $utilisateur['password_hash']);

    // Enregistrer chaque tentative.
    $journal = $pdo->prepare(
        "INSERT INTO login_attempts (email, ip, success)
         VALUES (:email, :ip, :success)"
    );

    $journal->execute([
        'email' => $email,
        'ip' => $ip,
        'success' => $identifiantsValides ? 1 : 0,
    ]);

    if (!$identifiantsValides) {
        repondre(401, [
            'success' => false,
            'message' => 'Adresse e-mail ou mot de passe incorrect.',
        ]);
    }

    // Seuls ces quatre rôles sont autorisés.
    $destinations = [
        'administrateur' => '../admin/tableau-de-bord.php',
        'direction' => '../direction/tableau-de-bord.php',
        'enseignant' => '../enseignants/tableau-de-bord.php',
        'parent' => '../parents/tableau-de-bord.php',
    ];

    $role = $utilisateur['role_code'];

    if (!isset($destinations[$role])) {
        repondre(403, [
            'success' => false,
            'message' => 'Accès non autorisé.',
        ]);
    }

    // Renouveler l'identifiant de session après connexion.
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $utilisateur['id'];
    $_SESSION['nom'] = $utilisateur['nom'];
    $_SESSION['email'] = $utilisateur['email'];
    $_SESSION['role'] = $role;
    $_SESSION['connecte'] = true;

    // Préparer la demande de changement du mot de passe provisoire.
    $_SESSION['doit_changer_mdp'] =
        (bool) $utilisateur['doit_changer_mdp'];

    // Enregistrer la date de la dernière connexion.
    $miseAJour = $pdo->prepare(
        'UPDATE users SET dernier_login = NOW() WHERE id = :id'
    );

    $miseAJour->execute([
        'id' => $utilisateur['id'],
    ]);

    // Ajouter une entrée au journal d'audit.
    $audit = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, user_nom, action, ip)
         VALUES (:user_id, :nom, :action, :ip)'
    );

    $audit->execute([
        'user_id' => $utilisateur['id'],
        'nom' => $utilisateur['nom'],
        'action' => 'Connexion réussie',
        'ip' => $ip,
    ]);

    repondre(200, [
        'success' => true,
        'message' => 'Connexion réussie.',
        'redirect' => $destinations[$role],
    ]);

} catch (PDOException $e) {
    error_log('SchoolConnect login : ' . $e->getMessage());

    repondre(500, [
        'success' => false,
        'message' => 'Une erreur interne est survenue. Réessayez plus tard.',
    ]);
}
