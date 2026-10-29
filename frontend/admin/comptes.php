<?php
declare(strict_types=1);
require __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';

require_role('administrateur');

$pdo  = db();
$moi  = (int)$_SESSION['user_id'];
$roles = $pdo->query('SELECT id, code, libelle FROM roles ORDER BY id')->fetchAll();
$rolesParId = array_column($roles, null, 'id');

function mot_de_passe_provisoire(int $n = 10): string
{
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $n; $i++) {
        $out .= $alpha[random_int(0, strlen($alpha) - 1)];
    }
    return $out;
}

function admins_actifs_hors(PDO $pdo, int $exceptId): int
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.code = 'administrateur' AND u.statut = 'actif' AND u.id <> ?"
    );
    $st->execute([$exceptId]);
    return (int)$st->fetchColumn();
}

/* ---------- Traitement des formulaires (POST puis redirection) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $retour = 'comptes.php';

    try {
        if ($action === 'create' || $action === 'update') {
            $nom    = trim((string)($_POST['nom'] ?? ''));
            $email  = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $roleId = (int)($_POST['role_id'] ?? 0);

            if (mb_strlen($nom) < 2 || mb_strlen($nom) > 120) {
                throw new RuntimeException('Le nom doit contenir entre 2 et 120 caractères.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                throw new RuntimeException('Adresse e-mail invalide.');
            }
            if (!isset($rolesParId[$roleId])) {
                throw new RuntimeException('Rôle invalide.');
            }

            if ($action === 'create') {
                $mdp = mot_de_passe_provisoire();
                $st = $pdo->prepare(
                    'INSERT INTO users (nom, email, password_hash, role_id, statut, doit_changer_mdp)
                     VALUES (?, ?, ?, ?, \'actif\', 1)'
                );
                $st->execute([$nom, $email, password_hash($mdp, PASSWORD_DEFAULT), $roleId]);
                audit("Création du compte {$email} ({$rolesParId[$roleId]['libelle']})");
                flash('success', "Compte créé. Mot de passe provisoire (à transmettre, affiché une seule fois) : {$mdp}");
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $st = $pdo->prepare('SELECT role_id FROM users WHERE id = ?');
                $st->execute([$id]);
                $ancien = $st->fetchColumn();
                if ($ancien === false) {
                    throw new RuntimeException('Compte introuvable.');
                }
                if ((int)$ancien !== $roleId) {
                    $codeAncien = $rolesParId[(int)$ancien]['code'];
                    if ($id === $moi) {
                        throw new RuntimeException('Vous ne pouvez pas changer votre propre rôle.');
                    }
                    if ($codeAncien === 'administrateur' && admins_actifs_hors($pdo, $id) === 0) {
                        throw new RuntimeException('Il doit rester au moins un administrateur actif.');
                    }
                }
                $st = $pdo->prepare('UPDATE users SET nom = ?, email = ?, role_id = ? WHERE id = ?');
                $st->execute([$nom, $email, $roleId, $id]);
                audit("Modification du compte {$email}");
                flash('success', 'Compte modifié.');
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === $moi) {
                throw new RuntimeException('Vous ne pouvez pas désactiver votre propre compte.');
            }
            $st = $pdo->prepare(
                'SELECT u.email, u.statut, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?'
            );
            $st->execute([$id]);
            $u = $st->fetch();
            if (!$u) {
                throw new RuntimeException('Compte introuvable.');
            }
            $nouveau = $u['statut'] === 'actif' ? 'desactive' : 'actif';
            if ($nouveau === 'desactive' && $u['code'] === 'administrateur' && admins_actifs_hors($pdo, $id) === 0) {
                throw new RuntimeException('Il doit rester au moins un administrateur actif.');
            }
            $pdo->prepare('UPDATE users SET statut = ? WHERE id = ?')->execute([$nouveau, $id]);
            audit(($nouveau === 'actif' ? 'Réactivation' : 'Désactivation') . " du compte {$u['email']}");
            flash('success', $nouveau === 'actif' ? 'Compte réactivé.' : 'Compte désactivé.');
        } else {
            throw new RuntimeException('Action inconnue.');
        }
    } catch (PDOException $ex) {
        // 23000 = violation d'unicité (e-mail déjà utilisé)
        flash('error', $ex->getCode() === '23000' ? 'Cette adresse e-mail est déjà utilisée.' : 'Erreur de base de données.');
        if ($action === 'update') {
            $retour = 'comptes.php?edit=' . (int)($_POST['id'] ?? 0);
        } elseif ($action === 'create') {
            $retour = 'comptes.php?new=1';
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
        if ($action === 'update') {
            $retour = 'comptes.php?edit=' . (int)($_POST['id'] ?? 0);
        } elseif ($action === 'create') {
            $retour = 'comptes.php?new=1';
        }
    }
    header('Location: ' . $retour);
    exit;
}

/* ---------- Données d'affichage ---------- */
$q       = trim((string)($_GET['q'] ?? ''));
$filtre  = (int)($_GET['role'] ?? 0);
$editId  = (int)($_GET['edit'] ?? 0);

