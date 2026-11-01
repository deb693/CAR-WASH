import { api, element as el, fillSelect, money } from "./api.js";

const app = document.querySelector("#booking-app");
const errorBox = document.querySelector("#booking-error");
const summary = document.querySelector("#booking-summary");
const progress = document.querySelector("#booking-progress");
const query = new URLSearchParams(location.search);
const state = {
  step: 0,
  intent: query.get("intent") === "enquiry" ? "enquiry" : "booking",
  service: query.get("service") || "full",
  make: "",
  model: query.get("model") || "",
  package: "",
  extras: [],
  otherVehicle: "",
  postcode: "",
  address: "",
  date: "",
  startsAt: "",
  name: "",
  email: "",
  phone: "",
  message: "",
  consent: false,
  quote: null,
};
let catalogue;
let quoteSequence = 0;
let slotSequence = 0;
let submission = null;

function showError(error) {
  errorBox.replaceChildren(document.createTextNode(error.message || error));
  if (
    [
      "outside_service_area",
      "packages_unavailable",
      "unknown_vehicle",
    ].includes(error.code)
  ) {
    errorBox.append(
      document.createTextNode(" "),
      el("button", {
        type: "button",
        class: "back-btn",
        text: "Switch to an enquiry →",
        on: {
          click: () => {
            state.intent = "enquiry";
            state.step = 0;
            render();
          },
        },
      }),
    );
  }
  for (const [name] of Object.entries(error.fields || {}))
    app.querySelector(`[name="${name}"]`)?.setAttribute("aria-invalid", "true");
  errorBox.focus();
}

function clearError() {
  errorBox.replaceChildren();
}
function vehicle() {
  return catalogue.models.find((item) => item.id === state.model);
}
function service() {
  return catalogue.services.find((item) => item.id === state.service);
}
function selectedPackage() {
  return catalogue.packages.find((item) => item.id === state.package);
}
function resetQuote() {
  state.quote = null;
  state.startsAt = "";
  state.date = "";
  state.consent = false;
  quoteSequence++;
  slotSequence++;
  submission = null;
}
function setField(id, key) {
  const input = app.querySelector(`#${id}`);
  input.value = state[key];
  input.addEventListener("input", () => {
    state[key] = input.value;
    input.removeAttribute("aria-invalid");
    state.consent = false;
    if (key === "postcode") {
      resetQuote();
      app.querySelector("#schedule")?.replaceChildren();
      const status = app.querySelector("#quote-status");
      if (status)
        status.textContent =
          "Check your price and times after changing the postcode.";
      renderSummary();
    }
  });
}

function renderSummary() {
  summary.replaceChildren();
  const definitions = el("dl");
  const values = [
    ["Service", service()?.name || "Choose your service"],
    ["Vehicle", vehicle()?.name || state.otherVehicle || "Choose your vehicle"],
    [
      "Package",
      state.intent === "enquiry"
        ? "Enquiry — no reserved time"
        : selectedPackage()?.name || "Choose your package",
    ],
  ];
  for (const [label, value] of values)
    definitions.append(
      el("div", {}, el("dt", { text: label }), el("dd", { text: value })),
    );
  summary.append(definitions);
  if (state.quote) {
    summary.append(el("div", { class: "booking-section" }));
    for (const item of state.quote.line_items)
      summary.append(
        el(
          "div",
          { class: "summary-line" },
          el("span", { text: item.label }),
          el("span", { text: money(item.amount_minor, state.quote.currency) }),
        ),
      );
    summary.append(
      el(
        "div",
        { class: "summary-line summary-total" },
        el("span", { text: "Your quote" }),
        el("span", {
          text: money(state.quote.total_minor, state.quote.currency),
        }),
      ),
    );
    summary.append(
      el("p", {
        text: `${state.quote.duration_minutes} min estimated service time. Quote valid for ${catalogue.quote_minutes} minutes. ${state.quote.tax_note}`,
      }),
    );
  } else
    summary.append(
      el("p", {
        text:
          state.intent === "enquiry"
            ? "Tell us what you need. An enquiry does not reserve an appointment or require a quote."
            : "Your exact quote will appear after we check your postcode. Package prices shown use your selected vehicle.",
      }),
    );
}

