
(() => {
    'use strict';

    const app = document.getElementById('notifications-app');

    // Ne rien faire si le composant n'est pas présent sur cette page.
    if (!app) return;

    const apiBase = app.dataset.apiBase.replace(/\/+$/, '');

    const toggle = document.getElementById('notifications-toggle');
    const panel = document.getElementById('notifications-panel');
    const count = document.getElementById('notifications-count');
    const list = document.getElementById('notifications-list');
    const status = document.getElementById('notifications-status');
    const refresh = document.getElementById('notifications-refresh');

    let csrfToken = '';
    let loading = false;

    const typeLabels = {
        info: 'Information',
        succes: 'Succès',
        avertissement: 'Avertissement',
        erreur: 'Erreur'
    };

    async function apiFetch(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options
        });

        const data = await response.json().catch(() => null);

        if (!response.ok || !data) {
            const message = data?.message
                || `Erreur HTTP ${response.status}`;
            throw new Error(message);
        }

        if (data.success !== true) {
            throw new Error(data.message || 'Une erreur est survenue.');
        }

        return data;
    }

    function formatDate(value) {
        if (!value) return '';

        // MySQL renvoie généralement une date sous la forme YYYY-MM-DD HH:mm:ss.
        const date = new Date(value.replace(' ', 'T'));

        if (Number.isNaN(date.getTime())) return value;

        return new Intl.DateTimeFormat('fr-FR', {
            dateStyle: 'short',
            timeStyle: 'short'
        }).format(date);
    }

    function updateCount(number) {
        const total = Number(number) || 0;

        count.textContent = total > 99 ? '99+' : String(total);
        count.hidden = total === 0;

        toggle.setAttribute(
            'aria-label',
            `Notifications, ${total} non lue${total > 1 ? 's' : ''}`
        );
    }

    async function loadCount() {
        const data = await apiFetch(`${apiBase}/compteur.php`);
        updateCount(data.non_lues);
    }

    function createNotificationElement(notification) {
        const item = document.createElement('li');
        item.className = 'notification-item';

        if (Number(notification.lu) === 0) {
            item.classList.add('unread');
        }

        const type = document.createElement('span');
        type.className = 'notification-type';
        type.textContent = typeLabels[notification.type] || 'Notification';

        const title = document.createElement('h3');
        title.className = 'notification-title';
        title.textContent = notification.titre || 'Notification';

        const message = document.createElement('p');
        message.className = 'notification-message';
        message.textContent = notification.message || '';

        const date = document.createElement('time');
        date.className = 'notification-date';
        date.textContent = formatDate(notification.cree_le);

        item.append(type, title, message, date);

        const actions = document.createElement('div');
        actions.className = 'notification-actions';

        // Le lien est facultatif. On refuse les protocoles dangereux.
        if (notification.lien) {
            try {
                const target = new URL(notification.lien, window.location.href);

                if (
                    ['http:', 'https:'].includes(target.protocol) &&
                    target.origin === window.location.origin
                ) {
                    const link = document.createElement('a');
                    link.href = target.href;
                    link.textContent = 'Consulter';
                    actions.appendChild(link);
                }
            } catch {
                // Un lien invalide est ignoré.
            }
        }

        if (Number(notification.lu) === 0) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = 'Marquer comme lue';

            button.addEventListener('click', async () => {
                button.disabled = true;

                try {
                    if (!csrfToken) {
                        throw new Error(
                            'Jeton de sécurité absent. Actualisez la page.'
                        );
                    }

                    const body = new URLSearchParams();
                    body.set('id', String(notification.id));
                    body.set('csrf', csrfToken);

                    await apiFetch(`${apiBase}/marque-lue.php`, {
                        method: 'POST',
                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded;charset=UTF-8'
                        },
                        body: body.toString()
                    });

                    await loadNotifications();
                } catch (error) {
                    status.textContent = error.message;
                    button.disabled = false;
                }
            });

            actions.appendChild(button);
        } else {
            const readLabel = document.createElement('span');
            readLabel.textContent = 'Déjà lue';
            actions.appendChild(readLabel);
        }

        item.appendChild(actions);
        return item;
    }

    async function loadNotifications() {
        if (loading) return;
        loading = true;

        status.textContent = 'Chargement des notifications...';
        list.replaceChildren();

        try {
            const data = await apiFetch(`${apiBase}/liste.php`);

            // Le jeton est fourni par liste.php pour les requêtes POST.
            csrfToken = data.csrf || '';

            const notifications = Array.isArray(data.notifications)
                ? data.notifications
                : [];

            if (notifications.length === 0) {
                status.textContent = 'Vous n’avez aucune notification.';
            } else {
                status.textContent = '';
                const fragment = document.createDocumentFragment();

                notifications.forEach((notification) => {
                    fragment.appendChild(
                        createNotificationElement(notification)
                    );
                });

                list.appendChild(fragment);
            }

            await loadCount();
        } catch (error) {
            status.textContent = error.message
                || 'Impossible de charger les notifications.';
        } finally {
            loading = false;
        }
    }

    toggle.addEventListener('click', async () => {
        const opening = panel.hidden;

        panel.hidden = !opening;
        toggle.setAttribute('aria-expanded', String(opening));

        if (opening) {
            await loadNotifications();
        }
    });

    refresh.addEventListener('click', loadNotifications);

    // Actualiser périodiquement le compteur sans recharger la page.
    loadCount().catch((error) => {
        console.error('Compteur des notifications :', error);
    });

    window.setInterval(() => {
        loadCount().catch((error) => {
            console.error('Compteur des notifications :', error);
        });
    }, 60000);
})();
