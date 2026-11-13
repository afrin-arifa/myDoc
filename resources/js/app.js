import "bootstrap";

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

document.querySelectorAll("[data-schedule-tab], [data-open-schedule-tab]").forEach((trigger) => {
    trigger.addEventListener("click", (event) => {
        event.preventDefault();
        const tab = trigger.dataset.scheduleTab || trigger.dataset.openScheduleTab;

        document.querySelectorAll("[data-schedule-tab]").forEach((item) => {
            item.classList.toggle("is-active", item.dataset.scheduleTab === tab);
        });
        document.querySelectorAll("[data-schedule-panel]").forEach((panel) => {
            panel.hidden = panel.dataset.schedulePanel !== tab;
        });
    });
});

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
        slotPreviewOutput.textContent = `${result.total} slot(s) match this range; ${result.existing} already exist and ${result.past} are in the past.`;
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
