/* Homepage integration checks: actual media playback, motion and progressive content. */
const { chromium } = require("../.runtime/browser/node_modules/playwright");
const assert = require("node:assert/strict");
const BASE = "http://127.0.0.1:8081";

(async () => {
  const browser = await chromium.launch({
    executablePath: "C:/Program Files/Google/Chrome/Application/chrome.exe",
    headless: true,
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
  });
  const page = await context.newPage();
  const errors = [];
  page.on("pageerror", (error) => errors.push(error.message));
  const instantScroll = async (y) => {
    await page.evaluate((top) => scrollTo({ top, behavior: "instant" }), y);
    await page.waitForTimeout(140);
  };
  try {
    await page.goto(BASE, { waitUntil: "networkidle" });
    await page.waitForFunction(() => {
      const v = document.querySelector("video");
      return v.readyState >= 3 && !v.paused && v.currentTime > 0.2;
    });
    assert.match(
      await page.locator("video").evaluate((v) => v.currentSrc),
      /\/assets\/video\/steamrex-spring\.mp4$/,
    );
    await page.getByRole("button", { name: "Pause background video" }).click();
    assert(await page.locator("video").evaluate((v) => v.paused));
    const origin = await page
      .locator("[data-drive-story]")
      .evaluate((node) => node.getBoundingClientRect().top + scrollY);
    await instantScroll(origin - 82);
    const initialTransform = await page
      .locator(".animated-car")
      .evaluate((node) => getComputedStyle(node).transform);
    const travel = await page
      .locator("[data-drive-story]")
      .evaluate((node) => node.offsetHeight - (innerHeight - 82));
    await instantScroll(origin - 82 + travel * 0.95);
    const movedTransform = await page
      .locator(".animated-car")
      .evaluate((node) => getComputedStyle(node).transform);
    assert.notEqual(
      initialTransform,
      movedTransform,
      "car moves as the story scrolls",
    );
    assert(
      await page
        .locator("[data-drive-chapter]")
        .last()
        .evaluate((node) => node.classList.contains("is-active")),
    );
    await instantScroll(0);
    assert(
      await page.locator("video").evaluate((v) => v.paused),
      "explicit pause persists on returning to hero",
    );
    await page.getByRole("button", { name: "Play background video" }).click();
    await page.waitForFunction(() => !document.querySelector("video").paused);
    console.log(
      "PASS: local MP4 decodes/plays, pause persists, scroll moves the car/wheels and changes story chapter",
    );

    await page.getByRole("tab", { name: "Interior", exact: true }).focus();
    await page.keyboard.press("ArrowRight");
    assert(await page.locator("#exterior-panel").isVisible());
    assert(!(await page.locator("#interior-panel").isVisible()));
    assert.match(
      await page.locator("[data-care-image]").getAttribute("src"),
      /steamrex-exterior/,
    );
    await page.keyboard.press("Home");
    assert(await page.locator("#interior-panel").isVisible());
    assert((await page.locator(".review-card").count()) >= 3);
    assert.equal(
      await page
        .locator("script[type='application/ld+json']")
        .evaluateAll((nodes) =>
          nodes.some((node) => /aggregateRating/.test(node.textContent)),
        ),
      false,
    );
    console.log(
      "PASS: keyboard-accessible care tabs and attributed individual reviews without fabricated aggregate structured data",
    );

    for (const width of [390, 768, 1024, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      for (
        let y = 0;
        y < (await page.evaluate(() => document.body.scrollHeight));
        y += 850
      )
        await instantScroll(y);
      assert(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
        "viewport " + width + " has no overflow",
      );
      const broken = await page
        .locator("main img")
        .evaluateAll((images) =>
          images
            .filter((image) => !image.complete || image.naturalWidth === 0)
            .map((image) => image.src),
        );
      assert.deepEqual(broken, [], "all homepage imagery loads at " + width);
    }
    console.log(
      "PASS: all imagery loads and layouts fit 390, 768, 1024 and 1440 pixel viewports",
    );

    const reduced = await browser.newContext({
      reducedMotion: "reduce",
      viewport: { width: 390, height: 844 },
    });
    const reducedPage = await reduced.newPage();
    await reducedPage.goto(BASE, { waitUntil: "networkidle" });
    assert(
      await reducedPage
        .locator("video")
        .evaluate((v) => v.paused && !v.getAttribute("src")),
      "reduced motion downloads no video automatically",
    );
    assert.equal(
      await reducedPage
        .locator(".animated-car")
        .evaluate((node) => getComputedStyle(node).transform),
      "none",
    );
    await reducedPage
      .getByRole("button", { name: "Play background video" })
      .click();
    await reducedPage.waitForFunction(
      () => !document.querySelector("video").paused,
    );
    await reduced.close();

    const fallback = await browser.newContext();
    const fallbackPage = await fallback.newPage();
    await fallbackPage.route("**/*.mp4", (route) => route.abort());
    await fallbackPage.goto(BASE, { waitUntil: "networkidle" });
    await fallbackPage.waitForFunction(
      () => document.querySelector("video").error !== null,
    );
    assert(
      !(await fallbackPage
        .locator(".home-hero")
        .evaluate((node) => node.classList.contains("has-playing-video"))),
    );
    assert(await fallbackPage.locator(".hero-image").isVisible());
    await fallback.close();

    const noScript = await browser.newContext({ javaScriptEnabled: false });
    const noScriptPage = await noScript.newPage();
    await noScriptPage.goto(BASE);
    assert.equal(await noScriptPage.locator("h1").count(), 1);
    assert((await noScriptPage.locator(".review-card").count()) >= 3);
    assert((await noScriptPage.locator(".service-card a").count()) >= 3);
    await noScript.close();
    assert.deepEqual(errors, []);
    console.log(
      "PASS: reduced-motion and explicit-play behavior, failed-video image fallback, crawlable no-JavaScript content and no JavaScript errors",
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
