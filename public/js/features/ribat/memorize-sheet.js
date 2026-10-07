/** The three memorization write sheets: establish the range, record newly
 *  memorized pages, correct the boundary. Mirrors the task-create pattern —
 *  local Arabic checks first, then the server validates and owns the math
 *  (conventions §2: no Quran numbers here; inputs are generic pages). */

import { qs } from '../../core/dom.js';
import { ApiError } from '../../core/api-client.js';
import { navigate } from '../../core/router.js';
import { openSheet } from '../../components/bottom-sheet.js';
import { notify } from '../../components/notifications.js';
import { clearFieldErrors, showFieldErrors, focusFirstInvalid } from '../../components/form-errors.js';
import { establishState, markMemorized, correctBoundary } from './memorization-api.js';
import { createPlan } from '../revision/revision-api.js';
import { t } from '../../core/i18n.js';

/** Pages covered by one target unit (Issue 3A):
 *  1 juz = 20 pages · 1 hizb = 10 pages · 1 rub = 2.5 pages · 1 page = 1 page. */
const UNIT_PAGES = { page: 1, hizb: 10, rub: 2.5, juz: 20 };

/** Establishes the initial memorized range, then creates the revision plan
 *  from the daily target chosen in the same sheet (Issue 3A & Issue 4).
 *  onSuccess reloads the caller. */
export function openEstablishSheet(onSuccess) {
  const sheet = openSheet('sheet-establish');
  const form = qs('#establish-form', sheet.element);
  const preview = qs('#establish-days-preview', sheet.element);

  let planTarget = { target_unit: 'page', daily_amount: 1 };

  /** Live estimate: total pages ÷ pages-per-day → cycle length in days. */
  const updatePreview = () => {
    const rawStart = form.elements.memorized_start_page.value.trim();
    const rawBoundary = form.elements.current_boundary_page.value.trim();
    const rawAmount = form.elements.daily_amount.value.trim();
    const start = Number(rawStart);
    const boundary = Number(rawBoundary);
    const amount = Number(rawAmount);
    const pagesPerUnit = UNIT_PAGES[form.elements.target_unit.value] ?? 1;
    const totalPages = boundary - start + 1;
    const pagesPerDay = amount * pagesPerUnit;

    const valid = rawStart !== '' && rawBoundary !== '' && rawAmount !== ''
      && Number.isInteger(start) && Number.isInteger(boundary)
      && Number.isFinite(totalPages) && totalPages >= 1
      && Number.isFinite(pagesPerDay) && pagesPerDay > 0;
    if (!valid) {
      preview.textContent = t('establish.daysPreviewInvalid');
      return;
    }
    const days = Math.max(1, Math.ceil(totalPages / pagesPerDay));
    preview.textContent = t('establish.daysPreview', { days });
  };

  wireRangeMode(form, updatePreview);
  for (const name of ['memorized_start_page', 'current_boundary_page', 'daily_amount']) {
    form.elements[name].addEventListener('input', updatePreview);
  }
  form.elements.target_unit.addEventListener('change', updatePreview);
  updatePreview();

  wire(sheet, form, 'sheet-establish', () => {
    const start = Number(form.elements.memorized_start_page.value);
    const boundary = Number(form.elements.current_boundary_page.value);
    const note = form.elements.note.value.trim();
    const targetUnit = form.elements.target_unit.value;
    const dailyAmount = Number(form.elements.daily_amount.value);

    const localErrors = [];
    if (!Number.isInteger(start) || start < 1) {
      localErrors.push({ field: 'memorized_start_page', message: 'أدخل رقم أول صفحة صحيحاً يبدأ من 1' });
    }
    if (!Number.isInteger(boundary) || boundary < 1) {
      localErrors.push({ field: 'current_boundary_page', message: 'أدخل رقم صفحة الحد صحيحاً يبدأ من 1' });
    }
    if (localErrors.length === 0 && start > boundary) {
      localErrors.push({ field: 'memorized_start_page', message: 'أول صفحة يجب ألا تتجاوز حد الحفظ' });
    }
    if (!Number.isFinite(dailyAmount) || dailyAmount < 0.1 || dailyAmount > 9999) {
      localErrors.push({ field: 'daily_amount', message: 'الكمية يجب أن تكون بين 0.1 و9999' });
    }
    if (localErrors.length > 0) {
      updatePreview();
      return { localErrors };
    }

    planTarget = { target_unit: targetUnit, daily_amount: dailyAmount };

    const payload = { memorized_start_page: start, current_boundary_page: boundary };
    if (note !== '') {
      payload.note = note;
    }
    return { payload };
  }, async () => {
    // One smooth onboarding flow: range first, then the plan built on it.
    let planCreated = false;
    try {
      await createPlan(planTarget);
      planCreated = true;
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        navigate('/login');
        return;
      }
    }
    await onSuccess();
    notify.success(
      planCreated
        ? 'أُنشئ نطاق الحفظ وخطتك للمراجعة اليومية'
        : 'أُنشئ نطاق الحفظ — تعذر إنشاء خطة المراجعة'
    );
  }, 'تعذر إنشاء نطاق الحفظ');
}

