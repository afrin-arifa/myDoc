import "bootstrap";
import { Modal } from "bootstrap";

document.querySelectorAll(".schedule-filter").forEach((filter) => {
    filter.addEventListener("change", () => {
        const selected = [...document.querySelectorAll(".schedule-filter:checked")].map((item) => item.value);

        document.querySelectorAll("[data-schedule-status]").forEach((event) => {
            const consultationFilter = document.querySelector(".schedule-consultation-filter");
            const statusVisible = selected.includes(event.dataset.scheduleStatus);
            const consultationVisible = consultationFilter?.checked || event.dataset.scheduleKind !== "consultation";

            event.hidden = !statusVisible || !consultationVisible;
        });
    });
});

document.querySelector(".schedule-consultation-filter")?.addEventListener("change", (filter) => {
    document.querySelectorAll("[data-schedule-status]").forEach((event) => {
        event.hidden = !filter.currentTarget.checked && event.dataset.scheduleKind === "consultation";
    });
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

const slotGenerationForm = document.querySelector("[data-slot-generation-form]");
const slotPreviewOutput = document.querySelector("[data-slot-preview-output]");
const hasSelectedWeekday = () => slotGenerationForm.querySelector("[name='weekdays[]']:checked") !== null;

slotGenerationForm?.addEventListener("submit", (event) => {
    if (!hasSelectedWeekday()) {
        event.preventDefault();
        slotPreviewOutput.textContent = "Select at least one weekday.";
    }
});

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
        let summary = `${result.total} slot(s) match this range; ${result.existing} already exist, ${result.overlapping} overlap an existing slot and ${result.past} are in the past. Only the remaining times will be created.`;

        // Times come from the server as ISO strings in Bangladesh time; slicing keeps that wall-clock time.
        const overlapping = result.slots.filter((slot) => slot.overlapping && !slot.past);
        if (overlapping.length > 0) {
            const shown = overlapping.slice(0, 10).map((slot) => `${slot.starts_at.slice(0, 10)} ${slot.starts_at.slice(11, 16)}`);
            const more = overlapping.length > shown.length ? `, and ${overlapping.length - shown.length} more` : "";
            summary += ` Skipped because they overlap an existing slot: ${shown.join(", ")}${more}.`;
        }

        const locations = [...new Set(result.slots.map((slot) => slot.location || "Location not specified"))];
        if (locations.length > 0) {
            summary += ` Locations: ${locations.join(", ")}.`;
        }

        slotPreviewOutput.textContent = summary;
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
