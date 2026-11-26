// Only the Bootstrap components the pages use (data attributes and the modal opened on load).
import "bootstrap/js/dist/collapse";
import "bootstrap/js/dist/dropdown";
import Modal from "bootstrap/js/dist/modal";

const applyScheduleFilters = () => {
    const selected = [...document.querySelectorAll(".schedule-filter:checked")].map((item) => item.value);
    const showConsultations = document.querySelector(".schedule-consultation-filter")?.checked ?? true;

    document.querySelectorAll("[data-schedule-status]").forEach((event) => {
        event.hidden = !selected.includes(event.dataset.scheduleStatus)
            || (!showConsultations && event.dataset.scheduleKind === "consultation");
    });
};

document.querySelectorAll(".schedule-filter, .schedule-consultation-filter").forEach((filter) => {
    filter.addEventListener("change", applyScheduleFilters);
});

const showScheduleTab = (tab) => {
    if (!document.querySelector(`[data-schedule-panel="${tab}"]`)) {
        return;
    }

    document.querySelectorAll("[data-schedule-tab]").forEach((item) => {
        item.classList.toggle("is-active", item.dataset.scheduleTab === tab);
    });
    document.querySelectorAll("[data-schedule-panel]").forEach((panel) => {
        panel.hidden = panel.dataset.schedulePanel !== tab;
    });
};

document.querySelectorAll("[data-schedule-tab], [data-open-schedule-tab]").forEach((trigger) => {
    trigger.addEventListener("click", (event) => {
        event.preventDefault();
        showScheduleTab(trigger.dataset.scheduleTab || trigger.dataset.openScheduleTab);
    });
});

// After a form on the schedule page redirects back (e.g. to #locations-panel) or fails validation, reopen its tab.
const initialScheduleTab = document.querySelector("[data-initial-schedule-tab]")?.dataset.initialScheduleTab
    || window.location.hash.replace(/^#/, "").replace(/-panel$/, "");
if (initialScheduleTab) {
    showScheduleTab(initialScheduleTab);
}

// Open the timeline near the current time (today) or its first event, not always at the first hour.
const calendarScroll = document.querySelector("[data-calendar-scroll]");
if (calendarScroll) {
    const now = calendarScroll.querySelector(".doctor-calendar-now");
    const tops = [...calendarScroll.querySelectorAll(".doctor-calendar-event")].map((event) => event.offsetTop);
    const target = now ? now.offsetTop : Math.min(...tops);

    if (Number.isFinite(target)) {
        calendarScroll.scrollTop = Math.max(0, target - 120);
    }
}

const slotGenerationForm = document.querySelector("[data-slot-generation-form]");
const slotPreviewOutput = document.querySelector("[data-slot-preview-output]");
const hasSelectedWeekday = () => slotGenerationForm.querySelector("[name='weekdays[]']:checked") !== null;

slotGenerationForm?.addEventListener("submit", (event) => {
    if (!hasSelectedWeekday()) {
        event.preventDefault();
        slotPreviewOutput.textContent = "Select at least one weekday.";
    }
});

// Dates and times come from the server as ISO strings in Bangladesh time; slicing keeps that wall-clock time.
const renderSlotPreview = (result) => {
    const element = (tag, text, className = "") => Object.assign(document.createElement(tag), { textContent: text, className });
    const plural = (count) => `${count} ${count === 1 ? "slot" : "slots"}`;
    const weekdays = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
    const fresh = result.slots.filter((slot) => !slot.existing && !slot.overlapping && !slot.past);
    const parts = [element("p", `${plural(fresh.length)} will be created`, "doctor-slot-preview__total")];

    if (result.total === 0) {
        parts.push(element("p", "The weekly availability has no hours on these dates and weekdays."));
    } else if (fresh.length === 0) {
        parts.push(element("p", "Every time in this range already has a slot, overlaps one or is in the past."));
    }

    const byWeekday = new Map();
    fresh.forEach((slot) => {
        const weekday = new Date(`${slot.date}T00:00:00Z`).getUTCDay();
        byWeekday.set(weekday, (byWeekday.get(weekday) ?? 0) + 1);
    });
    const list = element("ul", "", "doctor-slot-preview__days");
    [...byWeekday.entries()]
        .sort(([first], [second]) => ((first + 6) % 7) - ((second + 6) % 7))
        .forEach(([weekday, count]) => list.append(element("li", `${weekdays[weekday]}: ${count}`)));
    if (byWeekday.size > 0) {
        parts.push(list);
    }

    const skipped = [
        [result.slots.filter((slot) => slot.past).length, "in the past"],
        [result.slots.filter((slot) => slot.existing && !slot.past).length, "already exist"],
        [result.slots.filter((slot) => slot.overlapping && !slot.past).length, "overlap an existing slot"],
    ].filter(([count]) => count > 0);
    if (skipped.length > 0) {
        parts.push(element("p", `Skipped: ${skipped.map(([count, reason]) => `${count} ${reason}`).join(", ")}.`));
    }

    const overlapping = result.slots.filter((slot) => slot.overlapping && !slot.past);
    if (overlapping.length > 0) {
        const details = document.createElement("details");
        details.append(element("summary", "Show overlapping times"), element("p", overlapping.map((slot) => `${slot.date} ${slot.starts_at.slice(11, 16)}`).join(", ")));
        parts.push(details);
    }

    if (fresh.length > 0) {
        const locations = [...new Set(fresh.map((slot) => slot.location || "Location not specified"))];
        parts.push(element("p", `Location: ${locations.join(", ")}`));
    }

    slotPreviewOutput.replaceChildren(...parts);
};

// A preview describes the choices it was made with; changing them makes it out of date.
slotGenerationForm?.addEventListener("change", () => slotPreviewOutput.replaceChildren());

document.querySelector("[data-slot-preview]")?.addEventListener("click", async () => {
    if (!hasSelectedWeekday()) {
        slotPreviewOutput.textContent = "Select at least one weekday.";

        return;
    }

    const data = new FormData(slotGenerationForm);
    data.delete("_token");

    slotPreviewOutput.textContent = "Loading preview...";
    try {
        const response = await fetch(slotGenerationForm.dataset.previewUrl, {
            method: "POST",
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": slotGenerationForm.querySelector("[name='_token']").value,
            },
            body: data,
        });
        const result = await response.json();
        if (!response.ok) {
            throw new Error(result.message || "Unable to preview slots.");
        }
        renderSlotPreview(result);
    } catch (error) {
        slotPreviewOutput.textContent = error.message;
    }
});

