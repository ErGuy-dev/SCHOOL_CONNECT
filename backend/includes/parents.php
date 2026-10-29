<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Fonctions communes de l'espace Parents
// Fichier : backend/includes/parents.php
//
// Utilisé par : frontend/parents/tableau-de-bord.php, resultats.php, bulletins.php,
//               copies.php, documents.php, annonces.php, profil.php
//
// Règle d'or : un parent ne voit QUE les élèves liés à son compte avec
// parent_student.verifie = 1. Chaque requête ci-dessous repasse par cette
// jointure : même si un identifiant est modifié dans l'URL, la base ne renvoie rien.
// =====================================================

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/settings.php';

const PAR_TYPES_ANNONCE = [
    'annonce'   => 'Annonce',
    'reunion'   => 'Réunion',
    'evenement' => 'Événement',
];

const PAR_TYPES_EVALUATION = [
    'interrogation' => 'Interrogation',
    'devoir'        => 'Devoir',
    'examen'        => 'Examen',
];

const PAR_LIENS = [
    'pere'   => 'Père',
    'mere'   => 'Mère',
    'tuteur' => 'Tuteur',
];

const PAR_CATEGORIES_DOCUMENT = [
    'administratif' => 'Administratif',
    'pedagogique'   => 'Pédagogique',
];

/* ---------- Mise en forme ---------- */

// 13.5 -> « 13,5 » ; 12.00 -> « 12 »
function par_nombre(mixed $valeur, int $decimales = 2): string
{
    $texte = number_format((float) $valeur, $decimales, ',', '');
    return $decimales > 0 ? rtrim(rtrim($texte, '0'), ',') : $texte;
}

function par_date(?string $date, bool $heure = false): string
{
    if (!$date) {
        return '—';
    }
    $d = date_create($date);
    return $d ? $d->format($heure ? 'd/m/Y H:i' : 'd/m/Y') : '—';
}

function par_taille(int $octets): string
{
    if ($octets >= 1048576) {
        return number_format($octets / 1048576, 1, ',', '') . ' Mo';
    }
    return max(1, (int) round($octets / 1024)) . ' Ko';
}

// Mêmes seuils que backend/includes/direction.php : le parent voit
// la même appréciation que celle du bulletin publié par la Direction.
function par_appreciation(float $noteSur20): string
{
    return match (true) {
        $noteSur20 >= 16 => 'Excellent',
        $noteSur20 >= 14 => 'Très bien',
        $noteSur20 >= 12 => 'Bien',
        $noteSur20 >= 10 => 'Assez bien',
        $noteSur20 >= 8  => 'Insuffisant',
        default          => 'Faible',
    };
}

// Classe CSS du badge selon la note sur 20.
function par_badge_note(float $noteSur20): string
{
    return $noteSur20 >= 10 ? 'ok' : 'ko';
}

/* ---------- Accès : enfants liés au parent ---------- */

/**
 * Enfants inscrits dont le lien avec CE parent a été vérifié par l'administration.
 * Un lien non vérifié ne donne aucun accès.
 */
function par_enfants(PDO $pdo, int $parentId): array
{
    $st = $pdo->prepare(
        "SELECT st.id, st.matricule, st.nom, st.prenom, st.class_id,
                c.nom AS classe, c.school_year_id, ps.lien
         FROM parent_student ps
         JOIN students st ON st.id = ps.student_id AND st.statut = 'inscrit'
         JOIN classes c   ON c.id = st.class_id
         WHERE ps.user_id = ? AND ps.verifie = 1
         ORDER BY st.prenom, st.nom, st.id"
    );
    $st->execute([$parentId]);
    return $st->fetchAll();
}

/**
 * Enfant actuellement sélectionné. Le choix ( ?enfant=ID ) est mémorisé en session,
 * mais seulement s'il figure dans la liste des enfants vérifiés de ce parent.
 * Un identifiant étranger est ignoré ET journalisé (tentative d'accès à un dossier non associé).
 */
