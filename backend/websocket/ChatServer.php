<?php
declare(strict_types=1);

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/database.php';

/**
 * Serveur WebSocket de SchoolConnect.
 *
 * Événements entrants :
 * - send_message : envoyer un message
 * - delivered    : confirmer la réception d'un message
 * - read         : confirmer la lecture d'un message
 *
 * L'identité du client est établie à partir d'un ticket temporaire.
 */
final class ChatServer implements MessageComponentInterface
{
    /** @var SplObjectStorage<ConnectionInterface, array{user_id:int}> */
    private SplObjectStorage $clients;

    private PDO $pdo;

    public function __construct()
    {
        $this->clients = new SplObjectStorage();
        $this->pdo = db();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        // Vérifier l'origine de la connexion dans un environnement local.
        $origin = $conn->httpRequest->getHeaderLine('Origin');

        if (!$this->isAllowedOrigin($origin)) {
            $conn->close();
            return;
        }

        // Lire le ticket temporaire transmis dans l'URL.
        parse_str(
            $conn->httpRequest->getUri()->getQuery(),
            $query
        );

        $ticket = isset($query['ticket']) && is_string($query['ticket'])
            ? $query['ticket']
            : '';

        if ($ticket === '' || strlen($ticket) > 512) {
            $conn->close();
            return;
        }

        try {
            $this->pdo->beginTransaction();

            // Verrouiller le ticket pour empêcher son utilisation simultanée.
            $stmt = $this->pdo->prepare(
                "SELECT id, user_id
                 FROM chat_ws_tickets
                 WHERE token_hash = :token_hash
                   AND used_at IS NULL
                   AND expires_at > NOW()
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->execute([
                'token_hash' => hash('sha256', $ticket),
            ]);

            $ticketRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ticketRow) {
                $this->pdo->rollBack();
                $conn->close();
                return;
            }

            // Consommer le ticket : il ne pourra plus être réutilisé.
            $update = $this->pdo->prepare(
                "UPDATE chat_ws_tickets
                 SET used_at = NOW()
                 WHERE id = :id AND used_at IS NULL"
            );

            $update->execute(['id' => $ticketRow['id']]);

            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                $conn->close();
                return;
            }

            $this->pdo->commit();

            $userId = (int) $ticketRow['user_id'];

            $this->clients->attach($conn, [
                'user_id' => $userId,
            ]);

            $this->sendJson($conn, [
                'type' => 'connected',
                'user_id' => $userId,
                'message' => 'Connexion WebSocket établie.',
            ]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            error_log('SchoolConnect WebSocket authentication error: '
                . $e->getMessage());

            $conn->close();
        }
    }

    public function onMessage(
        ConnectionInterface $from,
        $msg
    ): void {
        if (!$this->clients->contains($from)) {
            $from->close();
            return;
        }

        if (!is_string($msg) || strlen($msg) > 12000) {
            $this->sendError($from, 'Message invalide ou trop volumineux.');
            return;
        }

        $data = json_decode($msg, true);

        if (!is_array($data) || !isset($data['type'])) {
            $this->sendError($from, 'Format JSON invalide.');
            return;
        }

        $userId = $this->clients[$from]['user_id'];

        try {
            switch ($data['type']) {
                case 'ping':
                    $this->sendJson($from, ['type' => 'pong']);
                    break;

                case 'send_message':
                    $this->handleSendMessage($from, $userId, $data);
                    break;

                case 'delivered':
                    $this->handleDelivered($userId, $data);
                    break;

                case 'read':
                    $this->handleRead($userId, $data);
                    break;

                default:
                    $this->sendError($from, 'Événement inconnu.');
            }
        } catch (Throwable $e) {
            error_log('SchoolConnect WebSocket error: '
                . $e->getMessage());

            $this->sendError($from, 'Une erreur interne est survenue.');
        }
    }

