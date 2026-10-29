<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Création des comptes de test
// Fichier temporaire : backend/create-test-users.php
// À supprimer après utilisation.
// =====================================================

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/database.php';

// Comptes à créer : le code du rôle doit exister dans roles.
$comptes = [
    [
        'nom' => 'Directeur',
        'email' => 'ernelguymanariyo@gmail.com',
        'password' => '123directeur',
        'role' => 'direction',
    ],
    [
        'nom' => 'Enseignant',
        'email' => 'manariyoernelguy@gmail.com',
        'password' => '123enseignant',
        'role' => 'enseignant',
    ],
    [
        'nom' => 'Parent',
        'email' => 'facmult26@gmail.com',
        'password' => '123parent',
        'role' => 'parent',
    ],
];

try {
    foreach ($comptes as $compte) {
        // Retrouver l'identifiant réel du rôle.
        $requeteRole = $pdo->prepare(
            'SELECT id FROM roles WHERE code = :code LIMIT 1'
        );

        $requeteRole->execute(['code' => $compte['role']]);
        $role = $requeteRole->fetch();

        if (!$role) {
            echo "ERREUR : rôle introuvable : "
                . $compte['role'] . PHP_EOL;
            echo "Vérifie les codes de la table roles." . PHP_EOL;
            continue;
        }

        // Ne pas écraser un compte existant.
        $requeteUtilisateur = $pdo->prepare(
            'SELECT id FROM users WHERE email = :email LIMIT 1'
        );

        $requeteUtilisateur->execute([
            'email' => $compte['email'],
        ]);

        if ($requeteUtilisateur->fetch()) {
            echo "IGNORÉ : le compte existe déjà : "
                . $compte['email'] . PHP_EOL;
            continue;
        }

        // Hacher le mot de passe avant de l'enregistrer.
        $hash = password_hash(
            $compte['password'],
            PASSWORD_DEFAULT
        );

        if ($hash === false) {
            throw new RuntimeException(
                'Impossible de hacher un mot de passe.'
            );
        }

        // Créer le compte actif.
        $insertion = $pdo->prepare(
            'INSERT INTO users
                (nom, email, password_hash, role_id, statut,
                 doit_changer_mdp)
             VALUES
                (:nom, :email, :password_hash, :role_id,
                 :statut, :doit_changer_mdp)'
        );

        $insertion->execute([
            'nom' => $compte['nom'],
            'email' => $compte['email'],
            'password_hash' => $hash,
            'role_id' => (int) $role['id'],
            'statut' => 'actif',
            'doit_changer_mdp' => 1,
        ]);

        echo "CRÉÉ : " . $compte['email']
            . " | rôle : " . $compte['role'] . PHP_EOL;
    }

    echo PHP_EOL . "Traitement terminé." . PHP_EOL;

} catch (Throwable $e) {
    error_log('SchoolConnect création comptes : ' . $e->getMessage());

    http_response_code(500);
    echo "Une erreur est survenue. Consulte le journal PHP." . PHP_EOL;
}
