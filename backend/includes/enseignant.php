<?php
declare(strict_types=1);

// =====================================================
// SchoolConnect - Fonctions communes de l'espace Enseignant
// Fichier : backend/includes/enseignant.php
//
// Utilisé par : frontend/enseignants/evaluations.php, notes.php, copies.php
//               backend/api/copies/telecharger.php
// =====================================================

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/settings.php';

// Les copies sont rangées HORS de la racine web (comme les documents).
const COPIES_STORAGE_DIR = 'C:\\xampp\\schoolconnect-storage\\copies';
const COPIE_MAX_BYTES    = 5242880; // 5 Mo

/* ---------- Mise en forme ---------- */

// 20.00 -> 20 ; 7.50 -> 7,5
function ens_nombre(mixed $valeur): string
{
    $texte = number_format((float) $valeur, 2, ',', '');
    return rtrim(rtrim($texte, '0'), ',');
}

function ens_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $d = date_create($date);
    return $d ? $d->format('d/m/Y') : '—';
}

function ens_taille(int $octets): string
{
    if ($octets >= 1048576) {
        return number_format($octets / 1048576, 1, ',', '') . ' Mo';
    }
    return max(1, (int) round($octets / 1024)) . ' Ko';
}

const ENS_TYPES = [
    'interrogation' => 'Interrogation',
    'devoir'        => 'Devoir',
    'examen'        => 'Examen',
];

/* ---------- Accès aux données (toujours limité à l'enseignant connecté) ---------- */

function ens_annee_active(PDO $pdo): ?array
{
    $r = $pdo->query('SELECT id, libelle FROM school_years WHERE active = 1 LIMIT 1')->fetch();
    return $r ?: null;
}

/** Classes + matières affectées à l'enseignant pour l'année donnée. */
function ens_affectations(PDO $pdo, int $teacherId, int $anneeId): array
{
    $st = $pdo->prepare(
        'SELECT ta.class_id, ta.subject_id, c.nom AS classe, s.nom AS matiere,
                s.bareme AS bareme_matiere, s.coefficient AS coef_matiere
         FROM teacher_assignments ta
         JOIN classes c  ON c.id = ta.class_id
         JOIN subjects s ON s.id = ta.subject_id
         WHERE ta.teacher_id = ? AND c.school_year_id = ?
         ORDER BY c.nom, s.nom'
    );
    $st->execute([$teacherId, $anneeId]);
    return $st->fetchAll();
}

function ens_periodes(PDO $pdo, int $anneeId): array
{
    $st = $pdo->prepare(
        'SELECT id, libelle FROM evaluation_periods WHERE school_year_id = ? ORDER BY debut'
    );
    $st->execute([$anneeId]);
    return $st->fetchAll();
}

/**
 * Une évaluation appartenant à CET enseignant, dans une classe de l'année active.
 * Retourne null sinon : un enseignant ne peut jamais toucher l'évaluation d'un collègue.
 */
function ens_evaluation(PDO $pdo, int $id, int $teacherId, bool $verrou = false): ?array
{
    $st = $pdo->prepare(
        'SELECT a.*, c.nom AS classe, s.nom AS matiere, p.libelle AS periode
         FROM assessments a
         JOIN classes c  ON c.id = a.class_id
         JOIN school_years sy ON sy.id = c.school_year_id AND sy.active = 1
         JOIN subjects s ON s.id = a.subject_id
         JOIN evaluation_periods p ON p.id = a.period_id
         WHERE a.id = ? AND a.teacher_id = ?'
        . ($verrou ? ' FOR UPDATE' : '')
    );
    $st->execute([$id, $teacherId]);
    $r = $st->fetch();
    return $r ?: null;
}

/* ---------- Notifications aux parents (liens vérifiés uniquement) ---------- */

