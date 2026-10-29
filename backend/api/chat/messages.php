<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/chat.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    chat_json(['success' => false, 'message' => 'Méthode non autorisée.'], 405);
}

$uid = chat_user_id();
$conversationId = chat_positive_id($_GET['conversation_id'] ?? null);

if ($conversationId === 0 || !chat_is_member($conversationId, $uid)) {
    chat_json(['success' => false, 'message' => 'Conversation inaccessible.'], 403);
}

try {
    $stmt = db()->prepare(
        "SELECT
            m.id,
            m.sender_id,
            u.nom AS sender_nom,
            m.contenu,
            m.sent_at
         FROM chat_messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.conversation_id = ?
         ORDER BY m.sent_at DESC, m.id DESC
         LIMIT 100"
    );
    $stmt->execute([$conversationId]);

    // Inverser pour afficher du plus ancien au plus récent.
    $messages = array_reverse($stmt->fetchAll());

    chat_json([
        'success' => true,
        'messages' => $messages
    ]);
} catch (Throwable $e) {
    error_log('SchoolConnect chat messages : ' . $e->getMessage());
    chat_json(['success' => false, 'message' => 'Erreur de chargement des messages.'], 500);
}