function updateProgress() {
  progress.replaceChildren();
  ["Your clean", "Your details", "Review"].forEach((label, index) => {
    progress.append(
      el(
        "li",
        {
          class: state.step === index ? "active" : "",
          ...(state.step === index ? { "aria-current": "step" } : {}),
        },
        el("span", { class: "step-number", text: index + 1 }),
        document.createTextNode(label),
      ),
    );
  });
}

function render(focusHeading = true) {
  clearError();
  updateProgress();
  renderSummary();
  app.setAttribute("aria-busy", "false");
  if (state.step === 0) renderChoices();
  else if (state.step === 1) renderDetails();
  else renderReview();
  if (focusHeading) app.querySelector("h2")?.focus();
}

function renderChoices() {
  app.innerHTML = `<h2 tabindex="-1">Make it your kind of clean.</h2><p class="intro">Start with your service and vehicle. We will show you the right options.</p><form id="choices-form"><fieldset class="booking-mode"><legend class="label">What would you like to do?</legend><label class="choice-label"><input type="radio" name="intent" value="booking"><span>Request a clean<small>Pay after the service</small></span></label><label class="choice-label"><input type="radio" name="intent" value="enquiry"><span>Send an enquiry<small>Ask a question — no reserved time</small></span></label></fieldset><div class="field-grid"><div class="field full"><label for="service">Your service</label><select id="service" required></select></div><div class="field"><label for="make">Vehicle make</label><select id="make" required></select></div><div class="field"><label for="model">Vehicle model</label><select id="model" required></select></div><div class="field full" id="other-field" hidden><label for="other-vehicle">Tell us about your vehicle</label><input id="other-vehicle" maxlength="200" placeholder="Make, model and size, if known"></div></div><div id="packages"></div><div id="extras"></div><div class="actions"><a class="back-btn" href="/services.html">Explore services</a><button type="submit" class="btn">Your details <span aria-hidden="true">→</span></button></div></form>`;
  const serviceInput = app.querySelector("#service");
  const makeInput = app.querySelector("#make");
  const modelInput = app.querySelector("#model");
  fillSelect(serviceInput, catalogue.services, "Choose your service");
  serviceInput.value = state.service;
  fillSelect(
    makeInput,
    [...catalogue.makes, { id: "other", name: "Other / not listed" }],
    "Choose make",
  );
  makeInput.value = state.make;
  fillSelect(
    modelInput,
    catalogue.models.filter((item) => item.make_id === state.make),
    "Choose model",
  );
  modelInput.value = state.model;
  modelInput.disabled = state.make === "other" || !state.make;
  modelInput.required = state.make !== "other";
  const otherField = app.querySelector("#other-field");
  otherField.hidden = state.make !== "other";
  app.querySelector("#other-vehicle").required = state.make === "other";
  setField("other-vehicle", "otherVehicle");
  for (const radio of app.querySelectorAll("[name=intent]")) {
    radio.checked = radio.value === state.intent;
    radio.addEventListener("change", () => {
      state.intent = radio.value;
      resetQuote();
      render(false);
      app.querySelector(`[name=intent][value=${state.intent}]`).focus();
    });
  }
  serviceInput.addEventListener("change", () => {
    state.service = serviceInput.value;
    state.package = "";
    state.extras = [];
    resetQuote();
    render(false);
    app.querySelector("#service").focus();
  });
  makeInput.addEventListener("change", () => {
    state.make = makeInput.value;
    state.model = "";
    state.package = "";
    state.extras = [];
    if (state.make === "other") state.intent = "enquiry";
    resetQuote();
    render(false);
    app.querySelector("#make").focus();
  });
  modelInput.addEventListener("change", () => {
    state.model = modelInput.value;
    state.package = "";
    state.extras = [];
    resetQuote();
    render(false);
    app.querySelector("#model").focus();
  });
  renderPackages();
  app.querySelector("#choices-form").addEventListener("submit", (event) => {
    event.preventDefault();
    if (state.intent === "booking" && !selectedPackage()) {
      showError("Choose a vehicle and package, or switch to an enquiry.");
      return;
    }
    state.step = 1;
    render();
  });
}

