
(() => {
    'use strict';

    // Ne pas afficher le bouton sur les pages publiques.
    const path = window.location.pathname;

    if (
        path.includes('/auth/') ||
        path.endsWith('/connexion.html') ||
        path.endsWith('/login.html')
    ) {
        return;
    }

    // Afficher uniquement sur les pages privées qui l'autorisent.
    if (document.body.dataset.chatEnabled !== 'true') {
        return;
    }

    // Éviter de créer plusieurs boutons sur la même page.
    if (document.querySelector('.chat-global-button')) {
        return;
    }

    // Créer le lien vers la messagerie.
    const link = document.createElement('a');

    link.href = '/SCHOOL_CONNECT/frontend/chat/index.php';
    link.className = 'chat-global-button';
    link.setAttribute('aria-label', 'Ouvrir la messagerie');
    link.title = 'Messagerie SchoolConnect';

    // Icône de discussion et badge du compteur.
    link.innerHTML = `
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path
                d="M21 11.5a8.4 8.4 0 0 1-.9 3.8
                8.5 8.5 0 0 1-7.6 4.7
                8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7
                a8.4 8.4 0 0 1-.9-3.8
                8.5 8.5 0 0 1 4.7-7.6
                8.4 8.4 0 0 1 3.8-.9h.5
                a8.5 8.5 0 0 1 8 8v.5z"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>

        <span class="chat-global-label">Chat</span>

        <span
            class="chat-global-badge"
            id="chatUnreadBadge"
            hidden
            aria-live="polite"
        >0</span>
    `;

    document.body.appendChild(link);

    // Adresse de l'API qui compte les messages non lus.
    const apiUrl =
        '/SCHOOL_CONNECT/backend/api/chat/non-lus.php';

    const badge = link.querySelector('#chatUnreadBadge');

    let previousCount = 0;
    let requestInProgress = false;

    async function updateUnreadCount() {
        if (requestInProgress) return;

        // Ne pas effectuer de requête si la page est masquée.
        if (document.hidden) return;

        requestInProgress = true;

        try {
            const response = await fetch(apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (response.status === 401 ||
                response.status === 403) {
                badge.hidden = true;
                return;
            }

            if (!response.ok) {
                throw new Error('Erreur HTTP ' + response.status);
            }

            const data = await response.json();

            if (data.success !== true ||
                !Number.isInteger(Number(data.unreadCount))) {
                throw new Error('Réponse API invalide');
            }

            const count = Math.max(
                0,
                Number(data.unreadCount)
            );

            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = count === 0;

            link.setAttribute(
                'aria-label',
                count > 0
                    ? `Ouvrir la messagerie, ${count} message(s) non lu(s)`
                    : 'Ouvrir la messagerie'
            );

            // Petit effet visuel à la réception de nouveaux messages.
            if (count > previousCount) {
                badge.classList.remove('chat-badge-pop');

                // Redémarrer l'animation CSS.
                void badge.offsetWidth;

                badge.classList.add('chat-badge-pop');
            }

            previousCount = count;

        } catch (error) {
            // Une erreur temporaire ne doit pas bloquer la page.
            console.error(
                'Impossible de récupérer les messages non lus.',
                error
            );
        } finally {
            requestInProgress = false;
        }
    }

    // Premier comptage, puis actualisation périodique.
    updateUnreadCount();

    const refreshTimer = window.setInterval(
        updateUnreadCount,
        5000
    );

    // Actualiser dès que l'utilisateur revient sur la page.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            updateUnreadCount();
        }
    });

    // Nettoyer le timer lorsque la page est quittée.
    window.addEventListener('pagehide', () => {
        window.clearInterval(refreshTimer);
    });
})();
