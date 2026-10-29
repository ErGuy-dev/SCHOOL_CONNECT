<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect — Profil du parent
// Fichier : frontend/parents/profil.php
//
// Fonctions :
// - afficher les informations du parent connecté ;
// - changer le mot de passe provisoire ou actuel ;
// - protéger le formulaire contre les attaques CSRF ;
// - supprimer l'obligation de changement après succès.
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';
require_once __DIR__ . '/../../backend/includes/parents.php';

// Vérifier que l'utilisateur connecté est bien un parent actif.
require_role('parent');

$pdo = db();
$uid = (int) $_SESSION['user_id'];

$erreurs = [];
$succes = '';
$etat = (string) ($_GET['etat'] ?? '');

$nom = (string) ($_SESSION['user_nom'] ?? '');
$email = '';
$doitChangerMdp = false;

// Récupérer les données réelles du compte.
try {
    $stmt = $pdo->prepare(
        'SELECT nom, email, password_hash, doit_changer_mdp
         FROM users
         WHERE id = ? AND statut = ?'
    );
    $stmt->execute([$uid, 'actif']);
    $utilisateur = $stmt->fetch();

    if (!$utilisateur) {
        http_response_code(403);
        exit('Compte inaccessible. Veuillez vous reconnecter.');
    }

    $nom = (string) $utilisateur['nom'];
    $email = (string) $utilisateur['email'];
    $doitChangerMdp = (int) $utilisateur['doit_changer_mdp'] === 1;

} catch (Throwable $e) {
    error_log('SchoolConnect profil parent : ' . $e->getMessage());
    http_response_code(500);
    exit('Impossible de charger votre profil pour le moment.');
}

// Traitement du formulaire de changement de mot de passe.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $motDePasseActuel = (string) ($_POST['mot_de_passe_actuel'] ?? '');
    $nouveauMotDePasse = (string) ($_POST['nouveau_mot_de_passe'] ?? '');
    $confirmation = (string) ($_POST['confirmation'] ?? '');

    // Vérifier le mot de passe actuel.
    if (
        $motDePasseActuel === ''
        || !password_verify(
            $motDePasseActuel,
            (string) $utilisateur['password_hash']
        )
    ) {
        $erreurs[] = 'Le mot de passe actuel est incorrect.';
    }

    // Vérifier la longueur du nouveau mot de passe.
    if (strlen($nouveauMotDePasse) < 10) {
        $erreurs[] = 'Le nouveau mot de passe doit contenir au moins 10 caractères.';
    }

    // Exiger au moins une lettre et un chiffre.
    if (
        !preg_match('/[A-Za-z]/', $nouveauMotDePasse)
        || !preg_match('/[0-9]/', $nouveauMotDePasse)
    ) {
        $erreurs[] = 'Le nouveau mot de passe doit contenir au moins une lettre et un chiffre.';
    }

    // Vérifier la confirmation.
    if ($nouveauMotDePasse !== $confirmation) {
        $erreurs[] = 'La confirmation ne correspond pas au nouveau mot de passe.';
    }

    // Éviter de réutiliser le même mot de passe.
    if (
        $motDePasseActuel !== ''
        && $nouveauMotDePasse !== ''
        && password_verify(
            $nouveauMotDePasse,
            (string) $utilisateur['password_hash']
        )
    ) {
        $erreurs[] = 'Choisissez un mot de passe différent de l’actuel.';
    }

    if (!$erreurs) {
        try {
            $nouveauHash = password_hash(
                $nouveauMotDePasse,
                PASSWORD_DEFAULT
            );

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE users
                 SET password_hash = ?,
                     doit_changer_mdp = 0
                 WHERE id = ? AND statut = ?'
            );

            $stmt->execute([$nouveauHash, $uid, 'actif']);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'La mise à jour du mot de passe a échoué.'
                );
            }

            $pdo->commit();

            // Mettre à jour l'état en session.
            $_SESSION['doit_changer_mdp'] = false;

            // Journaliser l'action sans empêcher la modification
            // si le journal d'audit rencontre un problème.
            try {
                audit('Changement du mot de passe depuis le profil parent');
            } catch (Throwable $auditErreur) {
                error_log(
                    'SchoolConnect audit changement mot de passe : '
                    . $auditErreur->getMessage()
                );
            }

            // Rediriger après succès pour éviter une nouvelle soumission.
            header('Location: profil.php?etat=mdp_modifie', true, 303);
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('SchoolConnect changement mot de passe : ' . $e->getMessage());
            $erreurs[] = 'Impossible de modifier le mot de passe. Réessayez.';
        }
    }
}

