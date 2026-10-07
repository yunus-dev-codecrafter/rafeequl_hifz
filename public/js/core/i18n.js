/** Client-side i18n (Issue 2): a flat dictionary for Arabic (default) and
 *  English, plus the helpers every feature uses to read and switch locale.
 *
 *  Contract used by the rest of the app:
 *    t(key, params)   → translated string ("{name}" placeholders filled in)
 *    getLocale()      → the active locale ('ar' | 'en')
 *    setLocale(locale)→ stores + applies a locale
 *    applyLocale(locale) → sets <html lang/dir> and repaints [data-i18n]
 *
 *  The locale is mirrored into localStorage so boot can apply it before any
 *  network round trip; the account copy is written through Settings. */

const STORAGE_KEY = 'rafeeq.locale';
const DEFAULT_LOCALE = 'ar';
const LOCALES = ['ar', 'en'];

const MESSAGES = {
  ar: {
    // --- common buttons ---
    'common.save': 'حفظ',
    'common.cancel': 'إلغاء',
    'common.next': 'التالي',
    'common.finish': 'إنهاء',
    'common.back': 'رجوع',
    'common.close': 'إغلاق',
    'common.delete': 'حذف',
    'common.retry': 'إعادة المحاولة',
    'common.loading': 'جارٍ التحميل…',
    'common.backHome': 'العودة إلى الرئيسية',

    // --- navigation ---
    'nav.dashboard': 'الرئيسية',
    'nav.revision': 'المراجعة',
    'nav.rabt': 'الربط',
    'nav.flipCards': 'بطاقات الأخطاء',
    'nav.settings': 'الإعدادات',
    'nav.logout': 'خروج',

    // --- dashboard ---
    'dash.brand': 'رفيق الحفظ',
    'dash.summaryTitle': 'إنجاز اليوم',
    'dash.totalTasks': 'إجمالي المهام',
    'dash.active': 'قيد التنفيذ',
    'dash.completed': 'المكتملة',
    'dash.percent': 'النسبة',
    'dash.todayTasks': 'مهام اليوم',
    'dash.newTask': 'مهمة جديدة',
    'dash.quranActivities': 'أنشطة القرآن',
    'dash.progressTitle': 'التقدم والمتابعة',
    'dash.memorization': 'الحفظ',
    'dash.revision': 'المراجعة',
    'dash.cardsAndTasks': 'البطاقات والمهام',
    'dash.recent': 'أحدث النشاطات',
    'dash.savedPages': 'صفحات محفوظة',
    'dash.memorizationPercent': 'نسبة الحفظ',
    'dash.boundary': 'حد الحفظ',
    'dash.memorizedRange': 'نطاق الحفظ',
    'dash.openRevision': 'افتح المراجعة',
    'dash.openRabt': 'افتح الربط',
    'dash.openCards': 'افتح البطاقات',
    'dash.establishRange': 'إنشاء نطاق الحفظ',
    'dash.markMemorized': 'تسجيل محفوظ',
    'dash.correctBoundary': 'تصحيح الحد',

    // --- settings ---
    'settings.title': 'الإعدادات',
    'settings.appearance': 'العرض والصوت',
    'settings.screenAwake': 'إبقاء الشاشة مضاءة',
    'settings.sound': 'الصوت',
    'settings.theme': 'وضع العرض',
    'settings.language': 'اللغة',
    'settings.reminders': 'تذكيرات يومية',
    'settings.dailyRevision': 'تفضيلات المراجعة',
    'settings.account': 'الحساب',
    'settings.privacy': 'الخصوصية والبيانات',
    'settings.logout': 'تسجيل الخروج',
    'settings.themeAuto': 'تلقائي',
    'settings.themeLight': 'النهار',
    'settings.themeDark': 'الليل',
    'settings.unitPage': 'صفحة',
    'settings.unitHizb': 'حزب',
    'settings.unitRub': 'ربع',
    'settings.unitJuz': 'جزء',

    // --- revision session ---
    'revision.title': 'المراجعة',
    'revision.sessionTitle': 'الجلسة الجارية',
    'revision.currentPage': 'الصفحة الحالية',
    'revision.startPage': 'صفحة البداية',
    'revision.endPage': 'صفحة النهاية',
    'revision.completed': 'المكتمل',
    'revision.remaining': 'المتبقي',
    'revision.next': 'الصفحة التالية (تمت)',
    'revision.finish': 'إنهاء الجلسة',
    'revision.flag': 'علّم خطأً',
    'revision.pause': 'إيقاف مؤقت',
    'revision.interrupt': 'انقطاع',
    'revision.errorsOnPage': '{count} خطأ على هذه الصفحة',
    'revision.needRangeTitle': 'أنشئ نطاق الحفظ أولاً',
    'revision.needRangeText': 'لا يمكن إنشاء خطة مراجعة قبل تحديد نطاق حفظك الحالي.',

    // --- rabt ---
    'rabt.title': 'الربط',
    'rabt.range': 'الصفحات {start}–{end} ({count} صفحة)',
    'rabt.activePage': 'الصفحة الحالية',
    'rabt.markDone': 'تمت مراجعة الصفحة',
    'rabt.doneTitle': 'ما شاء الله!',
    'rabt.doneText': 'أتممت ربط صفحاتك اليوم بنجاح.',
    'rabt.establishFirst': 'أنشئ نطاق الحفظ أولاً لعرض نطاق الربط.',
    'rabt.loadFailed': 'تعذر تحميل نطاق الربط.',

    // --- memorization range setup ---
    'establish.title': 'إنشاء نطاق الحفظ',
    'establish.rangeMode': 'وضع النطاق',
    'establish.modeExisting': 'استخدام نطاق حفظي الحالي (من أول صفحة إلى حدّي)',
    'establish.modeNew': 'بدء حفظ جديد من الصفر',
    'establish.intro': 'حدّد أول صفحة في نطاق حفك وآخر صفحة وصلت إليها الآن؛ يُسجَّل ذلك كنطاقك الحالي دون تخمين أي صفحات.',
    'establish.start': 'أول صفحة محفوظة',
    'establish.boundary': 'حد الحفظ الحالي (آخر صفحة محفوظة)',
    'establish.note': 'ملاحظة (اختياري)',
    'establish.submit': 'إنشاء النطاق',
    'establish.dailyTarget': 'الهدف اليومي للمراجعة',
    'establish.targetUnit': 'وحدة القياس',
    'establish.dailyAmount': 'الكمية يومياً',
    'establish.daysPreview': 'بهذا المعدل، ستستغرق دورة مراجعتك ما يقارب {days} يوماً لاكتمالها.',
    'establish.daysPreviewInvalid': 'أدخل نطاقاً صحيحاً وكمية يومية لتظهر المدة المتوقعة.',
  },

  en: {
    // --- common buttons ---
    'common.save': 'Save',
    'common.cancel': 'Cancel',
    'common.next': 'Next',
    'common.finish': 'Finish',
    'common.back': 'Back',
    'common.close': 'Close',
    'common.delete': 'Delete',
    'common.retry': 'Try again',
    'common.loading': 'Loading…',
    'common.backHome': 'Back to home',

    // --- navigation ---
    'nav.dashboard': 'Dashboard',
    'nav.revision': 'Revision',
    'nav.rabt': 'Rabt',
    'nav.flipCards': 'Flip Cards',
    'nav.settings': 'Settings',
    'nav.logout': 'Logout',

    // --- dashboard ---
    'dash.brand': 'Rafeequl Hifz',
    'dash.summaryTitle': "Today's Progress",
    'dash.totalTasks': 'Total Tasks',
    'dash.active': 'Active',
    'dash.completed': 'Completed',
    'dash.percent': 'Percentage',
    'dash.todayTasks': "Today's Tasks",
    'dash.newTask': 'New Task',
    'dash.quranActivities': 'Quran Activities',
    'dash.progressTitle': 'Progress & Tracking',
    'dash.memorization': 'Memorization',
    'dash.revision': 'Revision',
    'dash.cardsAndTasks': 'Cards & Tasks',
    'dash.recent': 'Recent Activity',
    'dash.savedPages': 'Pages Memorized',
    'dash.memorizationPercent': 'Memorized Range',
    'dash.boundary': 'Memorization Boundary',
    'dash.memorizedRange': 'Memorized Range',
    'dash.openRevision': 'Open revision',
    'dash.openRabt': 'Open rabt',
    'dash.openCards': 'Open cards',
    'dash.establishRange': 'Set up range',
    'dash.markMemorized': 'Log memorized',
    'dash.correctBoundary': 'Correct boundary',

    // --- settings ---
    'settings.title': 'Settings',
    'settings.appearance': 'Appearance & Sound',
    'settings.screenAwake': 'Keep screen awake',
    'settings.sound': 'Sound',
    'settings.theme': 'Display mode',
    'settings.language': 'Language',
    'settings.reminders': 'Daily reminders',
    'settings.dailyRevision': 'Daily Revision',
    'settings.account': 'Account',
    'settings.privacy': 'Privacy & Data',
    'settings.logout': 'Log out',
    'settings.themeAuto': 'System',
    'settings.themeLight': 'Light',
    'settings.themeDark': 'Dark',
    'settings.unitPage': 'Page',
    'settings.unitHizb': 'Hizb',
    'settings.unitRub': 'Rub',
    'settings.unitJuz': 'Juz',

    // --- revision session ---
    'revision.title': 'Revision',
    'revision.sessionTitle': 'Active session',
    'revision.currentPage': 'Current page',
    'revision.startPage': 'Start page',
    'revision.endPage': 'End page',
    'revision.completed': 'Completed',
    'revision.remaining': 'Remaining',
    'revision.next': 'Next page (done)',
    'revision.finish': 'Finish session',
    'revision.flag': 'Flag an error',
    'revision.pause': 'Pause',
    'revision.interrupt': 'Interrupt',
    'revision.errorsOnPage': '{count} error(s) on this page',
    'revision.needRangeTitle': 'Set up your range first',
    'revision.needRangeText': 'A revision plan needs your memorized range before it can be created.',

    // --- rabt ---
    'rabt.title': 'Rabt',
    'rabt.range': 'Pages {start}–{end} ({count} pages)',
    'rabt.activePage': 'Current page',
    'rabt.markDone': 'Page reviewed',
    'rabt.doneTitle': 'Mashallah!',
    'rabt.doneText': "You completed today's rabt pages.",
    'rabt.establishFirst': 'Set up your memorization range to see the rabt window.',
    'rabt.loadFailed': 'Could not load the rabt window.',

    // --- memorization range setup ---
    'establish.title': 'Set up memorization range',
    'establish.rangeMode': 'Range mode',
    'establish.modeExisting': 'Use my current memorized range (first page → boundary)',
    'establish.modeNew': 'Start a new memorization from scratch',
    'establish.intro': 'Pick the first page of your range and the last page you reached; this records your current range without guessing any pages.',
    'establish.start': 'First memorized page',
    'establish.boundary': 'Current boundary (last memorized page)',
    'establish.note': 'Note (optional)',
    'establish.submit': 'Create range',
    'establish.dailyTarget': 'Daily revision target',
    'establish.targetUnit': 'Unit',
    'establish.dailyAmount': 'Amount per day',
    'establish.daysPreview': 'At this rate, your revision cycle will take approximately {days} days to complete.',
    'establish.daysPreviewInvalid': 'Enter a valid range and daily amount to see the estimated duration.',
  },
};