const patientSearchInput = document.querySelector("[data-patient-search]");

if (patientSearchInput) {
    const patientResults = document.querySelector("[data-patient-search-results]");
    const patientIdInput = document.querySelector("[data-patient-id]");
    const patientLabelInput = document.querySelector("[data-patient-label]");
    const patientSelected = document.querySelector("[data-patient-selected]");
    let searchTimer;
    let searchController;

    const selectPatient = (patient) => {
        patientIdInput.value = patient ? patient.id : "";
        patientLabelInput.value = patient ? `${patient.name} (${patient.email})` : "";
        patientSelected.textContent = patient ? `Selected: ${patientLabelInput.value}` : "";
        patientResults.replaceChildren();
    };

    patientSearchInput.addEventListener("input", () => {
        clearTimeout(searchTimer);
        selectPatient(null);
        const term = patientSearchInput.value.trim();

        if (term.length < 2) {
            return;
        }

        searchTimer = setTimeout(async () => {
            searchController?.abort();
            searchController = new AbortController();

            try {
                const url = new URL(patientSearchInput.dataset.searchUrl, window.location.origin);
                url.searchParams.set("search", term);
                const response = await fetch(url, {
                    headers: { Accept: "application/json" },
                    signal: searchController.signal,
                });
                if (!response.ok) {
                    throw new Error("Unable to search patients.");
                }
                const { data } = await response.json();

                patientResults.replaceChildren(
                    ...(data.length
                        ? data.map((patient) => {
                              const button = document.createElement("button");
                              button.type = "button";
                              button.className = "list-group-item list-group-item-action";
                              button.textContent = `${patient.name} (${patient.email})`;
                              button.addEventListener("click", () => selectPatient(patient));

                              return button;
                          })
                        : [Object.assign(document.createElement("div"), {
                              className: "list-group-item text-secondary",
                              textContent: "No matching patients with previous appointments.",
                          })]),
                );
            } catch (error) {
                if (error.name !== "AbortError") {
                    patientResults.replaceChildren(Object.assign(document.createElement("div"), {
                        className: "list-group-item text-danger",
                        textContent: error.message,
                    }));
                }
            }
        }, 300);
    });
}

