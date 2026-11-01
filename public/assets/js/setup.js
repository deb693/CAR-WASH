import { api } from "./api.js";
const form = document.querySelector("#setup-form"),
  message = document.querySelector("#setup-message");
try {
  const status = await api("setup/status");
  if (!status.available) {
    form.hidden = true;
    message.textContent =
      "Setup is disabled or already complete. Use the admin sign-in page.";
    form.parentNode.append(message);
  }
} catch (error) {
  message.textContent = error.message;
}
form.addEventListener("submit", async (event) => {
  event.preventDefault();
  const button = form.querySelector("button");
  button.disabled = true;
  try {
    await api("setup/owner", {
      method: "POST",
      body: {
        setup_token: document.querySelector("#setup-token").value,
        name: document.querySelector("#setup-name").value,
        email: document.querySelector("#setup-email").value,
        password: document.querySelector("#setup-password").value,
      },
    });
    location.href = "/admin/";
  } catch (error) {
    message.textContent = error.message;
  } finally {
    button.disabled = false;
  }
});
