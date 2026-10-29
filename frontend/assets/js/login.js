
"use strict";

// =====================================================
// SchoolConnect - Connexion
// Fichier : frontend/assets/js/login.js
// =====================================================

// 1. Récupérer les éléments du formulaire HTML.
const form = document.getElementById("form");
const emailInput = document.getElementById("email");
const passwordInput = document.getElementById("pwd");
const message = document.getElementById("message");

// Vérifier que les éléments HTML existent.
if (!form || !emailInput || !passwordInput || !message) {
    console.error(
        "SchoolConnect : formulaire de connexion incomplet. " +
        "Vérifiez les identifiants HTML : form, email, pwd et message."
    );
} else {
    const submitButton = form.querySelector('button[type="submit"]');

    // 2. Afficher un message.
    function afficherMessage(texte, type = "err") {
        message.textContent = texte;
        message.className = `mssg ${type}`;
        message.hidden = false;
    }

    // 3. Masquer le message.
    function masquerMessage() {
        message.textContent = "";
        message.hidden = true;
        message.className = "mssg err";
    }

    // 4. Vérifier le format de l'adresse e-mail.
    function emailValide(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    // 5. Effacer les messages pendant la saisie.
    emailInput.addEventListener("input", masquerMessage);
    passwordInput.addEventListener("input", masquerMessage);

    // 6. Traiter l'envoi du formulaire.
    form.addEventListener("submit", async function (event) {
        event.preventDefault();

        const email = emailInput.value.trim();
        const password = passwordInput.value;

        if (email === "" || password === "") {
            afficherMessage("Veuillez remplir tous les champs.");
            return;
        }

        if (!emailValide(email)) {
            afficherMessage(
                "Veuillez saisir une adresse e-mail valide."
            );
            emailInput.focus();
            return;
        }

        // Empêcher les envois multiples.
        submitButton.disabled = true;
        const ancienTexte = submitButton.textContent;
        submitButton.textContent = "Connexion en cours...";
        masquerMessage();

        try {
            // IMPORTANT : frontend/auth/ vers backend/auth/
           const response = await fetch("../../backend/auth/login.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8"
                },
                body: new URLSearchParams({
                    email: email,
                    password: password
                }),
                credentials: "same-origin"
            });

            // Lire la réponse du serveur.
            const resultat = await response.json();

            if (!response.ok || !resultat.success) {
                afficherMessage(
                    resultat.message || "La connexion a échoué."
                );
                return;
            }

            if (
                typeof resultat.redirect !== "string" ||
                resultat.redirect.length === 0
            ) {
                afficherMessage(
                    "La connexion a réussi, mais la destination est invalide."
                );
                return;
            }

            afficherMessage(
                resultat.message || "Connexion réussie.",
                "success"
            );

            // Rediriger vers le tableau de bord.
            window.location.assign(resultat.redirect);

        } catch (erreur) {
            console.error(
                "Erreur de connexion à SchoolConnect :",
                erreur
            );

            afficherMessage(
                "Impossible de contacter le serveur. " +
                "Vérifiez que XAMPP est démarré et réessayez."
            );

        } finally {
            submitButton.disabled = false;
            submitButton.textContent = ancienTexte;
        }
    });
}
