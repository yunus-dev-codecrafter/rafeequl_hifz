/** Dashboard control row: screen awake, sound, day/night mode, settings entry.
 *  The settings sheet itself lives in features/settings. */

import { qs } from '../../core/dom.js';
import { t } from '../../core/i18n.js';
import { notify } from '../../components/notifications.js';
import { openSettingsSheet } from '../settings/settings-sheet.js';
import {
  isAwakeEnabled,
  isSoundEnabled,
  isWakeSupported,
  setAwake,
  setSound,
  getTheme,
  cycleTheme,
} from '../../core/prefs.js';

// Dictionary keys, resolved with t() at paint time (Issue 2).
const THEME_LABELS = {
  auto: 'settings.themeAuto',
  light: 'settings.themeLight',
  dark: 'settings.themeDark',
};

/** Wires the control row; onPrefChange re-syncs the row from the settings sheet. */
export function wireControls(root, onPrefChange) {
  const awakeButton = qs('[data-control="awake"]', root);
  const soundButton = qs('[data-control="sound"]', root);
  const themeButton = qs('[data-control="theme"]', root);
  const settingsButton = qs('[data-control="settings"]', root);

  if (!isWakeSupported()) {
    awakeButton.disabled = true;
    awakeButton.title = 'إبقاء الشاشة مضاءة غير مدعوم في هذا المتصفح';
  }

  awakeButton.addEventListener('click', async () => {
    const next = !isAwakeEnabled();
    await setAwake(next);
    syncControls(root);
    notify.info(next ? 'ستبقى الشاشة مضاءة أثناء استخدامك' : 'ستطفأ الشاشة تلقائياً');
    onPrefChange?.();
  });

  soundButton.addEventListener('click', () => {
    const next = !isSoundEnabled();
    setSound(next);
    syncControls(root);
    notify.info(next ? 'تم تشغيل الصوت' : 'تم كتم الصوت');
    onPrefChange?.();
  });

  themeButton.addEventListener('click', () => {
    cycleTheme();
    syncControls(root);
    onPrefChange?.();
  });

  settingsButton.addEventListener('click', () => {
    openSettingsSheet(onPrefChange);
  });

  syncControls(root);
}

/** Refreshes the control row to match the stored preferences. */
export function syncControls(root) {
  const awakeButton = qs('[data-control="awake"]', root);
  const soundButton = qs('[data-control="sound"]', root);
  const themeButton = qs('[data-control="theme"]', root);
  const themeLabel = qs('[data-slot="theme-label"]', themeButton);

  awakeButton.setAttribute('aria-pressed', String(isAwakeEnabled()));
  soundButton.setAttribute('aria-pressed', String(isSoundEnabled()));
  themeLabel.textContent = t(THEME_LABELS[getTheme()]);
  themeButton.setAttribute('aria-label', `${t('settings.theme')}: ${t(THEME_LABELS[getTheme()])}`);
}
