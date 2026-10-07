/** The flip-cards screen: flag an error at a Quran location, run reviews,
 *  move statuses and delete. Every list and number comes from the server —
 *  this module only renders and wires the Prompt 13 contract. */

import { renderInto, qs, clear, setSlot, setSlotBdi, make, cloneTemplate } from '../../core/dom.js';
import { ApiError } from '../../core/api-client.js';
import { navigate } from '../../core/router.js';
import { notify } from '../../components/notifications.js';
import { openSheet } from '../../components/bottom-sheet.js';
import { openModal } from '../../components/modal.js';
import { clearFieldErrors, showFieldErrors, focusFirstInvalid } from '../../components/form-errors.js';
import {
  listQueue,
  listCards,
  reviewCard,
  setCardStatus,
  deleteCard,
} from './flip-cards-api.js';
import { openFlagSheet } from './flag-card-sheet.js';

const STATUS_LABEL = {
  active: 'نشطة',
  in_review: 'قيد المراجعة',
  mastered: 'متقنة',
  archived: 'مؤرشفة',
};

const STATUS_CLASS = {
  active: 'flip-item__badge--active',
  in_review: 'flip-item__badge--in-review',
  mastered: 'flip-item__badge--mastered',
  archived: 'flip-item__badge--archived',
};

const SEVERITY_LABEL = { low: 'خفيفة', medium: 'متوسطة', high: 'شديدة' };

const ALL_LIMIT = 100;

let isBusy = false;
let cardsById = new Map();

export function renderFlipCards(container) {
  renderInto(container, 'view-flip-cards');
  const root = container.firstElementChild;

  qs('[data-action="flag-card"]', root).addEventListener('click', () => {
    openFlagSheet({ onCreated: () => refresh(root) });
  });
  wireActions(root);

  return refresh(root);
}

// ---------------------------------------------------------------------
// Loading
// ---------------------------------------------------------------------

async function refresh(root) {
  const status = qs('[data-slot="status"]', root);
  clear(status);
  status.appendChild(make('p', { className: 'state-card__text', text: 'جارٍ تحميل البطاقات…' }));

  try {
    const [queue, all] = await Promise.all([listQueue(), listCards({ limit: ALL_LIMIT })]);
    clear(status);
    cardsById = new Map();
    for (const card of [...queue.cards, ...all.cards]) {
      cardsById.set(card.id, card);
    }
    renderList(root, 'queue', queue.cards);
    renderList(root, 'all', all.cards);
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      navigate('/login');
      return;
    }
    renderLoadError(root, error);
  }
}

function renderList(root, slot, cards) {
  const list = qs('[data-slot="' + slot + '"]', root);
  const empty = qs('[data-slot="' + slot + '-empty"]', root);
  clear(list);
  for (const card of cards) {
    list.appendChild(renderItem(card));
  }
  empty.hidden = cards.length > 0;
  list.hidden = cards.length === 0;
}

function renderItem(card) {
  const item = cloneTemplate('item-flip-card');
  item.dataset.cardId = String(card.id);

  setSlot(item, 'title', card.category_name_ar || card.category_name_en || '');
  setSlotBdi(item, 'meta', buildMeta(card));

  const note = qs('[data-slot="note"]', item);
  const noteText = card.context_note
    ? card.error_note + ' · ' + card.context_note
    : card.error_note;
  note.textContent = noteText || '';
  note.hidden = note.textContent === '';

  const badge = qs('[data-slot="badge"]', item);
  badge.textContent = STATUS_LABEL[card.status] ?? card.status;
  badge.className = 'flip-item__badge ' + (STATUS_CLASS[card.status] ?? '');
  badge.hidden = false;

  const actions = qs('[data-slot="actions"]', item);
  for (const [label, action, modifier] of actionsFor(card.status)) {
    const button = make('button', {
      className: 'btn btn--sm ' + modifier,
      text: label,
      attrs: { type: 'button', 'data-action': action },
    });
    actions.appendChild(button);
  }
  return item;
}

function buildMeta(card) {
  const parts = [
    'سورة ' + card.surah_number,
    'آية ' + card.ayah_number,
    'صفحة ' + card.page_number,
    card.review_count + ' مراجعة',
  ];
  if (card.severity && SEVERITY_LABEL[card.severity]) {
    parts.push('شدة: ' + SEVERITY_LABEL[card.severity]);
  }
  if (card.next_review_at) {
    parts.push('المراجعة القادمة: ' + String(card.next_review_at).slice(0, 10));
  }
  return parts.join(' · ');
}

