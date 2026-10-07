/** The settings bottom sheet (Prompt 18): device preferences write through
 *  to the account settings, revision defaults, profile/password forms and
 *  privacy controls (export / delete). */

import { qs, qsa } from '../../core/dom.js';
import { ApiError } from '../../core/api-client.js';
import { openSheet } from '../../components/bottom-sheet.js';
import { openModal } from '../../components/modal.js';
import { notify, isDesktopSupported, requestDesktopPermission } from '../../components/notifications.js';
import { clearFieldErrors, showFieldErrors, focusFirstInvalid } from '../../components/form-errors.js';
import { getUser, setUser, logout } from '../../core/auth.js';
import { navigate } from '../../core/router.js';
import { t, getLocale, setLocale } from '../../core/i18n.js';
import {
  isAwakeEnabled,
  isSoundEnabled,
  isWakeSupported,
  setAwake,
  setSound,
  getTheme,
  cycleTheme,
  isDesktopNotificationsEnabled,
  setDesktopNotifications,
  getReminders,
  setReminders,
} from '../../core/prefs.js';
import {
  getSettings,
  updateSettings,
  updateProfile,
  changePassword,
  exportAccount,
  exportAccountCsv,
  deleteAccount,
} from './settings-api.js';
import { reconcileFromServer, restoreFromSnapshot, saveSnapshot } from './settings-sync.js';
import { isOffline } from '../../core/pwa.js';

// Dictionary keys, resolved with t() at paint time so the labels follow the
// locale that is active when the sheet is shown (Issue 2).
const THEME_LABELS = {
  auto: 'settings.themeAuto',
  light: 'settings.themeLight',
  dark: 'settings.themeDark',
};

const UNIT_ORDER = ['page', 'hizb', 'rub', 'juz'];

const UNIT_LABELS = {
  page: 'settings.unitPage',
  hizb: 'settings.unitHizb',
  rub: 'settings.unitRub',
  juz: 'settings.unitJuz',
};

