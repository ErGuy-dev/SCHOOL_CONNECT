<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier que l'utilisateur est connecté.
$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$userId || $userId < 1) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Connexion requise.'
    ]);

    exit;
}

try {
    $pdo = db();

    /*
     * Compter les messages des autres utilisateurs
     * dans les conversations auxquelles cet utilisateur
     * appartient et qui n'ont pas encore été lus.
     */
    $sql = "
        SELECT COUNT(*)
        FROM chat_messages AS m
        INNER JOIN chat_members AS cm
            ON cm.conversation_id = m.conversation_id
        WHERE cm.user_id = :user_id
          AND m.sender_id <> :sender_id
          AND (
              cm.last_read_at IS NULL
              OR m.sent_at > cm.last_read_at
          )
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        'user_id' => $userId,
        'sender_id' => $userId
    ]);

    $unreadCount = (int) $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'unreadCount' => $unreadCount
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('Erreur compteur chat : ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Impossible de compter les messages non lus.'
    ], JSON_UNESCAPED_UNICODE);
}
