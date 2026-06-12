const { apiUrl, nonce } = window.novaToolsPolyglot || {};

async function request(method, path, body = null) {
	const url = `${apiUrl}polyglot/v1${path}`;
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
		throw new Error(error.message || `Request failed with status ${response.status}`);
	}

	return response.json();
}

export const api = {
	get: (path) => request("GET", path),
	post: (path, body) => request("POST", path, body),
	put: (path, body) => request("PUT", path, body),
	del: (path) => request("DELETE", path),
};
