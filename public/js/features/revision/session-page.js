/** The revision screen: today's state machine + the live session panel. */

import { renderInto, qs, clear, setSlot, setSlotBdi, make, cloneTemplate } from '../../core/dom.js';
import { ApiError } from '../../core/api-client.js';
import { navigate } from '../../core/router.js';
import { notify } from '../../components/notifications.js';
import { openSheet } from '../../components/bottom-sheet.js';
import { showFieldErrors, focusFirstInvalid } from '../../components/form-errors.js';
import { updateProgressBar } from '../../components/progress-bar.js';
import {
  listPlans,
  planDetail,
  createPlan,
  setPlanStatus,
  listSessions,
  startSession,
  reportProgress,
  finishSession,
} from './revision-api.js';

const UNIT_LABELS = {
  page: 'صفحة',
  hizb: 'حزب',
  rub: 'ربع',
  juz: 'جزء',
};

let isBusy = false;

export async function renderRevision(container) {
  isBusy = false;
  renderInto(container, 'view-revision');
  const root = container.firstElementChild;
  await refresh(root);
}

async function refresh(root) {
  showLoadingState(root);
  try {
    const { plans } = await listPlans();
    const plan = pickPlan(plans);
    if (plan === null) {
      renderNoPlan(root);
      return;
    }
    const detail = await planDetail(plan.id);
    renderDetail(root, detail);
  } catch (error) {
    handleLoadError(error, root);
  }
}

function pickPlan(plans) {
  return plans.find((plan) => plan.status === 'active')
    ?? plans.find((plan) => plan.status === 'paused')
    ?? plans[0]
    ?? null;
}

// ---------------------------------------------------------------------
// Status states (before/without a running session)
// ---------------------------------------------------------------------

function showLoadingState(root) {
  qs('[data-slot="session"]', root).hidden = true;
  qs('[data-slot="segments-section"]', root).hidden = true;
  renderStatusCard(root, {
    icon: '…',
    title: 'جارٍ تحميل المراجعة…',
  });
}

function handleLoadError(error, root) {
  if (error instanceof ApiError && error.status === 401) {
    navigate('/login');
    return;
  }
  renderStatusCard(root, {
    icon: '!',
    title: 'تعذر تحميل المراجعة',
    text: error instanceof ApiError ? error.message : 'تحقق من اتصالك بالخادم ثم أعد المحاولة.',
    actionLabel: 'إعادة المحاولة',
    onAction: () => refresh(root),
  });
}

function renderNoPlan(root) {
  renderStatusCard(root, {
    icon: '+',
    title: 'لا توجد خطة مراجعة بعد',
    text: 'أنشئ خطة لتوزيع مراجعة صفحتك على أيام الأسبوع والالتزام بها يومياً.',
    actionLabel: 'ابدأ خطة مراجعة',
    onAction: () => createNewPlan(root),
  });
}

/** A plan requires an established memorization range (server 404) — send
 *  the user to the dashboard where the range sheets now live. */
function renderNeedState(root) {
  renderStatusCard(root, {
    icon: '!',
    title: 'أنشئ نطاق الحفظ أولاً',
    text: 'لا يمكن إنشاء خطة مراجعة قبل تحديد نطاق حفظك الحالي. أنشئ النطاق من الرئيسية ثم عد إلى المراجعة.',
    actionLabel: 'إلى الرئيسية',
    onAction: () => navigate('/'),
  });
}

function renderPaused(root, detail) {
  renderStatusCard(root, {
    icon: '⏸',
    title: 'الخطة متوقفة مؤقتاً',
    text: 'استأنف الخطة لمتابعة المراجعة اليومية.',
    actionLabel: 'استئناف الخطة',
    onAction: () => resumePlan(root, detail.plan.id),
  });
}

function renderCompleted(root, detail) {
  renderStatusCard(root, {
    icon: '✓',
    iconModifier: ' state-card__icon--done',
    title: 'اكتملت الدورة',
    text: 'أنهيت جميع مقطعات هذه الدورة. أنشئ خطة جديدة لمواصلة المراجعة.',
    actionLabel: 'ابدأ خطة جديدة',
    onAction: () => createNewPlan(root),
  });
}

function renderTodayDone(root, detail) {
  const next = detail.segments.find((segment) => segment.status === 'pending' && !segment.is_missed)
    ?? detail.segments.find((segment) => segment.status === 'pending');
  const text = next
    ? `انتهت مراجعة اليوم. المقطع القادم: مقطع ${next.segment_number} — صفحات ${next.start_page}–${next.end_page} (${next.scheduled_date}).`
    : 'انتهت مراجعة اليوم. أنهيت جميع مقطعات الدورة الحالية.';

  renderStatusCard(root, {
    icon: '✓',
    iconModifier: ' state-card__icon--done',
    title: 'راجعت مقطع اليوم',
    text,
  });
}

