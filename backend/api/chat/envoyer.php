<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/chat.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chat_json(['success' => false, 'message' => 'Méthode non autorisée.'], 405);
}

$uid = chat_user_id();
chat_check_csrf();

$conversationId = chat_positive_id($_POST['conversation_id'] ?? null);
$contenu = trim((string) ($_POST['contenu'] ?? ''));

if ($conversationId === 0 || !chat_is_member($conversationId, $uid)) {
    chat_json(['success' => false, 'message' => 'Conversation inaccessible.'], 403);
}

if ($contenu === '') {
    chat_json(['success' => false, 'message' => 'Le message ne peut pas être vide.'], 422);
}

if (mb_strlen($contenu, 'UTF-8') > 5000) {
    chat_json(['success' => false, 'message' => 'Le message dépasse 5 000 caractères.'], 422);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        'INSERT INTO chat_messages (conversation_id, sender_id, contenu)
         VALUES (?, ?, ?)'
    );
    $stmt->execute([$conversationId, $uid, $contenu]);

    $messageId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'UPDATE chat_conversations
         SET updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    $stmt->execute([$conversationId]);

    chat_json([
        'success' => true,
        'message' => 'Message envoyé.',
        'message_id' => $messageId
    ], 201);
} catch (Throwable $e) {
    error_log('SchoolConnect chat envoyer : ' . $e->getMessage());
    chat_json(['success' => false, 'message' => 'Impossible d’envoyer le message.'], 500);
}
