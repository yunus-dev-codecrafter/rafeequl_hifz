/** The simplified Rabt (الربط) screen (Issue 3C): a numbered cell grid over
 *  the rolling Rabt window, one active-page pointer, and a completion card.
 *
 *  Deliberately local and timer-free — the range comes from
 *  GET /memorization/rabt, and "تمت مراجعة الصفحة" only walks the pointer
 *  forward through the window the server returned. */

import { renderInto, qs, clear, setSlot, make } from '../../core/dom.js';
import { ApiError } from '../../core/api-client.js';
import { navigate } from '../../core/router.js';
import { t } from '../../core/i18n.js';
import { rabtRange } from './memorization-api.js';

const CELL_CLASS = 'rabt-cell';
const DONE_CLASS = 'rabt-cell--completed';
const ACTIVE_CLASS = 'rabt-cell--active';

/** Renders the Rabt view into the given container and loads the window. */
export async function renderRabtPage(container) {
  renderInto(container, 'view-rabt');
  const root = container.firstElementChild;
  await refresh(root);
}

async function refresh(root) {
  const status = qs('[data-slot="status"]', root);
  const grid = qs('[data-slot="rabt-grid"]', root);
  clear(status);
  clear(grid);
  status.appendChild(make('p', { className: 'state-card__text', text: 'جارٍ تحميل نطاق الربط…' }));

  let rabt;
  try {
    ({ rabt } = await rabtRange());
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      navigate('/login');
      return;
    }
    renderLoadError(status, error);
    return;
  }

  clear(status);
  renderWindow(root, grid, Number(rabt.start_page), Number(rabt.end_page));
}

// ---------------------------------------------------------------------
// Window
// ---------------------------------------------------------------------

function renderWindow(root, grid, startPage, endPage) {
  const valid = Number.isInteger(startPage) && Number.isInteger(endPage)
    && startPage >= 1 && endPage >= startPage;
  if (!valid) {
    renderLoadError(qs('[data-slot="status"]', root), null);
    return;
  }

  const count = endPage - startPage + 1;
  setSlot(root, 'range', t('rabt.range', { start: startPage, end: endPage, count }));

  clear(grid);
  for (let page = startPage; page <= endPage; page += 1) {
    grid.appendChild(make('span', {
      className: CELL_CLASS,
      text: String(page),
      attrs: { 'data-page': String(page) },
    }));
  }

  const doneCard = qs('[data-slot="rabt-done"]', root);
  const markButton = qs('[data-action="mark-page-done"]', root);
  const hero = qs('.rabt-hero', root);
  doneCard.hidden = true;
  markButton.hidden = false;
  hero.hidden = false;

  let activePage = startPage;
  setActiveCell(root, activePage);

  markButton.addEventListener('click', () => {
    const cell = cellAt(grid, activePage);
    if (cell !== null) {
      cell.classList.add(DONE_CLASS);
      cell.classList.remove(ACTIVE_CLASS);
    }

    if (activePage >= endPage) {
      hero.hidden = true;
      markButton.hidden = true;
      doneCard.hidden = false;
      return;
    }

    activePage += 1;
    setActiveCell(root, activePage);
  });
}

function setActiveCell(root, page) {
  const grid = qs('[data-slot="rabt-grid"]', root);
  for (const cell of grid.querySelectorAll('.' + ACTIVE_CLASS)) {
    cell.classList.remove(ACTIVE_CLASS);
  }
  const cell = cellAt(grid, page);
  if (cell !== null) {
    cell.classList.add(ACTIVE_CLASS);
  }
  setSlot(root, 'current-page', String(page));
}

function cellAt(grid, page) {
  return grid.querySelector('[data-page="' + page + '"]');
}

// ---------------------------------------------------------------------
// States
// ---------------------------------------------------------------------

function renderLoadError(status, error) {
  const notEstablished = error instanceof ApiError && error.status === 404;
  const card = make('div', { className: 'card state-card' });
  card.appendChild(make('span', {
    className: 'state-card__icon state-card__icon--error',
    text: '!',
    attrs: { 'aria-hidden': 'true' },
  }));
  card.appendChild(make('h2', {
    className: 'state-card__title',
    text: notEstablished ? t('revision.needRangeTitle') : t('rabt.loadFailed'),
  }));
  card.appendChild(make('p', {
    className: 'state-card__text',
    text: notEstablished
      ? t('rabt.establishFirst')
      : (error instanceof ApiError ? error.message : t('rabt.loadFailed')),
  }));
  card.appendChild(make('a', {
    className: 'btn btn--primary',
    text: t('common.backHome'),
    attrs: { href: '#/' },
  }));

  clear(status);
  status.appendChild(card);
}
