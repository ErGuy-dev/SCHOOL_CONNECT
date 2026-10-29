<?php
declare(strict_types=1);

/**
 * SchoolConnect - Ticket temporaire WebSocket
 *
 * Méthode HTTP : POST uniquement
 * Authentification : session PHP existante
 * Protection : origine + CSRF + compte actif
 * Validité du ticket : 60 secondes
 * Utilisation : une seule fois
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../includes/security.php';

/**
 * Envoyer une réponse JSON et arrêter le script.
 */
function wsTicketResponse(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * 1. Accepter uniquement les requêtes POST.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');

    wsTicketResponse(405, [
        'success' => false,
        'message' => 'Méthode non autorisée.',
    ]);
}

/**
 * 2. Vérifier l'origine si le navigateur l'envoie.
 *
 * On compare le nom d'hôte de Origin avec celui de HTTP_HOST.
 * Le port est retiré de HTTP_HOST avant la comparaison.
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';

    // Extraire uniquement le nom d'hôte de la requête HTTP.
    $requestHost = strtolower(
        preg_replace('/:\d+$/', '', $requestHost) ?? ''
    );

    if (
        !is_string($originHost)
        || $requestHost === ''
        || strcasecmp($originHost, $requestHost) !== 0
    ) {
        wsTicketResponse(403, [
            'success' => false,
            'message' => 'Origine de la requête refusée.',
        ]);
    }
}

/**
 * 3. Vérifier le jeton CSRF.
 *
 * security.php définit csrf_token() et csrf_check().
 * Ici, on vérifie directement le jeton pour que cet endpoint
 * puisse renvoyer une réponse JSON même si le jeton est invalide.
 *
 * Le frontend devra envoyer le champ "csrf" dans le POST.
 */
$sentToken = $_POST['csrf'] ?? '';
$sessionToken = $_SESSION['csrf'] ?? '';

if (
    !is_string($sentToken)
    || !is_string($sessionToken)
    || $sessionToken === ''
    || !hash_equals($sessionToken, $sentToken)
) {
    wsTicketResponse(403, [
        'success' => false,
        'message' => 'Jeton CSRF invalide ou expiré.',
    ]);
}

/**
 * 4. Vérifier que l'utilisateur est connecté.
 *
 * L'identifiant provient de la session PHP.
 * Il ne doit jamais être fourni par le JavaScript.
 */
$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($userId === false || $userId === null || $userId < 1) {
    wsTicketResponse(401, [
        'success' => false,
        'message' => 'Vous devez vous connecter à SchoolConnect.',
    ]);
}

try {
    /**
     * security.php charge déjà database.php.
     * La fonction db() est donc disponible.
     */
    $pdo = db();

    /**
     * 5. Vérifier que le compte existe et reste actif.
     */
    $userQuery = $pdo->prepare(
        "SELECT id
         FROM users
         WHERE id = :user_id
           AND statut = 'actif'
         LIMIT 1"
    );

    $userQuery->execute([
        'user_id' => $userId,
    ]);

    $user = $userQuery->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        wsTicketResponse(403, [
            'success' => false,
            'message' => 'Ce compte est introuvable ou désactivé.',
        ]);
    }

    /**
     * 6. Générer un ticket imprévisible de 256 bits.
     */
    $ticket = bin2hex(random_bytes(32));

    // Ne jamais enregistrer le ticket en clair dans la base.
    $ticketHash = hash('sha256', $ticket);

    /**
     * 7. Enregistrer le ticket avec une durée de vie de 60 secondes.
     *
     * chat_ws_tickets doit déjà exister dans la base SchoolConnect.
     */
    $insert = $pdo->prepare(
        "INSERT INTO chat_ws_tickets
            (user_id, token_hash, expires_at)
         VALUES
            (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 60 SECOND))"
    );

    $insert->execute([
        'user_id' => $userId,
        'token_hash' => $ticketHash,
    ]);

    /**
     * 8. Envoyer le ticket au frontend.
     *
     * Cette adresse est adaptée au serveur Ratchet local
     * configuré sur le port 8080.
     */
    wsTicketResponse(200, [
        'success' => true,
        'ticket' => $ticket,
        'expiresIn' => 60,
        'websocketUrl' => 'ws://127.0.0.1:8080',
    ]);

} catch (Throwable $e) {
    // Garder les détails techniques dans les journaux du serveur.
    error_log(
        'SchoolConnect WebSocket ticket error: ' . $e->getMessage()
    );

    wsTicketResponse(500, [
        'success' => false,
        'message' => 'Impossible de préparer la connexion WebSocket.',
    ]);
}
