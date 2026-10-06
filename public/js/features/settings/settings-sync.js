/** Server → device preference reconciliation (Prompt 18) plus the offline
 *  last-good snapshot used for write-through rollback (Prompt 19).
 *
 * localStorage stays the fast paint source; the account settings are the
 * source of truth. After sign-in (and on boot when a session exists) the
 * server values overwrite the local cache so preferences follow the
 * account across devices. Desktop notifications are NOT synced — the
 * browser permission itself is device-bound.
 */

import { getSettings } from './settings-api.js';
import { setThemeMode, setSound, setAwake } from '../../core/prefs.js';

const SNAPSHOT_KEY = 'rafeeq.settingsSnapshot';

/** Persists the last-known server settings (device-only, non-sensitive). */
export function saveSnapshot(settings) {
  try {
    localStorage.setItem(SNAPSHOT_KEY, JSON.stringify(settings));
  } catch {
    // Storage unavailable/full: rollback degrades to keeping local values.
  }
}

/** Last-good server settings seen on this device, or null. */
export function loadSnapshot() {
  try {
    const raw = localStorage.getItem(SNAPSHOT_KEY);
    return raw === null ? null : JSON.parse(raw);
  } catch {
    return null;
  }
}

/** Re-applies the last-good server settings to the device. Used when a
 *  write-through fails offline and the server cannot be reached to
 *  reconcile, so optimistic local changes do not linger as fake state. */
export async function restoreFromSnapshot() {
  const settings = loadSnapshot();
  if (settings === null) {
    return null;
  }

  setThemeMode(settings.theme);
  setSound(settings.sound_enabled);
  await setAwake(settings.screen_awake_enabled);

  return settings;
}

/** Fetches account settings and applies the device-relevant ones. Returns
 *  the settings, or null when the server cannot be reached (keep local). */
export async function reconcileFromServer() {
  try {
    const settings = await getSettings();

    setThemeMode(settings.theme);
    setSound(settings.sound_enabled);
    await setAwake(settings.screen_awake_enabled);
    saveSnapshot(settings);

    return settings;
  } catch {
    return null;
  }
}
