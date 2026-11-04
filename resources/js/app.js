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

    document.querySelector("[data-slot-preview]")?.addEventListener("click", async () => {
        const form = document.querySelector("[data-slot-generation-form]");
        const output = document.querySelector("[data-slot-preview-output]");
        const data = new FormData(form);
        data.delete("_token");

        output.textContent = "Loading preview...";
        try {
            const response = await fetch("/doctor/slots/preview", {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": form.querySelector("[name='_token']").value,
                },
                body: data,
            });
            const result = await response.json();
            if (!response.ok) {
                throw new Error(result.message || "Unable to preview slots.");
            }
            output.textContent = `${result.total} slot(s) match this range; ${result.existing} already exist and ${result.past} are in the past.`;
        } catch (error) {
            output.textContent = error.message;
        }
    });
});
