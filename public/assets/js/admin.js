import { element as el, money } from "./api.js";

const root = document.querySelector("#admin-app");
const dialog = document.querySelector("#editor");
const editor = document.querySelector("#editor-content");
let csrf = "",
  user = null,
  metadata = { resources: {}, lookups: {} },
  publicData = null;
let toastTimer;
let localMode = true;
let routeVersion = 0;
const labels = {
  bookings: "Booking requests",
  enquiries: "Enquiries",
  notifications: "Email delivery",
  audit: "Audit history",
  users: "Admin users",
  media: "Images",
  settings: "Business settings",
  readiness: "Launch checklist",
};

function toast(message, error = false) {
  const n = document.querySelector("#admin-live");
  n.textContent = message;
  n.className = "admin-toast" + (error ? " error-toast" : "");
  n.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (n.hidden = true), 7000);
}
async function call(path, { method = "GET", body, form } = {}) {
  const headers = { Accept: "application/json" };
  if (method !== "GET") headers["X-CSRF-Token"] = csrf;
  if (body !== undefined) headers["Content-Type"] = "application/json";
  let response;
  try {
    response = await fetch("/api/v1/" + path, {
      method,
      headers,
      credentials: "same-origin",
      body: form || (body !== undefined ? JSON.stringify(body) : undefined),
    });
  } catch {
    throw new Error("Connection failed. Check the local server and try again.");
  }
  let result;
  try {
    result = await response.json();
  } catch {
    throw new Error("The server could not complete this request.");
  }
  if (!response.ok) {
    const error = new Error(result.error?.message || "The request failed.");
    error.code = result.error?.code;
    if (response.status === 401 && path !== "admin/auth/login") {
      user = null;
      dialog.close();
      editor.replaceChildren();
      await session();
      login("Your session expired. Sign in again.");
    }
    throw error;
  }
  return result.data;
}
async function session() {
  const data = await call("admin/auth/session");
  csrf = data.csrf_token;
  user = data.user;
  localMode = data.local_mode;
}
function button(text, handler, classes = "btn outline") {
  return el("button", {
    type: "button",
    class: classes,
    text,
    on: {
      click: async () => {
        try {
          await handler();
        } catch (e) {
          toast(e.message, true);
        }
      },
    },
  });
}
function badge(status) {
  return el("span", {
    class: "admin-badge " + status,
    text: String(status).replaceAll("_", " "),
  });
}
function formatTime(
  time,
  timezone = publicData?.timezone || "Europe/Copenhagen",
) {
  if (!time) return "—";
  try {
    return new Intl.DateTimeFormat("en", {
      dateStyle: "medium",
      timeStyle: "short",
      timeZone: timezone,
    }).format(
      new Date(time.includes("T") ? time : time.replace(" ", "T") + "Z"),
    );
  } catch {
    return time;
  }
}
function fieldBox(label, node, wide = false) {
  const id = node.id || "field-" + crypto.randomUUID();
  node.id = id;
  return el(
    "div",
    { class: "editor-field" + (wide ? " wide" : "") },
    el("label", { for: id, text: label }),
    node,
  );
}
function input(type, value = "", required = true) {
  const node = el("input", { type, ...(required ? { required: "" } : {}) });
  node.value = value ?? "";
  return node;
}
function heading(title, description, action) {
  const h = el(
    "div",
    { class: "admin-top" },
    el("div", {}, el("h1", { text: title }), el("p", { text: description })),
  );
  if (action) h.append(action);
  return h;
}
function table(headers, records, render) {
  const t = el("table", { class: "admin-table" }),
    head = el(
      "thead",
      {},
      el("tr", {}, ...headers.map((h) => el("th", { scope: "col", text: h }))),
    );
  const body = el("tbody");
  for (const record of records)
    body.append(
      el(
        "tr",
        {},
        ...render(record).map((cell) =>
          el(
            "td",
            {},
            typeof cell === "string" || typeof cell === "number"
              ? document.createTextNode(String(cell))
              : cell,
          ),
        ),
      ),
    );
  t.append(head, body);
  if (!records.length)
    body.append(
      el(
        "tr",
        {},
        el("td", {
          colspan: headers.length,
          class: "admin-empty",
          text: "No records found.",
        }),
      ),
    );
  return el("div", { class: "admin-table-wrap" }, t);
}
function pager(data, route, query) {
  const pages = Math.max(1, Math.ceil(data.total / data.limit));
  const p = el(
    "div",
    { class: "admin-pagination" },
    el("span", {
      text: `${data.total} records · Page ${data.page} of ${pages}`,
    }),
  );
  const actions = el("div");
  for (const [label, step] of [
    ["Previous", -1],
    ["Next", 1],
  ]) {
    const b = button(label, () => {
      query.set("page", data.page + step);
      location.hash = route + "?" + query;
    });
    b.disabled = data.page + step < 1 || data.page + step > pages;
    actions.append(b);
  }
  p.append(actions);
  return p;
}
function modal(title) {
  editor.replaceChildren();
  const h = el("h2", { id: "editor-title", text: title });
  editor.append(
    el(
      "div",
      { class: "editor-header" },
      h,
      button("×", () => dialog.close(), "close-editor"),
    ),
  );
  dialog.showModal();
  return editor;
}
function modalForm(title, fields, save, label = "Save changes") {
  const box = modal(title),
    form = el("form"),
    error = el("div", { class: "error-box", role: "alert" }),
    grid = el("div", { class: "editor-fields" });
  grid.append(...fields);
  form.append(grid, error);
  const submit = el("button", { class: "btn", type: "submit", text: label });
  form.append(
    el(
      "div",
      { class: "actions" },
      button("Cancel", () => dialog.close()),
      submit,
    ),
  );
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    submit.disabled = true;
    error.textContent = "";
    try {
      await save(form);
      dialog.close();
      toast("Saved.");
      await route();
    } catch (e) {
      error.textContent = e.message;
      error.tabIndex = -1;
      error.focus();
    } finally {
      submit.disabled = false;
    }
  });
  box.append(form);
  return form;
}
function login(message = "") {
  root.innerHTML =
    '<main id="main" class="admin-login"><div class="admin-login-card"><a class="admin-logo" href="/">Steam<span>Rex</span></a><h1>Welcome back.</h1><p>Sign in to manage your bookings, services and website.</p><form id="login-form"><div class="field"><label for="login-email">Email address</label><input id="login-email" name="email" type="email" autocomplete="username" required></div><div class="field"><label for="login-password">Password</label><input id="login-password" name="password" type="password" autocomplete="current-password" required minlength="12"></div><div id="login-error" class="error-box" role="alert"></div><button class="btn" type="submit">Sign in →</button></form><p>Local setup: your generated login is in the private .runtime/admin-access.json file.</p></div></main>';
  if (!localMode)
    root.querySelector(".admin-login-card > p:last-child")?.remove();
  root.querySelector("#login-error").textContent = message;
  root
    .querySelector("#login-form")
    .addEventListener("submit", async (event) => {
      event.preventDefault();
      const submit = event.target.querySelector("button");
      submit.disabled = true;
      try {
        const result = await call("admin/auth/login", {
          method: "POST",
          body: {
            email: root.querySelector("#login-email").value,
            password: root.querySelector("#login-password").value,
          },
        });
        user = result.user;
        csrf = result.csrf_token;
        await loadData();
        shell();
        await route();
      } catch (e) {
        root.querySelector("#login-error").textContent = e.message;
      } finally {
        submit.disabled = false;
      }
    });
}
async function loadData() {
  publicData = await call("public/catalogue");
  if (user.role === "super_admin") metadata = await call("admin/metadata");
}
function shell() {
  root.innerHTML =
    '<div class="admin-layout"><aside class="admin-sidebar"><a class="admin-logo" href="/">Steam<span>Rex</span></a><button class="admin-mobile-menu" aria-expanded="false" type="button">Menu ☰</button><nav aria-label="Administration"></nav><div class="admin-user"></div></aside><main id="main" class="admin-main" tabindex="-1"></main></div>';
  const nav = root.querySelector("nav");
  for (const [id, label] of [
    ["overview", "Overview"],
    ["bookings", "Booking requests"],
    ["enquiries", "Enquiries"],
    ["notifications", "Email delivery"],
  ])
    nav.append(el("a", { href: "#" + id, text: label }));
  if (user.role === "super_admin") {
    nav.append(
      el("span", { class: "admin-nav-label", text: "Catalogue & operations" }),
    );
    for (const [id, spec] of Object.entries(metadata.resources))
      nav.append(el("a", { href: "#catalogue/" + id, text: spec.label }));
    nav.append(
      el("span", { class: "admin-nav-label", text: "Website & system" }),
    );
    for (const id of ["media", "settings", "users", "audit", "readiness"])
      nav.append(el("a", { href: "#" + id, text: labels[id] }));
  }
  root.querySelector(".admin-user").append(
    el("span", { text: user.name + " · " + user.role.replace("_", " ") }),
    button(
      "Sign out",
      async () => {
        await call("admin/auth/logout", { method: "POST", body: {} });
        user = null;
        await session();
        login();
      },
      "",
    ),
  );
  const toggle = root.querySelector(".admin-mobile-menu");
  toggle.addEventListener("click", () => {
    const open = toggle.getAttribute("aria-expanded") !== "true";
    toggle.setAttribute("aria-expanded", String(open));
    root.querySelector(".admin-sidebar").classList.toggle("expanded", open);
  });
}
function filters(routeName, query, statuses = []) {
  const f = el("form", { class: "admin-filters" });
  const search = input("search", query.get("q") || "", false),
    status = el("select"),
    date = input("date", query.get("date") || "", false);
  status.append(el("option", { value: "", text: "All statuses" }));
  for (const s of statuses)
    status.append(el("option", { value: s, text: s.replaceAll("_", " ") }));
  status.value = query.get("status") || "";
  f.append(fieldBox("Search", search));
  if (statuses.length)
    f.append(
      fieldBox("Status", status),
      fieldBox("Appointment / received date", date),
    );
  f.append(
    el("button", { class: "btn outline", type: "submit", text: "Filter" }),
  );
  f.addEventListener("submit", (event) => {
    event.preventDefault();
    const q = new URLSearchParams();
    if (search.value) q.set("q", search.value);
    if (status.value) q.set("status", status.value);
    if (date.value) q.set("date", date.value);
    location.hash = routeName + "?" + q;
  });
  return f;
}
async function overview(view) {
  const data = await call("admin/overview");
  const total = (items, status) =>
    Number(items.find((i) => i.status === status)?.count || 0);
  view.append(
    heading(
      "A clear view of your day.",
      "Review incoming requests, confirm appointments and keep every detail in one place.",
    ),
  );
  const stats = el("div", { class: "admin-stats" });
  for (const [label, value, routeName] of [
    [
      "Pending requests",
      total(data.bookings, "pending_confirmation"),
      "bookings?status=pending_confirmation",
    ],
    [
      "New enquiries",
      total(data.enquiries, "new_enquiry"),
      "enquiries?status=new_enquiry",
    ],
    [
      "Confirmed appointments",
      total(data.bookings, "confirmed"),
      "bookings?status=confirmed",
    ],
    ["Failed emails", data.failed_notifications, "notifications?status=failed"],
  ])
    stats.append(
      el(
        "a",
        { class: "admin-stat", href: "#" + routeName },
        el("span", { text: label }),
        el("strong", { text: value }),
      ),
    );
  view.append(
    stats,
    el(
      "div",
      { class: "admin-card" },
      el("h2", { text: "Upcoming appointments" }),
      table(["Reference", "Customer", "Status", "Time"], data.upcoming, (r) => [
        el("a", { href: "#bookings/" + r.id, text: r.reference }),
        JSON.parse(r.customer_json).full_name,
        badge(r.status),
        formatTime(r.starts_at, r.timezone),
      ]),
    ),
  );
}
async function requestList(view, type, query) {
  const routeName = type === "booking" ? "bookings" : "enquiries",
    data = await call("admin/" + routeName + "?" + query);
  view.append(
    heading(
      labels[routeName],
      type === "booking"
        ? "Submission creates a request. Confirm appointments explicitly after reviewing the details."
        : "Contact, close or convert enquiries into a validated booking.",
    ),
  );
  view.append(
    filters(
      routeName,
      query,
      type === "booking"
        ? [
            "pending_confirmation",
            "confirmed",
            "reschedule_requested",
            "completed",
            "cancelled",
            "rejected",
          ]
        : ["new_enquiry", "contacted", "enquiry_converted", "enquiry_closed"],
    ),
  );
  view.append(
    table(
      [
        "Reference",
        "Customer",
        "Status",
        "Received",
        ...(type === "booking" ? ["Requested time"] : []),
      ],
      data.items,
      (r) => [
        el("a", { href: "#" + routeName + "/" + r.id, text: r.reference }),
        r.customer.full_name,
        badge(r.status),
        formatTime(r.created_at),
        ...(type === "booking" ? [formatTime(r.starts_at, r.timezone)] : []),
      ],
    ),
    pager(data, routeName, query),
  );
}
function dataGrid(items) {
  const list = el("dl", { class: "admin-data" });
  for (const [label, value] of items)
    list.append(
      el(
        "div",
        {},
        el("dt", { text: label }),
        el("dd", { text: value || "—" }),
      ),
    );
  return list;
}
async function requestDetail(view, type, id) {
  const routeName = type === "booking" ? "bookings" : "enquiries",
    record = await call("admin/" + routeName + "/" + id);
  view.append(
    heading(
      record.reference,
      "Received " + formatTime(record.created_at),
      el("a", {
        href: "#" + routeName,
        class: "btn outline",
        text: "← All " + routeName,
      }),
    ),
  );
  const grid = el("div", { class: "admin-detail-grid" }),
    left = el("div"),
    right = el("div");
  grid.append(left, right);
  view.append(grid);
  left.append(
    el(
      "section",
      { class: "admin-card" },
      el(
        "div",
        { class: "admin-split-head" },
        el("h2", { text: "Request details" }),
        badge(record.status),
      ),
      dataGrid([
        ["Customer", record.customer.full_name],
        ["Email", record.customer.email],
        ["Phone", record.customer.phone],
        ["Address", record.address || record.context?.address],
        ["Postcode", record.postcode || record.context?.postcode],
        ["Customer note", record.message],
      ]),
    ),
  );
  const actions = el("div", { class: "admin-actions" });
  left.firstChild.append(actions);
  const action = (key, label, reasonRequired = true) =>
    button(label, () => {
      const reason = el("textarea", {
        required: reasonRequired ? "" : undefined,
      });
      if (!reasonRequired) reason.removeAttribute("required");
      modalForm(
        label,
        [fieldBox("Reason / context", reason, true)],
        async () => {
          await call(`admin/${routeName}/${id}`, {
            method: "PATCH",
            body: {
              version: record.version,
              action: key,
              reason: reason.value,
            },
          });
        },
      );
    });
  if (type === "booking") {
    if (record.status === "pending_confirmation")
      actions.append(
        action("confirm", "Confirm appointment", false),
        action("reject", "Reject request"),
      );
    if (record.status === "confirmed")
      actions.append(action("complete", "Mark completed", false));
    if (["pending_confirmation", "confirmed"].includes(record.status))
      actions.append(
        button("Propose new time", () => reschedule(record)),
        button("Change service / quote", () => quoteEditor(record, false)),
      );
    if (record.status === "reschedule_requested") {
      left.firstChild.append(
        el("p", {
          class: "notice",
          text:
            "Proposed time: " +
            formatTime(record.proposed_start, record.timezone) +
            ". The original reservation is retained until this change is accepted.",
        }),
      );
      actions.append(
        button("Accept agreed time", () => {
          const agreed = input("checkbox", "", true),
            reason = el("textarea", { required: "" });
          modalForm(
            "Accept reschedule",
            [
              fieldBox("Reason / agreement notes", reason, true),
              el(
                "label",
                { class: "check-label" },
                agreed,
                document.createTextNode(
                  "The customer agreed to the proposed time.",
                ),
              ),
            ],
            async () =>
              call(`admin/bookings/${id}`, {
                method: "PATCH",
                body: {
                  action: "accept_reschedule",
                  version: record.version,
                  reason: reason.value,
                  customer_agreed: agreed.checked,
                },
              }),
          );
        }),
        action("withdraw_reschedule", "Withdraw proposal"),
      );
    }
    if (
      ["pending_confirmation", "confirmed", "reschedule_requested"].includes(
        record.status,
      )
    )
      actions.append(action("cancel", "Cancel booking"));
    const quote = record.quote;
    left.append(
      el(
        "section",
        { class: "admin-card" },
        el("h2", { text: "Agreed service & quote" }),
        dataGrid([
          ["Service", quote.service],
          ["Vehicle", quote.vehicle],
          ["Package", quote.package],
          ["Service duration", quote.duration_minutes + " min"],
          ["Requested start", formatTime(record.starts_at, record.timezone)],
          [
            "Occupied until (with buffer)",
            formatTime(record.ends_at, record.timezone),
          ],
        ]),
        el(
          "ul",
          { class: "tick-list" },
          ...quote.inclusions.map((i) => el("li", { text: i })),
        ),
        table(["Item", "Amount"], quote.line_items, (item) => [
          item.label,
          money(item.amount_minor, quote.currency),
        ]),
        el("p", {
          class: "notice",
          text:
            "Total: " +
            money(quote.total_minor, quote.currency) +
            " · " +
            quote.tax_note,
        }),
      ),
    );
    if (record.source_enquiry_id)
      left.append(
        el("a", {
          href: "#enquiries/" + record.source_enquiry_id,
          class: "text-link",
          text: "View source enquiry →",
        }),
      );
  } else {
    left.firstChild.append(
      dataGrid([
        ["Service", record.context?.service_id],
        ["Vehicle", record.context?.vehicle],
      ]),
    );
    if (record.status === "new_enquiry")
      actions.append(action("contacted", "Mark contacted", false));
    if (["new_enquiry", "contacted"].includes(record.status))
      actions.append(
        button("Convert to booking", () => quoteEditor(record, true)),
        action("close", "Close enquiry"),
      );
    if (record.converted_booking_id)
      actions.append(
        el("a", {
          href: "#bookings/" + record.converted_booking_id,
          class: "btn",
          text: "View converted booking →",
        }),
      );
  }
  if (record.status !== "enquiry_converted")
    actions.append(
      button("Edit contact details", () => editContact(type, record)),
    );
  actions.append(
    button("Add internal note", () => {
      const note = el("textarea", { required: "", maxlength: "4000" });
      modalForm(
        "Internal note",
        [fieldBox("Note (not sent to the customer)", note, true)],
        async () =>
          call(`admin/${routeName}/${id}`, {
            method: "PATCH",
            body: { action: "note", version: record.version, note: note.value },
          }),
      );
    }),
  );
  if (
    user.role === "super_admin" &&
    [
      "completed",
      "cancelled",
      "rejected",
      "enquiry_closed",
      "enquiry_converted",
    ].includes(record.status)
  )
    actions.append(
      button(
        "Remove personal data",
        async () => {
          if (
            confirm(
              "Remove this closed request from the inbox and erase contact details, notes and delivery records? Service/price history is retained.",
            )
          ) {
            await call(`admin/${routeName}/${id}`, {
              method: "DELETE",
              body: { version: record.version },
            });
            location.hash = routeName;
          }
        },
        "btn outline danger",
      ),
    );
  const timeline = el("ul", { class: "admin-timeline" });
  for (const item of record.history)
    timeline.append(
      el(
        "li",
        {},
        el("strong", { text: item.to_status.replaceAll("_", " ") }),
        el("div", { text: item.reason }),
        el("small", {
          text:
            formatTime(item.created_at) +
            " · " +
            (item.admin_name || "Customer / system"),
        }),
      ),
    );
  right.append(
    el(
      "section",
      { class: "admin-card" },
      el("h2", { text: "History" }),
      timeline,
    ),
  );
  const notes = el("ul", { class: "admin-timeline" });
  for (const note of record.notes)
    notes.append(
      el(
        "li",
        {},
        el("div", { text: note.note }),
        el("small", {
          text:
            (note.admin_name || "Admin") + " · " + formatTime(note.created_at),
        }),
      ),
    );
  right.append(
    el(
      "section",
      { class: "admin-card" },
      el("h2", { text: "Internal notes" }),
      notes,
    ),
  );
  right.append(
    el(
      "section",
      { class: "admin-card" },
      el("h2", { text: "Email delivery" }),
      ...record.notifications.map((n) =>
        el(
          "div",
          { class: "booking-section" },
          el("a", { href: "#notification/" + n.id, text: n.subject }),
          el("p", { text: n.recipient }),
          badge(n.status),
          el("small", {
            text:
              n.transport === "log"
                ? "Captured locally; not emailed."
                : n.last_error || "",
          }),
        ),
      ),
    ),
  );
}
function editContact(type, record) {
  const nodes = {
      full_name: input("text", record.customer.full_name),
      email: input("email", record.customer.email),
      phone: input("tel", record.customer.phone),
    },
    message = el("textarea");
  message.value = record.message;
  const address = el("textarea");
  address.value = record.address || "";
  const fields = Object.entries(nodes).map(([key, n]) =>
    fieldBox(key.replace("_", " "), n),
  );
  if (type === "booking")
    fields.push(
      fieldBox("Address / access notes (postcode unchanged)", address, true),
    );
  fields.push(fieldBox("Customer note", message, true));
  modalForm("Edit contact details", fields, async () =>
    call(
      `admin/${type === "booking" ? "bookings" : "enquiries"}/${record.id}`,
      {
        method: "PATCH",
        body: {
          action: "edit_contact",
          version: record.version,
          customer: Object.fromEntries(
            Object.entries(nodes).map(([key, n]) => [key, n.value]),
          ),
          address: address.value,
          message: message.value,
        },
      },
    ),
  );
}
function reschedule(record) {
  const date = input("date"),
    times = el("select", { required: "" }),
    reason = el("textarea", { required: "" }),
    status = el("p", { class: "form-hint", role: "status" });
  times.append(el("option", { value: "", text: "Choose a day first" }));
  date.addEventListener("change", async () => {
    times.replaceChildren();
    try {
      const data = await call(
        `admin/bookings/${record.id}/availability?date=${encodeURIComponent(date.value)}`,
      );
      times.append(
        el("option", {
          value: "",
          text: data.slots.length ? "Choose a start" : "No available times",
        }),
        ...data.slots.map((s) =>
          el("option", { value: s.starts_at, text: s.label }),
        ),
      );
      status.textContent = "Times shown in " + data.timezone + ".";
    } catch (e) {
      status.textContent = e.message;
    }
  });
  modalForm(
    "Propose a new time",
    [
      fieldBox("Date", date),
      fieldBox("Requested start", times),
      status,
      fieldBox("Reason", reason, true),
    ],
    async () =>
      call(`admin/bookings/${record.id}`, {
        method: "PATCH",
        body: {
          action: "propose_reschedule",
          version: record.version,
          starts_at: times.value,
          reason: reason.value,
        },
      }),
    "Save proposal",
  );
}

