const { apiUrl, nonce } = window.novaToolsPolyglot || {};

async function request(method, path, body = null) {
  let url = `${apiUrl}polyglot/v1${path}`;
  if (apiUrl && apiUrl.includes("?")) {
    const separatorIndex = path.indexOf("?");
    if (separatorIndex !== -1) {
      const cleanPath =
        path.substring(0, separatorIndex) +
        "&" +
        path.substring(separatorIndex + 1);
      url = `${apiUrl}polyglot/v1${cleanPath}`;
    }
  }
  const options = {
    method,
    headers: {
      "Content-Type": "application/json",
      "X-WP-Nonce": nonce,
    },
  };

  if (body !== null) {
    options.body = JSON.stringify(body);
  }

  const response = await fetch(url, options);

  if (!response.ok) {
    const error = await response.json().catch(() => ({
      message: `HTTP ${response.status}`,
    }));
    throw new Error(
      error.message || `Request failed with status ${response.status}`,
    );
  }

  return response.json();
}

export const api = {
  get: (path) => request("GET", path),
  post: (path, body) => request("POST", path, body),
  put: (path, body) => request("PUT", path, body),
  del: (path) => request("DELETE", path),
};