function renderReady(root, segment) {
  renderStatusCard(root, {
    icon: '▸',
    title: `مهمة اليوم: مقطع ${segment.segment_number}`,
    text: `الصفحات ${segment.start_page}–${segment.end_page} (${segment.page_count} صفحات).`,
    actionLabel: 'ابدأ الجلسة',
    onAction: () => startFlow(root, segment),
  });
}

async function createNewPlan(root) {
  try {
    await createPlan();
    notify.success('تم إنشاء خطة المراجعة');
    await refresh(root);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      renderNeedState(root);
      return;
    }
    handleActionError(error, root);
  }
}

async function resumePlan(root, planId) {
  try {
    await setPlanStatus(planId, 'active');
    notify.success('تم استئناف الخطة');
    await refresh(root);
  } catch (error) {
    handleActionError(error, root);
  }
}

async function startFlow(root, segment) {
  try {
    const { sessions } = await listSessions(20);
    const prior = sessions.find((session) =>
      session.segment_id === segment.id
      && (session.status === 'partial' || session.status === 'interrupted')
    );
    await startSession(segment.id, prior ? prior.id : null);
    notify.success(prior ? 'تمت متابعة الجلسة السابقة' : 'بدأت جلسة المراجعة');
    await refresh(root);
  } catch (error) {
    handleActionError(error, root);
  }
}

// ---------------------------------------------------------------------
// Detail rendering (segment list + state decision)
// ---------------------------------------------------------------------

function renderDetail(root, detail) {
  const plan = detail.plan;
  const metaParts = [
    plan.name ? plan.name : 'خطة المراجعة',
    `${plan.daily_amount} ${UNIT_LABELS[plan.target_unit] ?? plan.target_unit} يومياً`,
    `الصفحات ${plan.range_start_page}–${plan.range_end_page}`,
  ];
  if (detail.missed_count > 0) {
    metaParts.push(`فائتة: ${detail.missed_count}`);
  }
  setSlotBdi(root, 'plan-meta', metaParts.join(' · '));

  renderSegmentList(root, detail);

  const session = detail.in_progress_session;
  if (session !== null) {
    renderSession(root, detail, session);
    return;
  }

  qs('[data-slot="session"]', root).hidden = true;

  if (plan.status === 'paused') {
    renderPaused(root, detail);
    return;
  }
  if (plan.status === 'completed') {
    renderCompleted(root, detail);
    return;
  }

  const today = detail.segments.find((segment) => segment.is_today);
  if (today) {
    renderReady(root, today);
  } else {
    renderTodayDone(root, detail);
  }
}

function renderSegmentList(root, detail) {
  const section = qs('[data-slot="segments-section"]', root);
  const list = qs('[data-slot="segments"]', root);
  const openSession = detail.in_progress_session;
  clear(list);

  for (const segment of detail.segments) {
    const isCurrent = (openSession && openSession.segment_id === segment.id) || segment.is_today;
    const item = make('li', {
      className: 'segment-item' + (isCurrent ? ' segment-item--current' : ''),
    });

    const label = make('span', { className: 'segment-item__label', text: `مقطع ${segment.segment_number}` });
    label.appendChild(make('bdi', { className: 'segment-item__date', text: segment.scheduled_date, attrs: { dir: 'ltr' } }));
    item.appendChild(label);
    item.appendChild(make('span', {
      className: 'badge ' + badgeClass(segment),
      text: badgeLabel(segment),
    }));
    list.appendChild(item);
  }

  section.hidden = detail.segments.length === 0;
}

function badgeLabel(segment) {
  if (segment.status === 'completed') {
    return 'تم';
  }
  if (segment.status === 'skipped') {
    return 'متخطّى';
  }
  if (segment.status === 'active') {
    return 'جارية';
  }
  if (segment.is_missed) {
    return 'فائتة';
  }
  if (segment.is_today) {
    return 'اليوم';
  }
  return 'قادمة';
}

function badgeClass(segment) {
  if (segment.status === 'completed') {
    return 'badge--done';
  }
  if (segment.status === 'active') {
    return 'badge--active';
  }
  if (segment.is_missed) {
    return 'badge--missed';
  }
  if (segment.is_today) {
    return 'badge--today';
  }
  return '';
}

// ---------------------------------------------------------------------
// Session panel
// ---------------------------------------------------------------------

