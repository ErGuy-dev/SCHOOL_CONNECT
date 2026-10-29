<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/chat.php';

$uid = chat_user_id();
$pdo = db();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Liste des utilisateurs actifs, sauf l'utilisateur connecté.
        $stmt = $pdo->prepare(
            "SELECT u.id, u.nom, r.code AS role
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.statut = 'actif'
               AND u.id <> ?
               AND r.code IN (
                   'administrateur',
                   'direction',
                   'enseignant',
                   'parent'
               )
             ORDER BY u.nom"
        );
        $stmt->execute([$uid]);
        $contacts = $stmt->fetchAll();

        // Conversations auxquelles l'utilisateur participe.
        $stmt = $pdo->prepare(
            "SELECT
                c.id,
                c.updated_at,
                other_user.id AS contact_id,
                other_user.nom AS contact_nom,
                last_message.contenu AS dernier_message,
                last_message.sent_at AS dernier_message_date,
                (
                    SELECT COUNT(*)
                    FROM chat_messages unread
                    WHERE unread.conversation_id = c.id
                      AND unread.sender_id <> ?
                      AND unread.sent_at > COALESCE(
                          mine.last_read_at,
                          '1000-01-01 00:00:00'
                      )
                ) AS non_lus
             FROM chat_conversations c
             JOIN chat_members mine
               ON mine.conversation_id = c.id
              AND mine.user_id = ?
             JOIN chat_members other_member
               ON other_member.conversation_id = c.id
              AND other_member.user_id <> ?
             JOIN users other_user
               ON other_user.id = other_member.user_id
              AND other_user.statut = 'actif'
             LEFT JOIN chat_messages last_message
               ON last_message.id = (
                   SELECT m.id
                   FROM chat_messages m
                   WHERE m.conversation_id = c.id
                   ORDER BY m.sent_at DESC, m.id DESC
                   LIMIT 1
               )
             ORDER BY COALESCE(last_message.sent_at, c.updated_at) DESC"
        );
        $stmt->execute([$uid, $uid, $uid]);
        $conversations = $stmt->fetchAll();

        chat_json([
            'success' => true,
            'csrf' => csrf_token(),
            'contacts' => $contacts,
            'conversations' => $conversations
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        chat_json([
            'success' => false,
            'message' => 'Méthode non autorisée.'
        ], 405);
    }

    chat_check_csrf();

    $contactId = chat_positive_id($_POST['contact_id'] ?? null);

    if ($contactId === 0 || $contactId === $uid) {
        chat_json([
            'success' => false,
            'message' => 'Contact invalide.'
        ], 422);
    }

    // Le contact doit être actif et avoir un rôle autorisé.
    $stmt = $pdo->prepare(
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
    $stmt->execute([$contactId]);

    if (!$stmt->fetchColumn()) {
        chat_json([
            'success' => false,
            'message' => 'Ce contact est indisponible.'
        ], 404);
    }

    // Chercher une conversation privée existante entre ces deux personnes.
    $stmt = $pdo->prepare(
        "SELECT mine.conversation_id
         FROM chat_members mine
         JOIN chat_members other_member
           ON other_member.conversation_id = mine.conversation_id
         WHERE mine.user_id = ?
           AND other_member.user_id = ?
           AND (
               SELECT COUNT(*)
               FROM chat_members members_count
               WHERE members_count.conversation_id = mine.conversation_id
           ) = 2
         LIMIT 1"
    );
    $stmt->execute([$uid, $contactId]);
    $conversationId = (int) ($stmt->fetchColumn() ?: 0);

    if ($conversationId === 0) {
        // Transaction pour ne pas laisser une conversation incomplète.
        $pdo->beginTransaction();

        $pdo->exec('INSERT INTO chat_conversations () VALUES ()');
        $conversationId = (int) $pdo->lastInsertId();

        $insert = $pdo->prepare(
            'INSERT INTO chat_members (conversation_id, user_id)
             VALUES (?, ?), (?, ?)'
        );
        $insert->execute([
            $conversationId, $uid,
            $conversationId, $contactId
        ]);

        $pdo->commit();
    }

    chat_json([
        'success' => true,
        'conversation_id' => $conversationId,
        'message' => 'Conversation prête.'
    ], 201);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('SchoolConnect chat conversations : ' . $e->getMessage());

    chat_json([
        'success' => false,
        'message' => 'Impossible de traiter les conversations.'
    ], 500);
}