function actionsFor(status) {
  if (status === 'active' || status === 'in_review') {
    return [
      ['راجع الآن', 'review', 'btn--primary'],
      ['أتقنتها', 'master', 'btn--success'],
      ['أرشفة', 'archive', 'btn--ghost'],
      ['حذف', 'delete', 'btn--ghost'],
    ];
  }
  return [
    ['إعادة فتح', 'reopen', ''],
    ['حذف', 'delete', 'btn--ghost'],
  ];
}

function renderLoadError(root, error) {
  const status = qs('[data-slot="status"]', root);
  clear(status);

  const card = make('div', { className: 'card state-card' });
  card.appendChild(make('p', { className: 'state-card__title', text: 'تعذر تحميل البطاقات' }));
  card.appendChild(
    make('p', {
      className: 'alert alert--danger',
      text: error instanceof ApiError ? error.message : 'تحقق من اتصالك بالخادم ثم أعد المحاولة.',
    })
  );
  const retry = make('button', {
    className: 'btn btn--primary',
    text: 'إعادة المحاولة',
    attrs: { type: 'button' },
  });
  retry.addEventListener('click', () => refresh(root));
  card.appendChild(retry);
  status.appendChild(card);

  renderList(root, 'queue', []);
  renderList(root, 'all', []);
}

// ---------------------------------------------------------------------
// Quick actions (delegated: review / status moves / delete)
// ---------------------------------------------------------------------

function wireActions(root) {
  root.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button || button.dataset.action === 'flag-card') {
      return;
    }
    const item = button.closest('[data-card-id]');
    if (!item || isBusy) {
      return;
    }

    const cardId = Number(item.dataset.cardId);
    const action = button.dataset.action;
    if (!Number.isInteger(cardId)) {
      return;
    }

    if (action === 'review') {
      const card = cardsById.get(cardId);
      if (card) {
        openReviewSheet(card, () => refresh(root));
      }
      return;
    }

    if (action === 'delete') {
      const card = cardsById.get(cardId);
      if (card) {
        openDeleteModal(card, () => refresh(root));
      }
      return;
    }

    const target = action === 'master'
      ? 'mastered'
      : action === 'archive'
        ? 'archived'
        : action === 'reopen'
          ? 'active'
          : null;
    if (target === null) {
      return;
    }

    isBusy = true;
    root.setAttribute('aria-busy', 'true');
    try {
      await setCardStatus(cardId, target);
      notify.success('حُدّثت حالة البطاقة');
      await refresh(root);
    } catch (error) {
      handleWriteError(error, 'تعذر تحديث حالة البطاقة');
    } finally {
      isBusy = false;
      root.removeAttribute('aria-busy');
    }
  });
}

// ---------------------------------------------------------------------
// Review sheet (result is mandatory; the server bumps the card state)
// ---------------------------------------------------------------------

function openReviewSheet(card, onDone) {
  const sheet = openSheet('sheet-review-card');
  const form = qs('#review-card-form', sheet.element);

  setSlot(
    sheet.element,
    'review-card-meta',
    (card.category_name_ar || card.category_name_en || '') +
      ' · سورة ' + card.surah_number + ' · آية ' + card.ayah_number
  );

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFieldErrors(form);

    const checked = qs('[name="result"]:checked', form);
    const result = checked ? checked.value : null;
    const notes = form.elements.notes.value.trim();

    if (result === null) {
      showFieldErrors(form, [{ field: 'result', message: 'اختر نتيجة المحاولة' }]);
      focusFirstInvalid(form);
      return;
    }

    const submit = qs('button[type="submit"]', form);
    submit.disabled = true;
    try {
      const payload = { result };
      if (notes !== '') {
        payload.notes = notes;
      }
      await reviewCard(card.id, payload);
      sheet.close();
      notify.success('سُجّلت المراجعة');
      await onDone();
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
        notify.error('تعذر تسجيل المراجعة');
      }
      submit.disabled = false;
    }
  });
}

// ---------------------------------------------------------------------
// Delete confirmation (card row + review history leave together)
// ---------------------------------------------------------------------

function openDeleteModal(card, onDone) {
  const modal = openModal('modal-delete-card');
  const form = qs('#delete-card-form', modal.element);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = qs('button[type="submit"]', form);
    submit.disabled = true;
    try {
      await deleteCard(card.id);
      modal.close();
      notify.success('حُذفت البطاقة وسجل مراجعتها');
      await onDone();
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        modal.close();
        navigate('/login');
        return;
      }
      notify.error(error instanceof ApiError ? error.message : 'تعذر حذف البطاقة');
      submit.disabled = false;
    }
  });
}

function handleWriteError(error, fallback) {
  if (error instanceof ApiError && error.status === 401) {
    navigate('/login');
    return;
  }
  notify.error(error instanceof ApiError ? error.message : fallback);
}
