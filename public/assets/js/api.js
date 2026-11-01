export async function api(path, { method = "GET", body, key, signal } = {}) {
  const headers = { Accept: "application/json" };
  if (body) headers["Content-Type"] = "application/json";
  if (key) headers["Idempotency-Key"] = key;
  let response;
  try {
    response = await fetch(`/api/v1/${path}`, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
      signal,
    });
  } catch (error) {
    if (error.name === "AbortError") throw error;
    throw new Error(
      "We could not connect. Check your connection and try again.",
    );
  }
  let result;
  try {
    result = await response.json();
  } catch {
    throw new Error(
      "The booking service is unavailable. Please try again shortly.",
    );
  }
  if (!response.ok) {
    const error = new Error(
      result.error?.message || "Something went wrong. Please try again.",
    );
    error.code = result.error?.code;
    error.fields = result.error?.fields || {};
    throw error;
  }
  return result.data;
}

export function element(tag, options = {}, ...children) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(options)) {
    if (key === "text") node.textContent = value;
    else if (key === "class") node.className = value;
    else if (key === "on")
      for (const [event, callback] of Object.entries(value))
        node.addEventListener(event, callback);
    else node.setAttribute(key, value);
  }
  node.append(...children);
  return node;
}

export const money = (value, currency) =>
  new Intl.NumberFormat("en", {
    style: "currency",
    currency,
    maximumFractionDigits: value % 100 ? 2 : 0,
  }).format(value / 100);

export function fillSelect(select, items, placeholder) {
  select.replaceChildren(element("option", { value: "", text: placeholder }));
  for (const item of items)
    select.append(element("option", { value: item.id, text: item.name }));
}
