/** User notifications: the toast facade every feature calls, plus helpers for
 *  optional desktop (system) notifications. Toast rendering stays in toast.js. */

import { showToast } from './toast.js';
import { isDesktopNotificationsEnabled } from '../core/prefs.js';

/** In-app messages (live region). Prefer the typed helpers. */
export const notify = {
  show(message, variant = 'info') {
    showToast(message, variant);
  },
  info(message) {
    showToast(message, 'info');
  },
  success(message) {
    showToast(message, 'success');
  },
  error(message) {
    showToast(message, 'error');
  },
};

// --- desktop notifications (opt-in, gated by browser permission) ----------

export function isDesktopSupported() {
  return typeof window !== 'undefined' && 'Notification' in window;
}

/** 'default' | 'granted' | 'denied' | 'unsupported' */
export function desktopPermission() {
  return isDesktopSupported() ? Notification.permission : 'unsupported';
}

export async function requestDesktopPermission() {
  if (!isDesktopSupported()) {
    return 'unsupported';
  }
  if (Notification.permission === 'granted') {
    return 'granted';
  }
  try {
    return await Notification.requestPermission();
  } catch {
    return 'denied';
  }
}

/** Fires one desktop notification; respects the stored preference. Returns
 *  true when a notification was actually shown. `options.onClick` (optional)
 *  is wired as the click handler and the notification closes itself. */
export function showDesktopNotification(title, options = {}) {
  if (!isDesktopNotificationsEnabled() || desktopPermission() !== 'granted') {
    return false;
  }
  try {
    const { onClick, ...payload } = options;
    const notification = new Notification(title, payload);
    if (typeof onClick === 'function') {
      notification.onclick = () => {
        onClick();
        notification.close();
      };
    }
    return true;
  } catch {
    return false;
  }
}