function par_enfant_courant(array $enfants): ?array
{
    if (!$enfants) {
        return null;
    }
    $ids = array_map(static fn(array $e): int => (int) $e['id'], $enfants);

    $demande = filter_var($_GET['enfant'] ?? null, FILTER_VALIDATE_INT);
    if ($demande !== false && $demande !== null && $demande > 0) {
        if (in_array($demande, $ids, true)) {
            $_SESSION['enfant_id'] = $demande;
        } else {
            try {
                audit('Accès refusé : dossier élève #' . $demande . ' non associé à ce compte');
            } catch (Throwable $e) {
                error_log('SchoolConnect audit parents : ' . $e->getMessage());
            }
        }
    }

    $choisi = (int) ($_SESSION['enfant_id'] ?? 0);
    if (!in_array($choisi, $ids, true)) {
        $choisi = $ids[0];
        $_SESSION['enfant_id'] = $choisi;
    }

    foreach ($enfants as $e) {
        if ((int) $e['id'] === $choisi) {
            return $e;
        }
    }
    return null;
}

/** HTML (déjà échappé) du sélecteur d'enfant, à placer dans $topbarGauche. */
function par_topbar_gauche(array $enfants, ?array $courant): string
{
    if (!$enfants || !$courant) {
        return '';
    }
    $h  = '<form method="get" class="child-form">';
    $h .= '<label class="muted" for="enfant">Enfant</label>';
    $h .= '<select id="enfant" name="enfant" class="child-select" data-auto-submit>';
    foreach ($enfants as $e) {
        $sel = (int) $e['id'] === (int) $courant['id'] ? ' selected' : '';
        $h  .= '<option value="' . (int) $e['id'] . '"' . $sel . '>'
             . e($e['prenom'] . ' ' . $e['nom'] . ' (' . $e['classe'] . ')') . '</option>';
    }
    $h .= '</select><noscript><button class="btn btn-sm" type="submit">Afficher</button></noscript></form>';
    return $h;
}

/**
 * Un compte créé par l'administrateur reçoit un mot de passe provisoire
 * (users.doit_changer_mdp = 1). Tant qu'il n'est pas changé, on renvoie vers le profil.
 */
function par_forcer_changement_mdp(PDO $pdo): void
{
    $st = $pdo->prepare('SELECT doit_changer_mdp FROM users WHERE id = ?');
    $st->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    if ((int) $st->fetchColumn() === 1) {
        header('Location: profil.php?etat=mdp_obligatoire', true, 303);
        exit;
    }
}

function par_a_permission(PDO $pdo, int $userId, string $permission): bool
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM users u
         JOIN role_permissions rp ON rp.role_id = u.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE u.id = ? AND u.statut = 'actif' AND p.code = ?"
    );
    $st->execute([$userId, $permission]);
    return (int) $st->fetchColumn() > 0;
}

/* ---------- Périodes et moyennes ---------- */

function par_periodes(PDO $pdo, int $anneeId): array
{
    $st = $pdo->prepare(
        'SELECT id, libelle, debut, fin FROM evaluation_periods WHERE school_year_id = ? ORDER BY debut, id'
    );
    $st->execute([$anneeId]);
    return $st->fetchAll();
}

/** Période en cours (ou la plus récente déjà commencée). */
function par_periode_defaut(array $periodes): int
{
    $aujourdhui = date('Y-m-d');
    $dernier = 0;
    foreach ($periodes as $p) {
        if ($p['debut'] <= $aujourdhui) {
            $dernier = (int) $p['id'];
            if ($aujourdhui <= $p['fin']) {
                return (int) $p['id'];
            }
        }
    }
    return $dernier > 0 ? $dernier : (int) ($periodes[0]['id'] ?? 0);
}

/**
 * Moyenne PROVISOIRE d'une période, calculée uniquement avec les évaluations PUBLIÉES.
 * Même formule que le bulletin (backend/includes/direction.php) : moyenne de matière
 * pondérée par le coefficient des évaluations, puis moyenne générale pondérée par
 * le coefficient des matières.
 *
 * @return array{moyenne: ?float, matieres: array<int,array>}
 */
