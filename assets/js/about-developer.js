/* ==========================================================================
   SmartRoll — About the Developer certificate lightbox (login page only)
   ========================================================================== */
(function () {
    "use strict";

    var lightbox = document.getElementById("certificateLightbox");
    if (!lightbox) return;

    var mediaTarget = lightbox.querySelector("[data-cert-lightbox-media]");
    var titleTarget = lightbox.querySelector("[data-cert-lightbox-title]");
    var descTarget = lightbox.querySelector("[data-cert-lightbox-desc]");
    var closeButton = lightbox.querySelector("[data-cert-lightbox-close]");
    var lastFocused = null;

    function openLightbox(card) {
        if (!card || !mediaTarget) return;

        var source = card.querySelector("[data-cert-media]");
        mediaTarget.innerHTML = source ? source.innerHTML : "";

        var title = card.getAttribute("data-cert-title") || "";
        var description = card.getAttribute("data-cert-description") || "";

        if (titleTarget) titleTarget.textContent = title;
        if (descTarget) {
            descTarget.textContent = description;
            descTarget.hidden = description === "";
        }

        lastFocused = document.activeElement;
        lightbox.classList.add("open");
        lightbox.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";

        if (closeButton) closeButton.focus();
    }

    function closeLightbox() {
        lightbox.classList.remove("open");
        lightbox.setAttribute("aria-hidden", "true");

        if (!document.querySelector(".modal-scrim.open")) {
            document.body.style.overflow = "";
        }

        if (lastFocused && typeof lastFocused.focus === "function") {
            lastFocused.focus();
        }
        lastFocused = null;
    }

    document.querySelectorAll("[data-certificate-open]").forEach(function (button) {
        button.addEventListener("click", function () {
            openLightbox(button.closest(".cert-card"));
        });
    });

    if (closeButton) closeButton.addEventListener("click", closeLightbox);

    lightbox.addEventListener("click", function (event) {
        if (event.target === lightbox) closeLightbox();
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && lightbox.classList.contains("open")) {
            event.stopPropagation();
            closeLightbox();
        }
    }, true);
})();
