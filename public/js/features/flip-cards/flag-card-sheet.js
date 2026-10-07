/** Flag-error bottom sheet, shared by the flip-cards screen and the live
 *  revision session (Prompt 13 contract). The server owns the location
 *  math; this module only collects the payload, renders field errors and
 *  reports the result. Two shapes:
 *    - standalone: the user types surah/ayah/page freely (flip-cards screen);
 *    - in-session: the page is locked to the session's current page and the
 *      ayah comes from the canonical page list, so a mistyped location is
 *      impossible while a session is running. */

import { qs, make } from '../../core/dom.js';
import { ApiError } from '../../core/api-client.js';
import { navigate } from '../../core/router.js';
import { notify } from '../../components/notifications.js';
import { openSheet } from '../../components/bottom-sheet.js';
import { clearFieldErrors, showFieldErrors, focusFirstInvalid } from '../../components/form-errors.js';
import { listCategories, createCard, pageAyahs } from './flip-cards-api.js';

const SHEET_STANDALONE = 'sheet-flag-card';
const SHEET_SESSION = 'sheet-flag-card-session';

const AYAH_PICK_MESSAGE = 'اختر رقم الآية';
const AYAH_TYPE_MESSAGE = 'أدخل رقم الآية صحيحاً يبدأ من 1';

/**
 * Opens the flag sheet.
 *
 * @param {object} [options]
 * @param {number} [options.prefillPage] lock the location to this page (live session)
 * @param {Function} [options.onCreated] resolved after the card was saved
 */
export function openFlagSheet(options = {}) {
  // The session payload may hand the page over as a numeric string.
  const requestedPage = Number(options.prefillPage);
  const prefillPage = Number.isInteger(requestedPage) && requestedPage > 0
    ? requestedPage
    : null;
  const onCreated = typeof options.onCreated === 'function' ? options.onCreated : () => {};
  const isLocked = prefillPage !== null;

  const sheet = openSheet(isLocked ? SHEET_SESSION : SHEET_STANDALONE);
  const form = qs('form', sheet.element);
  const categorySelect = form.elements.category_id;

  if (isLocked) {
    lockLocation(form, prefillPage);
    loadPageAyahs(form, prefillPage);
  }
  loadCategories(sheet, categorySelect);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFieldErrors(form);

    const location = readLocation(form);
    const categoryId = Number(categorySelect.value);
    const errorNote = form.elements.error_note.value.trim();
    const contextNote = form.elements.context_note.value.trim();
    const severity = form.elements.severity.value;

    // A read-only surah is derived from the chosen ayah — flagging it would
    // ask the user to fix a field they cannot touch.
    const surahInput = form.elements.surah_number;
    const ayahField = form.elements.ayah_number;
    const localErrors = [];
    if (!surahInput.readOnly && (!Number.isInteger(location.surah) || location.surah < 1)) {
      localErrors.push({ field: 'surah_number', message: 'أدخل رقم السورة صحيحاً يبدأ من 1' });
    }
    if (!Number.isInteger(location.ayah) || location.ayah < 1) {
      localErrors.push({
        field: 'ayah_number',
        message: ayahField.tagName === 'SELECT' ? AYAH_PICK_MESSAGE : AYAH_TYPE_MESSAGE,
      });
    }
    if (!Number.isInteger(location.page) || location.page < 1) {
      localErrors.push({ field: 'page_number', message: 'أدخل رقم الصفحة صحيحاً يبدأ من 1' });
    }
    if (!Number.isInteger(categoryId) || categoryId < 1) {
      localErrors.push({ field: 'category_id', message: 'اختر نوع الخطأ' });
    }
    if (errorNote === '') {
      localErrors.push({ field: 'error_note', message: 'اكتب وصف الخطأ' });
    }
    if (localErrors.length > 0) {
      showFieldErrors(form, localErrors);
      focusFirstInvalid(form);
      return;
    }

    const submit = qs('button[type="submit"]', form);
    submit.disabled = true;
    try {
      const payload = {
        surah_number: location.surah,
        ayah_number: location.ayah,
        page_number: location.page,
        category_id: categoryId,
        error_note: errorNote,
        severity,
      };
      if (contextNote !== '') {
        payload.context_note = contextNote;
      }
      await createCard(payload);
      sheet.close();
      notify.success('حُفظت البطاقة');
      await onCreated();
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
        notify.error('تعذر حفظ البطاقة');
      }
      submit.disabled = false;
    }
  });
}