function renderPackages() {
  const container = app.querySelector("#packages");
  container.replaceChildren();
  if (state.intent === "enquiry") {
    container.append(
      el("p", {
        class: "notice",
        text: "An enquiry is a conversation starter. No quote, time selection or online payment is required.",
      }),
    );
    renderExtras();
    return;
  }
  if (!vehicle() || !service()) {
    renderExtras();
    return;
  }
  const packages = catalogue.packages.filter(
    (item) =>
      item.class_id === vehicle().class_id && item.service_id === state.service,
  );
  if (
    packages.length !== 3 ||
    new Set(packages.map((item) => item.tier)).size !== 3
  ) {
    container.append(
      el("p", {
        class: "notice",
        text: "Packages for this combination are being updated. Please switch to an enquiry.",
      }),
    );
    renderExtras();
    return;
  }
  container.append(
    el("h3", { class: "booking-section", text: "Choose your level of care" }),
  );
  const grid = el("div", {
    class: "package-grid",
    role: "group",
    "aria-label": "Cleaning packages",
  });
  for (const item of packages) {
    const radio = el("input", {
      type: "radio",
      name: "package",
      value: item.id,
      id: `package-${item.id}`,
      required: "",
    });
    radio.checked = state.package === item.id;
    radio.addEventListener("change", () => {
      state.package = item.id;
      state.extras = [];
      resetQuote();
      renderPackages();
      renderSummary();
      app.querySelector(`#package-${item.id}`).focus();
    });
    const details = el(
      "details",
      {},
      el("summary", { text: "See inclusions" }),
      el("ul", {}, ...item.inclusions.map((text) => el("li", { text }))),
    );
    const card = el(
      "div",
      { class: "package-option" },
      el(
        "label",
        { class: "package-top", for: `package-${item.id}` },
        radio,
        el("span", { text: item.name }),
      ),
      el("span", {
        class: "price",
        text: money(
          catalogue.price_overrides.find(
            (override) =>
              override.package_id === item.id &&
              override.model_id === state.model,
          )?.price_minor ?? item.price_minor,
          catalogue.currency,
        ),
      }),
      el("p", { text: item.summary }),
      el("small", { text: `${item.duration_minutes} min estimated` }),
      details,
    );
    grid.append(card);
  }
  container.append(grid);
  renderExtras();
}

function renderExtras() {
  const container = app.querySelector("#extras");
  container.replaceChildren();
  if (!state.package || state.intent === "enquiry") return;
  const ids = catalogue.eligibility
    .filter((item) => item.package_id === state.package)
    .map((item) => item.add_on_id);
  const extras = catalogue.add_ons.filter((item) => ids.includes(item.id));
  if (!extras.length) return;
  container.append(
    el("h3", { class: "booking-section", text: "A little extra attention?" }),
  );
  const list = el("div", { class: "extra-list" });
  for (const extra of extras) {
    const input = el("input", { type: "checkbox", value: extra.id });
    input.checked = state.extras.includes(extra.id);
    input.addEventListener("change", () => {
      state.extras = input.checked
        ? [...state.extras, extra.id]
        : state.extras.filter((id) => id !== extra.id);
      resetQuote();
      renderSummary();
    });
    list.append(
      el(
        "label",
        { class: "extra-option" },
        input,
        el(
          "span",
          { text: extra.name },
          el("small", { text: `Adds around ${extra.duration_minutes} min` }),
        ),
        el("strong", {
          text: money(
            catalogue.add_on_price_overrides.find(
              (item) =>
                item.add_on_id === extra.id &&
                item.class_id === vehicle()?.class_id,
            )?.price_minor ?? extra.price_minor,
            catalogue.currency,
          ),
        }),
      ),
    );
  }
  container.append(list);
}

