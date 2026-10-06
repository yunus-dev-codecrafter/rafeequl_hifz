/** Settings, account and privacy endpoints (Prompt 18). */

import { api } from '../../core/api-client.js';

export function getSettings() {
  return api.get('/settings').then((data) => data.settings);
}

/** Partial update — only the patched fields change server-side. */
export function updateSettings(patch) {
  return api.put('/settings', patch).then((data) => data.settings);
}

export function updateProfile(patch) {
  return api.put('/auth/profile', patch).then((data) => data.user);
}

/** Revokes every session; the caller is signed out. */
export function changePassword(payload) {
  return api.put('/auth/password', payload);
}

/** Personal-data export payload (JSON). */
export function exportAccount() {
  return api.get('/account/export');
}

/** Sectioned CSV export as raw text (Prompt 23). */
export function exportAccountCsv() {
  return api.getText('/account/export?format=csv');
}

export function deleteAccount(password) {
  return api.delete('/account', { password });
}
