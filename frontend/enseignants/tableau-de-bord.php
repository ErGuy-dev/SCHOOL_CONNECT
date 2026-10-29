<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Tableau de bord de l'enseignant
// Fichier : frontend/enseignants/tableau-de-bord.php
// =====================================================

require_once __DIR__ . '/../../backend/includes/security.php';
require_once __DIR__ . '/../../backend/includes/theme.php';

// Connexion, statut actif et rôle revérifiés en base à chaque requête.
require_role('enseignant');

$pdo = db();
$enseignantId = (int) $_SESSION['user_id'];

function h(mixed $valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dateCourte(?string $date): string
{
    if (!$date) {
        return '—';
    }

    $d = date_create($date);
    return $d ? $d->format('d/m/Y') : '—';
}

// 20.00 -> 20 ; 7.50 -> 7,5
function nombreCourt(mixed $valeur): string
{
    $texte = number_format((float) $valeur, 2, ',', '');
    return rtrim(rtrim($texte, '0'), ',');
}

try {
    // Année scolaire active.
    $annee = $pdo->query(
        'SELECT id, libelle FROM school_years WHERE active = 1 LIMIT 1'
    )->fetch();

    $mesClasses = [];
    $brouillons = 0;
    $copiesAPublier = 0;
    $recentes = [];

    if ($annee) {
        // Classes et matières affectées à cet enseignant (année active).
        $st = $pdo->prepare(
            "SELECT ta.class_id, ta.subject_id,
                    c.nom AS classe, s.nom AS matiere,
                    (SELECT COUNT(*) FROM students st
                      WHERE st.class_id = c.id AND st.statut = 'inscrit') AS eleves
             FROM teacher_assignments ta
             JOIN classes c  ON c.id = ta.class_id
             JOIN subjects s ON s.id = ta.subject_id
             WHERE ta.teacher_id = ? AND c.school_year_id = ?
             ORDER BY c.nom, s.nom"
        );
        $st->execute([$enseignantId, (int) $annee['id']]);
        $mesClasses = $st->fetchAll();

        // Évaluations encore en brouillon.
        $st = $pdo->prepare(
            "SELECT COUNT(*)
             FROM assessments a
             JOIN classes c ON c.id = a.class_id
             WHERE a.teacher_id = ? AND a.statut = 'brouillon'
               AND c.school_year_id = ?"
        );
        $st->execute([$enseignantId, (int) $annee['id']]);
        $brouillons = (int) $st->fetchColumn();

        // Copies importées mais pas encore publiées aux parents.
        $st = $pdo->prepare(
            "SELECT COUNT(*)
             FROM school_documents sd
             JOIN assessments a ON a.id = sd.assessment_id
             JOIN classes c     ON c.id = a.class_id
             WHERE sd.uploaded_by = ? AND sd.statut = 'brouillon'
               AND c.school_year_id = ?"
        );
        $st->execute([$enseignantId, (int) $annee['id']]);
        $copiesAPublier = (int) $st->fetchColumn();

        // Les 5 dernières évaluations.
        $st = $pdo->prepare(
            "SELECT a.id, a.titre, a.date_eval, a.bareme, a.statut,
                    c.nom AS classe, s.nom AS matiere
             FROM assessments a
             JOIN classes c  ON c.id = a.class_id
             JOIN subjects s ON s.id = a.subject_id
             WHERE a.teacher_id = ? AND c.school_year_id = ?
             ORDER BY a.date_eval DESC, a.id DESC
             LIMIT 5"
        );
        $st->execute([$enseignantId, (int) $annee['id']]);
        $recentes = $st->fetchAll();
    }

    $erreur = false;
} catch (Throwable $e) {
    error_log('SchoolConnect tableau de bord enseignant : ' . $e->getMessage());
    $annee = false;
    $mesClasses = [];
    $recentes = [];
    $brouillons = 0;
    $copiesAPublier = 0;
    $erreur = true;
}

$nomAffichage = (string) $_SESSION['user_nom'];
?>
<!DOCTYPE html>
<html lang="fr" data-theme-server="<?= h($themeGlobal) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord | SchoolConnect</title>
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/layout.css">
    <link rel="stylesheet" href="../notifications/notifications.css">
    <link rel="stylesheet" href="../assets/css/chat-global.css">
</head>
<body data-chat-enabled="true">
<div class="app">

    <aside class="sidebar" id="menu">
        <div class="brand">SchoolConnect</div>
        <div class="role">Enseignant</div>

        <nav class="nav" aria-label="Navigation principale">
            <a href="tableau-de-bord.php" aria-current="page">Tableau de bord</a>
            <a href="evaluations.php">Évaluations</a>
            <a href="notes.php">Saisie des notes</a>
            <a href="copies.php">Copies corrigées</a>
        </nav>

        <a class="logout" href="../auth/logout.php">Se déconnecter</a>
    </aside>

    <div class="main">
        <?php require __DIR__ . '/../includes/topbar.php'; ?>

        <main class="content">
            <h1>Tableau de bord</h1>
            <p class="lead">
                Vos classes et vos matières
                <?php if ($annee): ?>
                    — année scolaire <?= h($annee['libelle']) ?>.
                <?php endif; ?>
            </p>

            <?php if ($erreur): ?>
                <div class="mssg err" role="alert">
                    Impossible de charger vos informations. Réessayez plus tard.
                </div>
            <?php elseif (!$annee): ?>
                <div class="mssg warn" role="status">
                    Aucune année scolaire active. Contactez l'administrateur.
                </div>
            <?php endif; ?>

            <div class="stats">
                <div class="stat">
                    <strong><?= count($mesClasses) ?></strong>
                    <span>Classes et matières attribuées</span>
                </div>

                <div class="stat">
                    <strong><?= $brouillons ?></strong>
                    <span>Évaluations en brouillon</span>
                </div>

                <div class="stat">
                    <strong><?= $copiesAPublier ?></strong>
                    <span>Copies à publier</span>
                </div>
            </div>

            <section class="card">
                <h2>Mes classes</h2>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th scope="col">Classe</th>
                            <th scope="col">Matière</th>
                            <th scope="col">Élèves</th>
                            <th scope="col"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$mesClasses): ?>
                            <tr>
                                <td colspan="4">
                                    Aucune classe ne vous est encore affectée.
                                    L'administrateur peut le faire dans « Classes et matières ».
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($mesClasses as $c): ?>
                            <tr>
                                <td><?= h($c['classe']) ?></td>
                                <td><?= h($c['matiere']) ?></td>
                                <td><?= (int) $c['eleves'] ?></td>
                                <td class="actions">
                                    <a class="btn btn-sm"
                                       href="evaluations.php?class_id=<?= (int) $c['class_id'] ?>&amp;subject_id=<?= (int) $c['subject_id'] ?>">
                                        Évaluations et notes
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <h2>Dernières évaluations</h2>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th scope="col">Évaluation</th>
                            <th scope="col">Classe</th>
                            <th scope="col">Matière</th>
                            <th scope="col">Date</th>
                            <th scope="col">Barème</th>
                            <th scope="col">Statut</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$recentes): ?>
                            <tr><td colspan="6">Aucune évaluation créée pour le moment.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($recentes as $r): ?>
                            <tr>
                                <td><?= h($r['titre']) ?></td>
                                <td><?= h($r['classe']) ?></td>
                                <td><?= h($r['matiere']) ?></td>
                                <td><?= h(dateCourte($r['date_eval'])) ?></td>
                                <td><?= h(nombreCourt($r['bareme'])) ?></td>
                                <td>
                                    <?php if ($r['statut'] === 'publiee'): ?>
                                        <span class="badge ok">Publiée</span>
                                    <?php else: ?>
                                        <span class="badge warn">Brouillon</span>
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

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/app.js"></script>
<script src="../notifications/notifications.js"></script>
<script src="../assets/js/chat-global.js" defer></script>
</body>
</html>