// ---------------------------------------------------------------------
// Location fields (in-session shape only)
// ---------------------------------------------------------------------

/** The page is read-only and the ayah comes from that same page, so the
 *  flagged location can never drift from what the user is revising. */
function lockLocation(form, page) {
  const pageInput = form.elements.page_number;
  pageInput.value = String(page);
  pageInput.readOnly = true;

  const surahInput = form.elements.surah_number;
  surahInput.readOnly = true;

  const ayahSelect = form.elements.ayah_number;
  ayahSelect.disabled = true;
  ayahSelect.appendChild(make('option', { text: 'اختر الآية', attrs: { value: '' } }));
  ayahSelect.addEventListener('change', () => {
    const selected = parseAyahValue(ayahSelect.value);
    surahInput.value = selected === null ? '' : String(selected.surah);
  });
}

/** Canonical ayahs for the locked page. On failure the sheet stays usable
 *  with a plain ayah number instead of blocking the flag (Risk 5). */
function loadPageAyahs(form, page) {
  const select = form.elements.ayah_number;
  pageAyahs(page)
    .then((data) => {
      for (const ayah of data.ayahs) {
        select.appendChild(make('option', {
          text: 'سورة ' + ayah.surah_number + ' · آية ' + ayah.ayah_number,
          attrs: { value: ayah.surah_number + ':' + ayah.ayah_number },
        }));
      }
      select.disabled = false;
    })
    .catch(() => fallbackToManualAyah(form, select));
}

function fallbackToManualAyah(form, select) {
  const input = make('input', {
    className: 'form__input',
    attrs: {
      id: select.id,
      name: 'ayah_number',
      type: 'number',
      inputmode: 'numeric',
      min: '1',
      step: '1',
      required: 'required',
    },
  });
  select.replaceWith(input);
  form.elements.surah_number.readOnly = false;
  notify.error('تعذر تحميل آيات الصفحة — أدخل رقم السورة والآية يدوياً');
}

/** Select values carry "surah:ayah"; a typed number carries just the ayah. */
function parseAyahValue(value) {
  const parts = String(value).split(':');
  if (parts.length !== 2) {
    return null;
  }
  const surah = Number(parts[0]);
  const ayah = Number(parts[1]);
  if (!Number.isInteger(surah) || surah < 1 || !Number.isInteger(ayah) || ayah < 1) {
    return null;
  }
  return { surah, ayah };
}

function readLocation(form) {
  const raw = String(form.elements.ayah_number.value).trim();
  const selected = parseAyahValue(raw);
  if (selected !== null) {
    return {
      surah: selected.surah,
      ayah: selected.ayah,
      page: Number(form.elements.page_number.value),
    };
  }
  return {
    surah: Number(form.elements.surah_number.value),
    ayah: raw === '' ? NaN : Number(raw),
    page: Number(form.elements.page_number.value),
  };
}

// ---------------------------------------------------------------------
// Categories (both shapes)
// ---------------------------------------------------------------------

function loadCategories(sheet, categorySelect) {
  categorySelect.appendChild(make('option', { text: 'اختر نوع الخطأ', attrs: { value: '' } }));
  listCategories()
    .then((data) => {
      for (const category of data.categories) {
        categorySelect.appendChild(
          make('option', { text: category.name_ar, attrs: { value: String(category.id) } })
        );
      }
    })
    .catch((error) => {
      sheet.close();
      if (error instanceof ApiError && error.status === 401) {
        navigate('/login');
        return;
      }
      notify.error(error instanceof ApiError ? error.message : 'تعذر تحميل أنواع الأخطاء');
    });
}