/** Opens the sheet; onPrefChange re-syncs the dashboard control row. */
export function openSettingsSheet(onPrefChange) {
  const sheet = openSheet('sheet-settings');
  const root = sheet.element;

  const awakeSwitch = qs('[data-pref="awake"]', root);
  const soundSwitch = qs('[data-pref="sound"]', root);
  const themeButton = qs('[data-pref="theme"]', root);
  const themeLabel = qs('[data-slot="theme-label"]', themeButton);
  const notifyRow = qs('[data-slot="notify-row"]', root);
  const notifySwitch = qs('[data-pref="notify"]', root);
  const remindersSection = qs('[data-slot="reminders-section"]', root);
  const remindTimeInput = qs('#pref-reminder-time', root);
  const remindSwitches = qsa('[data-remind]', root);
  const account = qs('[data-slot="account"]', root);

  const unitButton = qs('[data-pref="rev-unit"]', root);
  const unitLabel = qs('[data-slot="rev-unit-label"]', unitButton);
  const amountInput = qs('#pref-revision-amount', root);

  const profileForm = qs('#profile-form', root);
  const passwordForm = qs('#password-form', root);

  let revision = { unit: 'page', amount: 1 };

  const syncSheet = () => {
    awakeSwitch.setAttribute('aria-checked', String(isAwakeEnabled()));
    soundSwitch.setAttribute('aria-checked', String(isSoundEnabled()));
    themeLabel.textContent = t(THEME_LABELS[getTheme()]);
    notifySwitch.setAttribute(
      'aria-checked',
      String(isDesktopNotificationsEnabled() && isDesktopSupported() && Notification.permission === 'granted')
    );
    // Reminders are configured only while notifications are actually usable.
    const remindersOn =
      isDesktopNotificationsEnabled() && isDesktopSupported() && Notification.permission === 'granted';
    remindersSection.hidden = !remindersOn;
    if (remindersOn) {
      const reminders = getReminders();
      remindTimeInput.value = reminders.time;
      remindSwitches.forEach((button) => {
        button.setAttribute('aria-checked', String(reminders.cats[button.dataset.remind] !== false));
      });
    }
    unitLabel.textContent = t(UNIT_LABELS[revision.unit]);
    amountInput.value = String(revision.amount);
  };

  const syncAccount = () => {
    const user = getUser();
    account.textContent = user !== null ? user.email : '';
    if (user !== null) {
      profileForm.elements.display_name.value = user.display_name ?? '';
      profileForm.elements.email.value = user.email ?? '';
    }
  };

  syncSheet();
  syncAccount();

  // --- language radiogroup: roving tabindex + arrow keys (APG, Prompt 20) ---
  const localeButtons = qsa('[data-locale]', root);

  const syncLocaleTabStops = () => {
    const checked = localeButtons.find(
      (button) => button.getAttribute('aria-checked') === 'true' && !button.disabled
    );
    const stop = checked ?? localeButtons.find((button) => !button.disabled);
    localeButtons.forEach((button) => {
      button.tabIndex = button === stop ? 0 : -1;
    });
  };

  /** Reflects the active locale (device copy) onto the radiogroup. */
  const syncLocaleButtons = () => {
    localeButtons.forEach((button) => {
      button.setAttribute('aria-checked', String(button.dataset.locale === getLocale()));
    });
    syncLocaleTabStops();
  };
  syncLocaleButtons();

  localeButtons.forEach((button) => {
    button.addEventListener('click', () => {
      localeButtons.forEach((other) => other.setAttribute('aria-checked', String(other === button)));
      syncLocaleTabStops();

      const locale = button.dataset.locale;
      if (getLocale() !== locale) {
        setLocale(locale);
        // Re-paint the dynamic labels (theme, revision unit) too: [data-i18n]
        // only covers static text.
        syncSheet();
        writeThrough({ locale });
        onPrefChange?.();
      }
    });

    button.addEventListener('keydown', (event) => {
      const enabled = localeButtons.filter((option) => !option.disabled);
      const isRtl = document.documentElement.dir === 'rtl';
      const forwardKeys = isRtl ? ['ArrowLeft', 'ArrowDown'] : ['ArrowRight', 'ArrowDown'];
      const backKeys = isRtl ? ['ArrowRight', 'ArrowUp'] : ['ArrowLeft', 'ArrowUp'];

      let next = null;
      const index = enabled.indexOf(button);
      if (forwardKeys.includes(event.key)) {
        next = enabled[(index + 1) % enabled.length];
      } else if (backKeys.includes(event.key)) {
        next = enabled[(index - 1 + enabled.length) % enabled.length];
      } else if (event.key === 'Home') {
        next = enabled[0];
      } else if (event.key === 'End') {
        next = enabled[enabled.length - 1];
      }

      if (next !== null) {
        event.preventDefault();
        next.focus();
        next.click();
      }
    });
  });
  syncLocaleTabStops();

  if (isDesktopSupported()) {
    notifyRow.hidden = false;
  }

  // Server is the source of truth for the revision defaults (and the rest).
  getSettings()
    .then((settings) => {
      revision = {
        unit: settings.daily_revision_unit,
        amount: settings.daily_revision_amount,
      };
      // The account is the source of truth for the locale: adopt it so the
      // radiogroup and the painted UI can never disagree.
      if (settings.locale && settings.locale !== getLocale()) {
        setLocale(settings.locale);
      }
      syncLocaleButtons();
      syncSheet();
    })
    .catch((error) => {
      if (error instanceof ApiError && error.status === 401) {
        sheet.close();
        navigate('/login');
      }
      // Offline: keep whatever the local cache had.
    });

  /** Optimistic local change + server write-through; rolls back on failure.
   *  Offline there is no queue (Prompt 19): the write fails fast and the
   *  last-good server snapshot restores the device state. */
  const writeThrough = async (patch) => {
    try {
      const settings = await updateSettings(patch);
      saveSnapshot(settings);
    } catch {
      notify.error(
        isOffline() ? 'بلا اتصال — لم تُحفظ التغييرات على الحساب' : 'تعذر حفظ التغيير على الحساب'
      );
      const restored = (await reconcileFromServer()) ?? (await restoreFromSnapshot());
      if (restored !== null) {
        revision = {
          unit: restored.daily_revision_unit,
          amount: restored.daily_revision_amount,
        };
        // A failed locale write rolls the interface back to the account copy.
        if (patch.locale && restored.locale && restored.locale !== getLocale()) {
          setLocale(restored.locale);
          syncLocaleButtons();
        }
      }
      syncSheet();
      onPrefChange?.();
    }
  };

  // --- display & sound (device cache + account write-through) ---------------

  awakeSwitch.addEventListener('click', async () => {
    if (!isWakeSupported()) {
      notify.error('إبقاء الشاشة مضاءة غير مدعوم في هذا المتصفح');
      return;
    }
    const next = !isAwakeEnabled();
    await setAwake(next);
    syncSheet();
    onPrefChange?.();
    writeThrough({ screen_awake_enabled: next });
  });

  soundSwitch.addEventListener('click', () => {
    const next = !isSoundEnabled();
    setSound(next);
    syncSheet();
    onPrefChange?.();
    writeThrough({ sound_enabled: next });
  });

  themeButton.addEventListener('click', () => {
    const mode = cycleTheme();
    syncSheet();
    onPrefChange?.();
    writeThrough({ theme: mode });
  });

  // Device notifications stay device-local: the browser permission itself
  // is bound to this device, so there is nothing meaningful to sync.
  notifySwitch.addEventListener('click', async () => {
    if (!isDesktopSupported()) {
      notify.error('إشعارات الجهاز غير مدعومة في هذا المتصفح');
      return;
    }
    if (isDesktopNotificationsEnabled()) {
      setDesktopNotifications(false);
      syncSheet();
      return;
    }
    const permission = await requestDesktopPermission();
    if (permission === 'granted') {
      setDesktopNotifications(true);
      notify.success('تم تفعيل إشعارات الجهاز');
    } else if (permission === 'denied') {
      notify.error('رُفض إذن الإشعارات من المتصفح');
    } else {
      notify.error('إشعارات الجهاز غير مدعومة في هذا المتصفح');
    }
    syncSheet();
  });

  // --- daily reminders (device-local, Prompt 21) ---------------------------

  remindTimeInput.addEventListener('change', () => {
    const value = remindTimeInput.value;
    if (/^\d{2}:\d{2}$/.test(value)) {
      setReminders({ time: value });
    } else {
      remindTimeInput.value = getReminders().time;
    }
  });

  remindSwitches.forEach((button) => {
    button.addEventListener('click', () => {
      const category = button.dataset.remind;
      const next = getReminders().cats[category] === false;
      setReminders({ cats: { [category]: next } });
      button.setAttribute('aria-checked', String(next));
    });
  });

  // --- revision preferences -------------------------------------------------

  unitButton.addEventListener('click', () => {
    const next = UNIT_ORDER[(UNIT_ORDER.indexOf(revision.unit) + 1) % UNIT_ORDER.length];
    revision.unit = next;
    unitLabel.textContent = t(UNIT_LABELS[next]);
  });

  qs('[data-action="save-revision"]', root).addEventListener('click', async () => {
    const amountField = amountInput.closest('.form__field');
    clearFieldErrors(amountField);

    const raw = amountInput.value.trim();
    const amount = Number(raw);
    const errors = [];
    if (raw === '' || !Number.isFinite(amount) || amount < 0.1 || amount > 9999) {
      errors.push({ field: 'daily_revision_amount', message: 'الكمية يجب أن تكون بين 0.1 و9999' });
    }
    if (errors.length > 0) {
      showFieldErrors(amountField, errors);
      focusFirstInvalid(amountField);
      return;
    }

    const button = qs('[data-action="save-revision"]', root);
    button.disabled = true;
    try {
      const settings = await updateSettings({
        daily_revision_unit: revision.unit,
        daily_revision_amount: amount,
      });
      saveSnapshot(settings);
      revision = {
        unit: settings.daily_revision_unit,
        amount: settings.daily_revision_amount,
      };
      syncSheet();
      notify.success('حُفظت تفضيلات المراجعة — تُطبَّق على الخطط الجديدة فقط');
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        sheet.close();
        navigate('/login');
        return;
      }
      notify.error(error instanceof ApiError ? error.message : 'تعذر حفظ التغيير');
    } finally {
      button.disabled = false;
    }
  });

  // --- account: profile + password ------------------------------------------

  profileForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFieldErrors(profileForm);

    const submit = profileForm.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
      const user = await updateProfile({
        display_name: profileForm.elements.display_name.value.trim(),
        email: profileForm.elements.email.value.trim(),
      });
      setUser(user);
      syncAccount();
      notify.success('حُدِّثت بيانات الحساب');
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        sheet.close();
        navigate('/login');
        return;
      }
      if (error instanceof ApiError && error.errors.length > 0) {
        const shown = showFieldErrors(profileForm, error.errors);
        if (shown) {
          focusFirstInvalid(profileForm);
        } else {
          notify.error(error.message);
        }
      } else {
        notify.error('تعذر الاتصال بالخادم');
      }
    } finally {
      submit.disabled = false;
    }
  });

  passwordForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFieldErrors(passwordForm);

    const current = passwordForm.elements.current_password.value;
    const next = passwordForm.elements.new_password.value;
    const localErrors = [];
    if (current.length < 8) {
      localErrors.push({ field: 'current_password', message: 'كلمة المرور الحالية مطلوبة (8 أحرف على الأقل)' });
    }
    if (next.length < 8 || next.length > 72) {
      localErrors.push({ field: 'new_password', message: 'كلمة المرور الجديدة يجب أن تكون 8–72 حرفاً' });
    }
    if (localErrors.length > 0) {
      showFieldErrors(passwordForm, localErrors);
      focusFirstInvalid(passwordForm);
      return;
    }

    const submit = passwordForm.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
      await changePassword({ current_password: current, new_password: next });
      sheet.close();
      await logout();
      notify.success('غيُّرت كلمة المرور — سجّل الدخول من جديد');
      navigate('/login');
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        sheet.close();
        navigate('/login');
        return;
      }
      if (error instanceof ApiError && error.errors.length > 0) {
        const shown = showFieldErrors(passwordForm, error.errors);
        if (shown) {
          focusFirstInvalid(passwordForm);
        } else {
          notify.error(error.message);
        }
      } else {
        notify.error('تعذر الاتصال بالخادم');
      }
      submit.disabled = false;
    }
  });

  // --- privacy: export + delete ----------------------------------------------

  /** Fetches an export, downloads it as a file, and toasts the result. */
  const runExport = async (button, load, filename, mime, successMessage) => {
    button.disabled = true;
    try {
      const payload = await load();
      const text = mime === 'application/json' ? JSON.stringify(payload, null, 2) : payload;
      const blob = new Blob([text], { type: mime });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
      notify.success(successMessage);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        sheet.close();
        navigate('/login');
        return;
      }
      notify.error('تعذر إعداد ملف التصدير');
    } finally {
      button.disabled = false;
    }
  };

  qs('[data-action="export"]', root).addEventListener('click', () => {
    runExport(
      qs('[data-action="export"]', root),
      exportAccount,
      'rafeequl-hifz-export.json',
      'application/json',
      'بدأ تنزيل نسخة بياناتك',
    );
  });

  qs('[data-action="export-csv"]', root).addEventListener('click', () => {
    runExport(
      qs('[data-action="export-csv"]', root),
      exportAccountCsv,
      'rafeequl-hifz-export.csv',
      'text/csv;charset=utf-8',
      'بدأ تنزيل ملف CSV',
    );
  });

  qs('[data-action="delete-account"]', root).addEventListener('click', () => {
    const modal = openModal('modal-delete-account');
    const form = modal.modal.querySelector('#delete-account-form');

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearFieldErrors(form);

      const password = form.elements.password.value;
      if (password.length < 8) {
        showFieldErrors(form, [{ field: 'password', message: 'أدخل كلمة المرور للتأكيد (8 أحرف على الأقل)' }]);
        focusFirstInvalid(form);
        return;
      }

      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        await deleteAccount(password);
        modal.close();
        sheet.close();
        await logout();
        notify.success('حُذف الحساب نهائياً');
        navigate('/login');
      } catch (error) {
        if (error instanceof ApiError && error.errors.length > 0) {
          const shown = showFieldErrors(form, error.errors);
          if (shown) {
            focusFirstInvalid(form);
          } else {
            notify.error(error.message);
          }
        } else {
          notify.error('تعذر الاتصال بالخادم');
        }
        submit.disabled = false;
      }
    });
  });

  // --- session ---------------------------------------------------------------

  qs('[data-action="logout"]', root).addEventListener('click', async () => {
    sheet.close();
    await logout();
    notify.info('تم تسجيل الخروج');
    navigate('/login');
  });
}
