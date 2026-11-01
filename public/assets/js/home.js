const reducedMotion = matchMedia("(prefers-reduced-motion: reduce)");
const fullMotion = matchMedia("(min-width: 900px) and (min-height: 761px)");
const saveData = navigator.connection?.saveData === true;
const hero = document.querySelector(".home-hero");
const video = document.querySelector("[data-hero-video]");
const videoButton = document.querySelector("[data-video-toggle]");
let heroVisible = true;
let userPaused = false;
let userRequestedPlayback = false;
let videoFailed = false;

function motionPreference() {
  document.body.classList.toggle("home-motion", !reducedMotion.matches);
}
motionPreference();

function setVideoState(playing) {
  if (!videoButton) return;
  videoButton.setAttribute("aria-pressed", String(playing));
  videoButton.setAttribute(
    "aria-label",
    `${playing ? "Pause" : "Play"} background video`,
  );
  videoButton.querySelector("[data-video-icon]").textContent = playing
    ? "Ⅱ"
    : "▶";
  videoButton.querySelector("[data-video-label]").textContent = playing
    ? "Pause film"
    : "Play film";
}

async function updateVideo() {
  if (!video || video.dataset.enabled !== "true" || videoFailed) return;
  const allowed =
    userRequestedPlayback || (!reducedMotion.matches && !saveData);
  if (!allowed || userPaused || !heroVisible || document.hidden) {
    video.pause();
    return;
  }
  if (!video.getAttribute("src")) video.src = video.dataset.src;
  video.muted = true;
  try {
    await video.play();
  } catch {
    setVideoState(false);
  }
}

if (video && videoButton && video.dataset.enabled === "true") {
  videoButton.hidden = false;
  video.addEventListener("playing", () => {
    hero.classList.add("has-playing-video");
    setVideoState(true);
  });
  video.addEventListener("pause", () => setVideoState(false));
  video.addEventListener("error", () => {
    videoFailed = true;
    hero.classList.remove("has-playing-video");
    videoButton.hidden = true;
  });
  videoButton.addEventListener("click", () => {
    if (!video.paused) {
      userPaused = true;
      video.pause();
    } else {
      userPaused = false;
      userRequestedPlayback = true;
      updateVideo();
    }
  });
  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver(
      ([entry]) => {
        heroVisible = entry.isIntersecting;
        updateVideo();
      },
      { threshold: 0.05 },
    );
    observer.observe(hero);
  } else updateVideo();
  document.addEventListener("visibilitychange", updateVideo);
}

// A single pending animation frame, triggered by scroll/resize only. No perpetual loop.
const story = document.querySelector("[data-drive-story]");
const chapters = [...document.querySelectorAll("[data-drive-chapter]")];
let framePending = false;
const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
function renderDrive() {
  framePending = false;
  if (!story) return;
  const enabled = !reducedMotion.matches && fullMotion.matches;
  if (!enabled) {
    for (const property of [
      "--car-x",
      "--car-scale",
      "--wheel-turn",
      "--shine-x",
      "--drive-progress",
    ])
      story.style.removeProperty(property);
    chapters.forEach((chapter, i) =>
      chapter.classList.toggle("is-active", i === 0),
    );
    return;
  }
  const bounds = story.getBoundingClientRect();
  if (bounds.bottom < 0 || bounds.top > innerHeight) return;
  const distance = Math.max(1, bounds.height - (innerHeight - 82));
  const progress = clamp((82 - bounds.top) / distance, 0, 1);
  const eased = progress * progress * (3 - 2 * progress);
  story.style.setProperty(
    "--car-x",
    `${(eased - 0.5) * Math.min(innerWidth * 0.13, 180)}px`,
  );
  story.style.setProperty(
    "--car-scale",
    String(0.95 + Math.sin(progress * Math.PI) * 0.05),
  );
  story.style.setProperty("--wheel-turn", `${eased * 145}deg`);
  story.style.setProperty("--shine-x", `${eased * 280}px`);
  story.style.setProperty("--drive-progress", String(Math.max(0.03, progress)));
  const active = Math.min(2, Math.floor(progress * 3));
  chapters.forEach((chapter, i) =>
    chapter.classList.toggle("is-active", i === active),
  );
}
function requestDriveFrame() {
  if (framePending) return;
  framePending = true;
  requestAnimationFrame(renderDrive);
}
if (story) {
  addEventListener("scroll", requestDriveFrame, { passive: true });
  addEventListener("resize", requestDriveFrame, { passive: true });
  fullMotion.addEventListener("change", requestDriveFrame);
  requestDriveFrame();
}
reducedMotion.addEventListener("change", () => {
  motionPreference();
  userRequestedPlayback = false;
  updateVideo();
  requestDriveFrame();
});

const tabRoot = document.querySelector("[data-care-tabs]");
if (tabRoot) {
  const tabs = [...tabRoot.querySelectorAll("[role=tab]")];
  const image = tabRoot.querySelector("[data-care-image]");
  const caption = tabRoot.querySelector("[data-care-caption]");
  const images = {
    interior: {
      src: "/assets/images/steamrex-detail.jpg",
      alt: "SteamRex interior detail cleaning",
      caption: "THE INSIDE STORY",
    },
    exterior: {
      src: "/assets/images/steamrex-exterior.jpg",
      alt: "SteamRex exterior car cleaning",
      caption: "A FRESH OUTLOOK",
    },
  };
  function selectTab(tab, focus = false) {
    const key = tab.dataset.careTab;
    tabs.forEach((candidate) => {
      const selected = candidate === tab;
      candidate.setAttribute("aria-selected", String(selected));
      candidate.tabIndex = selected ? 0 : -1;
      document.getElementById(candidate.getAttribute("aria-controls")).hidden =
        !selected;
    });
    const nextImage = images[key];
    image.src = nextImage.src;
    image.alt = nextImage.alt;
    caption.textContent = nextImage.caption;
    if (focus) tab.focus();
  }
  tabs.forEach((tab, index) => {
    tab.addEventListener("click", () => selectTab(tab));
    tab.addEventListener("keydown", (event) => {
      let next;
      if (event.key === "ArrowRight") next = (index + 1) % tabs.length;
      if (event.key === "ArrowLeft")
        next = (index - 1 + tabs.length) % tabs.length;
      if (event.key === "Home") next = 0;
      if (event.key === "End") next = tabs.length - 1;
      if (next !== undefined) {
        event.preventDefault();
        selectTab(tabs[next], true);
      }
    });
  });
}
