/** Session state + auth calls; identity itself lives in the HttpOnly cookie.
 *  The user lives in the shared store (core/state.js) so the header and any
 *  other module can subscribe to session changes. */

import { api } from './api-client.js';
import { appState } from './state.js';
import { navigate } from './router.js';

export function getUser() {
  return appState.get('user');
}

export function setUser(user) {
  appState.set('user', user);
}

export function isAuthenticated() {
  return getUser() !== null;
}

/** Route guard: false (and a redirect to #/login) when there is no session. */
export function requireSession() {
  if (isAuthenticated()) {
    return true;
  }
  navigate('/login');
  return false;
}

/** Resolves the signed-in user, or null when there is no session (401). */
export async function fetchMe() {
  try {
    const data = await api.get('/auth/me');
    setUser(data.user);
    return getUser();
  } catch (error) {
    if (error.status === 401) {
      setUser(null);
      return null;
    }
    throw error;
  }
}

export async function login(payload) {
  const data = await api.post('/auth/login', payload);
  setUser(data.user);
  return getUser();
}

export async function register(payload) {
  const data = await api.post('/auth/register', payload);
  setUser(data.user);
  return getUser();
}

export async function logout() {
  try {
    await api.post('/auth/logout');
  } catch {
    // The local session is dropped regardless of the server round-trip.
  } finally {
    setUser(null);
  }
}