document.querySelectorAll("form[data-confirm]").forEach((form) => {
    form.addEventListener("submit", (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

// Medical document previews: one shared modal, filled from the thumbnail that opened it. Images come from the
// authorized preview endpoint, never from a storage URL.
const documentPreview = document.querySelector("[data-document-preview]");

if (documentPreview) {
    const previewImage = documentPreview.querySelector("[data-preview-modal-image]");
    const previewTitle = documentPreview.querySelector("[data-preview-modal-title]");
    const previewDownload = documentPreview.querySelector("[data-preview-modal-download]");

    documentPreview.addEventListener("show.bs.modal", (event) => {
        const trigger = event.relatedTarget;

        if (!trigger?.dataset.previewSrc) {
            event.preventDefault();

            return;
        }

        previewTitle.textContent = trigger.dataset.previewTitle;
        previewImage.alt = `Preview of ${trigger.dataset.previewTitle}`;
        previewImage.src = trigger.dataset.previewSrc;
        previewDownload.href = trigger.dataset.previewDownload;
    });

    documentPreview.addEventListener("hidden.bs.modal", () => {
        previewImage.removeAttribute("src");
        previewImage.alt = "";
    });
}

document.querySelectorAll("[data-preview-thumbnail]").forEach((thumbnail) => {
    const showFallback = () => {
        thumbnail.hidden = true;
        thumbnail.parentElement.querySelector("[data-preview-fallback]").hidden = false;
        thumbnail.closest("button").disabled = true;
    };

    thumbnail.addEventListener("error", showFallback);
    if (thumbnail.complete && thumbnail.naturalWidth === 0 && thumbnail.getAttribute("src")) {
        showFallback();
    }
});

// Reject files above the server's real upload limit before they are sent: PHP silently discards them otherwise.
document.querySelectorAll("input[type='file'][data-max-file-bytes]").forEach((input) => {
    const feedback = input.parentElement.querySelector("[data-file-size-error]");

    input.addEventListener("change", () => {
        const file = input.files[0];
        const tooLarge = file !== undefined && file.size > Number(input.dataset.maxFileBytes);
        const message = tooLarge ? `"${file.name}" is larger than ${input.dataset.maxFileLabel}. Choose a smaller file.` : "";

        input.setCustomValidity(message);
        input.classList.toggle("is-invalid", tooLarge);
        if (feedback) {
            feedback.textContent = message;
        }
    });
});

// Add assistant: the password fields apply only when the admin sets the password themselves.
document.querySelectorAll("form[data-account-setup]").forEach((form) => {
    const fields = form.querySelector("[data-setup-password]");
    const update = () => {
        const setsPassword = form.querySelector("[name='setup']:checked")?.value === "password";

        fields.hidden = !setsPassword;
        fields.querySelectorAll("input").forEach((input) => {
            input.disabled = !setsPassword;
            input.required = setsPassword && !("optional" in input.dataset);
        });
    };

    form.querySelectorAll("[name='setup']").forEach((option) => option.addEventListener("change", update));
    update();
});

// New appointment: show and send only the fields of the chosen way to add the patient.
document.querySelectorAll("[data-patient-modes]").forEach((modes) => {
    const form = modes.closest("form");
    const update = () => {
        const mode = modes.querySelector("[name='patient_mode']:checked")?.value;

        form.querySelectorAll("[data-patient-panel]").forEach((panel) => {
            const active = panel.dataset.patientPanel === mode;

            panel.hidden = !active;
            panel.querySelectorAll("input").forEach((input) => {
                input.disabled = !active;
            });
        });
    };

    modes.addEventListener("change", update);
    update();
});

// Every password field gets a show/hide button.
document.querySelectorAll("input[type='password']").forEach((input) => {
    const field = document.createElement("div");
    const toggle = document.createElement("button");
    const icon = document.createElement("i");
    const label = document.querySelector(`label[for="${input.id}"]`)?.textContent.trim().toLowerCase() ?? "password";

    field.className = "password-field";
    toggle.type = "button";
    toggle.className = "password-field__toggle";
    icon.setAttribute("aria-hidden", "true");
    toggle.append(icon);

    const render = () => {
        const visible = input.type === "text";
        icon.className = visible ? "fa-regular fa-eye-slash" : "fa-regular fa-eye";
        toggle.setAttribute("aria-label", `${visible ? "Hide" : "Show"} ${label}`);
    };

    toggle.addEventListener("click", () => {
        input.type = input.type === "password" ? "text" : "password";
        render();
    });
    // Submit as a password field, so browsers never remember it as ordinary text.
    input.form?.addEventListener("submit", () => {
        input.type = "password";
        render();
    });

    const hadFocus = document.activeElement === input;
    input.replaceWith(field);
    field.append(input, toggle);
    if (hadFocus) {
        input.focus();
    }
    render();
});

// Doctor photo: preview the chosen image (or its removal) before the profile is saved.
document.querySelectorAll("[data-photo-editor]").forEach((editor) => {
    const input = editor.querySelector("input[type='file']");
    const preview = editor.querySelector("[data-photo-preview]");
    const pending = editor.querySelector("[data-photo-pending]");
    const remove = editor.querySelector("[data-photo-remove]");
    const fallback = editor.querySelector("[data-photo-fallback]");
    const save = editor.querySelector("[data-photo-save]");
    const savedSrc = preview.getAttribute("src");
    let objectUrl = null;

    const update = () => {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }

        const file = input.files[0];
        const usable = file !== undefined && input.validity.valid && input.accept.split(",").includes(file.type);

        if (usable) {
            objectUrl = URL.createObjectURL(file);
            preview.src = objectUrl;
            pending.textContent = "New photo: save to apply.";
        } else if (remove?.checked) {
            preview.src = preview.dataset.photoDefault;
            pending.textContent = "Photo will be removed when you save.";
        } else {
            preview.src = savedSrc;
            pending.textContent = "";
        }

        // Patients without a photo show their initials instead of a picture.
        if (fallback) {
            const hasPicture = preview.getAttribute("src") !== "";
            preview.hidden = !hasPicture;
            fallback.hidden = hasPicture;
        }
        if (save) {
            save.hidden = pending.textContent === "";
        }
    };

    // Uploading and removing exclude each other.
    input.addEventListener("change", () => {
        if (remove && input.files.length > 0) {
            remove.checked = false;
        }
        update();
    });
    remove?.addEventListener("change", () => {
        if (remove.checked) {
            input.value = "";
            input.dispatchEvent(new Event("change"));
        }
        update();
    });
});

document.querySelectorAll("form[data-disable-on-submit]").forEach((form) => {
    form.addEventListener("submit", (event) => {
        if (event.defaultPrevented) {
            return;
        }

        form.querySelectorAll("button[type='submit']:not(:disabled)").forEach((button) => {
            button.dataset.originalHtml = button.innerHTML;
            button.dataset.disabledBySubmit = "true";
            button.disabled = true;
            button.setAttribute("aria-busy", "true");
            if (button.dataset.loadingText) {
                button.textContent = button.dataset.loadingText;
            }
        });
    });
});

// When the browser restores a page from its back/forward cache, buttons disabled by a submit would stay in their
// "Sending…" state. Restore only the buttons this script disabled; buttons rendered disabled by the server stay so.
window.addEventListener("pageshow", (event) => {
    if (!event.persisted) {
        return;
    }

    document.querySelectorAll("button[data-disabled-by-submit]").forEach((button) => {
        button.disabled = false;
        button.removeAttribute("aria-busy");
        if (button.dataset.originalHtml !== undefined) {
            button.innerHTML = button.dataset.originalHtml;
        }
        delete button.dataset.disabledBySubmit;
    });
});

// Re-open a dialog after a failed submission (e.g. a missing cancellation reason) so the error is visible.
document.querySelectorAll(".modal[data-show-on-load]").forEach((dialog) => Modal.getOrCreateInstance(dialog).show());