function par_moyenne_periode(PDO $pdo, int $parentId, int $studentId, int $periodId): array
{
    $st = $pdo->prepare(
        "SELECT sub.id, sub.nom AS matiere, sub.coefficient AS coef, COUNT(*) AS nb,
                SUM((g.note / a.bareme * 20) * a.coefficient) / NULLIF(SUM(a.coefficient), 0) AS moy
         FROM grades g
         JOIN assessments a ON a.id = g.assessment_id AND a.statut = 'publiee'
         JOIN students st ON st.id = g.student_id AND st.class_id = a.class_id AND st.statut = 'inscrit'
         JOIN parent_student ps ON ps.student_id = st.id AND ps.user_id = ? AND ps.verifie = 1
         JOIN subjects sub ON sub.id = a.subject_id
         WHERE st.id = ? AND a.period_id = ?
         GROUP BY sub.id, sub.nom, sub.coefficient
         ORDER BY sub.nom"
    );
    $st->execute([$parentId, $studentId, $periodId]);

    $matieres = [];
    $somme = 0.0;
    $coefs = 0;
    foreach ($st->fetchAll() as $r) {
        $moy = round((float) $r['moy'], 2);
        $coef = (int) $r['coef'];
        $matieres[] = [
            'id' => (int) $r['id'], 'matiere' => (string) $r['matiere'],
            'coefficient' => $coef, 'moyenne' => $moy, 'nb' => (int) $r['nb'],
        ];
        $somme += $moy * $coef;
        $coefs += $coef;
    }

    return [
        'moyenne'  => $coefs > 0 ? round($somme / $coefs, 2) : null,
        'matieres' => $matieres,
    ];
}

/* ---------- Résultats ---------- */

/**
 * Résultats PUBLIÉS d'un enfant. « nouveau » = le parent ne les a pas encore consultés.
 */
function par_resultats(
    PDO $pdo, int $parentId, int $studentId,
    int $periodId = 0, int $matiereId = 0,
    string $tri = 'date', string $ordre = 'desc', int $limite = 300
): array {
    $colonnes = [
        'date'    => 'a.date_eval',
        'matiere' => 'sub.nom',
        'titre'   => 'a.titre',
        'note'    => '(g.note / a.bareme)',
    ];
    $col = $colonnes[$tri] ?? $colonnes['date'];
    $sens = strtolower($ordre) === 'asc' ? 'ASC' : 'DESC';

    $where = ['st.id = ?'];
    $params = [$parentId, $parentId, $studentId];
    if ($periodId > 0) {
        $where[] = 'a.period_id = ?';
        $params[] = $periodId;
    }
    if ($matiereId > 0) {
        $where[] = 'sub.id = ?';
        $params[] = $matiereId;
    }

    $st = $pdo->prepare(
        "SELECT a.id AS eval_id, a.titre, a.type, a.date_eval, a.bareme, a.coefficient AS coef_eval,
                sub.id AS matiere_id, sub.nom AS matiere, p.libelle AS periode, g.note,
                (nr.ref IS NULL) AS nouveau
         FROM grades g
         JOIN assessments a ON a.id = g.assessment_id AND a.statut = 'publiee'
         JOIN students st ON st.id = g.student_id AND st.class_id = a.class_id AND st.statut = 'inscrit'
         JOIN parent_student ps ON ps.student_id = st.id AND ps.user_id = ? AND ps.verifie = 1
         JOIN subjects sub ON sub.id = a.subject_id
         JOIN evaluation_periods p ON p.id = a.period_id
         LEFT JOIN notification_reads nr
                ON nr.user_id = ? AND nr.ref = CONCAT('res:', a.id, ':', g.student_id)
         WHERE " . implode(' AND ', $where) . "
         ORDER BY {$col} {$sens}, a.id DESC
         LIMIT " . max(1, min(500, $limite))
    );
    $st->execute($params);
    return $st->fetchAll();
}

