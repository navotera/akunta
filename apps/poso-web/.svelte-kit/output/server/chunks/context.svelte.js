import "clsx";
function defaultBaseUrl() {
  if (typeof window === "undefined") return "";
  const { hostname, port, protocol } = window.location;
  if ((hostname === "127.0.0.1" || hostname === "localhost") && port === "5174") {
    return `${protocol}//${hostname}:8010`;
  }
  return "";
}
const baseUrl = defaultBaseUrl();
function getCookie(name) {
  if (typeof document === "undefined") return null;
  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const match = document.cookie.match(new RegExp(`(^|; )${escaped}=([^;]*)`));
  return match ? decodeURIComponent(match[2]) : null;
}
let csrfFetched = false;
async function ensureCsrfCookie() {
  if (csrfFetched) return;
  await fetch(`${baseUrl}/sanctum/csrf-cookie`, { credentials: "include" });
  csrfFetched = true;
}
class ApiError extends Error {
  status;
  body;
  constructor(status, body) {
    super(`POSO API error ${status}`);
    this.status = status;
    this.body = body;
  }
}
async function api(path, init = {}) {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  headers.set("X-Requested-With", "XMLHttpRequest");
  headers.set("X-Tenant-Slug", "pt-maju-bersama");
  if (init.body && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }
  const method = (init.method ?? "GET").toUpperCase();
  if (["POST", "PUT", "PATCH", "DELETE"].includes(method)) {
    await ensureCsrfCookie();
    const xsrf = getCookie("XSRF-TOKEN");
    if (xsrf) headers.set("X-XSRF-TOKEN", xsrf);
  }
  const response = await fetch(`${baseUrl}${path}`, {
    ...init,
    method,
    headers,
    credentials: "include"
  });
  if (!response.ok) {
    let body;
    try {
      body = await response.json();
    } catch {
      body = await response.text();
    }
    throw new ApiError(response.status, body);
  }
  return await response.json();
}
function getBootstrap() {
  return api("/api/v1/me").then((response) => response.data);
}
function selectEntity(entityId) {
  return api("/api/v1/context/entity", {
    method: "POST",
    body: JSON.stringify({ entity_id: entityId })
  }).then((response) => response.data.active_entity);
}
const state = { loading: false, error: null, data: null };
const posoContext = {
  get loading() {
    return state.loading;
  },
  get error() {
    return state.error;
  },
  get data() {
    return state.data;
  },
  get entities() {
    return state.data?.entities ?? [];
  },
  get activeEntity() {
    return state.data?.active_entity ?? null;
  },
  get user() {
    return state.data?.user ?? { name: "Andi Darmawan", role: "Administrator" };
  },
  async refresh() {
    state.loading = true;
    state.error = null;
    try {
      state.data = await getBootstrap();
    } catch (error) {
      state.error = error instanceof Error ? error.message : String(error);
    } finally {
      state.loading = false;
    }
  },
  async chooseEntity(entityId) {
    const active = await selectEntity(entityId);
    if (state.data) {
      state.data.active_entity = active;
      state.data.tenant = {
        id: active.tenant_id,
        slug: active.tenant_slug,
        name: active.tenant_name
      };
    }
  }
};
export {
  posoContext as p
};
