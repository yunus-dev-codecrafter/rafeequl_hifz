/** Daily reminders (Prompt 21): ONE combined, opt-in notification per day.
 *
 *  Respect rules (docs/frontend/notifications.md):
 *  - the master gate is the existing desktop-notification switch + browser
 *    permission; categories start ON and are individually configurable;
 *  - fires at most once per day, only inside a 10-minute window after the
 *    chosen time, and only lists categories with actual pending work;
 *  - delivery degrades: `new Notification` → service-worker notification →
 *    in-app toast; every step is guarded so the tick never throws;
 *  - app closed / tab killed → no reminder (device-local only, no push).
 */

import {
  notify,
  isDesktopSupported,
  desktopPermission,
  showDesktopNotification,
} from '../../components/notifications.js';
import {
  isDesktopNotificationsEnabled,
  getReminders,
  getReminderSent,
  markReminderSent,
  reminderDayKey,
} from '../../core/prefs.js';
import { navigate } from '../../core/router.js';
import { dayTasks } from '../tasks/tasks-api.js';
import { listQueue } from '../flip-cards/flip-cards-api.js';
import { getState, rabtRange } from '../ribat/memorization-api.js';
import { listPlans, planDetail } from '../revision/revision-api.js';

const TICK_MS = 60000;
const WINDOW_MINUTES = 10;
const TITLE = 'تذكير رفيق الحفظ';

let timer = null;
let sending = false;

// --- eligibility -----------------------------------------------------------

/** Master switch + permission + support. Cheap enough for every tick. */
export function remindersEligible() {
  if (!isDesktopSupported() || !isDesktopNotificationsEnabled()) {
    return false;
  }
  return desktopPermission() === 'granted';
}

// --- boot ------------------------------------------------------------------

export function initReminders() {
  if (timer !== null) {
    return;
  }
  timer = setInterval(tick, TICK_MS);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      tick();
    }
  });
  tick();
}

// --- scheduler -------------------------------------------------------------

function tick() {
  try {
    if (!remindersEligible()) {
      return;
    }
    const prefs = getReminders();
    const now = new Date();
    const dayKey = reminderDayKey(now);
    if (getReminderSent() === dayKey) {
      return;
    }
    const target = parseTime(prefs.time);
    const nowMinutes = now.getHours() * 60 + now.getMinutes();
    if (nowMinutes < target) {
      return;
    }
    if (nowMinutes >= target + WINDOW_MINUTES) {
      // Window closed with nothing delivered — stay quiet until tomorrow.
      markReminderSent(dayKey);
      return;
    }
    void sendDaily(prefs, dayKey);
  } catch {
    // A tick must never break the interval.
  }
}

function parseTime(value) {
  const match = /^(\d{2}):(\d{2})$/.exec(value);
  if (match === null) {
    return 18 * 60;
  }
  return Number(match[1]) * 60 + Number(match[2]);
}

// --- pending-work evaluation ----------------------------------------------

/** Returns {lines, failures}: one Arabic line per enabled category that still
 *  has work pending; failures > 0 means "retry later" (offline, 401, …). */
async function collectLines(cats) {
  const jobs = [];
  if (cats.revision) {
    jobs.push(checkRevision());
  }
  if (cats.tasks) {
    jobs.push(checkTasks());
  }
  if (cats.flip) {
    jobs.push(checkFlip());
  }
  if (cats.memorization) {
    jobs.push(checkMemorization());
  }
  if (cats.rabt) {
    jobs.push(checkRabt());
  }

  const results = await Promise.allSettled(jobs);
  const lines = [];
  let failures = 0;
  for (const result of results) {
    if (result.status === 'fulfilled') {
      if (result.value !== null) {
        lines.push(result.value);
      }
    } else {
      failures++;
    }
  }
  return { lines, failures };
}

async function checkRevision() {
  const { plans } = await listPlans();
  const plan = plans.find((item) => item.status === 'active');
  if (!plan) {
    return null;
  }
  const detail = await planDetail(plan.id);
  const segment = detail.segments.find(
    (item) => item.is_today && (item.status === 'pending' || item.status === 'active')
  );
  if (!segment) {
    return null;
  }
  return 'جدولة اليوم: صفحة ' + segment.start_page + '–' + segment.end_page;
}

async function checkTasks() {
  const day = await dayTasks();
  const remaining = day.summary.pending + day.summary.active;
  if (remaining === 0) {
    return null;
  }
  return 'مهام اليوم: ' + remaining + ' من ' + day.summary.total;
}

async function checkFlip() {
  const queue = await listQueue();
  if (queue.cards.length === 0) {
    return null;
  }
  return 'بطاقات المراجعة: ' + queue.cards.length + ' بطاقة';
}

async function checkMemorization() {
  const { state } = await getState();
  if (!state.established || state.next_page_to_memorize === null) {
    return null;
  }
  return 'متابعة الحفظ: صفحة ' + state.next_page_to_memorize;
}

async function checkRabt() {
  const { rabt } = await rabtRange();
  if (rabt.page_count === 0) {
    return null;
  }
  return 'الربط: ' + rabt.page_count + ' صفحة للمراجعة';
}

// --- delivery --------------------------------------------------------------

async function sendDaily(prefs, dayKey) {
  if (sending) {
    return;
  }
  sending = true;
  try {
    const { lines, failures } = await collectLines(prefs.cats);
    if (lines.length === 0) {
      if (failures === 0) {
        // Evaluated cleanly and nothing is due → silent day.
        markReminderSent(dayKey);
      }
      return;
    }
    const mode = await deliver(TITLE, lines.map((line) => '• ' + line).join('\n'));
    const visible = document.visibilityState === 'visible';
    if (mode === 'desktop' || visible) {
      markReminderSent(dayKey);
    }
    // Toast while hidden → retry on the next tick inside the window.
  } catch {
    // Offline / unexpected error → retry on the next tick inside the window.
  } finally {
    sending = false;
  }
}

/** desktop → service worker → in-app toast. Returns 'desktop' | 'toast'. */
async function deliver(title, body) {
  const onClick = () => {
    window.focus();
    navigate('/');
  };
  if (
    showDesktopNotification(title, {
      body,
      tag: 'rafeeq-reminder',
      lang: 'ar',
      dir: 'rtl',
      icon: '/assets/icons/icon-192.png',
      onClick,
    })
  ) {
    return 'desktop';
  }

  // Some mobile browsers require the service-worker path.
  try {
    if ('serviceWorker' in navigator) {
      const registration = await navigator.serviceWorker.getRegistration();
      if (registration) {
        await registration.showNotification(title, {
          body,
          tag: 'rafeeq-reminder',
          lang: 'ar',
          dir: 'rtl',
          icon: '/assets/icons/icon-192.png',
        });
        return 'desktop';
      }
    }
  } catch {
    // Fall through to the toast.
  }

  notify.info(body.replace(/\n/g, ' · '));
  return 'toast';
}