async function quoteEditor(record, conversion) {
  publicData = await call("public/catalogue");
  const service = el("select", { required: "" }),
    model = el("select", { required: "" }),
    packages = el("select", { required: "" }),
    postcode = input("text", record.postcode || record.context?.postcode || ""),
    address = el("textarea", { required: "" }),
    extras = el("div", { class: "admin-checkboxes" }),
    date = input("date"),
    time = el("select", { required: "" }),
    agreed = input("checkbox", "", true),
    confirmBooking = input("checkbox", "", false),
    reason = el("textarea", { required: conversion ? undefined : "" });
  if (conversion) reason.removeAttribute("required");
  address.value = record.address || record.context?.address || "";
  service.append(
    ...publicData.services.map((s) =>
      el("option", { value: s.id, text: s.name }),
    ),
  );
  model.append(
    el("option", { value: "", text: "Choose vehicle" }),
    ...publicData.models.map((m) =>
      el("option", {
        value: m.id,
        text:
          (publicData.makes.find((v) => v.id === m.make_id)?.name || "") +
          " " +
          m.name,
      }),
    ),
  );
  let quote = null;
  const result = el("p", { class: "notice", role: "status" });
  let chosen = [];
  const reset = () => {
    quote = null;
    time.replaceChildren();
    result.textContent = "Check the new quote after changing selections.";
    agreed.checked = false;
  };
  const showExtras = () => {
    chosen = [];
    extras.replaceChildren();
    const ids = publicData.eligibility
      .filter((e) => e.package_id === packages.value)
      .map((e) => e.add_on_id);
    for (const extra of publicData.add_ons.filter((e) => ids.includes(e.id))) {
      const c = input("checkbox", "", false);
      c.addEventListener("change", () => {
        chosen = c.checked
          ? [...chosen, extra.id]
          : chosen.filter((id) => id !== extra.id);
        reset();
      });
      extras.append(el("label", {}, c, document.createTextNode(extra.name)));
    }
  };
  const updatePackages = () => {
    reset();
    const cls = publicData.models.find((m) => m.id === model.value)?.class_id;
    packages.replaceChildren(
      el("option", { value: "", text: "Choose package" }),
      ...publicData.packages
        .filter((p) => p.service_id === service.value && p.class_id === cls)
        .map((p) => el("option", { value: p.id, text: p.name })),
    );
    showExtras();
  };
  service.addEventListener("change", updatePackages);
  model.addEventListener("change", updatePackages);
  packages.addEventListener("change", () => {
    reset();
    showExtras();
  });
  postcode.addEventListener("input", reset);
  const check = button("Calculate current quote", async () => {
    quote = await call("public/quotes", {
      method: "POST",
      body: {
        service_id: service.value,
        vehicle_model_id: model.value,
        package_id: packages.value,
        postcode: postcode.value,
        add_on_ids: chosen,
      },
    });
    result.textContent = `${money(quote.total_minor, quote.currency)} · ${quote.duration_minutes} min. Customer agreement is required.`;
  });
  date.addEventListener("change", async () => {
    time.replaceChildren();
    if (!quote) {
      result.textContent = "Calculate a quote first.";
      return;
    }
    try {
      const slots = await call(
        `public/availability?quote_id=${quote.quote_id}&date=${date.value}`,
      );
      time.append(
        el("option", {
          value: "",
          text: slots.slots.length ? "Choose a time" : "No available times",
        }),
        ...slots.slots.map((s) =>
          el("option", { value: s.starts_at, text: s.label }),
        ),
      );
    } catch (e) {
      result.textContent = e.message;
    }
  });
  const fields = [
    fieldBox("Service", service),
    fieldBox("Vehicle", model),
    fieldBox("Package", packages),
    fieldBox("Postcode", postcode),
    fieldBox("Address / access details", address, true),
    el(
      "div",
      { class: "editor-field wide" },
      el("label", { text: "Available extras" }),
      extras,
    ),
    check,
    el("div", { class: "editor-field wide" }, result),
  ];
  if (conversion)
    fields.push(fieldBox("Date", date), fieldBox("Requested start", time));
  else fields.push(fieldBox("Reason for service/price change", reason, true));
  fields.push(
    el(
      "label",
      { class: "check-label editor-field wide" },
      agreed,
      document.createTextNode(
        "The customer agreed to this quote and appointment details.",
      ),
    ),
  );
  if (conversion)
    fields.push(
      el(
        "label",
        { class: "check-label editor-field wide" },
        confirmBooking,
        document.createTextNode("Also confirm the appointment now."),
      ),
    );
  modalForm(
    conversion ? "Convert enquiry to booking" : "Amend agreed service",
    fields,
    async () => {
      if (!quote) throw new Error("Calculate and review the quote first.");
      if (conversion) {
        const data = await call(`admin/enquiries/${record.id}/convert`, {
          method: "POST",
          body: {
            version: record.version,
            quote_id: quote.quote_id,
            starts_at: time.value,
            address: address.value,
            customer_agreed: agreed.checked,
            confirm: confirmBooking.checked,
          },
        });
        location.hash = "bookings/" + data.booking.id;
      } else
        await call(`admin/bookings/${record.id}`, {
          method: "PATCH",
          body: {
            action: "amend",
            version: record.version,
            quote_id: quote.quote_id,
            customer_agreed: agreed.checked,
            reason: reason.value,
            address: address.value,
          },
        });
    },
    conversion ? "Create booking" : "Update agreed quote",
  );
}

