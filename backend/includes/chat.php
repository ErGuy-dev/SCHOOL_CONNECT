<?php
declare(strict_types=1);

/*
 * SchoolConnect - Fonctions communes de la messagerie.
 * Les utilisateurs et rôles sont vérifiés depuis la base.
 */

require_once __DIR__ . '/security.php';

/**
 * Répond en JSON puis arrête le script.
 */
function chat_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * Vérifie la connexion et le statut du compte.
 * Retourne l'identifiant de l'utilisateur connecté.
 */
function chat_user_id(): int
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);

    if ($uid <= 0) {
        chat_json([
            'success' => false,
            'message' => 'Vous devez vous connecter.'
        ], 401);
    }

    $stmt = db()->prepare(
        "SELECT u.id
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
           AND u.statut = 'actif'
           AND r.code IN (
               'administrateur',
               'direction',
               'enseignant',
               'parent'
           )
         LIMIT 1"
    );

    $stmt->execute([$uid]);

    if (!$stmt->fetch()) {
        chat_json([
            'success' => false,
            'message' => 'Compte inactif ou accès refusé.'
        ], 403);
    }

    return $uid;
}

/**
 * Vérifie le jeton CSRF transmis par une requête POST.
 */
function chat_check_csrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    $stored = $_SESSION['csrf'] ?? '';

    if (
        !is_string($sent) ||
        !is_string($stored) ||
        $stored === '' ||
        !hash_equals($stored, $sent)
    ) {
        chat_json([
            'success' => false,
            'message' => 'Jeton de sécurité invalide. Rechargez la page.'
        ], 403);
    }
}

/**
 * Vérifie que l'utilisateur appartient à la conversation.
 */
function chat_is_member(int $conversationId, int $userId): bool
{
    $stmt = db()->prepare(
        'SELECT 1
         FROM chat_members
         WHERE conversation_id = ?
           AND user_id = ?
         LIMIT 1'
    );

    $stmt->execute([$conversationId, $userId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Vérifie et retourne un identifiant positif.
 */
function chat_positive_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return ($id !== false && $id > 0) ? $id : 0;
}
