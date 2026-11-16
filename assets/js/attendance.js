/* ==========================================================================
   SmartRoll — attendance marking page behaviour
   ========================================================================== */
(function () {
    "use strict";

    var form = document.querySelector("[data-attendance-form]");
    if (!form) return;

    var stateEl = document.getElementById("saveState");
    var stateText = document.getElementById("saveStateText");
    var saveButton = document.getElementById("saveAttendanceBtn");
    var dirty = false;
    var saving = false;
    var dateInput = form.querySelector('input[name="attendance_date"]');
    var attendanceDate = form.getAttribute("data-attendance-date") || (dateInput ? dateInput.value : "");

    function isWeekendDate(value) {
        var parts = String(value || "").split("-");
        if (parts.length !== 3) return false;

        var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        if (isNaN(date.getTime())) return false;

        var day = date.getDay();
        return day === 0 || day === 6;
    }

    function setState(text, tone, icon) {
        if (!stateEl || !stateText) return;
        stateEl.classList.remove("dirty", "saved");
        if (tone) stateEl.classList.add(tone);
        stateText.textContent = text;
        var iconEl = stateEl.querySelector(".material-icons-round");
        if (iconEl && icon) iconEl.textContent = icon;
    }

    function markDirty() {
        dirty = true;
        setState("Unsaved changes", "dirty", "edit_note");
    }

    if (isWeekendDate(attendanceDate)) {
        form.querySelectorAll("input, button, select, textarea").forEach(function (field) {
            field.disabled = true;
        });
        setState("No Class — attendance cannot be recorded on weekends", "dirty", "event_busy");
        return;
    }

    function updateSummary() {
        var counts = { present: 0, late: 0, absent: 0, excused: 0 };
        form.querySelectorAll('.attendance-row input[type="radio"]:checked').forEach(function (input) {
            if (Object.prototype.hasOwnProperty.call(counts, input.value)) {
                counts[input.value]++;
            }
        });

        Object.keys(counts).forEach(function (status) {
            var el = form.querySelector('[data-summary="' + status + '"]');
            if (el) el.textContent = counts[status];
        });
    }

    form.addEventListener("change", function (event) {
        if (event.target.matches('input[type="radio"], .remark-input')) {
            updateSummary();
            markDirty();
        }
    });

    form.addEventListener("input", function (event) {
        if (event.target.matches(".remark-input")) {
            markDirty();
        }
    });

    var markAll = document.querySelector("[data-mark-all-present]");
    if (markAll) {
        markAll.addEventListener("click", function () {
            form.querySelectorAll(".attendance-row").forEach(function (row) {
                var present = row.querySelector('input[type="radio"][value="present"]');
                if (present) present.checked = true;
            });
            updateSummary();
            markDirty();
            if (window.showSnackbar) {
                window.showSnackbar("All students marked present. Don't forget to save.", "info");
            }
        });
    }

    form.addEventListener("submit", function (event) {
        if (isWeekendDate(attendanceDate)) {
            event.preventDefault();
            setState("No Class — attendance cannot be recorded on weekends", "dirty", "event_busy");
            if (window.showSnackbar) {
                window.showSnackbar("No Class: Saturdays and Sundays are not school days.", "error");
            }
            return;
        }

        if (saving) {
            event.preventDefault();
            return;
        }

        saving = true;
        dirty = false;

        if (saveButton) {
            saveButton.disabled = true;
            saveButton.classList.add("disabled");
        }
        setState("Saving attendance…", "", "cloud_upload");
    });

    window.addEventListener("beforeunload", function (event) {
        if (!dirty) return;
        event.preventDefault();
        event.returnValue = "";
    });

    updateSummary();
})();