function minorUnits(value) {
  if (!/^\d+(?:\.\d{1,2})?$/.test(value))
    throw new Error("Enter an amount with at most two decimal places.");
  const [whole, fraction = ""] = value.split(".");
  return Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
}
function schemaControl(key, rule, value, record) {
  let n;
  const moneyField = key.endsWith("_minor") || key === "tax_rate_basis_points";
  if (rule.type === "boolean") {
    n = input("checkbox", "", false);
    n.checked = Boolean(Number(value) || value === true);
  } else if (rule.type === "textarea" || rule.type === "lines") {
    n = el("textarea");
    n.value = Array.isArray(value) ? value.join("\n") : value || "";
  } else if (rule.type === "multi") {
    n = el("div", { class: "admin-checkboxes" });
    for (const item of metadata.lookups[rule.resource] || []) {
      const c = input("checkbox", item.id, false);
      c.checked = (value || []).includes(item.id);
      n.append(el("label", {}, c, document.createTextNode(item.name)));
    }
  } else if (rule.type === "select" || rule.type === "image") {
    n = el("select");
    n.append(el("option", { value: "", text: "Choose…" }));
    const items =
      rule.type === "image"
        ? metadata.lookups.images
        : rule.resource
          ? metadata.lookups[rule.resource]
          : Object.entries(rule.options).map(([id, name]) => ({ id, name }));
    for (const item of items || [])
      n.append(el("option", { value: item.id, text: item.name }));
    n.value = value ?? "";
  } else {
    n = input(
      rule.type === "password"
        ? "password"
        : rule.type === "integer"
          ? "number"
          : rule.type === "date"
            ? "date"
            : rule.type === "time"
              ? "time"
              : rule.type === "email"
                ? "email"
                : rule.type === "url"
                  ? "url"
                  : "text",
      moneyField && value !== undefined ? Number(value) / 100 : (value ?? ""),
      false,
    );
    if (rule.type === "time") n.value = (value || "").slice(0, 5);
    if (rule.type === "integer") {
      n.min = moneyField ? rule.min / 100 : rule.min;
      n.max = moneyField ? rule.max / 100 : rule.max;
      n.step = moneyField ? "0.01" : "1";
    }
  }
  if (rule.required && !["boolean", "multi"].includes(rule.type))
    n.required = true;
  if (rule.max && !["integer"].includes(rule.type)) n.maxLength = rule.max;
  n.dataset.field = key;
  n.dataset.type = rule.type;
  if (moneyField) n.dataset.money = "1";
  const name =
    key === "tax_rate_basis_points"
      ? "Tax rate (%)"
      : key.endsWith("_minor")
        ? rule.label.replace(" (minor units)", "") +
          " (" +
          (publicData?.currency || "DKK") +
          ")"
        : rule.label;
  return {
    node: n,
    box: fieldBox(name, n, ["textarea", "lines", "multi"].includes(rule.type)),
  };
}
function collectFields(controls) {
  const data = {};
  for (const [key, n] of Object.entries(controls)) {
    const type = n.dataset.type;
    if (type === "boolean") data[key] = n.checked;
    else if (type === "multi")
      data[key] = [...n.querySelectorAll("input:checked")].map((i) => i.value);
    else if (type === "integer")
      data[key] = n.dataset.money ? minorUnits(n.value) : Number(n.value);
    else if (type === "lines") data[key] = n.value.split("\n");
    else data[key] = n.value;
  }
  return data;
}
async function catalogueList(view, resource, query) {
  const spec = metadata.resources[resource];
  if (!spec) throw new Error("This section is not available for your role.");
  const data = await call(`admin/catalogue/${resource}?${query}`);
  view.append(
    heading(
      spec.label,
      resource === "packages"
        ? "Manage names, inclusions, prices and eligible add-ons. Each supported service / vehicle class needs tiers 1, 2 and 3."
        : "Changes take effect on the public website immediately. Removing records preserves existing booking history.",
      button("Add " + (resource === "content" ? "page" : "item"), () =>
        editResource(resource),
      ),
    ),
  );
  const f = filters("catalogue/" + resource, query);
  const archived = input("checkbox", "", false);
  archived.checked = query.get("archived") === "1";
  archived.addEventListener("change", () => {
    query.set("archived", archived.checked ? "1" : "0");
    query.delete("page");
    location.hash = "catalogue/" + resource + "?" + query;
  });
  f.append(
    el(
      "label",
      { class: "check-label" },
      archived,
      document.createTextNode("Show removed items"),
    ),
  );
  view.append(f);
  const candidates = {
    packages: [
      "name",
      "service_id",
      "class_id",
      "tier",
      "price_minor",
      "active",
    ],
    "vehicle-models": ["name", "make_id", "class_id", "active"],
    "price-overrides": ["package_id", "model_id", "price_minor"],
    "addon-price-overrides": ["add_on_id", "class_id", "price_minor"],
    "availability-rules": [
      "weekday",
      "opens_at",
      "closes_at",
      "capacity",
      "active",
    ],
    "blocked-dates": ["day", "reason", "starts_at", "ends_at"],
    content: ["id", "title", "published"],
    "service-areas": ["postcode", "name", "surcharge_minor", "active"],
    "add-ons": ["name", "price_minor", "duration_minutes", "active"],
    faqs: ["question", "sort_order", "active"],
    reviews: ["author", "rating", "source_label", "active"],
  };
  const columns = candidates[resource] || [
    "name",
    ...(spec.fields.active ? ["active"] : []),
  ];
  view.append(
    table(
      [
        ...columns.map(
          (c) => spec.fields[c]?.label.replace(" (minor units)", "") || c,
        ),
        "Actions",
      ],
      data.items,
      (r) => [
        ...columns.map((c) => {
          if (c.endsWith("_minor")) return money(r[c], publicData.currency);
          if (["active", "published"].includes(c)) return r[c] ? "Yes" : "No";
          const rule = spec.fields[c];
          if (rule?.options) return rule.options[r[c]] || String(r[c]);
          if (rule?.resource)
            return (
              metadata.lookups[rule.resource]?.find(
                (i) => String(i.id) === String(r[c]),
              )?.name || String(r[c])
            );
          return String(r[c] ?? "—");
        }),
        el(
          "div",
          {},
          button(
            query.get("archived") === "1" ? "Edit / restore" : "Edit",
            () => editResource(resource, r._key),
            "",
          ),
          ...(query.get("archived") === "1"
            ? []
            : [
                button(
                  "Remove",
                  async () => {
                    if (
                      confirm(
                        ["reviews", "faqs", "content"].includes(resource)
                          ? "Hide this item from the public website? You can restore it later."
                          : "Remove this item from new bookings? Historical bookings keep their saved details.",
                      )
                    ) {
                      await call(
                        `admin/catalogue/${resource}/${encodeURIComponent(r._key)}`,
                        { method: "DELETE", body: { version: r.version } },
                      );
                      await loadData();
                      await route();
                    }
                  },
                  "danger",
                ),
              ]),
        ),
      ],
    ),
    pager(data, "catalogue/" + resource, query),
  );
}
async function editResource(resource, id) {
  metadata.lookups = await call("admin/lookups");
  const spec = metadata.resources[resource],
    record = id
      ? await call(`admin/catalogue/${resource}/${encodeURIComponent(id)}`)
      : {};
  const controls = {},
    fields = [];
  for (const [key, rule] of Object.entries(spec.fields)) {
    if (rule.type === "hidden") continue;
    const control = schemaControl(
      key,
      rule,
      record[key] ??
        (rule.type === "boolean"
          ? true
          : rule.type === "integer"
            ? rule.min
            : undefined),
      record,
    );
    if (
      id &&
      (key === spec.key ||
        key === "id" ||
        (spec.key === "composite" && ["package_id", "model_id"].includes(key)))
    )
      control.node.disabled = true;
    controls[key] = control.node;
    fields.push(control.box);
  }
  if (resource === "content")
    fields.unshift(
      el("p", {
        class: "notice editor-field wide",
        text: "Built-in page keys: home, services, how-it-works, service-areas, contact, privacy, terms. Other keys publish at /pages/your-key.html. Text is safely rendered as paragraphs.",
      }),
    );
  modalForm(
    (id ? "Edit " : "Add ") + spec.label.toLowerCase(),
    fields,
    async () => {
      const body = {
        ...collectFields(controls),
        ...(id ? { version: record.version } : {}),
      };
      await call(
        `admin/catalogue/${resource}` +
          (id ? "/" + encodeURIComponent(id) : ""),
        { method: id ? "PUT" : "POST", body },
      );
      await loadData();
    },
    id && record.deleted_at ? "Save & restore" : "Save changes",
  );
}
async function settings(view) {
  const data = await call("admin/settings");
  view.append(
    heading(
      "Business settings",
      "Control your public details, booking rules, tax, publication and email delivery. SMTP passwords are encrypted and never returned to the browser.",
    ),
  );
  const form = el("form"),
    controls = {};
  const groups = [
    [
      "Business details",
      [
        "business_name",
        "site_url",
        "currency",
        "timezone",
        "phone",
        "email",
        "business_email",
        "contact_hours",
        "service_regions",
        "notification_email",
        "hero_image",
        "hero_video_enabled",
        "hero_video",
      ],
    ],
    [
      "Scheduling",
      [
        "buffer_minutes",
        "lead_minutes",
        "booking_horizon_days",
        "quote_minutes",
      ],
    ],
    ["Pricing and tax", ["tax_mode", "tax_rate_basis_points", "tax_label"]],
    [
      "Publication and privacy",
      [
        "demo_mode",
        "booking_enabled",
        "search_indexing",
        "legal_approved",
        "terms_version",
        "privacy_version",
        "retention_days",
      ],
    ],
    [
      "Email delivery",
      [
        "mail_transport",
        "mail_from",
        "smtp_host",
        "smtp_port",
        "smtp_security",
        "smtp_username",
        "smtp_password",
      ],
    ],
  ];
  for (const [label, keys] of groups) {
    const group = el(
        "fieldset",
        { class: "admin-settings-group" },
        el("legend", { text: label }),
      ),
      grid = el("div", { class: "editor-fields" });
    for (const key of keys) {
      const c = schemaControl(key, data.fields[key], data.values[key]);
      controls[key] = c.node;
      grid.append(c.box);
      if (key === "smtp_password")
        c.box.append(
          el("small", {
            text: data.values.smtp_password_configured
              ? "Password saved. Leave blank to keep it."
              : "No SMTP password saved.",
          }),
        );
    }
    group.append(grid);
    if (label === "Email delivery")
      group.append(
        el("p", {
          class: "notice",
          text: "Simply.com website SMTP: websmtp.simply.com, port 587, STARTTLS. Use a mailbox username and password from the hosting control panel. Local capture never contacts a mail provider.",
        }),
      );
    form.append(group);
  }
  const error = el("div", { class: "error-box", role: "alert" }),
    submit = el("button", {
      class: "btn",
      type: "submit",
      text: "Save business settings",
    });
  form.append(error, submit);
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    submit.disabled = true;
    try {
      await call("admin/settings", {
        method: "PUT",
        body: { ...collectFields(controls), _revision: data.values._revision },
      });
      await loadData();
      toast("Settings saved.");
      await route();
    } catch (e) {
      error.textContent = e.message;
      error.tabIndex = -1;
      error.focus();
    } finally {
      submit.disabled = false;
    }
  });
  view.append(form);
}
async function notifications(view, query) {
  const data = await call("admin/notifications?" + query);
  view.append(
    heading(
      "Email delivery",
      "Queued, captured, sent and failed messages. Capture-mode messages are local previews; SMTP acceptance does not guarantee inbox placement.",
    ),
  );
  view.append(
    table(
      ["Message", "Recipient", "Status", "Attempts", "Delivery"],
      data.items,
      (r) => [
        el("a", { href: "#notification/" + r.id, text: r.subject }),
        r.recipient,
        badge(r.status),
        String(r.attempts),
        r.transport === "log" ? "Local capture" : formatTime(r.sent_at),
      ],
    ),
    pager(data, "notifications", query),
  );
}
async function notification(view, id) {
  const n = await call("admin/notifications/" + id);
  view.append(
    heading(
      n.subject,
      n.recipient,
      el("a", {
        href: "#notifications",
        class: "btn outline",
        text: "← Email delivery",
      }),
    ),
    el(
      "section",
      { class: "admin-card" },
      badge(n.status),
      dataGrid([
        ["Attempts", String(n.attempts)],
        [
          "Transport",
          n.transport === "log" ? "Local capture — not emailed" : n.transport,
        ],
        ["Last error", n.last_error],
        ["Sent / captured", formatTime(n.sent_at)],
      ]),
      el("pre", { text: n.body }),
      ...(["failed", "pending"].includes(n.status)
        ? [
            button("Retry delivery", async () => {
              await call(`admin/notifications/${id}/retry`, {
                method: "POST",
                body: {},
              });
              toast("Message queued for retry.");
              await route();
            }),
          ]
        : []),
    ),
  );
}
async function users(view) {
  const data = await call("admin/users");
  view.append(
    heading(
      "Admin users",
      "Super admins control configuration. Operators manage customer requests. Removing access invalidates existing sessions.",
      button("Add admin", () => editUser()),
    ),
  );
  view.append(
    table(["Name", "Email", "Role", "Active", "Actions"], data.items, (r) => [
      r.name,
      r.email,
      r.role.replace("_", " "),
      r.active ? "Yes" : "No",
      button("Edit", () => editUser(r), ""),
    ]),
  );
}
function editUser(record = {}) {
  const name = input("text", record.name),
    email = input("email", record.email),
    role = el("select"),
    active = input("checkbox", "", false),
    password = input("password", "", !record.id);
  password.minLength = 12;
  role.append(
    el("option", { value: "operator", text: "Operator — requests only" }),
    el("option", { value: "super_admin", text: "Super admin — all controls" }),
  );
  role.value = record.role || "operator";
  active.checked = record.id ? Boolean(record.active) : true;
  modalForm(
    record.id ? "Edit admin" : "Create admin",
    [
      fieldBox("Name", name),
      fieldBox("Email", email),
      fieldBox("Role", role),
      fieldBox(
        record.id
          ? "New password (blank keeps existing)"
          : "Password (12+ characters)",
        password,
      ),
      el(
        "label",
        { class: "check-label" },
        active,
        document.createTextNode("Active login"),
      ),
    ],
    async () => {
      await call("admin/users" + (record.id ? "/" + record.id : ""), {
        method: record.id ? "PUT" : "POST",
        body: {
          name: name.value,
          email: email.value,
          role: role.value,
          active: active.checked,
          password: password.value,
          session_version: record.session_version,
        },
      });
    },
  );
}
async function media(view) {
  const data = await call("admin/media");
  view.append(
    heading(
      "Images",
      "Upload JPG, PNG or WebP up to 5 MB. Choose the resulting image in services, pages or homepage settings.",
    ),
  );
  const form = el("form", { class: "admin-filters" }),
    file = input("file");
  file.accept = "image/jpeg,image/png,image/webp";
  const submit = el("button", {
    class: "btn",
    type: "submit",
    text: "Upload image",
  });
  form.append(fieldBox("Image", file), submit);
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    submit.disabled = true;
    try {
      const body = new FormData();
      body.append("image", file.files[0]);
      await call("admin/media", { method: "POST", form: body });
      toast("Image uploaded.");
      await loadData();
      await route();
    } catch (e) {
      toast(e.message, true);
    } finally {
      submit.disabled = false;
    }
  });
  view.append(form);
  const grid = el("div", { class: "admin-media-grid" });
  for (const image of data.items)
    grid.append(
      el(
        "article",
        {},
        el("img", {
          src: image.path,
          alt: image.alt_text || image.name,
          width: image.width,
          height: image.height,
        }),
        el("p", { text: image.name }),
        button(
          "Edit details",
          () => {
            const name = input("text", image.name),
              alt = input("text", image.alt_text, false);
            modalForm(
              "Image details",
              [fieldBox("Name", name, true), fieldBox("Alt text", alt, true)],
              async () =>
                call("admin/media/" + image.id, {
                  method: "PUT",
                  body: {
                    name: name.value,
                    alt_text: alt.value,
                    version: image.version,
                  },
                }),
            );
          },
          "",
        ),
        button(
          "Remove",
          async () => {
            if (
              confirm(
                "Remove this image from the picker? Already-published uses keep working.",
              )
            ) {
              await call("admin/media/" + image.id, {
                method: "DELETE",
                body: { version: image.version },
              });
              await route();
            }
          },
          "danger",
        ),
      ),
    );
  view.append(grid);
}
async function readiness(view) {
  const data = await call("admin/readiness");
  view.append(
    heading(
      "Launch checklist",
      "Configuration status before local acceptance testing and Simply.com deployment.",
    ),
  );
  for (const [title, items, klass] of [
    ["Required before opening live requests", data.blocking, "error-box"],
    ["Information", data.warnings, "notice"],
  ])
    if (items.length)
      view.append(
        el(
          "section",
          { class: klass },
          el("h2", { text: title }),
          el("ul", {}, ...items.map((t) => el("li", { text: t }))),
        ),
      );
  if (data.ready)
    view.append(
      el("p", {
        class: "notice",
        text: "Application settings are ready for live requests. Verify the hosting database, HTTPS, worker cron and backups before launch.",
      }),
    );
  view.append(
    el(
      "section",
      { class: "admin-card" },
      el("h2", { text: "Background delivery" }),
      dataGrid([
        [
          "Worker last seen",
          data.worker_heartbeat
            ? formatTime(data.worker_heartbeat)
            : "Not run yet",
        ],
        ["Last delivery", formatTime(data.last_delivery)],
      ]),
    ),
    el(
      "section",
      { class: "admin-card" },
      el("h2", { text: "Deployment essentials" }),
      el("p", {
        text: "Upload the prepared public_html, app and scripts folders. Create the private environment file with hosting database credentials, import database/install.sql and schedule scripts/worker.php through Simply.com cron. Full instructions are in docs/DEPLOYMENT.md.",
      }),
    ),
  );
}
async function auditView(view, query) {
  const data = await call("admin/audit?" + query);
  view.append(
    heading(
      "Audit history",
      "Configuration, user and operational changes are recorded with the acting admin.",
    ),
  );
  view.append(
    table(["Time", "Admin", "Action", "Record", "Details"], data.items, (r) => [
      formatTime(r.created_at),
      r.admin_name || "System",
      r.action,
      r.entity_type + " / " + r.entity_id,
      button(
        "View",
        () => {
          const box = modal("Audit details");
          box.append(
            el("pre", {
              text: JSON.stringify(JSON.parse(r.details_json), null, 2),
            }),
          );
        },
        "",
      ),
    ]),
    pager(data, "audit", query),
  );
}
async function route() {
  if (!user) return;
  const [path, search = ""] = (location.hash.slice(1) || "overview").split("?"),
    parts = path.split("/"),
    query = new URLSearchParams(search),
    target = root.querySelector("#main"),
    view = el("div");
  if (!target) return;
  const version = ++routeVersion;
  target.replaceChildren(el("p", { text: "Loading…" }));
  root.querySelectorAll("nav a").forEach((a) => {
    if (a.getAttribute("href") === "#" + path)
      a.setAttribute("aria-current", "page");
    else a.removeAttribute("aria-current");
  });
  root.querySelector(".admin-sidebar")?.classList.remove("expanded");
  root
    .querySelector(".admin-mobile-menu")
    ?.setAttribute("aria-expanded", "false");
  try {
    view.replaceChildren();
    if (parts[0] === "overview") await overview(view);
    else if (["bookings", "enquiries"].includes(parts[0])) {
      const type = parts[0] === "bookings" ? "booking" : "enquiry";
      if (parts[1]) await requestDetail(view, type, parts[1]);
      else await requestList(view, type, query);
    } else if (parts[0] === "catalogue")
      await catalogueList(view, parts[1], query);
    else if (parts[0] === "settings") await settings(view);
    else if (parts[0] === "notifications") await notifications(view, query);
    else if (parts[0] === "notification") await notification(view, parts[1]);
    else if (parts[0] === "users") await users(view);
    else if (parts[0] === "media") await media(view);
    else if (parts[0] === "readiness") await readiness(view);
    else if (parts[0] === "audit") await auditView(view, query);
    else
      view.append(
        heading("Page not found", "Choose a section from the navigation."),
      );
  } catch (e) {
    view.replaceChildren(
      heading("Unable to load this section", e.message),
      button("Try again", route),
    );
  }
  if (version === routeVersion && user) {
    target.replaceChildren(view);
    target.focus({ preventScroll: true });
  }
}
window.addEventListener("hashchange", () => route());
try {
  await session();
  if (user) {
    await loadData();
    shell();
    await route();
  } else login();
} catch (e) {
  root.replaceChildren(
    el(
      "main",
      { id: "main", class: "admin-loading" },
      el("h1", { text: "Administration unavailable" }),
      el("p", { text: e.message }),
      button("Reload", () => location.reload()),
    ),
  );
}