// Après redirection, afficher le message correspondant.
if ($etat === 'mdp_modifie') {
    $succes = 'Votre mot de passe a été modifié avec succès.';
}

// Le sélecteur de l'enfant est inutile sur la page profil.
$topbarGauche = '';
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= e($themeGlobal) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Mon profil | SchoolConnect</title>

    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/layout.css">
    <link rel="stylesheet" href="../assets/css/parents.css">
    <link rel="stylesheet" href="../notifications/notifications.css">
    <link rel="stylesheet" href="../assets/css/chat-global.css">
</head>

<body data-chat-enabled="true">
<div class="app">

    <?php par_sidebar('profil'); ?>

    <div class="main">

        <?php require __DIR__ . '/../includes/topbar.php'; ?>

        <main class="content">

            <h1>Mon profil</h1>

            <p class="lead">
                Consultez les informations de votre compte et sécurisez
                votre accès à SchoolConnect.
            </p>

            <?php if ($doitChangerMdp): ?>
                <div class="mssg warn" role="alert">
                    <strong>Changement obligatoire du mot de passe</strong>
                    <p>
                        Votre compte utilise un mot de passe provisoire.
                        Vous devez le remplacer avant d'accéder aux autres
                        pages de votre espace parent.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($succes !== ''): ?>
                <div class="mssg success" role="status">
                    <?= e($succes) ?>
                </div>
            <?php endif; ?>

            <?php if ($erreurs): ?>
                <div class="mssg err" role="alert">
                    <strong>Le formulaire contient des erreurs :</strong>
                    <ul>
                        <?php foreach ($erreurs as $erreur): ?>
                            <li><?= e($erreur) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <section class="card">
                <h2>Informations du compte</h2>

                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                        <tr>
                            <th scope="row">Nom</th>
                            <td><?= e($nom) ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Adresse e-mail</th>
                            <td><?= e($email) ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Type de compte</th>
                            <td>Parent ou tuteur</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <h2>Changer le mot de passe</h2>

                <p class="muted">
                    Utilisez un mot de passe personnel que vous ne partagez
                    avec personne.
                </p>

                <form method="post" action="profil.php<?= $doitChangerMdp ? '?etat=mdp_obligatoire' : '' ?>">
                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e(csrf_token()) ?>">

                    <div class="form-group">
                        <label for="mot_de_passe_actuel">
                            Mot de passe actuel
                        </label>
                        <input
                            type="password"
                            id="mot_de_passe_actuel"
                            name="mot_de_passe_actuel"
                            autocomplete="current-password"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="nouveau_mot_de_passe">
                            Nouveau mot de passe
                        </label>
                        <input
                            type="password"
                            id="nouveau_mot_de_passe"
                            name="nouveau_mot_de_passe"
                            minlength="10"
                            autocomplete="new-password"
                            required>
                        <small>
                            Au moins 10 caractères, avec une lettre et un chiffre.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="confirmation">
                            Confirmer le nouveau mot de passe
                        </label>
                        <input
                            type="password"
                            id="confirmation"
                            name="confirmation"
                            minlength="10"
                            autocomplete="new-password"
                            required>
                    </div>

                    <button type="submit" class="btn">
                        Modifier le mot de passe
                    </button>
                </form>
            </section>

            <?php if (!$doitChangerMdp): ?>
                <p>
                    <a href="tableau-de-bord.php">
                        Retour au tableau de bord
                    </a>
                </p>
            <?php endif; ?>

        </main>
    </div>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/app.js"></script>
<script src="../assets/js/parents.js"></script>
<script src="../notifications/notifications.js"></script>
<script src="../assets/js/chat-global.js" defer></script>
</body>
</html>