/** The range mode picker: the current range, or a fresh start from page 1. */
function wireRangeMode(form, onChange) {
  const startInput = form.elements.memorized_start_page;
  const boundaryInput = form.elements.current_boundary_page;

  for (const radio of form.querySelectorAll('input[name="range_mode"]')) {
    radio.addEventListener('change', () => {
      if (!radio.checked || radio.value !== 'new') {
        return;
      }
      startInput.value = '1';
      boundaryInput.value = '1';
      onChange();
    });
  }
}

/** Records pages memorized now; start pre-fills from the server's next page. */
export function openMarkSheet(prefillStart, onSuccess) {
  const sheet = openSheet('sheet-mark');
  const form = qs('#mark-form', sheet.element);
  if (prefillStart !== null && prefillStart !== undefined) {
    form.elements.start_page.value = String(prefillStart);
  }

  wire(sheet, form, 'sheet-mark', () => {
    const start = Number(form.elements.start_page.value);
    const end = Number(form.elements.end_page.value);
    const note = form.elements.note.value.trim();

    const localErrors = [];
    if (!Number.isInteger(start) || start < 1) {
      localErrors.push({ field: 'start_page', message: 'أدخل رقم صفحة البداية صحيحاً يبدأ من 1' });
    }
    if (!Number.isInteger(end) || end < 1) {
      localErrors.push({ field: 'end_page', message: 'أدخل رقم صفحة النهاية صحيحاً يبدأ من 1' });
    }
    if (localErrors.length === 0 && start > end) {
      localErrors.push({ field: 'start_page', message: 'يجب أن تكون صفحة النهاية بعد صفحة البداية' });
    }
    if (localErrors.length > 0) {
      return { localErrors };
    }

    const payload = { start_page: start, end_page: end };
    if (note !== '') {
      payload.note = note;
    }
    return { payload };
  }, async (data) => {
    await onSuccess();
    if (data && data.revision_plans_paused) {
      notify.info('سُجّلت الصفحات، وأُوقفت خطط المراجعة خارج النطاق الجديد');
    } else {
      notify.success('سُجّلت الصفحات المحفوظة');
    }
  }, 'تعذر تسجيل الصفحات');
}

/** Corrects the boundary (never erases history); confirm is mandatory. */
export function openCorrectSheet(currentBoundary, onSuccess) {
  const sheet = openSheet('sheet-correct');
  const form = qs('#correct-form', sheet.element);
  if (currentBoundary !== null && currentBoundary !== undefined) {
    form.elements.current_boundary_page.value = String(currentBoundary);
  }

  wire(sheet, form, 'sheet-correct', () => {
    const boundary = Number(form.elements.current_boundary_page.value);
    const confirmed = form.elements.confirm.checked;
    const note = form.elements.note.value.trim();

    const localErrors = [];
    if (!Number.isInteger(boundary) || boundary < 1) {
      localErrors.push({ field: 'current_boundary_page', message: 'أدخل رقم صفحة الحد صحيحاً يبدأ من 1' });
    }
    if (!confirmed) {
      localErrors.push({ field: 'confirm', message: 'أكّد تعديل الحد للمتابعة' });
    }
    if (localErrors.length > 0) {
      return { localErrors };
    }

    const payload = { current_boundary_page: boundary, confirm: true };
    if (note !== '') {
      payload.note = note;
    }
    return { payload };
  }, async (data) => {
    await onSuccess();
    if (data && data.revision_plans_paused) {
      notify.info('حُفظ التصحيح، وأُوقفت خطط المراجعة خارج النطاق الجديد');
    } else {
      notify.success('حُفظ تصحيح حد الحفظ');
    }
  }, 'تعذر حفظ التصحيح');
}

/** Shared wiring: local validation → POST → close/notify → onSuccess. */
function wire(sheet, form, templateId, build, onSuccess, failureMessage) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFieldErrors(form);

    const built = build();
    if (built.localErrors) {
      showFieldErrors(form, built.localErrors);
      focusFirstInvalid(form);
      return;
    }

    const submit = qs('button[type="submit"]', form);
    submit.disabled = true;
    try {
      const data = await send(templateId, built.payload);
      sheet.close();
      await onSuccess(data);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        sheet.close();
        navigate('/login');
        return;
      }
      if (error instanceof ApiError && error.errors.length > 0) {
        const shown = showFieldErrors(form, error.errors);
        if (shown) {
          focusFirstInvalid(form);
        } else {
          notify.error(error.message);
        }
      } else {
        notify.error(failureMessage);
      }
      submit.disabled = false;
    }
  });
}

function send(templateId, payload) {
  if (templateId === 'sheet-establish') {
    return establishState(payload);
  }
  if (templateId === 'sheet-mark') {
    return markMemorized(payload);
  }
  return correctBoundary(payload);
}
