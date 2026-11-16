/* ==========================================================================
   SmartRoll — shared UI behaviour
   Pure vanilla JavaScript. No frameworks.
   ========================================================================== */
(function () {
    "use strict";

    /* ----------------------------------------------------------------------
       Theme (light default, optional dark mode)
    ---------------------------------------------------------------------- */
    var root = document.documentElement;
    var themeToggle = document.querySelector("[data-theme-toggle]");

    function currentTheme() {
        return root.getAttribute("data-theme") === "dark" ? "dark" : "light";
    }

    function syncThemeButton() {
        if (!themeToggle) return;
        var dark = currentTheme() === "dark";
        var icon = themeToggle.querySelector(".material-icons-round");
        if (icon) icon.textContent = dark ? "light_mode" : "dark_mode";
        themeToggle.setAttribute("aria-label", dark ? "Switch to light theme" : "Switch to dark theme");
        themeToggle.setAttribute("title", dark ? "Light mode" : "Dark mode");
    }

    if (themeToggle) {
        syncThemeButton();
        themeToggle.addEventListener("click", function () {
            var next = currentTheme() === "dark" ? "light" : "dark";
            root.setAttribute("data-theme", next);
            try {
                localStorage.setItem("smartroll-theme", next);
            } catch (e) { /* storage may be unavailable */ }
            syncThemeButton();
        });
    }

    /* ----------------------------------------------------------------------
       Navigation drawer
    ---------------------------------------------------------------------- */
    var sidebar = document.getElementById("sidebar");
    var drawerToggle = document.getElementById("drawerToggle");
    var drawerScrim = document.getElementById("drawerScrim");
    var mobileQuery = window.matchMedia("(max-width: 1080px)");

    function closeDrawer() {
        if (sidebar) sidebar.classList.remove("open");
        if (drawerScrim) drawerScrim.classList.remove("show");
    }

    function toggleDrawer() {
        if (!sidebar) return;
        if (mobileQuery.matches) {
            var open = sidebar.classList.toggle("open");
            if (drawerScrim) drawerScrim.classList.toggle("show", open);
        } else {
            document.body.classList.toggle("sidebar-collapsed");
        }
    }

    if (drawerToggle) drawerToggle.addEventListener("click", toggleDrawer);
    if (drawerScrim) drawerScrim.addEventListener("click", closeDrawer);

    document.querySelectorAll(".sidebar .nav-link").forEach(function (link) {
        link.addEventListener("click", function () {
            if (mobileQuery.matches) closeDrawer();
        });
    });

    mobileQuery.addEventListener("change", closeDrawer);

    /* ----------------------------------------------------------------------
       Dropdown menus
    ---------------------------------------------------------------------- */
    function closeMenus(except) {
        document.querySelectorAll(".menu.show").forEach(function (menu) {
            if (menu !== except) menu.classList.remove("show");
        });
    }

    document.querySelectorAll("[data-menu-toggle]").forEach(function (toggle) {
        toggle.addEventListener("click", function (event) {
            event.stopPropagation();
            var dropdown = toggle.closest(".dropdown");
            var menu = dropdown ? dropdown.querySelector(".menu") : null;
            if (!menu) return;
            var willShow = !menu.classList.contains("show");
            closeMenus(menu);
            menu.classList.toggle("show", willShow);
            toggle.setAttribute("aria-expanded", willShow ? "true" : "false");
        });
    });

    document.addEventListener("click", function (event) {
        if (!event.target.closest(".dropdown")) closeMenus();
    });

    /* ----------------------------------------------------------------------
       Modals
    ---------------------------------------------------------------------- */
    var lastFocused = null;

    function syncBodyScrollLock() {
        var hasOpenModal = !!document.querySelector(".modal-scrim.open");
        document.body.classList.toggle("modal-open", hasOpenModal);
        document.body.style.overflow = hasOpenModal ? "hidden" : "";
    }

    function openModal(id, trigger) {
        var scrim = document.getElementById(id);
        if (!scrim) return;

        var form = scrim.querySelector("form");
        var record = null;

        if (trigger && trigger.dataset.record) {
            try {
                record = JSON.parse(trigger.dataset.record);
            } catch (e) {
                record = null;
            }
        }

        if (form) {
            form.reset();
            var modeField = form.querySelector("[data-modal-mode]");
            if (modeField) modeField.value = "create";
            var idField = form.querySelector("[data-modal-id]");
            if (idField) idField.value = "";
            var passwordField = form.querySelector("[data-optional-password]");
            if (passwordField) passwordField.required = false;
        }

        if (record) {
            Object.keys(record).forEach(function (key) {
                if (key.indexOf("_") === 0) return;
                if (!form) return;
                var field = form.querySelector('[name="' + key + '"]');
                if (!field) return;
                if (field.type === "checkbox") {
                    field.checked = !!record[key];
                } else if (field.type !== "file") {
                    field.value = record[key] === null ? "" : record[key];
                }
            });

            if (form) {
                var mode = form.querySelector("[data-modal-mode]");
                if (mode && record.mode) mode.value = record.mode;
                var idInput = form.querySelector("[data-modal-id]");
                if (idInput && record.id !== undefined) idInput.value = record.id;
                var pass = form.querySelector("[data-optional-password]");
                if (pass) pass.required = false;
            }

            scrim.querySelectorAll("[data-value]").forEach(function (el) {
                var key = el.getAttribute("data-value");
                if (key && Object.prototype.hasOwnProperty.call(record, key)) {
                    el.textContent = record[key] === null ? "—" : record[key];
                }
            });

            var titleEl = scrim.querySelector("[data-modal-title]");
            if (titleEl && record._title) titleEl.textContent = record._title;
            var subtitleEl = scrim.querySelector("[data-modal-subtitle]");
            if (subtitleEl && record._subtitle) subtitleEl.textContent = record._subtitle;
        } else {
            var addTitle = scrim.querySelector("[data-modal-title]");
            if (addTitle && scrim.dataset.titleAdd) addTitle.textContent = scrim.dataset.titleAdd;
            var addSubtitle = scrim.querySelector("[data-modal-subtitle]");
            if (addSubtitle && scrim.dataset.subtitleAdd) addSubtitle.textContent = scrim.dataset.subtitleAdd;
        }

        lastFocused = document.activeElement;
        var scrollBody = scrim.querySelector(".modal-body");
        if (scrollBody) scrollBody.scrollTop = 0;
        scrim.classList.add("open");
        scrim.setAttribute("aria-hidden", "false");
        syncBodyScrollLock();

        window.setTimeout(function () {
            var focusable = scrim.querySelector(
                "input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button"
            );
            if (focusable) focusable.focus();
        }, 140);
    }

    function closeModal(scrim) {
        if (!scrim) return;
        scrim.classList.remove("open");
        scrim.setAttribute("aria-hidden", "true");
        syncBodyScrollLock();
        if (lastFocused && typeof lastFocused.focus === "function") {
            lastFocused.focus();
            lastFocused = null;
        }
    }

    document.querySelectorAll("[data-modal-open]").forEach(function (trigger) {
        trigger.addEventListener("click", function () {
            openModal(trigger.dataset.modalOpen, trigger);
        });
    });

    document.querySelectorAll(".modal-scrim").forEach(function (scrim) {
        scrim.addEventListener("click", function (event) {
            if (event.target === scrim) closeModal(scrim);
        });
        scrim.querySelectorAll("[data-modal-close]").forEach(function (button) {
            button.addEventListener("click", function () {
                closeModal(scrim);
            });
        });
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            document.querySelectorAll(".modal-scrim.open").forEach(closeModal);
            closeMenus();
            closeDrawer();
        }
    });

    /* ----------------------------------------------------------------------
       Snackbar notifications
    ---------------------------------------------------------------------- */
    var snackbar = document.getElementById("snackbar");
    var snackbarTimer = null;

    var snackIcons = {
        success: "check_circle",
        error: "error",
        warning: "warning",
        info: "info"
    };

    function showSnackbar(message, type) {
        type = type || "success";
        if (!snackbar) {
            return;
        }

        var icon = snackbar.querySelector(".snackbar-icon");
        var text = snackbar.querySelector(".snackbar-text");
        if (icon) icon.textContent = snackIcons[type] || "info";
        if (text) text.textContent = message;

        snackbar.className = "snackbar " + type;
        window.clearTimeout(snackbarTimer);

        // restart animation
        void snackbar.offsetWidth;
        snackbar.classList.add("show");

        snackbarTimer = window.setTimeout(function () {
            snackbar.classList.remove("show");
        }, type === "error" ? 6000 : 4200);
    }

    window.showSnackbar = showSnackbar;

    if (snackbar) {
        var dismiss = snackbar.querySelector("[data-snackbar-close]");
        if (dismiss) {
            dismiss.addEventListener("click", function () {
                window.clearTimeout(snackbarTimer);
                snackbar.classList.remove("show");
            });
        }
    }

    /* ----------------------------------------------------------------------
       Confirmation dialog (Material)
    ---------------------------------------------------------------------- */
    var confirmResolver = null;
    var confirmScrim = null;

    function buildConfirmDialog() {
        if (confirmScrim) return confirmScrim;

        confirmScrim = document.createElement("div");
        confirmScrim.className = "modal-scrim";
        confirmScrim.setAttribute("aria-hidden", "true");
        confirmScrim.innerHTML =
            '<div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">' +
            '  <div class="modal-head">' +
            '    <div>' +
            '      <div class="confirm-icon"><span class="material-icons-round">delete_forever</span></div>' +
            '      <h2 id="confirmTitle">Are you sure?</h2>' +
            '    </div>' +
            '    <button type="button" class="modal-close" data-confirm-cancel aria-label="Close">' +
            '      <span class="material-icons-round">close</span>' +
            '    </button>' +
            '  </div>' +
            '  <div class="modal-body">' +
            '    <p class="confirm-copy" data-confirm-copy></p>' +
            '  </div>' +
            '  <div class="modal-actions">' +
            '    <button type="button" class="btn btn-text" data-confirm-cancel>Cancel</button>' +
            '    <button type="button" class="btn btn-danger" data-confirm-ok>Delete</button>' +
            '  </div>' +
            '</div>';

        document.body.appendChild(confirmScrim);

        confirmScrim.addEventListener("click", function (event) {
            if (event.target === confirmScrim) settle(false);
            if (event.target.closest("[data-confirm-cancel]")) settle(false);
            if (event.target.closest("[data-confirm-ok]")) settle(true);
        });

        return confirmScrim;
    }

    function settle(result) {
        if (!confirmScrim) return;
        confirmScrim.classList.remove("open");
        confirmScrim.setAttribute("aria-hidden", "true");
        syncBodyScrollLock();
        if (confirmResolver) {
            var resolve = confirmResolver;
            confirmResolver = null;
            resolve(result);
        }
    }

    function confirmDialog(options) {
        var scrim = buildConfirmDialog();
        options = options || {};

        var title = scrim.querySelector("#confirmTitle");
        var copy = scrim.querySelector("[data-confirm-copy]");
        var ok = scrim.querySelector("[data-confirm-ok]");
        var icon = scrim.querySelector(".confirm-icon .material-icons-round");
        var iconWrap = scrim.querySelector(".confirm-icon");

        if (title) title.textContent = options.title || "Are you sure?";
        if (copy) copy.textContent = options.message || "This action cannot be undone.";
        if (ok) {
            ok.textContent = options.confirmText || "Confirm";
            ok.className = "btn " + (options.danger === false ? "btn-filled" : "btn-danger");
        }
        if (icon) icon.textContent = options.icon || (options.danger === false ? "help" : "delete_forever");
        if (iconWrap) iconWrap.style.background = options.danger === false ? "var(--primary-container)" : "";
        if (iconWrap) iconWrap.style.color = options.danger === false ? "var(--primary)" : "";

        scrim.classList.add("open");
        scrim.setAttribute("aria-hidden", "false");
        syncBodyScrollLock();

        window.setTimeout(function () {
            if (ok) ok.focus();
        }, 120);

        return new Promise(function (resolve) {
            confirmResolver = resolve;
        });
    }

    window.smartrollConfirm = confirmDialog;

    /* Intercept forms with data-confirm */
    document.addEventListener("submit", function (event) {
        var form = event.target.closest("form[data-confirm]");
        if (!form || form.dataset.confirmed === "1") return;

        event.preventDefault();
        confirmDialog({
            title: form.dataset.confirmTitle || "Delete this record?",
            message: form.dataset.confirm,
            confirmText: form.dataset.confirmText || "Delete",
            danger: form.dataset.confirmDanger !== "false"
        }).then(function (ok) {
            if (ok) {
                form.dataset.confirmed = "1";
                form.submit();
            }
        });
    });

    /* Intercept links with data-confirm */
    document.querySelectorAll("a[data-confirm]").forEach(function (link) {
        link.addEventListener("click", function (event) {
            event.preventDefault();
            confirmDialog({
                title: link.dataset.confirmTitle || "Are you sure?",
                message: link.dataset.confirm,
                confirmText: link.dataset.confirmText || "Continue",
                danger: link.dataset.confirmDanger !== "false"
            }).then(function (ok) {
                if (ok) window.location.href = link.href;
            });
        });
    });

    /* ----------------------------------------------------------------------
       Password visibility toggles
    ---------------------------------------------------------------------- */
    document.querySelectorAll("[data-password-toggle]").forEach(function (button) {
        button.addEventListener("click", function () {
            var field = document.getElementById(button.dataset.passwordToggle);
            if (!field) return;
            var showing = field.type === "text";
            field.type = showing ? "password" : "text";
            var icon = button.querySelector(".material-icons-round");
            if (icon) icon.textContent = showing ? "visibility" : "visibility_off";
            button.setAttribute("aria-label", showing ? "Show password" : "Hide password");
        });
    });

    /* ----------------------------------------------------------------------
       Client-side table search
    ---------------------------------------------------------------------- */
    document.querySelectorAll("[data-table-search]").forEach(function (input) {
        var table = document.querySelector(input.dataset.tableSearch);
        if (!table) {
            var card = input.closest(".table-card");
            table = card ? card.querySelector("table") : null;
        }
        if (!table) return;

        var bodyRows = Array.prototype.slice.call(table.querySelectorAll("tbody tr"));
        var countEl = document.querySelector(input.dataset.countTarget || "");

        function applyFilter() {
            var query = input.value.trim().toLowerCase();
            var visible = 0;
            bodyRows.forEach(function (row) {
                var match = query === "" || row.textContent.toLowerCase().indexOf(query) !== -1;
                row.style.display = match ? "" : "none";
                if (match) visible++;
            });
            if (countEl) countEl.textContent = visible;
        }

        input.addEventListener("input", applyFilter);
    });

    /* ----------------------------------------------------------------------
       Auto-submit filter forms
    ---------------------------------------------------------------------- */
    document.querySelectorAll("form[data-auto-submit]").forEach(function (form) {
        form.querySelectorAll("select, input[type=date]").forEach(function (field) {
            field.addEventListener("change", function () {
                form.submit();
            });
        });
    });

    /* ----------------------------------------------------------------------
       Small helpers exposed for page scripts
    ---------------------------------------------------------------------- */
    window.smartroll = {
        debounce: function (fn, delay) {
            var timer;
            return function () {
                var args = arguments;
                var context = this;
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    fn.apply(context, args);
                }, delay || 200);
            };
        },
        escapeHtml: function (value) {
            return String(value)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
    };
})();
