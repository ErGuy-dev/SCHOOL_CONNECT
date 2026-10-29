<?php
declare(strict_types=1);

/*
 * SchoolConnect
 * Fichier : backend/config/database.php
 * Rôle : établir une connexion sécurisée à MySQL avec PDO.
 */

function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    // Configuration de la base de données locale XAMPP.
    $host     = 'localhost';
    $port     = '3306';
    $dbname   = 'schoolconnect';
    $username = 'root';
    $password = ''; // Mot de passe MySQL local, à adapter si nécessaire.

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
    } catch (PDOException $e) {
        // Les détails techniques restent dans le journal du serveur.
        error_log('SchoolConnect - Erreur de connexion MySQL : ' . $e->getMessage());

        // Ne pas afficher les identifiants ou détails de la base aux visiteurs.
        http_response_code(500);
        exit('Erreur de connexion à la base de données.');
    }

    return $pdo;
}

$pdo = db();