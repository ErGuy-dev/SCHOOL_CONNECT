<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Déconnexion
// Fichier : frontend/auth/logout.php
// =====================================================

// security.php démarre la session et charge audit().
require_once __DIR__ . '/../../backend/includes/security.php';

// Enregistrer la déconnexion avant de détruire la session.
if (!empty($_SESSION['user_id'])) {
    $_SESSION['user_nom'] = $_SESSION['user_nom'] ?? ($_SESSION['nom'] ?? 'inconnu');

    try {
        audit('Déconnexion');
    } catch (Throwable $e) {
        // Une erreur de journal ne doit jamais empêcher la déconnexion.
        error_log('SchoolConnect logout : ' . $e->getMessage());
    }
}

// Vider toutes les données de session.
$_SESSION = [];

// Supprimer le cookie de session côté navigateur.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Détruire la session côté serveur.
session_destroy();

// Empêcher le retour arrière vers une page protégée en cache.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: connexion.html');
exit;