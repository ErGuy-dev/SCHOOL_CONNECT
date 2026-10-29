<?php
declare(strict_types=1);

/**
 * =========================================================
 * SchoolConnect - Gestion centralisée des paramètres
 * Fichier : backend/includes/settings.php
 * Compatible : PHP 8+
 * =========================================================
 *
 * Utilisation :
 * require_once __DIR__ . '/settings.php';
 *
 * $parametres = school_settings();
 * $theme = school_setting('theme', 'light');
 */

// =========================================================
// 1. CHARGER LA CONNEXION À LA BASE DE DONNÉES
// =========================================================
require_once __DIR__ . '/../config/database.php';

// =========================================================
// 2. VALEURS PAR DÉFAUT
// =========================================================
function school_setting_defaults(): array
{
    return [
        'school_name' => 'SchoolConnect',
        'theme' => 'light',
        'auto_backup_enabled' => false,
        'auto_backup_frequency' => 'daily',
        'auto_backup_time' => '02:00',
        'backup_retention_days' => 30,
        'two_factor_enabled' => false,
        'session_timeout_minutes' => 60,
        'max_login_attempts' => 5,
        'login_lockout_minutes' => 15,
        'notifications_enabled' => true,
    ];
}

// =========================================================
// 3. CONVERSION DES VALEURS MYSQL VERS LE BON TYPE PHP
// =========================================================
function school_setting_convert(string $cle, mixed $valeur): mixed
{
    $valeur = (string) $valeur;

    switch ($cle) {
        case 'auto_backup_enabled':
        case 'two_factor_enabled':
        case 'notifications_enabled':
            return in_array($valeur, ['1', 'true', 'yes'], true);

        case 'backup_retention_days':
            return max(1, min(3650, (int) $valeur));

        case 'session_timeout_minutes':
            return max(5, min(1440, (int) $valeur));

        case 'max_login_attempts':
            return max(1, min(20, (int) $valeur));

        case 'login_lockout_minutes':
            return max(1, min(1440, (int) $valeur));

        case 'theme':
            return in_array($valeur, ['light', 'dark', 'system'], true)
                ? $valeur
                : 'light';

        case 'auto_backup_frequency':
            return in_array($valeur, ['daily', 'weekly'], true)
                ? $valeur
                : 'daily';

        case 'auto_backup_time':
            return preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $valeur)
                ? $valeur
                : '02:00';

        case 'school_name':
            $nom = trim($valeur);
            return $nom !== '' ? mb_substr($nom, 0, 150) : 'SchoolConnect';

        default:
            return $valeur;
    }
}

// =========================================================
// 4. LIRE TOUS LES PARAMÈTRES
// =========================================================
function school_settings(bool $recharger = false): array
{
    static $cache = null;

    if ($cache !== null && !$recharger) {
        return $cache;
    }

    $cache = school_setting_defaults();

    try {
        $pdo = db();

        $requete = $pdo->query(
            'SELECT setting_key, setting_value
             FROM system_settings'
        );

        foreach ($requete->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $cle = (string) $ligne['setting_key'];

            // Ignorer les clés inconnues.
            if (!array_key_exists($cle, $cache)) {
                continue;
            }

            $cache[$cle] = school_setting_convert(
                $cle,
                $ligne['setting_value']
            );
        }
    } catch (Throwable $e) {
        // Ne jamais exposer les détails techniques à l'utilisateur.
        error_log(
            'SchoolConnect - Lecture des paramètres : '
            . $e->getMessage()
        );

        // Les valeurs par défaut restent disponibles.
    }

    return $cache;
}

// =========================================================
// 5. LIRE UN SEUL PARAMÈTRE
// =========================================================
function school_setting(string $cle, mixed $defaut = null): mixed
{
    $parametres = school_settings();

    if (array_key_exists($cle, $parametres)) {
        return $parametres[$cle];
    }

    return $defaut;
}

// =========================================================
// 6. VÉRIFIER SI UNE OPTION EST ACTIVÉE
// =========================================================
function school_setting_enabled(string $cle): bool
{
    $valeur = school_setting($cle, false);

    return $valeur === true || $valeur === 1 || $valeur === '1';
}