function renderDetails() {
  const isBooking = state.intent === "booking";
  app.innerHTML = `<h2 tabindex="-1">A time and place that fit.</h2><p class="intro">${isBooking ? "Check your location, then choose a time to request. All times are shown in the business timezone." : "Tell us where you are and what you need. An enquiry does not reserve a time."}</p><form id="details-form"><div class="field-grid"><div class="field full"><label for="address">${isBooking ? "Service address & parking/access notes" : "Address or location (optional)"}</label><textarea id="address" name="address" maxlength="500" autocomplete="street-address" ${isBooking ? "required" : ""}></textarea></div><div class="field"><label for="postcode">Postcode ${isBooking ? "" : "(optional)"}</label><input id="postcode" name="postcode" maxlength="12" autocomplete="postal-code" ${isBooking ? "required" : ""}></div></div>${isBooking ? '<div class="actions"><button id="check-quote" type="button" class="btn outline">Check price & times ↗</button></div><p class="form-hint">Coverage and prices are checked before you select a time.</p><div id="quote-status" role="status" class="form-hint"></div><div id="schedule"></div>' : ""}<div class="booking-section"><h3>How can we reach you?</h3><div class="field-grid"><div class="field full"><label for="full-name">Full name</label><input id="full-name" name="full_name" maxlength="100" autocomplete="name" required></div><div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="254" autocomplete="email" required></div><div class="field"><label for="phone">Phone number</label><input id="phone" name="phone" type="tel" maxlength="30" autocomplete="tel" required></div><div class="field full"><label for="message">${isBooking ? "Anything else we should know? (optional)" : "What would you like help with?"}</label><textarea id="message" name="message" maxlength="2000" ${isBooking ? "" : "required"}></textarea></div></div></div><div class="actions"><button type="button" id="back" class="back-btn">← Your clean</button><button type="submit" class="btn">Review request <span aria-hidden="true">→</span></button></div></form>`;
  for (const [id, key] of [
    ["address", "address"],
    ["postcode", "postcode"],
    ["full-name", "name"],
    ["email", "email"],
    ["phone", "phone"],
    ["message", "message"],
  ])
    setField(id, key);
  app.querySelector("#back").addEventListener("click", () => {
    state.step = 0;
    render();
  });
  if (isBooking) {
    app.querySelector("#check-quote").addEventListener("click", checkQuote);
    if (state.quote) renderSchedule();
  }
  app.querySelector("#details-form").addEventListener("submit", (event) => {
    event.preventDefault();
    if (isBooking && (!state.quote || !state.startsAt)) {
      showError("Check your price and choose an available time first.");
      return;
    }
    state.step = 2;
    render();
  });
}

async function checkQuote() {
  clearError();
  const postcode = app.querySelector("#postcode");
  if (!postcode.reportValidity()) return;
  const current = ++quoteSequence;
  const button = app.querySelector("#check-quote");
  const status = app.querySelector("#quote-status");
  button.disabled = true;
  status.textContent = "Checking your coverage and price…";
  state.quote = null;
  state.startsAt = "";
  slotSequence++;
  app.querySelector("#schedule").replaceChildren();
  renderSummary();
  try {
    const quote = await api("public/quotes", {
      method: "POST",
      body: {
        service_id: state.service,
        vehicle_model_id: state.model,
        package_id: state.package,
        add_on_ids: state.extras,
        postcode: state.postcode,
      },
    });
    if (current !== quoteSequence || state.step !== 1) return;
    state.quote = quote;
    status.textContent =
      "Your postcode is covered. Choose a day and requested start time below.";
    renderSummary();
    renderSchedule();
  } catch (error) {
    if (current === quoteSequence && state.step === 1) {
      status.textContent = "";
      showError(error);
    }
  } finally {
    button.disabled = false;
  }
}