    /**
     * Enregistrer un message et créer un suivi pour chaque destinataire.
     */
    private function handleSendMessage(
        ConnectionInterface $from,
        int $userId,
        array $data
    ): void {
        $conversationId = filter_var(
            $data['conversation_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $contenu = trim(
            is_string($data['contenu'] ?? null)
                ? $data['contenu']
                : ''
        );

        if (!$conversationId || $conversationId < 1) {
            $this->sendError($from, 'Conversation invalide.');
            return;
        }

        if ($contenu === '' || mb_strlen($contenu, 'UTF-8') > 5000) {
            $this->sendError($from, 'Le message doit contenir entre 1 et 5000 caractères.');
            return;
        }

        if (!$this->isMember((int) $conversationId, $userId)) {
            $this->sendError($from, 'Accès à cette conversation refusé.');
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO chat_messages
                    (conversation_id, sender_id, contenu)
                 VALUES
                    (:conversation_id, :sender_id, :contenu)"
            );

            $insert->execute([
                'conversation_id' => $conversationId,
                'sender_id' => $userId,
                'contenu' => $contenu,
            ]);

            $messageId = (int) $this->pdo->lastInsertId();

            // Créer un suivi individuel pour chaque autre participant.
            $receipt = $this->pdo->prepare(
                "INSERT INTO chat_message_receipts
                    (message_id, user_id, statut)
                 SELECT :message_id, user_id, 'SENT'
                 FROM chat_members
                 WHERE conversation_id = :conversation_id
                   AND user_id <> :sender_id"
            );

            $receipt->execute([
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
                'sender_id' => $userId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $message = [
            'type' => 'new_message',
            'message' => [
                'id' => $messageId,
                'conversation_id' => (int) $conversationId,
                'sender_id' => $userId,
                'contenu' => $contenu,
            ],
        ];

        // Informer tous les membres connectés.
        $members = $this->getMemberIds((int) $conversationId);

        foreach ($members as $memberId) {
            $this->sendToUser($memberId, $message);
        }

        // Le message est enregistré, mais il n'est pas encore considéré
        // comme reçu ou lu : le destinataire doit confirmer ces événements.
        $this->sendJson($from, [
            'type' => 'message_sent',
            'message_id' => $messageId,
            'statut' => 'SENT',
        ]);
    }

    /**
     * Le navigateur destinataire confirme qu'il a reçu le message.
     */
    private function handleDelivered(int $userId, array $data): void
    {
        $messageId = filter_var(
            $data['message_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$messageId || $messageId < 1) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE chat_message_receipts AS r
             INNER JOIN chat_messages AS m ON m.id = r.message_id
             SET r.statut = 'DELIVERED',
                 r.delivered_at = COALESCE(r.delivered_at, NOW())
             WHERE r.message_id = :message_id
               AND r.user_id = :user_id
               AND r.statut = 'SENT'"
        );

        $stmt->execute([
            'message_id' => $messageId,
            'user_id' => $userId,
        ]);

        if ($stmt->rowCount() > 0) {
            $this->notifySender($messageId, [
                'type' => 'message_status',
                'message_id' => (int) $messageId,
                'user_id' => $userId,
                'statut' => 'DELIVERED',
            ]);
        }
    }

    /**
     * Le navigateur confirme que le destinataire a affiché le message.
     */
    private function handleRead(int $userId, array $data): void
    {
        $messageId = filter_var(
            $data['message_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$messageId || $messageId < 1) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE chat_message_receipts AS r
             INNER JOIN chat_messages AS m ON m.id = r.message_id
             INNER JOIN chat_members AS cm
                ON cm.conversation_id = m.conversation_id
               AND cm.user_id = r.user_id
             SET r.statut = 'READ',
                 r.delivered_at = COALESCE(r.delivered_at, NOW()),
                 r.read_at = COALESCE(r.read_at, NOW())
             WHERE r.message_id = :message_id
               AND r.user_id = :user_id
               AND r.statut <> 'READ'"
        );

        $stmt->execute([
            'message_id' => $messageId,
            'user_id' => $userId,
        ]);

        if ($stmt->rowCount() > 0) {
            $this->notifySender($messageId, [
                'type' => 'message_status',
                'message_id' => (int) $messageId,
                'user_id' => $userId,
                'statut' => 'READ',
            ]);
        }
    }

    private function isMember(int $conversationId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1
             FROM chat_members
             WHERE conversation_id = :conversation_id
               AND user_id = :user_id
             LIMIT 1"
        );

        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function getMemberIds(int $conversationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT user_id
             FROM chat_members
             WHERE conversation_id = :conversation_id"
        );

        $stmt->execute(['conversation_id' => $conversationId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function notifySender(int $messageId, array $event): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT sender_id
             FROM chat_messages
             WHERE id = :message_id"
        );

        $stmt->execute(['message_id' => $messageId]);

        $senderId = $stmt->fetchColumn();

        if ($senderId !== false) {
            $this->sendToUser((int) $senderId, $event);
        }
    }

    private function sendToUser(int $userId, array $payload): void
    {
        foreach ($this->clients as $client) {
            if ($this->clients[$client]['user_id'] === $userId) {
                $this->sendJson($client, $payload);
            }
        }
    }

    private function sendJson(
        ConnectionInterface $conn,
        array $payload
    ): void {
        $conn->send(json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private function sendError(
        ConnectionInterface $conn,
        string $message
    ): void {
        $this->sendJson($conn, [
            'type' => 'error',
            'message' => $message,
        ]);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        // Configuration de développement local uniquement.
        $host = parse_url($origin, PHP_URL_HOST);

        return in_array(
            $host,
            ['localhost', '127.0.0.1'],
            true
        );
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
        }
    }

    public function onError(
        ConnectionInterface $conn,
        \Exception $e
    ): void {
        error_log('SchoolConnect WebSocket connection error: '
            . $e->getMessage());

        $conn->close();
    }
}