/** Nombre de résultats publiés que le parent n'a pas encore consultés (toutes périodes). */
function par_compter_nouveaux(PDO $pdo, int $parentId, int $studentId): int
{
    $st = $pdo->prepare(
        "SELECT COUNT(*)
         FROM grades g
         JOIN assessments a ON a.id = g.assessment_id AND a.statut = 'publiee'
         JOIN students st ON st.id = g.student_id AND st.class_id = a.class_id AND st.statut = 'inscrit'
         JOIN parent_student ps ON ps.student_id = st.id AND ps.user_id = ? AND ps.verifie = 1
         WHERE st.id = ?
           AND NOT EXISTS (SELECT 1 FROM notification_reads nr
                           WHERE nr.user_id = ? AND nr.ref = CONCAT('res:', a.id, ':', g.student_id))"
    );
    $st->execute([$parentId, $studentId, $parentId]);
    return (int) $st->fetchColumn();
}

/** Marque comme lus les résultats d'une période (appelé APRÈS leur affichage). */
function par_marquer_resultats_lus(PDO $pdo, int $parentId, int $studentId, int $periodId): void
{
    try {
        $st = $pdo->prepare(
            "INSERT IGNORE INTO notification_reads (user_id, ref)
             SELECT ps.user_id, CONCAT('res:', a.id, ':', g.student_id)
             FROM grades g
             JOIN assessments a ON a.id = g.assessment_id AND a.statut = 'publiee'
             JOIN students st ON st.id = g.student_id AND st.class_id = a.class_id AND st.statut = 'inscrit'
             JOIN parent_student ps ON ps.student_id = st.id AND ps.user_id = ? AND ps.verifie = 1
             WHERE st.id = ? AND a.period_id = ?"
        );
        $st->execute([$parentId, $studentId, $periodId]);
    } catch (Throwable $e) {
        // Un marquage raté ne doit jamais empêcher l'affichage.
        error_log('SchoolConnect résultats lus : ' . $e->getMessage());
    }
}

/* ---------- Bulletins ---------- */

/** Bulletins PUBLIÉS (les bulletins retirés par la Direction disparaissent). */
function par_bulletins(PDO $pdo, int $parentId, int $studentId): array
{
    $st = $pdo->prepare(
        "SELECT rc.id, rc.moyenne, rc.rang, rc.effectif, rc.appreciation, rc.detail, rc.published_at,
                p.libelle AS periode, sy.libelle AS annee
         FROM report_cards rc
         JOIN parent_student ps ON ps.student_id = rc.student_id AND ps.user_id = ? AND ps.verifie = 1
         JOIN evaluation_periods p ON p.id = rc.period_id
         JOIN school_years sy ON sy.id = p.school_year_id
         WHERE rc.student_id = ? AND rc.statut = 'publie'
         ORDER BY p.debut DESC, rc.id DESC"
    );
    $st->execute([$parentId, $studentId]);
    return $st->fetchAll();
}

/* ---------- Copies corrigées ---------- */

