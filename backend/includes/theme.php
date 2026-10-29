<?php
declare(strict_types=1);

// Charger les paramètres globaux de SchoolConnect.
require_once __DIR__ . '/settings.php';

// Récupérer le thème choisi par l'administrateur.
$themeGlobal = school_setting('theme', 'light');

// Sécuriser la valeur du thème.
if (!in_array($themeGlobal, ['light', 'dark', 'system'], true)) {
    $themeGlobal = 'light';
}
