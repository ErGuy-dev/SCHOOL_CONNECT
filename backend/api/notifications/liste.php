<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../includes/security.php';

// L'API accepte uniquement les utilisateurs connectés.
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Vous devez vous connecter.'
    ]);
    exit;
}

// Vérifier le compte et son rôle à partir de la base.
require_role('administrateur', 'direction', 'enseignant', 'parent');

try {
    // L'identifiant vient de la session, jamais d'un paramètre URL.
    $userId = (int) $_SESSION['user_id'];

    $sql = '
        SELECT id, titre, message, type, lien, lu, lu_le, cree_le
        FROM notifications
        WHERE user_id = ?
        ORDER BY cree_le DESC
        LIMIT 100
    ';

    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
    'success' => true,
    'csrf' => csrf_token(),
    'notifications' => $notifications
], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log($e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur lors du chargement des notifications.'
    ], JSON_UNESCAPED_UNICODE);
}