/** Copies PUBLIÉES, d'évaluations PUBLIÉES (mêmes règles que backend/api/copies/telecharger.php). */
function par_copies(PDO $pdo, int $parentId, int $studentId, int $matiereId = 0, string $q = ''): array
{
    $where = ["sd.student_id = ?", "sd.statut = 'publie'", "a.statut = 'publiee'"];
    $params = [$parentId, $studentId];
    if ($matiereId > 0) {
        $where[] = 'sub.id = ?';
        $params[] = $matiereId;
    }
    if ($q !== '') {
        $where[] = '(a.titre LIKE ? OR sub.nom LIKE ?)';
        $motif = '%' . addcslashes($q, '%_\\') . '%';
        array_push($params, $motif, $motif);
    }

    $st = $pdo->prepare(
        "SELECT sd.id, sd.mime, sd.taille, sd.nom_original, a.titre, a.date_eval, a.bareme,
                sub.id AS matiere_id, sub.nom AS matiere, g.note
         FROM school_documents sd
         JOIN assessments a ON a.id = sd.assessment_id
         JOIN parent_student ps ON ps.student_id = sd.student_id AND ps.user_id = ? AND ps.verifie = 1
         JOIN subjects sub ON sub.id = a.subject_id
         LEFT JOIN grades g ON g.assessment_id = a.id AND g.student_id = sd.student_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY a.date_eval DESC, sd.id DESC
         LIMIT 200"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/* ---------- Annonces ---------- */

/**
 * Annonces visibles par ce parent : celles pour tous les parents, et celles des classes
 * de ses enfants (liens vérifiés). Les annonces retirées par la Direction n'apparaissent pas.
 */
function par_annonces(PDO $pdo, int $parentId, int $limite = 50, string $q = '', string $type = ''): array
{
    $where = [
        "an.statut = 'publiee'",
        "(an.class_id IS NULL OR an.class_id IN (
            SELECT st.class_id FROM parent_student ps
            JOIN students st ON st.id = ps.student_id AND st.statut = 'inscrit'
            WHERE ps.user_id = ? AND ps.verifie = 1))",
    ];
    $params = [$parentId];
    if ($q !== '') {
        $where[] = '(an.titre LIKE ? OR an.message LIKE ?)';
        $motif = '%' . addcslashes($q, '%_\\') . '%';
        array_push($params, $motif, $motif);
    }
    if (isset(PAR_TYPES_ANNONCE[$type])) {
        $where[] = 'an.type = ?';
        $params[] = $type;
    }

    $st = $pdo->prepare(
        'SELECT an.id, an.type, an.titre, an.message, an.date_evenement, an.created_at,
                c.nom AS classe, u.nom AS auteur
         FROM announcements an
         LEFT JOIN classes c ON c.id = an.class_id
         JOIN users u ON u.id = an.auteur_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY an.created_at DESC, an.id DESC
         LIMIT ' . max(1, min(200, $limite))
    );
    $st->execute($params);
    return $st->fetchAll();
}

/* ---------- Messages ---------- */

function par_messages(): array
{
    return [
        'mdp_obligatoire' => ['warn', 'Votre mot de passe est provisoire. Choisissez un nouveau mot de passe pour accéder à votre espace.'],
        'mdp_ok'          => ['success', 'Votre mot de passe a été modifié.'],
        'mdp_actuel_faux' => ['err', 'Le mot de passe actuel est incorrect.'],
        'mdp_faible'      => ['err', 'Le nouveau mot de passe doit contenir au moins 10 caractères, avec au moins une lettre et un chiffre.'],
        'mdp_different'   => ['err', 'La confirmation ne correspond pas au nouveau mot de passe.'],
        'mdp_identique'   => ['err', 'Le nouveau mot de passe doit être différent de l’actuel.'],
        'mdp_trop_essais' => ['err', 'Trop d’essais incorrects. Réessayez dans quelques minutes.'],
        'erreur'          => ['err', 'Une erreur est survenue. Réessayez plus tard.'],
    ];
}

/* ---------- Menu latéral ---------- */

function par_sidebar(string $active): void
{
    $items = [
        'tableau-de-bord' => ['tableau-de-bord.php', 'Tableau de bord'],
        'resultats'       => ['resultats.php', 'Résultats'],
        'bulletins'       => ['bulletins.php', 'Bulletins'],
        'copies'          => ['copies.php', 'Copies corrigées'],
        'documents'       => ['documents.php', 'Documents'],
        'annonces'        => ['annonces.php', 'Annonces'],
        'profil'          => ['profil.php', 'Mon profil'],
    ];
    echo '<aside class="sidebar" id="menu">' . "\n";
    echo '<div class="brand">SchoolConnect</div>' . "\n";
    echo '<div class="role">Parent ou tuteur</div>' . "\n";
    echo '<nav class="nav" aria-label="Navigation principale">' . "\n";
    foreach ($items as $cle => [$href, $libelle]) {
        $courant = $cle === $active ? ' aria-current="page"' : '';
        echo '<a href="' . e($href) . '"' . $courant . '>' . e($libelle) . '</a>' . "\n";
    }
    echo '</nav>' . "\n";
    echo '<a class="logout" href="../auth/logout.php">Se déconnecter</a>' . "\n";
    echo '</aside>' . "\n";
}