function ens_notifier_parents(
    PDO $pdo,
    int $classId,
    ?int $studentId,
    string $titre,
    string $debutMessage,
    string $lien
): void {
    if (!school_setting_enabled('notifications_enabled')) {
        return;
    }
    try {
        $sql = "INSERT INTO notifications (user_id, titre, message, type, lien)
                SELECT DISTINCT ps.user_id, ?, CONCAT(?, st.prenom, ' ', st.nom, '.'), 'info', ?
                FROM parent_student ps
                JOIN students st ON st.id = ps.student_id
                JOIN users u ON u.id = ps.user_id AND u.statut = 'actif'
                WHERE ps.verifie = 1 AND st.statut = 'inscrit' AND st.class_id = ?";
        $params = [mb_substr($titre, 0, 150), $debutMessage, $lien, $classId];
        if ($studentId !== null) {
            $sql .= ' AND st.id = ?';
            $params[] = $studentId;
        }
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        // Une notification ratée ne doit jamais annuler une publication déjà faite.
        error_log('SchoolConnect notification parents : ' . $e->getMessage());
    }
}

/* ---------- Téléversement d'une copie ---------- */

/**
 * Valide et range le fichier. Lève InvalidArgumentException avec un code d'état
 * (voir ens_messages) si quelque chose ne va pas.
 */
function ens_upload_copie(array $f): array
{
    $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('fichier_absent');
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('fichier_trop_gros');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
        throw new InvalidArgumentException('fichier_invalide');
    }

    $taille = (int) $f['size'];
    if ($taille < 1) {
        throw new InvalidArgumentException('fichier_invalide');
    }
    if ($taille > COPIE_MAX_BYTES) {
        throw new InvalidArgumentException('fichier_trop_gros');
    }

    $original = basename(str_replace('\\', '/', (string) $f['name']));
    $original = preg_replace('/[\x00-\x1F\x7F]/u', '', $original) ?? '';
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

    $autorises = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    if (!isset($autorises[$ext])) {
        throw new InvalidArgumentException('fichier_format');
    }

    // Le contenu réel doit correspondre à l'extension annoncée.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $f['tmp_name']) ?: '';
    if ($mime !== $autorises[$ext]) {
        throw new InvalidArgumentException('fichier_format');
    }

    if (!is_dir(COPIES_STORAGE_DIR) && !mkdir(COPIES_STORAGE_DIR, 0700, true) && !is_dir(COPIES_STORAGE_DIR)) {
        error_log('SchoolConnect : impossible de créer ' . COPIES_STORAGE_DIR);
        throw new InvalidArgumentException('stockage');
    }
    if (!is_writable(COPIES_STORAGE_DIR)) {
        error_log('SchoolConnect : dossier non inscriptible ' . COPIES_STORAGE_DIR);
        throw new InvalidArgumentException('stockage');
    }

    // Nom aléatoire : l'utilisateur ne peut ni le deviner ni le choisir.
    $nom  = bin2hex(random_bytes(24)) . '.' . $ext;
    $path = rtrim(COPIES_STORAGE_DIR, '\\/') . DIRECTORY_SEPARATOR . $nom;
    if (!move_uploaded_file((string) $f['tmp_name'], $path)) {
        throw new InvalidArgumentException('stockage');
    }
    @chmod($path, 0600);

    return [
        'original' => mb_substr($original, 0, 255),
        'nom'      => $nom,
        'path'     => $path,
        'mime'     => $mime,
        'taille'   => $taille,
        'sha256'   => hash_file('sha256', $path),
    ];
}

function ens_supprimer_fichier(?string $nomStockage): void
{
    if (!$nomStockage) {
        return;
    }
    $path = rtrim(COPIES_STORAGE_DIR, '\\/') . DIRECTORY_SEPARATOR . basename($nomStockage);
    if (is_file($path)) {
        @unlink($path);
    }
}

/* ---------- Messages (code d'état dans l'URL -> texte) ---------- */

