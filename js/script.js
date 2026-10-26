(() => {
  "use strict";

  const API = {
    schedules: "backend/schedules.php",
    manualFeed: "backend/manualfeed.php",
    manualHistory: "backend/manual_his.php"
    
  };

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  function setMessage(element, message, type = "") {
    if (!element) return;
    element.textContent = message;
    element.className = "inline-message" + (type ? " " + type : "");
  }

  async function requestJson(url, options = {}) {
    const response = await fetch(url, {
      headers: { "Content-Type": "application/json", ...(options.headers || {}) },
      ...options
    });
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(`The server returned an unexpected response (HTTP ${response.status}). Check the PHP endpoint and its URL.`);
    }
    if (!response.ok || data.success === false) {
      throw new Error(data.message || `Request failed (HTTP ${response.status}).`);
    }
    return data;
  }

  // Mobile sidebar toggle.
  const sidebar = $("#sidebar");
  const menuToggle = $("[data-menu-toggle]");
  if (menuToggle && sidebar) {
    menuToggle.addEventListener("click", () => sidebar.classList.toggle("open"));
    document.addEventListener("click", (event) => {
      if (window.innerWidth <= 760 && sidebar.classList.contains("open") &&
          !sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
        sidebar.classList.remove("open");
      }
    });
  
 }


 function updateDateTime() {
   
  const dateElement = document.getElementById("current-date");
  const timeElement = document.getElementById("current-time");

  const now = new Date();

    if (dateElement) {
        dateElement.textContent = now.toLocaleDateString("en-PH", {
            weekday: "short",
            month: "short",
            day: "2-digit",
            year: "numeric",
            timeZone: "Asia/Manila"
        });
    }

    if (timeElement) {
        timeElement.textContent = now.toLocaleTimeString("en-PH", {
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            hour12: true,
            timeZone: "Asia/Manila"
        });
    }
 }

  function updateCountdowns() {
    document.querySelectorAll("[data-countdown]").forEach(countdown => {
        const target = new Date(countdown.dataset.countdown).getTime();

        if (Number.isNaN(target)) {
            countdown.textContent = "Countdown unavailable";
            return;
        }

        const remaining = target - Date.now();

        if (remaining <= 0) {
            countdown.textContent = "Feeding time reached";
            return;
        }

        const seconds = Math.floor(remaining / 1000);
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;

        countdown.textContent =
            `${days}d ${String(hours).padStart(2, "0")}h ` +
            `${String(minutes).padStart(2, "0")}m ` +
            `${String(secs).padStart(2, "0")}s`;
    });
 }

  updateDateTime();
  updateCountdowns();

 setInterval(updateDateTime, 1000);
 setInterval(updateCountdowns, 1000);

 // Refresh the dashboard to select the nearest upcoming schedule.
if (document.body.dataset.page === "dashboard") {
    setInterval(() => {
        window.location.reload();
    }, 30000);
}


  
  // Schedules page: list, create, edit and cancel schedules through backend/schedules.php.
  const scheduleForm = $("#schedule-form");
  if (scheduleForm) {
    const list = $("#schedule-list");
    const message = $("#schedule-message");
    const idInput = $("#schedule-id");
    const dateInput = $("#feeding-date");
    const timeInput = $("#feeding-time");
    const title = $("#schedule-form-title");
    const submit = $("#schedule-submit");
    const cancelEdit = $("#schedule-cancel-edit");
    const refresh = $("#refresh-schedules");

    // Prevent selecting a date in the past in the UI. The backend must still validate requests.
    const now = new Date();
    const localToday = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
    dateInput.min = localToday;

    function escapeHtml(value) {
      return String(value ?? "").replace(/[&<>"']/g, (character) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;"
      })[character]);
    }

    function statusClass(status) {
      return String(status || "").toLowerCase().replace(/[^a-z-]/g, "");
    }

    function formatDate(dateString) {
      const date = new Date(`${dateString}T12:00:00`);
      return Number.isNaN(date.getTime()) ? dateString : date.toLocaleDateString(undefined, {
        year: "numeric", month: "short", day: "numeric"
      });
    }

    function formatTime(timeString) {
      const parts = String(timeString || "").split(":");
      if (parts.length < 2) return timeString || "—";
      const date = new Date();
      date.setHours(Number(parts[0]), Number(parts[1]), 0, 0);
      return date.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
    }

    function resetForm() {
      scheduleForm.reset();
      idInput.value = "";
      title.textContent = "Add a schedule";
      submit.textContent = "＋ Save schedule";
      cancelEdit.classList.add("hidden");
      dateInput.min = localToday;
    }

    function beginEdit(schedule) {
      idInput.value = schedule.id ?? schedule.schedule_id ?? "";
      dateInput.value = schedule.feeding_date || "";
      timeInput.value = String(schedule.feeding_time || "").slice(0, 5);
      title.textContent = "Edit schedule";
      submit.textContent = "Save changes";
      cancelEdit.classList.remove("hidden");
      setMessage(message, "Editing schedule. Save your changes when ready.", "");
      scheduleForm.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    function renderSchedules(items) {
      if (!items.length) {
        list.innerHTML = `<div class="empty-state"><span>🗓️</span><strong>No schedules found</strong><p>Add a date and time above to create your first schedule.</p></div>`;
        return;
      }
      const rows = items.map((schedule) => {
        const id = Number(schedule.id ?? schedule.schedule_id);
        const status = String(schedule.status || "").trim();
        const canEdit = ["Pending", "Scheduled"].includes(status);
        const actions = canEdit
          ? `<div class="schedule-actions"><button class="table-action" type="button" data-edit="${id}">Edit</button> <button class="table-action danger" type="button" data-cancel="${id}">Cancel</button></div>`
          : `<span class="muted">—</span>`;
        return `<tr>
          <td>#${id}</td>
          <td>${escapeHtml(formatDate(schedule.feeding_date))}</td>
          <td>${escapeHtml(formatTime(schedule.feeding_time))}</td>
          <td><span class="status-pill status-${escapeHtml(statusClass(status))}">${escapeHtml(status)}</span></td>
          <td>${actions}</td>
        </tr>`;
      }).join("");
      list.innerHTML = `<div class="table-wrap"><table><thead><tr><th>ID</th><th>Date</th><th>Time</th><th>Status</th><th>Actions</th></tr></thead><tbody>${rows}</tbody></table></div>`;
      $$("[data-edit]", list).forEach((button) => button.addEventListener("click", () => {
        const schedule = items.find((item) => Number(item.id ?? item.schedule_id) === Number(button.dataset.edit));
        if (schedule) beginEdit(schedule);
      }));
      $$("[data-cancel]", list).forEach((button) => button.addEventListener("click", () => cancelSchedule(Number(button.dataset.cancel))));
    }

    async function loadSchedules() {
      list.innerHTML = `<div class="loading-state">Loading schedules…</div>`;
      try {
        const data = await requestJson(API.schedules, { method: "GET", headers: {} });
        renderSchedules(Array.isArray(data.schedules) ? data.schedules : []);
      } catch (error) {
        list.innerHTML = `<div class="alert alert-warning">${escapeHtml(error.message)}<br>Check that <code>backend/schedules.php</code> exists and returns JSON.</div>`;
      }
    }

    async function cancelSchedule(id) {
      if (!window.confirm(`Cancel schedule #${id}?`)) return;
      try {
        await requestJson(API.schedules, {
          method: "DELETE",
          body: JSON.stringify({ id })
        });
        setMessage(message, `Schedule #${id} cancelled.`, "success");
        if (String(idInput.value) === String(id)) resetForm();
        await loadSchedules();
      } catch (error) {
        setMessage(message, error.message, "error");
      }
    }

    scheduleForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const feedingDate = dateInput.value;
      const feedingTime = timeInput.value;
      if (!feedingDate || !feedingTime) {
        setMessage(message, "Choose both a date and a time.", "error");
        return;
      }
      if (!idInput.value && feedingDate < localToday) {
        setMessage(message, "Choose today or a future date.", "error");
        return;
      }

      const isEditing = Boolean(idInput.value);
      const payload = {
        feeding_date: feedingDate,
        feeding_time: feedingTime,
        // Compatibility field for the existing database schema. Servo duration is configured in Arduino code.
        dispense_seconds: 1.00
      };
      if (isEditing) payload.id = Number(idInput.value);

      submit.disabled = true;
      setMessage(message, isEditing ? "Saving changes…" : "Adding schedule…", "");
      try {
        await requestJson(API.schedules, {
          method: isEditing ? "PUT" : "POST",
          body: JSON.stringify(payload)
        });
        setMessage(message, isEditing ? "Schedule updated successfully." : "Schedule added successfully.", "success");
        resetForm();
        await loadSchedules();
      } catch (error) {
        setMessage(message, error.message, "error");
      } finally {
        submit.disabled = false;
      }
    });

    cancelEdit.addEventListener("click", resetForm);
    refresh.addEventListener("click", loadSchedules);
    loadSchedules();
  }

  // Manual feeding request. The ESP32 must poll backend/esp32_manual.php to operate the servo.
  const manualButton = $("#manual-feed-button");
  if (manualButton) {
    const message = $("#manual-message");
    manualButton.addEventListener("click", async () => {
      if (!window.confirm("Send a manual feeding request?")) return;
      manualButton.disabled = true;
      setMessage(message, "Sending feeding request…", "");
      try {
        const data = await requestJson(API.manualFeed, {
          method: "POST",
          body: JSON.stringify({})
        });
        const commandId = data.command_id ? ` (request #${data.command_id})` : "";
        setMessage(message, `${data.message || "Feeding request saved."}${commandId} Status: ${data.status || "Pending"}. The ESP32 must be online to dispense food.`, "success");
      } catch (error) {
        setMessage(message, error.message, "error");
      } finally {
        manualButton.disabled = false;
      }
    });
  }
  
  
  // Manual Feeding Records
  const manualRecords = document.querySelector("#manual-records-body");
  const refreshManualRecords = document.querySelector("#refresh-manual-records");

  if (manualRecords) {
    function escapeManualHtml(value) {
      return String(value ?? "").replace(/[&<>"']/g, character => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
      })[character]);
    }

    async function loadManualRecords() {
      manualRecords.innerHTML =
        '<tr><td colspan="3">Loading manual feeding records...</td></tr>';
        
      

      try {
        const data = await requestJson(API.manualHistory, {
          method: "GET",
          headers: {}
        });

        const records = Array.isArray(data.records) ? data.records : [];

        if (records.length === 0) {
          manualRecords.innerHTML =
            '<tr><td colspan="3">No manual feeding records yet.</td></tr>';
          return;
        }

        manualRecords.innerHTML = records.map(record => {
          const date = new Date(
            String(record.date_time).replace(" ", "T") + "+08:00"
          );

          const formattedDate = Number.isNaN(date.getTime())
            ? escapeManualHtml(record.date_time)
            : escapeManualHtml(date.toLocaleString("en-PH", {
                year: "numeric",
                month: "short",
                day: "2-digit",
                hour: "2-digit",
                minute: "2-digit",
                second: "2-digit",
                hour12: true,
                timeZone: "Asia/Manila"
              }));

          const grams = Number(record.estimated_grams);
          const status = String(record.status || "Unknown");
          const statusClass = status.toLowerCase().replace(/[^a-z-]/g, "");

          return `
            <tr>
              <td>${formattedDate}</td>
              <td>${Number.isFinite(grams) ? grams : 0} g</td>
              <td>
                <span class="status-pill status-${escapeManualHtml(statusClass)}">
                  ${escapeManualHtml(status)}
                </span>
              </td>
            </tr>
          `;
        }).join("");

      } catch (error) {
         console.error("Manual records error:", error);

         manualRecords.innerHTML = `
         <tr>
            <td colspan="3">
                Unable to load records: ${escapeManualHtml(error.message)}
            </td>
          </tr>
       `;
      }

    }

    if (refreshManualRecords) {
      refreshManualRecords.addEventListener("click", loadManualRecords);
    }

    loadManualRecords();
  }



  // Client-side history search.
  const historySearch = $("#history-search");
  const historyTable = $("#history-table");
  if (historySearch && historyTable) {
    const rows = $$("tbody tr", historyTable);
    const noResults = $("#history-no-results");
    historySearch.addEventListener("input", () => {
      const term = historySearch.value.trim().toLowerCase();
      let visible = 0;
      rows.forEach((row) => {
        const match = (row.dataset.search || row.textContent.toLowerCase()).includes(term);
        row.classList.toggle("hidden", !match);
        if (match) visible++;
      });
      if (noResults) {
        noResults.classList.toggle("hidden", visible !== 0);
      } 

    });
  }

})();



function setupHistoryFilters() {
    const searchInput = document.getElementById("history-search");
    const dateInput = document.getElementById("history-date");
    const clearButton = document.getElementById("clear-history-filters");

    if (!searchInput || !dateInput || !clearButton) {
        return;
    }

    const rows = document.querySelectorAll("tr[data-date][data-search]");

    function filterRows() {
        const keyword = searchInput.value.trim().toLowerCase();
        const selectedDate = dateInput.value;

        rows.forEach(function (row) {
            const searchText = row.dataset.search.toLowerCase();
            const rowDate = row.dataset.date;

            const textMatches = searchText.includes(keyword);
            const dateMatches =
                selectedDate === "" || rowDate === selectedDate;

            row.style.display =
                textMatches && dateMatches ? "" : "none";
        });
    }

    searchInput.addEventListener("input", filterRows);
    dateInput.addEventListener("input", filterRows);
    dateInput.addEventListener("change", filterRows);

    clearButton.addEventListener("click", function () {
        searchInput.value = "";
        dateInput.value = "";
        filterRows();
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", setupHistoryFilters);
} else {
    setupHistoryFilters();
}

