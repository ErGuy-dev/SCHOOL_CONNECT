
'use strict';

(() => {
    const config = window.SCHOOL_CHAT;
    const $ = (selector) => document.querySelector(selector);

    const conversationList = $('#conversation-list');
    const contactList = $('#contact-list');
    const messageList = $('#message-list');
    const contactSearch = $('#contact-search');
    const messageForm = $('#message-form');
    const messageInput = $('#message-input');
    const sendButton = $('#send-button');
    const sidebarStatus = $('#sidebar-status');
    const chatError = $('#chat-error');

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');

    if (!config || !csrfMeta) {
        console.error('Configuration SchoolConnect ou jeton CSRF introuvable.');
        return;
    }

    let csrf = csrfMeta.content;

    let contacts = [];
    let conversations = [];
    let activeConversationId = 0;
    let activeContactName = '';
    let activeContactRole = '';

    let lastRenderedMessageId = 0;
    let loadingMessages = false;
    let pendingMessage = false;

    // Variables de connexion WebSocket.
    let socket = null;
    let socketConnecting = false;
    let reconnectTimer = null;
    let reconnectDelay = 3000;
    let pageIsClosing = false;

    const roles = {
        administrateur: 'Administrateur',
        direction: 'Direction',
        enseignant: 'Enseignant',
        parent: 'Parent'
    };

    // --------------------------------------------------
    // AFFICHAGE DES ERREURS
    // --------------------------------------------------

    function showError(message) {
        chatError.textContent = message;
        chatError.hidden = false;
    }

    function clearError() {
        chatError.textContent = '';
        chatError.hidden = true;
    }

    // --------------------------------------------------
    // FONCTIONS UTILITAIRES
    // --------------------------------------------------

    function initials(name) {
        return String(name || '?')
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map(part => part.charAt(0).toUpperCase())
            .join('');
    }

    function formatDate(value) {
        if (!value) return '';

        const date = new Date(String(value).replace(' ', 'T'));

        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return date.toLocaleTimeString('fr-FR', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    // --------------------------------------------------
    // COMMUNICATION AVEC L'API PHP
    // --------------------------------------------------

    async function api(url, options = {}) {
        const response = await fetch(config.apiBase + url, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options
        });

        const data = await response.json().catch(() => null);

        if (!response.ok || !data || data.success !== true) {
            throw new Error(
                data?.message || 'Une erreur est survenue.'
            );
        }

        if (data.csrf) {
            csrf = data.csrf;
        }

        return data;
    }

    function postForm(values = {}) {
        const form = new FormData();

        form.append('csrf', csrf);

        Object.entries(values).forEach(([key, value]) => {
            form.append(key, String(value));
        });

        return {
            method: 'POST',
            body: form
        };
    }

    // --------------------------------------------------
    // LISTE DES CONTACTS ET CONVERSATIONS
    // --------------------------------------------------

    function createPersonButton(person, isConversation = false) {
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'person-item';

        if (
            isConversation &&
            Number(person.id) === activeConversationId
        ) {
            button.classList.add('active');
        }

        const personName = isConversation
            ? person.contact_nom
            : person.nom;

        const avatar = document.createElement('span');
        avatar.className = 'avatar';
        avatar.textContent = initials(personName);

        const content = document.createElement('span');
        content.className = 'person-content';

        const name = document.createElement('span');
        name.className = 'person-name';
        name.textContent = personName || 'Utilisateur';

        const preview = document.createElement('span');
        preview.className = 'person-preview';

        if (isConversation) {
            preview.textContent =
                person.dernier_message || 'Aucun message';
        } else {
            preview.textContent =
                roles[person.role] || person.role || '';
        }

        content.append(name, preview);
        button.append(avatar, content);

        if (isConversation && Number(person.non_lus) > 0) {
            const badge = document.createElement('span');

            badge.className = 'unread-badge';
            badge.textContent = String(person.non_lus);
            badge.setAttribute('aria-label', 'Messages non lus');

            button.append(badge);
        }

        button.addEventListener('click', () => {
            if (isConversation) {
                openConversation(
                    Number(person.id),
                    person.contact_nom,
                    ''
                );
            } else {
                startConversation(person);
            }
        });

        return button;
    }

    function renderSidebar() {
        const query = contactSearch.value
            .trim()
            .toLocaleLowerCase('fr');

        conversationList.replaceChildren();
        contactList.replaceChildren();

        const filteredConversations = conversations.filter(item =>
            String(item.contact_nom || '')
                .toLocaleLowerCase('fr')
                .includes(query)
        );

        const filteredContacts = contacts.filter(item =>
            String(item.nom || '')
                .toLocaleLowerCase('fr')
                .includes(query)
        );

        filteredConversations.forEach(item => {
            conversationList.append(
                createPersonButton(item, true)
            );
        });

        filteredContacts.forEach(person => {
            contactList.append(
                createPersonButton(person, false)
            );
        });

        if (!conversations.length) {
            conversationList.textContent =
                'Aucune conversation pour le moment.';
        } else if (!filteredConversations.length) {
            conversationList.textContent =
                'Aucune conversation correspondante.';
        }

        if (!filteredContacts.length) {
            contactList.textContent = query
                ? 'Aucun contact correspondant.'
                : 'Aucun autre utilisateur actif.';
        }
    }

    async function loadSidebar() {
        const data = await api('conversations.php');

        contacts = data.contacts || [];
        conversations = data.conversations || [];

        if (
            socket &&
            socket.readyState === WebSocket.OPEN
        ) {
            sidebarStatus.textContent =
                'Messagerie en temps réel connectée';
        } else {
            sidebarStatus.textContent =
                `${contacts.length} contact(s) disponible(s)`;
        }

        renderSidebar();
    }

    async function startConversation(person) {
        clearError();

        try {
            const data = await api(
                'conversations.php',
                postForm({ contact_id: person.id })
            );

            await loadSidebar();

            await openConversation(
                Number(data.conversation_id),
                person.nom,
                roles[person.role] || person.role
            );
        } catch (error) {
            showError(error.message);
        }
    }

    // --------------------------------------------------
    // OUVERTURE D'UNE CONVERSATION
    // --------------------------------------------------

    async function openConversation(id, name, role) {
        activeConversationId = id;
        activeContactName = name;
        activeContactRole = role || '';

        lastRenderedMessageId = 0;
        messageList.replaceChildren();

        $('#active-contact-name').textContent = name;

        $('#active-contact-role').textContent =
            activeContactRole || 'Conversation privée';

        messageInput.disabled = false;
        sendButton.disabled = false;

        clearError();
        renderSidebar();

        await loadMessages(true);
    }

    // --------------------------------------------------
    // AFFICHAGE DES MESSAGES
    // --------------------------------------------------

    function renderMessages(messages, initial = false) {
        if (initial) {
            messageList.replaceChildren();
            lastRenderedMessageId = 0;
        }

        const shouldScroll =
            initial ||
            messageList.scrollHeight -
            messageList.scrollTop -
            messageList.clientHeight < 100;

        let added = false;

        messages.forEach(message => {
            const id = Number(message.id);

            // Évite d'afficher plusieurs fois le même message.
            if (id <= lastRenderedMessageId) {
                return;
            }

            const bubble = document.createElement('article');

            bubble.className = 'message ' +
                (
                    Number(message.sender_id) === Number(config.userId)
                        ? 'mine'
                        : 'theirs'
                );

            const author = document.createElement('div');
            author.className = 'message-author';

            author.textContent =
                Number(message.sender_id) === Number(config.userId)
                    ? 'Vous'
                    : (message.sender_nom || 'Utilisateur');

            const text = document.createElement('p');
            text.className = 'message-content';

            // textContent empêche l'interprétation du contenu comme HTML.
            text.textContent = message.contenu || '';

            const time = document.createElement('time');
            time.className = 'message-time';
            time.textContent = formatDate(message.sent_at);

            bubble.append(author, text, time);
            messageList.append(bubble);

            lastRenderedMessageId = Math.max(
                lastRenderedMessageId,
                id
            );

            added = true;
        });

        if (!messages.length && initial) {
            const empty = document.createElement('div');

            empty.className = 'empty-state';
            empty.textContent =
                'Écrivez le premier message de cette conversation.';

            messageList.append(empty);
        }

        if (shouldScroll && added) {
            messageList.scrollTop = messageList.scrollHeight;
        }
    }

    // --------------------------------------------------
    // CHARGEMENT ET LECTURE DES MESSAGES
    // --------------------------------------------------

    async function markRead(conversationId) {
        if (!conversationId) return;

        await api(
            'marquer-lu.php',
            postForm({ conversation_id: conversationId })
        );
    }

    async function loadMessages(initial = false) {
        if (!activeConversationId || loadingMessages) {
            return;
        }

        loadingMessages = true;

        const requestedConversation = activeConversationId;

        try {
            const data = await api(
                'messages.php?conversation_id=' +
                encodeURIComponent(requestedConversation)
            );

            // Ignore les résultats d'une ancienne conversation.
            if (requestedConversation !== activeConversationId) {
                return;
            }

            renderMessages(data.messages || [], initial);

            await markRead(requestedConversation);

            // Ne rafraîchit pas inutilement la liste si
            // l'utilisateur a changé de conversation entre-temps.
            if (requestedConversation === activeConversationId) {
                await loadSidebar();
            }
        } catch (error) {
            showError(error.message);
        } finally {
            loadingMessages = false;
        }
    }

    // --------------------------------------------------
    // CONNEXION WEBSOCKET
    // --------------------------------------------------

    async function connectWebSocket() {
        if (pageIsClosing || socketConnecting) {
            return;
        }

        if (
            socket &&
            (
                socket.readyState === WebSocket.OPEN ||
                socket.readyState === WebSocket.CONNECTING
            )
        ) {
            return;
        }

        socketConnecting = true;

        try {
            // Le ticket est temporaire et à usage unique.
            const data = await api(
                'ws-ticket.php',
                postForm({})
            );

            if (pageIsClosing) {
                socketConnecting = false;
                return;
            }

            socket = new WebSocket(
                data.websocketUrl +
                '?ticket=' +
                encodeURIComponent(data.ticket)
            );

            socket.addEventListener('open', () => {
                socketConnecting = false;
                reconnectDelay = 3000;

                sidebarStatus.textContent =
                    'Messagerie en temps réel connectée';

                clearTimeout(reconnectTimer);
                clearError();
            });

            socket.addEventListener('message', async event => {
                let message;

                try {
                    message = JSON.parse(event.data);
                } catch {
                    return;
                }

                switch (message.type) {
                    case 'connected':
                        sidebarStatus.textContent =
                            'Messagerie en temps réel connectée';
                        break;

                    case 'new_message':
                        // Le serveur annonce qu'un message a été enregistré.
                        if (
                            activeConversationId &&
                            Number(message.conversation_id) ===
                                Number(activeConversationId)
                        ) {
                            await loadMessages();
                        } else {
                            await loadSidebar();
                        }
                        break;

                    case 'message_created':
                        // Notification diffusée après l'enregistrement
                        // par envoyer.php.
                        if (
                            activeConversationId &&
                            Number(message.conversation_id) ===
                                Number(activeConversationId)
                        ) {
                            await loadMessages();
                        }

                        await loadSidebar();
                        break;

                    case 'message_sent':
                        // Compatible avec le protocole Ratchet existant.
                        pendingMessage = false;
                        sendButton.disabled = !activeConversationId;
                        messageInput.value = '';

                        await loadMessages();
                        await loadSidebar();
                        break;

                    case 'error':
                        pendingMessage = false;
                        sendButton.disabled = !activeConversationId;

                        showError(
                            message.message ||
                            'Le serveur WebSocket a rencontré une erreur.'
                        );
                        break;

                    default:
                        // Les autres événements ne sont pas traités ici.
                        break;
                }
            });

            socket.addEventListener('close', () => {
                socketConnecting = false;

                if (pageIsClosing) return;

                sidebarStatus.textContent =
                    'Connexion interrompue. Reconnexion…';

                scheduleReconnect();
            });

            socket.addEventListener('error', () => {
                sidebarStatus.textContent =
                    'Connexion WebSocket interrompue';
            });
        } catch (error) {
            socketConnecting = false;

            showError(
                'Connexion WebSocket impossible : ' + error.message
            );

            scheduleReconnect();
        }
    }

    function scheduleReconnect() {
        if (pageIsClosing) return;

        clearTimeout(reconnectTimer);

        reconnectTimer = window.setTimeout(() => {
            connectWebSocket();
        }, reconnectDelay);

        reconnectDelay = Math.min(reconnectDelay * 2, 15000);
    }

    /**
     * Signale au serveur qu'un message a déjà été enregistré par PHP.
     *
     * Cette fonction ne fonctionne que si ChatServer.php prend en charge
     * le type "message_created". Elle ne crée pas de message en base.
     */
    function notifyWebSocketMessageCreated(
        conversationId,
        messageId
    ) {
        if (
            !socket ||
            socket.readyState !== WebSocket.OPEN
        ) {
            return false;
        }

        socket.send(JSON.stringify({
            type: 'message_created',
            conversation_id: Number(conversationId),
            message_id: Number(messageId)
        }));

        return true;
    }

    // --------------------------------------------------
    // ENVOI D'UN MESSAGE
    // --------------------------------------------------

    messageForm.addEventListener('submit', async event => {
        event.preventDefault();

        const contenu = messageInput.value.trim();

        if (
            !activeConversationId ||
            !contenu ||
            pendingMessage
        ) {
            return;
        }

        const conversationId = activeConversationId;

        pendingMessage = true;
        sendButton.disabled = true;
        clearError();

        try {
            // 1. PHP enregistre le message une seule fois.
            const data = await api(
                'envoyer.php',
                postForm({
                    conversation_id: conversationId,
                    contenu
                })
            );

            messageInput.value = '';

            // 2. WebSocket annonce le message déjà enregistré.
            // Si la connexion est momentanément coupée, le message
            // reste enregistré et sera récupéré par l'API.
            if (data.message_id) {
                notifyWebSocketMessageCreated(
                    conversationId,
                    data.message_id
                );
            }

            // 3. Affichage immédiat du message envoyé.
            if (conversationId === activeConversationId) {
                await loadMessages();
            }

            await loadSidebar();
        } catch (error) {
            showError(error.message);
        } finally {
            pendingMessage = false;
            sendButton.disabled = !activeConversationId;
            messageInput.focus();
        }
    });

    // Entrée envoie ; Maj + Entrée ajoute une nouvelle ligne.
    messageInput.addEventListener('keydown', event => {
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.isComposing
        ) {
            event.preventDefault();
            messageForm.requestSubmit();
        }
    });

    // --------------------------------------------------
    // RECHERCHE ET ACTUALISATION MANUELLE
    // --------------------------------------------------

    contactSearch.addEventListener('input', renderSidebar);

    $('#refresh-button').addEventListener('click', async () => {
        try {
            await loadSidebar();
            await loadMessages();
            clearError();
        } catch (error) {
            showError(error.message);
        }
    });

    async function refreshSidebarSafely() {
        try {
            await loadSidebar();
        } catch (error) {
            showError(error.message);
        }
    }

    // --------------------------------------------------
    // INITIALISATION
    // --------------------------------------------------

    refreshSidebarSafely();
    connectWebSocket();

    // Actualisation de secours pendant les premiers tests.
    // Elle sera supprimable une fois le temps réel validé.
    window.setInterval(() => {
        if (!document.hidden) {
            loadMessages();
        }
    }, 3000);

    window.setInterval(() => {
        if (!document.hidden) {
            refreshSidebarSafely();
        }
    }, 10000);

    // Nettoyage lors de la fermeture de la page.
    window.addEventListener('pagehide', () => {
        pageIsClosing = true;
        clearTimeout(reconnectTimer);

        if (socket) {
            socket.close();
        }
    });
})();
