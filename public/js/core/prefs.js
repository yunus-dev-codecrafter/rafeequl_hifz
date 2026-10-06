/** Persisted display preferences: theme, screen wake lock, sound (localStorage). */

const KEYS = {
  theme: 'rafeeq.theme',
  awake: 'rafeeq.awake',
  sound: 'rafeeq.sound',
  notify: 'rafeeq.notify',
};

const THEMES = ['auto', 'light', 'dark'];

let wakeLock = null;

function read(key, fallback) {
  try {
    return window.localStorage.getItem(key) ?? fallback;
  } catch {
    return fallback;
  }
}

function write(key, value) {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    // Private mode: preferences simply do not persist.
  }
}

// --- theme ----------------------------------------------------------------

/** Current theme mode: auto (default), light or dark. */
export function getTheme() {
  const value = read(KEYS.theme, 'auto');
  return THEMES.includes(value) ? value : 'auto';
}

/** Applies the stored theme to <html data-theme> (auto = follow the system). */
export function applyTheme() {
  document.documentElement.dataset.theme = getTheme();
}

/** Applies a specific mode (auto | light | dark) and persists it. Returns the mode. */
export function setThemeMode(mode) {
  if (!THEMES.includes(mode)) {
    return getTheme();
  }
  write(KEYS.theme, mode);
  applyTheme();
  return mode;
}

/** Advances auto → light → dark → auto. Returns the new mode. */
export function cycleTheme() {
  const next = THEMES[(THEMES.indexOf(getTheme()) + 1) % THEMES.length];
  return setThemeMode(next);
}

// --- screen wake lock ------------------------------------------------------

export function isWakeSupported() {
  return typeof navigator !== 'undefined' && 'wakeLock' in navigator;
}

export function isAwakeEnabled() {
  return read(KEYS.awake, 'off') === 'on';
}

/** Persists the preference and acquires/releases the wake lock. */
export async function setAwake(enabled) {
  write(KEYS.awake, enabled ? 'on' : 'off');
  if (enabled) {
    await acquireWakeLock();
  } else {
    await releaseWakeLock();
  }
}

async function acquireWakeLock() {
  if (!isWakeSupported() || !isAwakeEnabled()) {
    return;
  }
  try {
    wakeLock = await navigator.wakeLock.request('screen');
    wakeLock.addEventListener('release', () => {
      wakeLock = null;
    });
  } catch {
    wakeLock = null;
  }
}

async function releaseWakeLock() {
  if (wakeLock !== null) {
    try {
      await wakeLock.release();
    } catch {
      // Already released.
    }
    wakeLock = null;
  }
}

/** Re-acquires the lock after the tab becomes visible again. */
export async function resumeWakeLock() {
  if (isAwakeEnabled() && wakeLock === null) {
    await acquireWakeLock();
  }
}

// --- sound -----------------------------------------------------------------

export function isSoundEnabled() {
  return read(KEYS.sound, 'on') === 'on';
}

export function setSound(enabled) {
  write(KEYS.sound, enabled ? 'on' : 'off');
}

// --- desktop notifications -------------------------------------------------

export function isDesktopNotificationsEnabled() {
  return read(KEYS.notify, 'off') === 'on';
}

export function setDesktopNotifications(enabled) {
  write(KEYS.notify, enabled ? 'on' : 'off');
}

// --- daily reminders (Prompt 21, device-local) -----------------------------

export const REMINDER_CATS = ['revision', 'rabt', 'memorization', 'flip', 'tasks'];
const DEFAULT_REMINDERS = { time: '18:00', cats: {} };
REMINDER_CATS.forEach((cat) => { DEFAULT_REMINDERS.cats[cat] = true; });

/** Reminder prefs: {time, cats:{revision,rabt,memorization,flip,tasks}}.
 *  The master gate is the existing desktop-notification switch + permission. */
export function getReminders() {
  const raw = read('rafeeq.reminders', null);
  if (raw === null) {
    return cloneReminders(DEFAULT_REMINDERS);
  }
  try {
    const parsed = JSON.parse(raw);
    const cats = {};
    for (const cat of REMINDER_CATS) {
      cats[cat] = parsed?.cats?.[cat] !== false;
    }
    const time = typeof parsed?.time === 'string' && /^\d{2}:\d{2}$/.test(parsed.time)
      ? parsed.time
      : DEFAULT_REMINDERS.time;
    return { time, cats };
  } catch {
    return cloneReminders(DEFAULT_REMINDERS);
  }
}

function cloneReminders(value) {
  return { time: value.time, cats: { ...value.cats } };
}

/** Merges a partial update and persists it. Returns the full prefs. */
export function setReminders(patch) {
  const current = getReminders();
  const next = {
    time: patch.time ?? current.time,
    cats: { ...current.cats, ...(patch.cats ?? {}) },
  };
  write('rafeeq.reminders', JSON.stringify(next));
  return next;
}

/** Local YYYY-MM-DD for day stamps. */
export function reminderDayKey(date = new Date()) {
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return date.getFullYear() + '-' + m + '-' + d;
}

/** The day reminders were last delivered ('' when never). */
export function getReminderSent() {
  return read('rafeeq.reminder.sent', '');
}

/** Records that today's reminder was delivered (or the window closed). */
export function markReminderSent(dayKey) {
  write('rafeeq.reminder.sent', dayKey);
}

// --- init ------------------------------------------------------------------

/** Applies the stored theme and resumes wake lock handling (called at boot). */
export function initPrefs() {
  applyTheme();
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      resumeWakeLock();
    }
  });
}
