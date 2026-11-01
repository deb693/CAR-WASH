/* Optional QA tool: npm install --prefix .runtime/browser playwright --no-save */
const { chromium } = require("../.runtime/browser/node_modules/playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

(async () => {
  const base = process.env.TEST_URL || "http://127.0.0.1:8081";
  if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base))
    throw new Error("Browser tests only submit to a loopback test server.");
  const browser = await chromium.launch({
    executablePath: "C:/Program Files/Google/Chrome/Application/chrome.exe",
    headless: true,
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1050 },
  });
  const page = await context.newPage();
  const errors = [];
  page.on("pageerror", (error) => errors.push(error.message));
  fs.mkdirSync("test-results", { recursive: true });
  try {
    await page.goto(base, { waitUntil: "networkidle" });
    // Exercise real scroll reveals before taking a full-page image.
    for (
      let y = 0;
      y < (await page.evaluate(() => document.body.scrollHeight));
      y += 600
    ) {
      await page.evaluate((position) => scrollTo(0, position), y);
      await page.waitForTimeout(120);
    }
    await page.evaluate(() => scrollTo(0, 0));
    await page.waitForTimeout(750);
    assert.equal(
      await page.locator(".reveal:not(.visible)").count(),
      0,
      "all scroll-reveal sections become visible",
    );
    await page.screenshot({
      path: "test-results/home-desktop.png",
      fullPage: true,
    });
    assert.match(await page.title(), /Mobile steam car cleaning.*SteamRex/);
    assert.equal(
      await page.locator(".site-header .brand").getAttribute("aria-label"),
      "SteamRex home",
    );
    await page.locator("#quick-make").selectOption("volkswagen");
    await page.locator("#quick-model").selectOption("vw-golf");
    await page.getByRole("button", { name: /See my options/ }).click();
    await page.waitForURL("**/book.html?model=vw-golf");
    await page.locator(".package-option input").first().waitFor();
    assert.equal(await page.locator(".package-option").count(), 3);
    await page.locator(".package-option input").first().check();
    await page.getByRole("button", { name: /Your details/ }).click();
    await page
      .locator("#address")
      .fill("TEST ONLY — 1 Example Street, parking beside entrance");
    await page.locator("#postcode").fill("1000");
    await page.getByRole("button", { name: /Check price/ }).click();
    await page.locator("#date").waitFor();
    const day = new Date(Date.now() + 12 * 86400000);
    while (day.getUTCDay() === 0) day.setUTCDate(day.getUTCDate() + 1);
    await page.locator("#date").fill(day.toISOString().slice(0, 10));
    await page.locator(".time-option input").first().waitFor();
    await page.locator(".time-option input").first().check();
    await page.locator("#full-name").fill("Browser Test");
    await page.locator("#email").fill("browser@example.test");
    await page.locator("#phone").fill("+4512345678");
    await page.getByRole("button", { name: /Review request/ }).click();
    await page.locator("#consent").check();
    await page.screenshot({
      path: "test-results/booking-review.png",
      fullPage: true,
    });
    await page.getByRole("button", { name: /Send request/ }).click();
    await page.locator("#booking-success .reference").waitFor();
    assert.match(
      await page.locator("#booking-success").innerText(),
      /pending confirmation/,
    );
    console.log(
      "PASS: browser booking from homepage to persisted pending request",
    );

    await page.goto(base + "/book.html?intent=enquiry", {
      waitUntil: "networkidle",
    });
    await page.locator("#make").selectOption("other");
    await page.locator("#other-vehicle").fill("Unlisted test vehicle");
    await page.getByRole("button", { name: /Your details/ }).click();
    await page.locator("#postcode").fill("9999");
    await page.locator("#full-name").fill("Enquiry Test");
    await page.locator("#email").fill("enquiry@example.test");
    await page.locator("#phone").fill("+4512345678");
    await page
      .locator("#message")
      .fill("Test-only enquiry for an unlisted vehicle outside coverage.");
    await page.getByRole("button", { name: /Review request/ }).click();
    await page.locator("#consent").check();
    await page.getByRole("button", { name: /Send request/ }).click();
    await page.locator("#booking-success .reference").waitFor();
    assert.match(
      await page.locator("#booking-success").innerText(),
      /No appointment has been reserved/,
    );
    console.log(
      "PASS: browser enquiry with unsupported postcode and unknown vehicle",
    );

    const mobile = await context.newPage();
    mobile.on("pageerror", (error) => errors.push(error.message));
    await mobile.setViewportSize({ width: 390, height: 844 });
    await mobile.emulateMedia({ reducedMotion: "reduce" });
    const pages = [
      "/",
      "/services.html",
      "/services/interior.html",
      "/services/exterior.html",
      "/services/complete.html",
      "/how-it-works.html",
      "/service-areas.html",
      "/contact.html",
      "/book.html",
      "/privacy.html",
      "/terms.html",
    ];
    for (const url of pages) {
      const response = await mobile.goto(base + url, {
        waitUntil: "networkidle",
      });
      assert.equal(response.status(), 200, url);
      assert.equal(await mobile.locator("h1").count(), 1, url + " has one H1");
      assert(
        await mobile.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
        url + " does not overflow mobile viewport",
      );
    }
    await mobile.goto(base, { waitUntil: "networkidle" });
    for (
      let y = 0;
      y < (await mobile.evaluate(() => document.body.scrollHeight));
      y += 650
    ) {
      await mobile.evaluate((position) => scrollTo(0, position), y);
      await mobile.waitForTimeout(80);
    }
    await mobile.evaluate(() => scrollTo(0, 0));
    await mobile.screenshot({
      path: "test-results/home-mobile.png",
      fullPage: true,
    });
    await mobile.getByRole("button", { name: "Open navigation" }).click();
    assert.equal(
      await mobile
        .getByRole("button", { name: "Open navigation" })
        .getAttribute("aria-expanded"),
      "true",
    );
    await mobile.keyboard.press("Escape");
    assert.equal(
      await mobile
        .getByRole("button", { name: "Open navigation" })
        .getAttribute("aria-expanded"),
      "false",
    );
    console.log(
      "PASS: 11 mobile pages, one H1, no horizontal overflow, reduced motion and menu keyboard behavior",
    );
    assert.deepEqual(errors, [], "No browser JavaScript errors");
    console.log("PASS: no browser JavaScript errors");
    console.log("Screenshots saved to " + path.resolve("test-results"));
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
