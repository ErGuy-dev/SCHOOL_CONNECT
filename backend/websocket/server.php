<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/ChatServer.php';

use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

$host = '0.0.0.0';
$port = 8080;

try {
    // Utiliser la boucle événementielle fournie par ReactPHP.
    $loop = Loop::get();

    // Ouvrir le serveur TCP sur le port 8080.
    $socket = new SocketServer(
        "{$host}:{$port}",
        [],
        $loop
    );

    // Construire le serveur WebSocket Ratchet.
    $chatServer = new ChatServer();

    $server = new IoServer(
        new HttpServer(
            new WsServer($chatServer)
        ),
        $socket,
        $loop
    );

    echo "============================================\n";
    echo " SchoolConnect - Serveur WebSocket\n";
    echo " Adresse locale : ws://127.0.0.1:8080\n";
    echo " Port           : {$port}\n";
    echo " Statut         : démarré\n";
    echo "============================================\n";

    $server->run();

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "Erreur de démarrage du serveur WebSocket : "
        . $e->getMessage()
        . PHP_EOL
    );
    exit(1);
}
