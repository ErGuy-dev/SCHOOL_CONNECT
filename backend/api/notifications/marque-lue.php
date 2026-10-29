<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Méthode non autorisée.'
    ]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Vous devez vous connecter.'
    ]);
    exit;
}

require_role('administrateur', 'direction', 'enseignant', 'parent');

// Vérifie le jeton CSRF envoyé dans le formulaire POST.
csrf_check();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Identifiant de notification invalide.'
    ]);
    exit;
}

try {
    $userId = (int) $_SESSION['user_id'];

    // Vérifier que la notification appartient à cet utilisateur.
    $check = db()->prepare(
        'SELECT id
         FROM notifications
         WHERE id = ?
           AND user_id = ?
         LIMIT 1'
    );

    $check->execute([$id, $userId]);

    if (!$check->fetchColumn()) {
        // Même réponse si elle n'existe pas ou appartient à un autre compte.
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Notification introuvable.'
        ]);
        exit;
    }

    // Mise à jour limitée à la notification de cet utilisateur.
    $update = db()->prepare(
        'UPDATE notifications
         SET lu = 1,
             lu_le = COALESCE(lu_le, NOW())
         WHERE id = ?
           AND user_id = ?'
    );

    $update->execute([$id, $userId]);

    echo json_encode([
        'success' => true,
        'message' => 'Notification marquée comme lue.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log($e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Impossible de modifier la notification.'
    ], JSON_UNESCAPED_UNICODE);
}
