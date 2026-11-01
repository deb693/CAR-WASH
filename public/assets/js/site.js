import { api, fillSelect, element } from "./api.js";

const toggle = document.querySelector(".menu-toggle");
const nav = document.querySelector(".nav-links");
toggle?.addEventListener("click", () => {
  const open = toggle.getAttribute("aria-expanded") !== "true";
  toggle.setAttribute("aria-expanded", String(open));
  nav.classList.toggle("is-open", open);
});
document.addEventListener("keydown", (event) => {
  if (
    event.key === "Escape" &&
    toggle?.getAttribute("aria-expanded") === "true"
  ) {
    toggle.setAttribute("aria-expanded", "false");
    nav.classList.remove("is-open");
    toggle.focus();
  }
});

if (
  !matchMedia("(prefers-reduced-motion: reduce)").matches &&
  "IntersectionObserver" in window
) {
  document.body.classList.add("motion-ready");
  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries)
        if (entry.isIntersecting) {
          entry.target.classList.add("visible");
          observer.unobserve(entry.target);
        }
    },
    { threshold: 0.08 },
  );
  document
    .querySelectorAll(".reveal")
    .forEach((node) => observer.observe(node));
}

for (const form of document.querySelectorAll("[data-coverage-form]")) {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = form.querySelector("button");
    const result = form.querySelector("[data-coverage-result]");
    button.disabled = true;
    result.textContent = "Checking your postcode…";
    try {
      const area = await api("public/service-area/check", {
        method: "POST",
        body: { postcode: new FormData(form).get("postcode") },
      });
      result.replaceChildren(
        document.createTextNode(
          area.supported
            ? "Good news. This postcode is covered. "
            : "This postcode is outside our current coverage. ",
        ),
        element("a", {
          href: area.supported ? "/book.html" : "/book.html?intent=enquiry",
          text: area.supported ? "Choose your clean →" : "Send an enquiry →",
        }),
      );
    } catch (error) {
      result.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });
}

const quick = document.querySelector("[data-quick-start]");
if (quick) {
  const make = quick.querySelector("[name=make]");
  const model = quick.querySelector("[name=model]");
  const status = quick.querySelector("[role=status]");
  try {
    const data = await api("public/catalogue");
    fillSelect(make, data.makes, "Choose make");
    make.disabled = false;
    make.addEventListener("change", () => {
      fillSelect(
        model,
        data.models.filter((item) => item.make_id === make.value),
        "Choose model",
      );
      model.disabled = !make.value;
    });
    quick.addEventListener("submit", (event) => {
      event.preventDefault();
      if (quick.reportValidity())
        location.href = `/book.html?model=${encodeURIComponent(model.value)}`;
    });
  } catch (error) {
    status.replaceChildren(
      document.createTextNode(error.message + " "),
      element("a", { href: "/book.html", text: "Open booking →" }),
    );
  }
}