let current = DEFAULT_LOCALE;

function readStoredLocale() {
  try {
    const value = window.localStorage.getItem(STORAGE_KEY);
    return LOCALES.includes(value) ? value : DEFAULT_LOCALE;
  } catch {
    return DEFAULT_LOCALE;
  }
}

function storeLocale(locale) {
  try {
    window.localStorage.setItem(STORAGE_KEY, locale);
  } catch {
    // Private mode: the locale simply does not persist locally.
  }
}

/** The active locale ('ar' | 'en'). */
export function getLocale() {
  return current;
}

/** Stores and applies a locale; unknown values fall back to Arabic. */
export function setLocale(locale) {
  current = LOCALES.includes(locale) ? locale : DEFAULT_LOCALE;
  storeLocale(current);
  applyLocale(current);
  return current;
}

/** Sets <html lang>/<html dir> and repaints every translated node. */
export function applyLocale(locale) {
  const active = LOCALES.includes(locale) ? locale : DEFAULT_LOCALE;
  current = active;
  if (typeof document !== 'undefined') {
    document.documentElement.lang = active;
    document.documentElement.dir = active === 'ar' ? 'rtl' : 'ltr';
    applyTranslations();
  }
  return active;
}

/** Translates `key`; `{name}` placeholders are replaced from `params`. */
export function t(key, params) {
  const fallback = MESSAGES[DEFAULT_LOCALE];
  let text = MESSAGES[current]?.[key] ?? fallback[key] ?? key;
  if (params) {
    for (const [name, value] of Object.entries(params)) {
      text = text.split('{' + name + '}').join(String(value));
    }
  }
  return text;
}

/** Boot hook: applies the stored locale to <html> and to [data-i18n] nodes. */
export function initI18n() {
  return applyLocale(readStoredLocale());
}

// ---------------------------------------------------------------------
// Painting
// ---------------------------------------------------------------------

/** Document + every open <template>'s fragment (template content is not in
 *  the document tree, so a plain querySelectorAll would miss it). */
function paintRoots() {
  if (typeof document === 'undefined') {
    return [];
  }
  const roots = [document];
  for (const template of document.querySelectorAll('template')) {
    roots.push(template.content);
  }
  return roots;
}

function applyTranslations() {
  for (const root of paintRoots()) {
    for (const node of root.querySelectorAll('[data-i18n]')) {
      node.textContent = t(node.dataset.i18n);
    }
    for (const node of root.querySelectorAll('[data-i18n-aria]')) {
      node.setAttribute('aria-label', t(node.dataset.i18nAria));
    }
  }
}
