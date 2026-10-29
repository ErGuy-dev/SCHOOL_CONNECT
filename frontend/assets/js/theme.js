
/**
 * SchoolConnect — Gestion du thème global
 *
 * Thèmes disponibles : light, dark, system.
 */
(() => {
    const appliquerTheme = (theme) => {
        const racine = document.documentElement;

        racine.classList.remove('theme-light', 'theme-dark');

        if (theme === 'system') {
            theme = window.matchMedia('(prefers-color-scheme: dark)').matches
                ? 'dark'
                : 'light';
        }

        racine.classList.add(`theme-${theme}`);
        racine.dataset.theme = theme;
    };

    // Le thème est fourni par PHP dans l'attribut data-theme-server.
    const themeServeur = document.documentElement.dataset.themeServer;

    if (['light', 'dark', 'system'].includes(themeServeur)) {
        appliquerTheme(themeServeur);
    } else {
        appliquerTheme('light');
    }

    // Mettre à jour automatiquement le thème si le système change.
    const preferenceSysteme = window.matchMedia(
        '(prefers-color-scheme: dark)'
    );

    preferenceSysteme.addEventListener('change', () => {
        if (document.documentElement.dataset.themeServer === 'system') {
            appliquerTheme('system');
        }
    });
})();