function renderSchedule() {
  const container = app.querySelector("#schedule");
  container.innerHTML = `<div class="booking-section"><h3>Your requested time</h3><label for="date">Choose a date</label><input id="date" type="date" required><p class="form-hint" id="timezone-note"></p><div id="slot-status" role="status" class="form-hint"></div><div id="slots" class="time-grid" role="group" aria-label="Available requested start times"></div></div>`;
  const date = container.querySelector("#date");
  const localDay = new Intl.DateTimeFormat("en-CA", {
    timeZone: catalogue.timezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
  date.min = localDay;
  date.value = state.date;
  container.querySelector("#timezone-note").textContent =
    `Business timezone: ${catalogue.timezone}. A displayed time is not a confirmed appointment.`;
  date.addEventListener("change", () => {
    state.date = date.value;
    state.startsAt = "";
    loadSlots();
  });
  if (state.date) loadSlots();
}

async function loadSlots() {
  const sequence = ++slotSequence;
  const status = app.querySelector("#slot-status");
  const slots = app.querySelector("#slots");
  slots.replaceChildren();
  status.textContent = "Finding available times…";
  if (!state.date || !state.quote) {
    status.textContent = "";
    return;
  }
  const quoteId = state.quote.quote_id;
  try {
    const data = await api(
      `public/availability?quote_id=${encodeURIComponent(quoteId)}&date=${encodeURIComponent(state.date)}`,
    );
    if (
      sequence !== slotSequence ||
      state.step !== 1 ||
      quoteId !== state.quote?.quote_id
    )
      return;
    status.textContent = data.slots.length
      ? "Choose a requested start time."
      : "No available times on this day. Please choose another date.";
    if (!data.slots.some((item) => item.starts_at === state.startsAt))
      state.startsAt = "";
    for (const slot of data.slots) {
      const radio = el("input", {
        type: "radio",
        name: "requested_time",
        value: slot.starts_at,
        required: "",
      });
      radio.checked = state.startsAt === slot.starts_at;
      radio.addEventListener("change", () => {
        state.startsAt = slot.starts_at;
      });
      slots.append(
        el(
          "label",
          { class: "time-option" },
          radio,
          document.createTextNode(slot.label),
        ),
      );
    }
  } catch (error) {
    if (sequence === slotSequence && state.step === 1) {
      status.textContent = "";
      showError(error);
    }
  }
}

function renderReview() {
  app.innerHTML = `<h2 tabindex="-1">One last look.</h2><p class="intro">${state.intent === "booking" ? "Check your details before sending your request. Your appointment still needs to be confirmed." : "Check your enquiry details. This will not reserve an appointment."}</p><dl class="review-list" id="review-details"></dl><form id="review-form"><div class="booking-section"><label class="check-label"><input id="consent" type="checkbox" required><span>I have read the <a href="/terms.html" target="_blank" rel="noopener">service terms</a> and <a href="/privacy.html" target="_blank" rel="noopener">privacy notice</a> and agree to the handling of my request. ${catalogue.demo_mode ? "This preview is for testing only." : ""}</span></label></div><div class="hp" aria-hidden="true"><label for="website">Leave this field empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div><div class="actions"><button type="button" id="back" class="back-btn">← Edit details</button><button type="submit" class="btn" id="submit-request">Send request <span aria-hidden="true">↗</span></button></div><p class="form-hint">No online payment will be collected. ${catalogue.notification_mode === "log" ? "Test emails are captured locally for review." : "An acknowledgement will be emailed after submission."}</p></form>`;
  const fields = [
    [
      "Request type",
      state.intent === "booking"
        ? "Pay after wash — booking request"
        : "Enquiry — no reserved appointment",
    ],
    ["Contact", `${state.name} · ${state.email} · ${state.phone}`],
    [
      "Location",
      `${state.address || "Not provided"}${state.postcode ? ", " + state.postcode : ""}`,
    ],
    ["Message", state.message || "No additional notes"],
  ];
  if (state.startsAt && state.intent === "booking")
    fields.splice(1, 0, [
      "Requested time",
      new Intl.DateTimeFormat("en", {
        dateStyle: "full",
        timeStyle: "short",
        timeZone: catalogue.timezone,
      }).format(new Date(state.startsAt)) + ` (${catalogue.timezone})`,
    ]);
  for (const [label, value] of fields)
    app
      .querySelector("#review-details")
      .append(
        el("div", {}, el("dt", { text: label }), el("dd", { text: value })),
      );
  const consent = app.querySelector("#consent");
  consent.checked = state.consent;
  consent.addEventListener("change", () => {
    state.consent = consent.checked;
  });
  app.querySelector("#back").addEventListener("click", () => {
    state.step = 1;
    render();
  });
  app.querySelector("#review-form").addEventListener("submit", submitRequest);
}

async function submitRequest(event) {
  event.preventDefault();
  clearError();
  const button = app.querySelector("#submit-request");
  const payload = {
    customer: { full_name: state.name, email: state.email, phone: state.phone },
    address: state.address,
    message: state.message,
    consent: state.consent,
    website: app.querySelector("#website").value,
    ...(state.intent === "booking"
      ? { quote_id: state.quote.quote_id, starts_at: state.startsAt }
      : {
          service_id: state.service,
          vehicle: vehicle()?.name || state.otherVehicle,
          postcode: state.postcode,
        }),
  };
  const serialized = JSON.stringify(payload);
  if (!submission || submission.serialized !== serialized)
    submission = { serialized, key: crypto.randomUUID() };
  button.disabled = true;
  button.textContent = "Sending your request…";
  try {
    const result = await api(
      `public/${state.intent === "booking" ? "bookings" : "enquiries"}`,
      { method: "POST", body: payload, key: submission.key },
    );
    showSuccess(result);
  } catch (error) {
    if (
      ["quote_expired", "quote_changed", "slot_unavailable"].includes(
        error.code,
      )
    ) {
      if (error.code !== "slot_unavailable") state.quote = null;
      state.startsAt = "";
      state.step = 1;
      if (error.code === "quote_changed") {
        try {
          catalogue = await api("public/catalogue");
          if (!service() || !vehicle() || !selectedPackage()) {
            if (!service()) state.service = catalogue.services[0]?.id || "";
            if (!vehicle()) { state.model = ""; state.make = ""; }
            state.package = ""; state.extras = []; state.step = 0;
          }
        } catch { /* Keep the original error and allow a safe retry. */ }
      }
      render();
    }
    showError(error);
  } finally {
    button.disabled = false;
    button.textContent = "Send request ↗";
  }
}

function showSuccess(result) {
  document.querySelector("#booking-layout").hidden = true;
  const panel = document.querySelector("#booking-success");
  panel.hidden = false;
  panel.className = "success-panel";
  const heading = el("h2", {
    tabindex: "-1",
    text: "Your request is safely with us.",
  });
  panel.replaceChildren(
    el("div", { class: "success-icon", "aria-hidden": "true", text: "✓" }),
    heading,
    el("p", {
      text:
        result.status === "pending_confirmation"
          ? "Your booking request has been saved. It is pending confirmation and is not yet a confirmed appointment."
          : "Your enquiry has been saved. No appointment has been reserved.",
    }),
    el("div", { class: "reference", text: result.reference }),
    el("p", {
      text: catalogue.demo_mode
        ? "Keep this reference. Your test request and email acknowledgements are saved locally; no live appointment is arranged."
        : "Keep this reference. Your acknowledgement is queued for email delivery. Contact the team with this reference if you need help.",
    }),
    el("a", { href: "/", class: "btn", text: "Back to a fresh start ↗" }),
  );
  heading.focus();
}

try {
  catalogue = await api("public/catalogue");
  if (!catalogue.booking_enabled)
    throw new Error(
      "Online requests are currently closed. Please contact the team.",
    );
  if (vehicle()) state.make = vehicle().make_id;
  else state.model = "";
  if (!service()) state.service = catalogue.services[0]?.id || "";
  render(false);
} catch (error) {
  app.setAttribute("aria-busy", "false");
  app.replaceChildren(
    el("h2", { text: "We could not load your options." }),
    el("p", { class: "intro", text: error.message }),
    el("button", {
      type: "button",
      class: "btn",
      text: "Try again",
      on: { click: () => location.reload() },
    }),
  );
}
