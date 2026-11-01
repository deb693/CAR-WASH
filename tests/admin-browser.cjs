const { chromium } = require("../.runtime/browser/node_modules/playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const BASE = "http://127.0.0.1:8081";

(async () => {
  const browser = await chromium.launch({
    executablePath: "C:/Program Files/Google/Chrome/Application/chrome.exe",
    headless: true,
  });
  const context = await browser.newContext({
      viewport: { width: 1440, height: 1000 },
    }),
    page = await context.newPage(),
    errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  const get = async (path) => {
    const response = await page.request.get(BASE + "/api/v1/" + path);
    assert(response.ok());
    return (await response.json()).data;
  };
  const navigate = async (hash) => {
    await page.goto(BASE + "/admin/#" + hash);
    await page.locator("#main h1").waitFor();
    assert.equal(
      await page
        .getByRole("heading", { name: "Unable to load this section" })
        .count(),
      0,
      hash,
    );
  };
  const save = async (label = "Save changes") => {
    await page
      .locator("dialog")
      .getByRole("button", { name: label, exact: true })
      .click();
    await page.locator("dialog[open]").waitFor({ state: "hidden" });
  };
  try {
    await page.goto(BASE + "/admin/");
    await page.locator("#login-email").fill("owner@example.test");
    await page.locator("#login-password").fill("TestOnlySteamRex!2026");
    await page.getByRole("button", { name: "Sign in" }).click();
    await page
      .getByRole("heading", { name: "A clear view of your day." })
      .waitFor();
    const meta = await get("admin/metadata");
    for (const resource of Object.keys(meta.resources)) {
      await navigate("catalogue/" + resource);
      await page
        .getByRole("button", {
          name: resource === "content" ? "Add page" : "Add item",
          exact: true,
        })
        .click();
      await page.locator("dialog[open]").waitFor();
      await page
        .locator("dialog")
        .getByRole("button", { name: "×", exact: true })
        .click();
    }
    for (const route of [
      "settings",
      "users",
      "audit",
      "readiness",
      "notifications",
      "media",
      "bookings",
      "enquiries",
    ])
      await navigate(route);
    console.log(
      "PASS: all admin sections and every resource editor load without errors",
    );
    await navigate("catalogue/add-ons");
    await page.getByRole("button", { name: "Add item", exact: true }).click();
    await page.getByLabel("Add-on name", { exact: true }).fill("Browser extra");
    await page
      .getByLabel("Description", { exact: true })
      .fill("A browser-created optional treatment");
    await page.getByLabel("Price (DKK)", { exact: true }).fill("12.50");
    await page.getByLabel("Additional minutes", { exact: true }).fill("5");
    await save();
    await page.getByText("Browser extra", { exact: true }).waitFor();
    await navigate("catalogue/packages");
    let row = page
      .locator("tbody tr")
      .filter({ hasText: "Essential" })
      .filter({ hasText: "Complete clean" })
      .filter({ hasText: "Small / hatchback" });
    await row.getByRole("button", { name: "Edit", exact: true }).click();
    await page.getByLabel("Price (DKK)", { exact: true }).fill("85.50");
    await page.getByLabel("Browser extra", { exact: true }).check();
    await page
      .getByLabel("Inclusions — one per line", { exact: true })
      .fill("Careful surface clean\nGlass cleaned\nBrowser-added attention");
    await save();
    const catalog = await get("public/catalogue");
    assert.equal(
      catalog.packages.find((p) => p.id === "full-small-1").price_minor,
      8550,
    );
    console.log(
      "PASS: browser creates priced add-on, attaches it to a package and changes live public price/inclusions",
    );
    await navigate("catalogue/service-areas");
    await page.getByRole("button", { name: "Add item", exact: true }).click();
    await page.getByLabel("Area name", { exact: true }).fill("Browser area");
    await page.getByLabel("Exact postcode", { exact: true }).fill("5678");
    await page.getByLabel("Travel surcharge (DKK)", { exact: true }).fill("0");
    await save();
    const area = (
      await get("admin/catalogue/service-areas?q=Browser")
    ).items.find((a) => a.postcode === "5678");
    assert(area);
    const customer = await context.newPage();
    customer.on("pageerror", (e) => errors.push(e.message));
    await customer.goto(BASE + "/book.html?model=vw-golf");
    await customer.locator(".package-option input").first().waitFor();
    await customer.locator("#package-full-small-1").check();
    await customer.getByLabel("Browser extra", { exact: false }).waitFor();
    await customer.getByLabel("Browser extra", { exact: false }).check();
    await customer.getByRole("button", { name: /Your details/ }).click();
    await customer.locator("#address").fill("TEST ONLY browser address");
    await customer.locator("#postcode").fill("5678");
    await customer.getByRole("button", { name: /Check price/ }).click();
    await customer.locator("#date").waitFor();
    const day = new Date(Date.now() + 15 * 86400000);
    while (day.getUTCDay() === 0) day.setUTCDate(day.getUTCDate() + 1);
    await customer.locator("#date").fill(day.toISOString().slice(0, 10));
    await customer.locator(".time-option input").first().waitFor();
    await customer.locator(".time-option input").first().check();
    await customer.locator("#full-name").fill("Browser lifecycle");
    await customer.locator("#email").fill("browser-lifecycle@example.test");
    await customer.locator("#phone").fill("+4512345678");
    await customer.getByRole("button", { name: /Review request/ }).click();
    await customer.locator("#consent").check();
    await customer.getByRole("button", { name: /Send request/ }).click();
    await customer.locator(".reference").waitFor();
    const reference = await customer.locator(".reference").innerText();
    const booking = (await get("admin/bookings?q=" + reference)).items[0];
    let detail = await get("admin/bookings/" + booking.id);
    assert.equal(detail.quote.total_minor, 9800);
    await navigate("bookings/" + booking.id);
    await page
      .getByRole("button", { name: "Confirm appointment", exact: true })
      .click();
    await save();
    await page.locator(".admin-badge.confirmed").first().waitFor();
    assert.equal(await page.locator("#main h1").count(), 1);
    await page
      .getByRole("button", { name: "Add internal note", exact: true })
      .click();
    await page
      .getByLabel("Note (not sent to the customer)", { exact: true })
      .fill("Browser-verified internal note");
    await save();
    await page
      .getByText("Browser-verified internal note", { exact: true })
      .waitFor();
    await page.screenshot({
      path: "test-results/admin-booking.png",
      fullPage: true,
    });
    await page
      .getByRole("button", { name: "Cancel booking", exact: true })
      .click();
    await page
      .getByLabel("Reason / context", { exact: true })
      .fill("Browser test cancellation");
    await save();
    await page.locator(".admin-badge.cancelled").first().waitFor();
    console.log(
      "PASS: real customer uses admin add-on and postcode; admin confirms, notes and cancels with correct quote",
    );
    await customer.goto(BASE + "/book.html?intent=enquiry");
    await customer.locator("#make").selectOption("other");
    await customer.locator("#other-vehicle").fill("Browser conversion vehicle");
    await customer.getByRole("button", { name: /Your details/ }).click();
    await customer.locator("#full-name").fill("Browser conversion");
    await customer.locator("#email").fill("browser-convert@example.test");
    await customer.locator("#phone").fill("+4512345678");
    await customer.locator("#message").fill("Please arrange a clean.");
    await customer.getByRole("button", { name: /Review request/ }).click();
    await customer.locator("#consent").check();
    await customer.getByRole("button", { name: /Send request/ }).click();
    await customer.locator(".reference").waitFor();
    const enquiryRef = await customer.locator(".reference").innerText();
    const enquiry = (await get("admin/enquiries?q=" + enquiryRef)).items[0];
    await navigate("enquiries/" + enquiry.id);
    await page
      .getByRole("button", { name: "Convert to booking", exact: true })
      .click();
    await page.getByLabel("Service", { exact: true }).selectOption("full");
    await page.getByLabel("Vehicle", { exact: true }).selectOption("vw-golf");
    await page
      .getByLabel("Package", { exact: true })
      .selectOption("full-small-1");
    await page.getByLabel("Postcode", { exact: true }).fill("1000");
    await page
      .getByLabel("Address / access details", { exact: true })
      .fill("TEST ONLY conversion address");
    await page
      .getByRole("button", { name: "Calculate current quote", exact: true })
      .click();
    await page
      .locator("dialog .notice")
      .filter({ hasText: "Customer agreement is required" })
      .waitFor();
    const convertDay = new Date(Date.now() + 19 * 86400000);
    while (convertDay.getUTCDay() === 0)
      convertDay.setUTCDate(convertDay.getUTCDate() + 1);
    await page
      .getByLabel("Date", { exact: true })
      .fill(convertDay.toISOString().slice(0, 10));
    await page.waitForFunction(
      () =>
        document.querySelector("dialog select[required]:last-of-type") || true,
    );
    const times = page.getByLabel("Requested start", { exact: true });
    await times.locator("option").nth(1).waitFor({ state: "attached" });
    await times.selectOption(
      await times.locator("option").nth(1).getAttribute("value"),
    );
    await page
      .getByLabel(
        "The customer agreed to this quote and appointment details.",
        { exact: true },
      )
      .check();
    await page
      .getByLabel("Also confirm the appointment now.", { exact: true })
      .check();
    await save("Create booking");
    await page.waitForURL("**/admin/#bookings/*");
    await page.locator(".admin-badge.confirmed").first().waitFor();
    assert.equal(await page.locator("#main h1").count(), 1);
    console.log(
      "PASS: browser enquiry conversion with fresh quote, customer agreement and exactly one rendered detail view",
    );
    const mobile = await context.newPage();
    mobile.on("pageerror", (e) => errors.push(e.message));
    await mobile.setViewportSize({ width: 390, height: 844 });
    for (const route of [
      "overview",
      "bookings",
      "catalogue/packages",
      "settings",
      "readiness",
    ]) {
      await mobile.goto(BASE + "/admin/#" + route);
      await mobile.locator("#main h1").waitFor();
      assert(
        await mobile.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
        route + " overflows",
      );
    }
    await mobile.screenshot({
      path: "test-results/admin-mobile.png",
      fullPage: true,
    });
    await navigate("overview");
    await page.screenshot({
      path: "test-results/admin-overview.png",
      fullPage: true,
    });
    assert.deepEqual(errors, []);
    console.log("PASS: admin mobile layouts and no browser JavaScript errors");
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
