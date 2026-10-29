<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/* Échappement HTML */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* CSRF */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(403);
        exit('Requête refusée (jeton CSRF invalide).');
    }
}

/*
 * Accès par rôle. La page de connexion doit remplir :
 *   $_SESSION['user_id'], $_SESSION['user_nom'], $_SESSION['role']  (code : 'administrateur', ...)
 * Le statut est revérifié en base à chaque requête : un compte désactivé est coupé tout de suite.
 */
function require_role(string ...$roles): void
{
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        header('Location: ../auth/connexion.html');
        exit;
    }
    $st = db()->prepare(
        'SELECT u.nom, u.statut, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?'
    );
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u || $u['statut'] !== 'actif' || !in_array($u['code'], $roles, true)) {
        session_destroy();
        header('Location: ../auth/connexion.html');
        exit;
    }
    $_SESSION['user_nom'] = $u['nom'];
    $_SESSION['role']     = $u['code'];
}

/* Journal d'audit */
function audit(string $action): void
{
    $st = db()->prepare('INSERT INTO audit_logs (user_id, user_nom, action, ip) VALUES (?, ?, ?, ?)');
    $st->execute([
        $_SESSION['user_id'] ?? null,
        $_SESSION['user_nom'] ?? 'inconnu',
        mb_substr($action, 0, 255),
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
    ]);
}

/* Messages flash (affichés une seule fois) */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
?>