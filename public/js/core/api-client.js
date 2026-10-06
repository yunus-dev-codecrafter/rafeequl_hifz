/** The single place the app talks to the server (conventions §3). */

const BASE_PATH = '/api/v1';

export class ApiError extends Error {
  constructor(status, errors, message) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }

  /** The first validation error for a given field, if any. */
  fieldError(field) {
    const match = this.errors.find((error) => error.field === field);
    return match ? match.message : null;
  }
}

async function request(method, path, body) {
  const init = {
    method,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  };

  if (body !== undefined) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }

  const response = await fetch(BASE_PATH + path, init);

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (!response.ok || payload === null || payload.ok !== true) {
    const errors = Array.isArray(payload?.errors) ? payload.errors : [];
    const message = errors[0]?.message ?? `Request failed (${response.status})`;
    throw new ApiError(response.status, errors, message);
  }

  return payload.data;
}

/**
 * GET returning the raw response body as text (Prompt 23: file downloads
 * such as the CSV export). Error responses still parse their JSON envelope
 * and raise ApiError exactly like request().
 */
async function getText(path) {
  const response = await fetch(BASE_PATH + path, {
    method: 'GET',
    credentials: 'same-origin',
  });

  const text = await response.text();

  if (!response.ok) {
    let payload = null;
    try {
      payload = JSON.parse(text);
    } catch {
      payload = null;
    }
    const errors = Array.isArray(payload?.errors) ? payload.errors : [];
    const message = errors[0]?.message ?? `Request failed (${response.status})`;
    throw new ApiError(response.status, errors, message);
  }

  return text;
}

export const api = {
  get: (path) => request('GET', path),
  post: (path, body) => request('POST', path, body),
  put: (path, body) => request('PUT', path, body),
  delete: (path, body) => request('DELETE', path, body),
  getText,
};
