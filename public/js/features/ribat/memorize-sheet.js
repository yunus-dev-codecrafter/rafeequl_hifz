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

/** Establishes the initial memorized range; onSuccess reloads the caller. */
export function openEstablishSheet(onSuccess) {
  const sheet = openSheet('sheet-establish');
  const form = qs('#establish-form', sheet.element);

  wire(sheet, form, 'sheet-establish', () => {
    const start = Number(form.elements.memorized_start_page.value);
    const boundary = Number(form.elements.current_boundary_page.value);
    const note = form.elements.note.value.trim();

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
    if (localErrors.length > 0) {
      return { localErrors };
    }

    const payload = { memorized_start_page: start, current_boundary_page: boundary };
    if (note !== '') {
      payload.note = note;
    }
    return { payload };
  }, async () => {
    await onSuccess();
    notify.success('أُنشئ نطاق الحفظ');
  }, 'تعذر إنشاء نطاق الحفظ');
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
