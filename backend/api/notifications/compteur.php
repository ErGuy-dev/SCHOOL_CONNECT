<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../includes/security.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Vous devez vous connecter.'
    ]);
    exit;
}

require_role('administrateur', 'direction', 'enseignant', 'parent');

try {
    $userId = (int) $_SESSION['user_id'];

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM notifications
         WHERE user_id = ?
           AND lu = 0'
    );

    $stmt->execute([$userId]);

    $nombre = (int) $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'non_lues' => $nombre
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log($e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Impossible de compter les notifications.'
    ], JSON_UNESCAPED_UNICODE);
}
