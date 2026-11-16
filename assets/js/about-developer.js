/* ==========================================================================
   SmartRoll — About the Developer page behaviour
   Image placeholder fallbacks + certificate lightbox. Pure vanilla JavaScript.
   ========================================================================== */
(function () {
    "use strict";

    /* ----------------------------------------------------------------------
       Image placeholders
       Keeps the labelled placeholder visible until its image actually loads.
    ---------------------------------------------------------------------- */
    function syncImageState(image) {
        var wrapper = image.closest(".dev-image");
        if (!wrapper) return;

        if (image.complete && image.naturalWidth > 0) {
            wrapper.classList.add("has-image");
            wrapper.classList.remove("is-missing");
        } else if (image.complete) {
            wrapper.classList.add("is-missing");
            wrapper.classList.remove("has-image");
        }
    }

    document.querySelectorAll("[data-dev-image]").forEach(syncImageState);

    document.addEventListener("load", function (event) {
        if (event.target && event.target.matches && event.target.matches("[data-dev-image]")) {
            syncImageState(event.target);
        }
    }, true);

    document.addEventListener("error", function (event) {
        if (!event.target || !event.target.matches || !event.target.matches("[data-dev-image]")) return;
        var wrapper = event.target.closest(".dev-image");
        if (wrapper) {
            wrapper.classList.add("is-missing");
            wrapper.classList.remove("has-image");
        }
    }, true);

    /* ----------------------------------------------------------------------
       Technology logos
       If a local logo file fails to load, fall back to the Devicon CDN copy
       (needs an internet connection). Runs only once per image.
    ---------------------------------------------------------------------- */
    document.querySelectorAll("img[data-tech-logo]").forEach(function (img) {
        function useFallback() {
            var fallback = img.getAttribute("data-fallback");
            if (!fallback || img.dataset.fellBack) return;
            img.dataset.fellBack = "1";
            img.src = "https://cdn.jsdelivr.net/gh/devicons/devicon/icons/" + fallback;
        }

        img.addEventListener("error", useFallback);

        // Covers the case where the image already failed before this script ran.
        if (img.complete && img.naturalWidth === 0) useFallback();
    });

    /* ----------------------------------------------------------------------
       Certificate lightbox
    ---------------------------------------------------------------------- */
    var lightbox = document.getElementById("certificateLightbox");
    if (!lightbox) return;

    var mediaTarget = lightbox.querySelector("[data-cert-lightbox-media]");
    var titleTarget = lightbox.querySelector("[data-cert-lightbox-title]");
    var descTarget = lightbox.querySelector("[data-cert-lightbox-desc]");
    var closeButton = lightbox.querySelector("[data-cert-lightbox-close]");
    var lastFocused = null;

    function openLightbox(card) {
        var source = card ? card.querySelector("[data-cert-media]") : null;
        if (!source || !mediaTarget) return;

        var media = source.cloneNode(true);
        mediaTarget.replaceChildren(media);

        media.querySelectorAll("[data-dev-image]").forEach(syncImageState);

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

        if (lastFocused && lastFocused.isConnected && typeof lastFocused.focus === "function") {
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