function ens_messages(): array
{
    return [
        // Évaluations
        'eval_creee'        => ['success', 'L’évaluation a été créée. Vous pouvez saisir les notes.'],
        'eval_modifiee'     => ['success', 'L’évaluation a été modifiée.'],
        'eval_supprimee'    => ['success', 'L’évaluation a été supprimée.'],
        'eval_invalide'     => ['err', 'Vérifiez le titre, la date, le barème (1 à 100), le coefficient (0,25 à 10) et la période.'],
        'eval_doublon'      => ['err', 'Une évaluation portant ce titre existe déjà pour cette classe et cette matière.'],
        'eval_affectation'  => ['err', 'Cette classe et cette matière ne vous sont pas affectées.'],
        'eval_introuvable'  => ['err', 'Évaluation introuvable. Rechargez la page.'],
        'eval_verrouillee'  => ['err', 'Cette évaluation est publiée : elle ne peut plus être modifiée ni supprimée.'],
        'bareme_trop_bas'   => ['err', 'Le nouveau barème est inférieur à une note déjà saisie.'],
        'eval_refusee'      => ['err', 'Modification refusée par les règles de la base de données.'],
        // Notes
        'notes_ok'          => ['success', 'Le brouillon des notes a été enregistré.'],
        'eval_publiee'      => ['success', 'Les résultats sont publiés. Les parents concernés ont été notifiés.'],
        'notes_invalides'   => ['err', 'Certaines notes sont invalides. Corrigez les champs en rouge : rien n’a été enregistré.'],
        'publication_vide'  => ['err', 'Impossible de publier : aucune note n’a été saisie.'],
        // Copies
        'copie_ok'          => ['success', 'La copie a été importée (brouillon). Publiez-la pour que le parent la voie.'],
        'copie_publiee'     => ['success', 'La copie est publiée.'],
        'copies_publiees'   => ['success', 'Les copies en brouillon ont été publiées.'],
        'copie_retiree'     => ['success', 'La copie a été retirée de la vue des parents (brouillon).'],
        'copie_supprimee'   => ['success', 'La copie a été supprimée.'],
        'copie_introuvable' => ['err', 'Copie introuvable. Rechargez la page.'],
        'copie_eleve'       => ['err', 'Cet élève n’appartient pas à la classe de l’évaluation.'],
        'copie_deja_publiee' => ['err', 'Une copie publiée existe déjà pour cet élève. Retirez-la d’abord.'],
        'copie_eval_non_publiee' => ['err', 'Publiez d’abord les résultats de l’évaluation : une copie ne peut pas être vue avant la note.'],
        'copie_a_retirer'   => ['err', 'Retirez la copie (brouillon) avant de la supprimer.'],
        'fichier_absent'    => ['err', 'Choisissez un fichier.'],
        'fichier_trop_gros' => ['err', 'Fichier trop volumineux : 5 Mo maximum.'],
        'fichier_format'    => ['err', 'Format refusé : PDF, JPG ou PNG uniquement (le contenu doit correspondre à l’extension).'],
        'fichier_invalide'  => ['err', 'Le téléversement a échoué. Réessayez.'],
        'stockage'          => ['err', 'Le dossier de stockage des copies est inaccessible. Contactez l’administrateur.'],
        // Communs
        'annee_absente'     => ['warn', 'Aucune année scolaire active. Contactez l’administrateur.'],
        'action_inconnue'   => ['err', 'Action inconnue.'],
        'erreur'            => ['err', 'Une erreur est survenue. Consultez les journaux PHP.'],
    ];
}

/* ---------- Menu latéral ---------- */

function ens_sidebar(string $active): void
{
    $items = [
        'tableau-de-bord' => ['tableau-de-bord.php', 'Tableau de bord'],
        'evaluations'     => ['evaluations.php', 'Évaluations'],
        'notes'           => ['notes.php', 'Saisie des notes'],
        'copies'          => ['copies.php', 'Copies corrigées'],
    ];
    echo '<aside class="sidebar" id="menu">' . "\n";
    echo '<div class="brand">SchoolConnect</div>' . "\n";
    echo '<div class="role">Enseignant</div>' . "\n";
    echo '<nav class="nav" aria-label="Navigation principale">' . "\n";
    foreach ($items as $cle => [$href, $libelle]) {
        $courant = $cle === $active ? ' aria-current="page"' : '';
        echo '<a href="' . e($href) . '"' . $courant . '>' . e($libelle) . '</a>' . "\n";
    }
    echo '</nav>' . "\n";
    echo '<a class="logout" href="../auth/logout.php">Se déconnecter</a>' . "\n";
    echo '</aside>' . "\n";
}