function renderSession(root, detail, session) {
  const segment = detail.segments.find((item) => item.id === session.segment_id);
  if (!segment) {
    handleLoadError(new ApiError(404, [], 'Segment not found'), root);
    return;
  }

  clear(qs('[data-slot="status"]', root));
  const slot = qs('[data-slot="session"]', root);
  slot.hidden = false;
  clear(slot);
  const panel = cloneTemplate('panel-session');
  slot.appendChild(panel);
  let activeSession = session;

  paintSession(panel, activeSession, segment);

  const buttons = {
    next: qs('[data-action="next"]', panel),
    finish: qs('[data-action="finish"]', panel),
    pause: qs('[data-action="pause"]', panel),
    interrupt: qs('[data-action="interrupt"]', panel),
  };

  const withBusy = async (action) => {
    if (isBusy) {
      return;
    }
    isBusy = true;
    Object.values(buttons).forEach((button) => { button.disabled = true; });
    try {
      await action();
    } catch (error) {
      handleActionError(error, root);
    } finally {
      isBusy = false;
      Object.values(buttons).forEach((button) => { button.disabled = false; });
      paintSession(panel, activeSession, segment);
    }
  };

  buttons.next.addEventListener('click', () => withBusy(async () => {
    if (activeSession.pages_completed >= activeSession.total_pages) {
      return;
    }
    const { session: updated } = await reportProgress(activeSession.id, activeSession.current_page);
    activeSession = updated;
    paintSession(panel, activeSession, segment);
  }));

  buttons.finish.addEventListener('click', () => withBusy(async () => {
    const result = await finishSession(activeSession.id, { status: 'completed' });
    notify.success(result.cycle_completed ? 'أُنجز المقطع واكتملت الدورة' : 'أُنجزت جلسة المراجعة');
    await refresh(root);
  }));

  buttons.pause.addEventListener('click', () => withBusy(async () => {
    await finishSession(activeSession.id, { status: 'partial' });
    notify.success('أُوقفت الجلسة مؤقتاً — تقدمك محفوظ');
    await refresh(root);
  }));

  buttons.interrupt.addEventListener('click', () => {
    if (isBusy) {
      return;
    }
    openInterruptSheet(root, () => activeSession);
  });
}

function paintSession(panel, session, segment) {
  setSlotBdi(panel, 'segment-label', `جدولة ${segment.segment_number} · صفحة ${segment.start_page}-${segment.end_page}`);
  setSlot(panel, 'current-page', String(session.current_page));
  setSlot(panel, 'start-page', String(segment.start_page));
  setSlot(panel, 'end-page', String(segment.end_page));
  setSlotBdi(panel, 'completed', `${session.pages_completed} من ${session.total_pages}`);
  setSlot(panel, 'remaining', String(session.pages_remaining));
  setSlot(panel, 'percent', `${session.percent_complete}٪`);

  updateProgressBar(qs('.progress-bar', panel), session.percent_complete);

  const atEnd = session.pages_completed >= session.total_pages;
  const hint = qs('[data-slot="hint"]', panel);
  hint.hidden = !atEnd;
  if (atEnd) {
    hint.textContent = 'وصلت إلى نهاية المقطع — أنهِ الجلسة عند الاستعداد.';
  }

  const nextButton = qs('[data-action="next"]', panel);
  const finishButton = qs('[data-action="finish"]', panel);
  nextButton.disabled = atEnd;
  nextButton.hidden = atEnd;
  finishButton.className = atEnd ? 'btn btn--success' : 'btn';
}

function openInterruptSheet(root, getSession) {
  const sheet = openSheet('sheet-interruption');
  const form = qs('#interrupt-form', sheet.element);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const session = getSession();
    const reason = form.interruption_reason.value.trim();

    try {
      await finishSession(session.id, reason
        ? { status: 'interrupted', interruption_reason: reason }
        : { status: 'interrupted' });
      sheet.close();
      notify.success('سُجل الانقطاع — تقدمك محفوظ للمتابعة لاحقاً');
      await refresh(root);
    } catch (error) {
      const shown = error instanceof ApiError && error.errors.length > 0
        ? showFieldErrors(form, error.errors)
        : false;
      if (shown) {
        focusFirstInvalid(form);
      } else {
        sheet.close();
        handleActionError(error, root);
      }
    }
  });
}

// ---------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------

function renderStatusCard(root, options) {
  qs('[data-slot="session"]', root).hidden = true;
  const slot = qs('[data-slot="status"]', root);
  clear(slot);

  const card = make('div', { className: 'card state-card' });
  card.appendChild(make('span', {
    className: 'state-card__icon' + (options.iconModifier ?? ''),
    text: options.icon,
    attrs: { 'aria-hidden': 'true' },
  }));
  card.appendChild(make('h2', { className: 'state-card__title', text: options.title }));
  if (options.text) {
    card.appendChild(make('p', { className: 'state-card__text', text: options.text }));
  }
  if (options.actionLabel) {
    const button = make('button', {
      className: 'btn btn--primary',
      text: options.actionLabel,
      attrs: { type: 'button' },
    });
    button.addEventListener('click', async () => {
      if (button.disabled) {
        return;
      }
      button.disabled = true;
      try {
        await options.onAction();
      } catch (error) {
        handleActionError(error, root);
      } finally {
        button.disabled = false;
      }
    });
    card.appendChild(button);
  }

  slot.appendChild(card);
}

function handleActionError(error, root) {
  if (error instanceof ApiError && error.status === 401) {
    navigate('/login');
    return;
  }
  if (error instanceof ApiError) {
    notify.error(error.message);
    if (error.status === 404 || error.status === 422) {
      refresh(root);
    }
    return;
  }
  notify.error('تعذر الاتصال بالخادم');
}
