<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Fonctions communes de l'espace Direction
// Fichier : backend/includes/direction.php
//
// Utilisé par : frontend/direction/tableau-de-bord.php, statistiques.php,
//               bulletins.php, annonces.php
// =====================================================

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/settings.php';

/*
 * Moyenne sur 20, pondérée par le coefficient de chaque évaluation.
 * Même définition que backend/api/rapports/statistiques.php : les chiffres
 * de la Direction et ceux de l'administrateur sont donc identiques.
 */
const DIR_MOYENNE_SQL = 'SUM((g.note / a.bareme * 20) * a.coefficient) / NULLIF(SUM(a.coefficient), 0)';
const DIR_REUSSITE_SQL = 'SUM(CASE WHEN (g.note / a.bareme * 20) >= 10 THEN 1 ELSE 0 END) * 100 / NULLIF(COUNT(*), 0)';

const DIR_TYPES_ANNONCE = [
    'annonce'   => 'Annonce',
    'reunion'   => 'Réunion',
    'evenement' => 'Événement',
];

/* ---------- Mise en forme ---------- */

function dir_nombre(mixed $valeur, int $decimales = 2): string
{
    $texte = number_format((float) $valeur, $decimales, ',', '');
    return $decimales > 0 ? rtrim(rtrim($texte, '0'), ',') : $texte;
}

function dir_date(?string $date, bool $heure = false): string
{
    if (!$date) {
        return '—';
    }
    $d = date_create($date);
    return $d ? $d->format($heure ? 'd/m/Y H:i' : 'd/m/Y') : '—';
}

function dir_annee_active(PDO $pdo): ?array
{
    $r = $pdo->query('SELECT id, libelle FROM school_years WHERE active = 1 LIMIT 1')->fetch();
    return $r ?: null;
}

/* ---------- Bulletins ---------- */

function dir_appreciation(float $moyenne): string
{
    return match (true) {
        $moyenne >= 16 => 'Excellent',
        $moyenne >= 14 => 'Très bien',
        $moyenne >= 12 => 'Bien',
        $moyenne >= 10 => 'Assez bien',
        $moyenne >= 8  => 'Insuffisant',
        default        => 'Faible',
    };
}

/**
 * Calcule les bulletins d'une classe pour une période, à partir des SEULES
 * évaluations publiées. Moyenne de matière = moyenne des évaluations (pondérée
 * par leur coefficient) ; moyenne générale = moyennes de matière pondérées par
 * le coefficient de la matière. Rang : à égalité, même rang (1, 2, 2, 4).
 *
 * @return array<int,array> trié par rang, puis par nom
 */
function dir_calculer_bulletins(PDO $pdo, int $classId, int $periodId): array
{
    $st = $pdo->prepare(
        "SELECT st.id AS sid, st.matricule, st.nom, st.prenom,
                sub.id AS subid, sub.nom AS matiere, sub.coefficient AS coef,
                SUM((g.note / a.bareme * 20) * a.coefficient) / NULLIF(SUM(a.coefficient), 0) AS moy
         FROM grades g
         JOIN assessments a ON a.id = g.assessment_id
         JOIN students st ON st.id = g.student_id AND st.class_id = a.class_id AND st.statut = 'inscrit'
         JOIN subjects sub ON sub.id = a.subject_id
         WHERE a.class_id = ? AND a.period_id = ? AND a.statut = 'publiee'
         GROUP BY st.id, sub.id
         ORDER BY st.nom, st.prenom, sub.nom"
    );
    $st->execute([$classId, $periodId]);

    $eleves = [];
    foreach ($st->fetchAll() as $r) {
        $sid = (int) $r['sid'];
        $eleves[$sid] ??= [
            'student_id' => $sid, 'matricule' => $r['matricule'], 'nom' => $r['nom'],
            'prenom' => $r['prenom'], 'matieres' => [], 'somme' => 0.0, 'coefs' => 0,
        ];
        $moy = round((float) $r['moy'], 2);
        $eleves[$sid]['matieres'][] = ['matiere' => $r['matiere'], 'coefficient' => (int) $r['coef'], 'moyenne' => $moy];
        $eleves[$sid]['somme'] += $moy * (int) $r['coef'];
        $eleves[$sid]['coefs'] += (int) $r['coef'];
    }

    $liste = [];
    foreach ($eleves as $e) {
        $e['moyenne'] = $e['coefs'] > 0 ? round($e['somme'] / $e['coefs'], 2) : 0.0;
        $e['appreciation'] = dir_appreciation($e['moyenne']);
        unset($e['somme'], $e['coefs']);
        $liste[] = $e;
    }

    usort($liste, static fn($a, $b) => [$b['moyenne'], $a['nom'], $a['prenom']] <=> [$a['moyenne'], $b['nom'], $b['prenom']]);

    $effectif = count($liste);
    $rang = 0;
    $precedent = null;
    foreach ($liste as $i => &$e) {
        if ($precedent === null || $e['moyenne'] !== $precedent) {
            $rang = $i + 1;
            $precedent = $e['moyenne'];
        }
        $e['rang'] = $rang;
        $e['effectif'] = $effectif;
    }
    unset($e);

    return $liste;
}

