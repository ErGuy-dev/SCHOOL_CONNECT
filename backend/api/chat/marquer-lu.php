<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/chat.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chat_json(['success' => false, 'message' => 'Méthode non autorisée.'], 405);
}

$uid = chat_user_id();
chat_check_csrf();

$conversationId = chat_positive_id($_POST['conversation_id'] ?? null);

if ($conversationId === 0 || !chat_is_member($conversationId, $uid)) {
    chat_json(['success' => false, 'message' => 'Conversation inaccessible.'], 403);
}

try {
    $stmt = db()->prepare(
        'UPDATE chat_members
         SET last_read_at = CURRENT_TIMESTAMP
         WHERE conversation_id = ? AND user_id = ?'
    );
    $stmt->execute([$conversationId, $uid]);

    chat_json(['success' => true, 'message' => 'Conversation marquée comme lue.']);
} catch (Throwable $e) {
    error_log('SchoolConnect chat marquer-lu : ' . $e->getMessage());
    chat_json(['success' => false, 'message' => 'Impossible de marquer la conversation comme lue.'], 500);
}