$sql = 'SELECT u.id, u.nom, u.email, u.statut, u.role_id, r.libelle
        FROM users u JOIN roles r ON r.id = u.role_id WHERE 1 = 1';
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $sql .= ' AND (u.nom LIKE ? OR u.email LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}
if ($filtre > 0) {
    $sql .= ' AND u.role_id = ?';
    $params[] = $filtre;
}
$sql .= ' ORDER BY u.nom';
$st = $pdo->prepare($sql);
$st->execute($params);
$comptes = $st->fetchAll();

$edit = null;
if ($editId > 0) {
    $st = $pdo->prepare('SELECT id, nom, email, role_id FROM users WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
}
$flash  = take_flash();
$ouvrir = ($edit !== null) || isset($_GET['new']);
?>

<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= htmlspecialchars($themeGlobal, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Comptes | SchoolConnect</title>
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../notifications/notifications.css">
<link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">
<aside class="sidebar" id="menu">
<div class="brand">SchoolConnect</div>
<div class="role">Administrateur</div>
<nav class="nav" aria-label="Navigation principale">
    <a href="tableau-de-bord.php">Tableau de bord</a>
    <a href="comptes.php" aria-current="page">Comptes</a>
    <a href="classes-matieres.php">Classes et matières</a>
    <a href="sauvegardes.php">Sauvegardes</a>
    <a href="journaux.php">Journaux d'audit</a>
    <a href="documents.php">Documents</a>
    <a href="rapports.php">Rapports et statistiques</a>
    <a href="parametres.php">Paramètres</a>
</nav>
<a class="logout" href="../auth/connexion.html">Se déconnecter</a>
</aside>
<div class="main">
<header class="topbar">
<button class="menu-btn" type="button" aria-controls="menu" aria-expanded="false">Menu</button>

<span class="spacer"></span>

<div class="topbar-right">
    <!-- Centre de notifications -->
    <section id="notifications-app" class="notifications-app"
             data-api-base="/SCHOOL_CONNECT/backend/api/notifications">

        <button type="button" id="notifications-toggle" class="notifications-toggle"
                aria-expanded="false" aria-controls="notifications-panel"
                aria-label="Notifications">
            <svg class="notifications-icon" viewBox="0 0 24 24" width="22" height="22"
                 fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
                <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
            </svg>
            <span id="notifications-count" class="notifications-count" hidden>0</span>
        </button>

        <section id="notifications-panel" class="notifications-panel"
                 aria-label="Centre de notifications" hidden>
            <header class="notifications-header">
                <h2>Mes notifications</h2>
                <button type="button" id="notifications-refresh"
                        class="notifications-refresh">Actualiser</button>
            </header>

            <p id="notifications-status" class="notifications-status"
               role="status" aria-live="polite">Chargement des notifications...</p>

            <ul id="notifications-list" class="notifications-list"></ul>
        </section>
    </section>

    <span class="user"><?= e($_SESSION['user_nom']) ?></span>
</div>
</header>
<main class="content">
<h1>Comptes</h1>
<p class="lead">Seuls l'administrateur, la direction, les enseignants et les parents peuvent se connecter. Les élèves n'ont pas de compte.</p>

<?php if ($flash): ?>
<div class="mssg <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status" tabindex="-1"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="page-actions">
<button type="button" class="btn" id="open-create">Créer un compte</button>
</div>

<dialog id="account-dialog" class="modal" aria-labelledby="dialog-title">
<form method="post" action="comptes.php">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
<?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

<div class="modal-head">
<h2 id="dialog-title"><?= $edit ? 'Modifier le compte' : 'Créer un compte' ?></h2>
<button type="button" class="modal-close" data-close aria-label="Fermer">&times;</button>
</div>

<div class="form-grid">
<div class="field"><label for="nom">Nom complet</label><input type="text" id="nom" name="nom" maxlength="120" required value="<?= e($edit['nom'] ?? '') ?>"></div>
<div class="field"><label for="email">Adresse e-mail</label><input type="email" id="email" name="email" maxlength="190" required value="<?= e($edit['email'] ?? '') ?>"></div>
<div class="field"><label for="role">Rôle</label>
<select id="role" name="role_id">
<?php foreach ($roles as $r): ?>
<option value="<?= (int)$r['id'] ?>" <?= ($edit && (int)$edit['role_id'] === (int)$r['id']) ? 'selected' : '' ?>><?= e($r['libelle']) ?></option>
<?php endforeach; ?>
</select></div>
</div>

<?php if (!$edit): ?>
<p class="hint">Un mot de passe provisoire sera généré. Le compte devra le changer à la première connexion.</p>
<?php endif; ?>

<div class="modal-actions">
<button type="button" class="btn btn-secondary" data-close>Annuler</button>
<button class="btn" type="submit"><?= $edit ? 'Enregistrer' : 'Créer le compte' ?></button>
</div>
</form>
</dialog>

<section class="card">
<h2>Comptes existants</h2>
<form method="get" action="comptes.php" class="toolbar">
<input type="search" name="q" value="<?= e($q) ?>" placeholder="Rechercher un nom ou un e-mail" aria-label="Rechercher">
<select name="role" aria-label="Filtrer par rôle" onchange="this.form.submit()">
<option value="0">Tous les rôles</option>
<?php foreach ($roles as $r): ?>
<option value="<?= (int)$r['id'] ?>" <?= $filtre === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['libelle']) ?></option>
<?php endforeach; ?>
</select>
<button class="btn btn-secondary btn-sm" type="submit">Filtrer</button>
</form>
<div class="table-wrap">
<table class="table">
<thead><tr><th scope="col">Nom</th><th scope="col">E-mail</th><th scope="col">Rôle</th><th scope="col">Statut</th><th scope="col"></th></tr></thead>
<tbody>
<?php if (!$comptes): ?>
<tr><td colspan="5">Aucun compte trouvé.</td></tr>
<?php endif; ?>
<?php foreach ($comptes as $c): $actif = $c['statut'] === 'actif'; ?>
<tr>
<td><?= e($c['nom']) ?></td>
<td><?= e($c['email']) ?></td>
<td><?= e($c['libelle']) ?></td>
<td><span class="badge <?= $actif ? 'ok' : 'ko' ?>"><?= $actif ? 'Actif' : 'Désactivé' ?></span></td>
<td class="actions">
<a class="btn btn-secondary btn-sm" href="comptes.php?edit=<?= (int)$c['id'] ?>">Modifier</a>
<?php if ((int)$c['id'] !== $moi): ?>
<form method="post" action="comptes.php" style="display:inline">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="action" value="toggle">
<input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
<button type="submit" class="btn btn-secondary btn-sm"><?= $actif ? 'Désactiver' : 'Réactiver' ?></button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>
</main>
</div>
</div>

<script>
(function () {
  var d = document.getElementById('account-dialog');
  var openBtn = document.getElementById('open-create');
  if (!d || typeof d.showModal !== 'function') return;

  var modeEdition = <?= $edit ? 'true' : 'false' ?>;

  openBtn.addEventListener('click', function () { d.showModal(); });

  d.querySelectorAll('[data-close]').forEach(function (b) {
    b.addEventListener('click', function () { d.close(); });
  });

  // Clic sur le fond sombre = fermer
  d.addEventListener('click', function (e) { if (e.target === d) d.close(); });

  // En mode modification, fermer ramène à la liste (retire ?edit=)
  d.addEventListener('close', function () {
    if (modeEdition) window.location.href = 'comptes.php';
  });

  <?php if ($ouvrir): ?>d.showModal();<?php endif; ?>
})();
</script>
<script src="../assets/js/theme.js"></script>
<script src="../assets/js/app.js"></script>
<script src="../notifications/notifications.js"></script>
<script src="../assets/js/chat-global.js" defer></script>
</body>
</html>