/* ---------- Notifications aux parents ---------- */

/**
 * Notifie les parents : ceux dont un enfant est dans la classe (lien vérifié),
 * ou tous les parents actifs si $classId est null.
 */
function dir_notifier_parents(PDO $pdo, ?int $classId, string $titre, string $message, string $lien): void
{
    if (!school_setting_enabled('notifications_enabled')) {
        return;
    }
    try {
        if ($classId === null) {
            $pdo->prepare(
                "INSERT INTO notifications (user_id, titre, message, type, lien)
                 SELECT u.id, ?, ?, 'info', ?
                 FROM users u JOIN roles r ON r.id = u.role_id AND r.code = 'parent'
                 WHERE u.statut = 'actif'"
            )->execute([mb_substr($titre, 0, 150), $message, $lien]);
        } else {
            $pdo->prepare(
                "INSERT INTO notifications (user_id, titre, message, type, lien)
                 SELECT DISTINCT ps.user_id, ?, ?, 'info', ?
                 FROM parent_student ps
                 JOIN students st ON st.id = ps.student_id AND st.statut = 'inscrit' AND st.class_id = ?
                 JOIN users u ON u.id = ps.user_id AND u.statut = 'actif'
                 WHERE ps.verifie = 1"
            )->execute([mb_substr($titre, 0, 150), $message, $lien, $classId]);
        }
    } catch (Throwable $e) {
        error_log('SchoolConnect notification parents (direction) : ' . $e->getMessage());
    }
}

/* ---------- Messages ---------- */

function dir_messages(): array
{
    return [
        'annonce_ok'        => ['success', 'L’annonce est publiée. Les parents concernés ont été notifiés.'],
        'annonce_modifiee'  => ['success', 'L’annonce a été modifiée.'],
        'annonce_retiree'   => ['success', 'L’annonce a été retirée : les parents ne la voient plus.'],
        'annonce_remise'    => ['success', 'L’annonce est de nouveau visible par les parents.'],
        'annonce_invalide'  => ['err', 'Vérifiez le titre (120 caractères max.), le message (5000 max.), la date et les destinataires.'],
        'annonce_introuvable' => ['err', 'Annonce introuvable. Rechargez la page.'],
        'bulletins_ok'      => ['success', 'Les bulletins sont publiés. Les parents concernés ont été notifiés.'],
        'bulletins_retires' => ['success', 'Les bulletins ont été retirés de la vue des parents.'],
        'bulletins_vide'    => ['err', 'Aucun bulletin à publier : aucune note publiée pour cette classe et cette période.'],
        'bulletins_invalide' => ['err', 'Choisissez une classe et une période valides de l’année active.'],
        'annee_absente'     => ['warn', 'Aucune année scolaire active. Contactez l’administrateur.'],
        'action_inconnue'   => ['err', 'Action inconnue.'],
        'erreur'            => ['err', 'Une erreur est survenue. Consultez les journaux PHP.'],
    ];
}

/* ---------- Menu latéral ---------- */

function dir_sidebar(string $active): void
{
    $items = [
        'tableau-de-bord' => ['tableau-de-bord.php', 'Tableau de bord'],
        'statistiques'    => ['statistiques.php', 'Statistiques'],
        'bulletins'       => ['bulletins.php', 'Bulletins'],
        'annonces'        => ['annonces.php', 'Annonces'],
    ];
    echo '<aside class="sidebar" id="menu">' . "\n";
    echo '<div class="brand">SchoolConnect</div>' . "\n";
    echo '<div class="role">Direction</div>' . "\n";
    echo '<nav class="nav" aria-label="Navigation principale">' . "\n";
    foreach ($items as $cle => [$href, $libelle]) {
        $courant = $cle === $active ? ' aria-current="page"' : '';
        echo '<a href="' . e($href) . '"' . $courant . '>' . e($libelle) . '</a>' . "\n";
    }
    echo '</nav>' . "\n";
    echo '<a class="logout" href="../auth/logout.php">Se déconnecter</a>' . "\n";
    echo '</aside>' . "\n";
}