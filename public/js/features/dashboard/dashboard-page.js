/** The dashboard: identity header, display controls, today's summary,
 *  task groups with quick actions, and the Quran activity shortcuts.
 *  Every displayed statistic comes from the backend (never hard-coded). */

import { renderInto, qs, setSlot, setSlotBdi, make, clear } from '../../core/dom.js';
import { ApiError } from '../../core/api-client.js';
import { navigate } from '../../core/router.js';
import { updateProgressBar } from '../../components/progress-bar.js';
import { dayTasks } from '../tasks/tasks-api.js';
import { getState, rabtRange } from '../ribat/memorization-api.js';
import { listQueue } from '../flip-cards/flip-cards-api.js';
import { progressSummary } from '../progress/progress-api.js';
import { wireControls, syncControls } from './controls.js';
import { renderTaskGroups, wireTaskActions } from '../tasks/task-list.js';
import { openCreateSheet } from '../tasks/task-create.js';
import { openEstablishSheet, openMarkSheet, openCorrectSheet } from '../ribat/memorize-sheet.js';

/** Client-side encouragement lines (never contain statistics). */
const MESSAGES = {
  empty: [
    'يوم جديد، ابدأ بخطة صغيرة وثبّت عليها.',
    'الاستمرار على قليل خيرٌ من كثيرةٍ متقطعة.',
    'ابدأ اليوم بما يقرّبك من هدفك.',
  ],
  start: [
    'أول خطوة اليوم بانتظارك، ابدأ بثقة.',
    'لا تحمّل اليوم أكثر مما يُحتمل، مهمة واحدة تكفي.',
    'الوقتُ ثمين، ابدأ الآن.',
  ],
  some: [
    'ما شاء الله، تقدّمٌ جميل — واصل هكذا.',
    'أحسنت، الاستمرار مفتاح الحفظ.',
    'كل خطوة تُثبت جهدك، فاستمر.',
  ],
  done: [
    'ما شاء الله، أتممت مهام اليوم كلها.',
    'أداءٌ ممتاز اليوم، بارك الله فيك.',
    'إنجازٌ كامل، استمتع بحسّ الإنجاز.',
  ],
};

export function renderDashboard(container) {
  renderInto(container, 'view-dashboard');
  const root = container.firstElementChild;

  setSlot(root, 'date', formatToday());
  wireControls(root, () => syncControls(root));
  wireTaskActions(root, () => loadDay(root));
  qs('[data-action="create-task"]', root).addEventListener('click', () => {
    openCreateSheet(() => loadDay(root));
  });
  wireMemActions(root);

  return refresh(root);
}

// ---------------------------------------------------------------------
// Loading
// ---------------------------------------------------------------------

async function refresh(root) {
  showDayLoading(root);
  try {
    // Prompt 26: day summary and the four activity calls are independent —
    // fire them together so the boot costs one round trip, not two.
    const dayPromise = dayTasks();
    const activitiesPromise = fetchActivities();
    const day = await dayPromise;
    renderDay(root, day);
    renderActivities(root, await activitiesPromise);
  } catch (error) {
    handleDayError(error, root);
  }
}

/** Reloads only the summary + task groups (after an action or a new task). */
async function loadDay(root) {
  try {
    const day = await dayTasks();
    renderDay(root, day);
  } catch (error) {
    handleDayError(error, root);
  }
}

function fetchActivities() {
  return Promise.allSettled([getState(), rabtRange(), listQueue(), progressSummary()]);
}

function renderActivities(root, results) {
  const [state, rabt, queue, analytics] = results;
  renderRabt(root, rabt);
  renderQueue(root, queue);
  renderProgress(root, state);
  renderAnalytics(root, analytics);
}

// ---------------------------------------------------------------------
// Summary (all numbers from GET /tasks summary)
// ---------------------------------------------------------------------

function showDayLoading(root) {
  const content = qs('[data-slot="content"]', root);
  clear(content);
  content.appendChild(make('p', { className: 'dash-loading', text: 'جارٍ تحميل المهام…' }));
}

function renderDay(root, day) {
  const { summary } = day;

  setSlot(root, 'message', pickMessage(summary));
  setSlot(root, 'total', String(summary.total));
  setSlot(root, 'active', String(summary.active));
  setSlot(root, 'completed', String(summary.completed));
  setSlot(root, 'percent', summary.completion_percent + '٪');
  updateProgressBar(qs('.dash-summary .progress-bar', root), summary.completion_percent);

  const content = qs('[data-slot="content"]', root);
  clear(content);
  for (const group of buildGroups()) {
    content.appendChild(group);
  }
  renderTaskGroups(root, day.tasks);
}

function buildGroups() {
  const makeGroup = (name, label) => {
    const section = make('section', { className: 'task-group' });
    const heading = make('h3', { className: 'task-group__title' });
    heading.appendChild(document.createTextNode(label + ' '));
    const count = make('span', { className: 'task-group__count' });
    count.dataset.slot = 'count-' + name;
    heading.appendChild(count);

    const list = make('ul', { className: 'task-group__list' });
    list.dataset.slot = 'list-' + name;

    const empty = make('p', { className: 'task-group__empty', text: EMPTY_TEXTS[name] });
    empty.dataset.slot = 'empty-' + name;

    section.appendChild(heading);
    section.appendChild(list);
    section.appendChild(empty);
    return section;
  };

  return [
    makeGroup('pending', 'قيد الانتظار'),
    makeGroup('active', 'قيد التنفيذ'),
    makeGroup('completed', 'المكتملة'),
  ];
}

const EMPTY_TEXTS = {
  pending: 'لا توجد مهام قيد الانتظار',
  active: 'لا توجد مهام قيد التنفيذ',
  completed: 'لم تُكتمل مهام بعد',
};

function pickMessage(summary) {
  let bucket;
  if (summary.total === 0) {
    bucket = 'empty';
  } else if (summary.completed >= summary.total) {
    bucket = 'done';
  } else if (summary.completed > 0) {
    bucket = 'some';
  } else {
    bucket = 'start';
  }

  const lines = MESSAGES[bucket];
  const dayIndex = Math.floor(Date.now() / 86400000) % lines.length;
  return lines[dayIndex];
}

function handleDayError(error, root) {
  if (error instanceof ApiError && error.status === 401) {
    navigate('/login');
    return;
  }

  const content = qs('[data-slot="content"]', root);
  const card = make('div', { className: 'card state-card' });
  card.appendChild(
    make('p', { className: 'state-card__title', text: 'تعذر تحميل مهام اليوم' })
  );
  card.appendChild(
    make('p', {
      className: 'alert alert--danger',
      text: error instanceof ApiError ? error.message : 'تحقق من اتصالك بالخادم ثم أعد المحاولة.',
    })
  );
  const retry = make('button', { className: 'btn btn--primary', text: 'إعادة المحاولة', attrs: { type: 'button' } });
  retry.addEventListener('click', () => refresh(root));
  card.appendChild(retry);
  clear(content);
  content.appendChild(card);
}

// ---------------------------------------------------------------------
// Quran activities (each stat from its own endpoint)
// ---------------------------------------------------------------------

function renderRabt(root, result) {
  if (result.status === 'fulfilled') {
    const rabt = result.value.rabt;
    setSlot(
      root,
      'rabt',
      'نطاقك: من صفحة ' + rabt.start_page + ' إلى صفحة ' + rabt.end_page + ' · ' + rabt.page_count + ' صفحة'
    );
    return;
  }
  setSlot(root, 'rabt', activityFallback(result.reason, 'أنشئ نطاق الحفظ أولاً لعرض نطاق الربط.'));
}

function renderQueue(root, result) {
  if (result.status === 'fulfilled') {
    const count = result.value.cards.length;
    setSlot(root, 'queue', count === 0
      ? 'لا توجد بطاقات في قائمة المراجعة'
      : count + ' بطاقة في قائمة المراجعة');
    return;
  }
  setSlot(root, 'queue', activityFallback(result.reason, 'تعذر تحميل قائمة البطاقات.'));
}

function renderProgress(root, result) {
  const bar = qs('[data-slot="progress-bar"]', root);
  if (result.status === 'rejected') {
    setSlot(root, 'progress', activityFallback(result.reason, 'تعذر تحميل نطاق الحفظ.'));
    updateProgressBar(bar, 0);
    showMemActions(root, false);
    return;
  }

  const state = result.value.state;
  if (!state.established || state.progress === null) {
    setSlot(root, 'progress', 'لم تنشئ نطاق الحفظ بعد.');
    updateProgressBar(bar, 0);
    showMemActions(root, true, state);
    return;
  }

  const progress = state.progress;
  setSlotBdi(
    root,
    'progress',
    progress.percent_memorized + '% من رصيدك الحالي · ' + progress.page_count + ' صفحة من ' + progress.total_pages + ' صفحة'
  );
  updateProgressBar(bar, progress.percent_memorized);
  showMemActions(root, true, state);
}

/** The memorization write actions live on the نطاق الحفظ card: establish
 *  before the range exists, then mark/correct — each sheet pre-fills from
 *  the server's own numbers and reloads the dashboard on success. */
function wireMemActions(root) {
  qs('[data-action="establish-range"]', root).addEventListener('click', () => {
    openEstablishSheet(() => refresh(root));
  });
  qs('[data-action="mark-memorized"]', root).addEventListener('click', (event) => {
    const start = Number(event.currentTarget.dataset.start);
    openMarkSheet(Number.isInteger(start) && start > 0 ? start : null, () => refresh(root));
  });
  qs('[data-action="correct-boundary"]', root).addEventListener('click', (event) => {
    const boundary = Number(event.currentTarget.dataset.boundary);
    openCorrectSheet(Number.isInteger(boundary) && boundary > 0 ? boundary : null, () => refresh(root));
  });
}

function showMemActions(root, visible, state = null) {
  const container = qs('[data-slot="mem-actions"]', root);
  const establish = qs('[data-action="establish-range"]', root);
  const mark = qs('[data-action="mark-memorized"]', root);
  const correct = qs('[data-action="correct-boundary"]', root);

  container.hidden = !visible;
  const established = Boolean(state && state.established);
  establish.hidden = established;
  mark.hidden = !established;
  correct.hidden = !established;

  if (established) {
    const next = Number(state.next_page_to_memorize);
    mark.dataset.start = Number.isInteger(next) && next > 0 ? String(next) : '';
    const boundary = Number(state.current_boundary_page);
    correct.dataset.boundary = Number.isInteger(boundary) && boundary > 0 ? String(boundary) : '';
  }
}

function activityFallback(reason, notEstablishedText) {
  if (reason instanceof ApiError && reason.status === 404) {
    return notEstablishedText;
  }
  return 'تعذر التحميل.';
}

// ---------------------------------------------------------------------
// Progress analytics (every number below arrives from GET /progress/summary)
// ---------------------------------------------------------------------

function renderAnalytics(root, result) {
  if (result.status === 'rejected') {
    fillAnalyticsPlaceholders(root, '—');
    const list = qs('[data-slot="recent"]', root);
    clear(list);
    list.appendChild(
      make('li', { className: 'dash-recent__empty', text: 'تعذر تحميل التقدم.' })
    );
    updateProgressBar(qs('[data-slot="rev-bar"]', root), 0);
    return;
  }

  const analytics = result.value;
  const mem = analytics.memorization;
  const rev = analytics.revision;
  const flip = analytics.flip_cards;
  const week = analytics.productivity;

  setSlot(root, 'mem-pages', mem.established ? String(mem.page_count) : '—');
  setSlot(root, 'mem-percent', mem.established ? mem.percent_memorized + '%' : '—');
  setSlot(root, 'mem-boundary', mem.established ? String(mem.current_boundary_page) : '—');
  setSlot(root, 'mem-recent', String(mem.pages_last_14_days));

  setSlot(root, 'rev-sessions', String(rev.sessions_last_14_days));
  setSlot(root, 'rev-days', String(rev.active_days_last_14_days));
  setSlot(root, 'rev-segments', rev.segments_completed + ' / ' + rev.segments_total);
  setSlot(root, 'rev-total', String(rev.sessions_total));
  updateProgressBar(qs('[data-slot="rev-bar"]', root), rev.consistency_percent);

  setSlot(root, 'flip-pending', String(flip.pending_review));
  setSlot(root, 'flip-mastered', String(flip.mastered));
  setSlot(root, 'tasks-week', week.completed + ' / ' + week.planned);
  setSlot(root, 'rabt-tasks', String(analytics.rabt.tasks_completed_last_14_days));

  renderRecent(root, analytics.recent_activity);
}

function fillAnalyticsPlaceholders(root, value) {
  const slots = [
    'mem-pages', 'mem-percent', 'mem-boundary', 'mem-recent',
    'rev-sessions', 'rev-days', 'rev-segments', 'rev-total',
    'flip-pending', 'flip-mastered', 'tasks-week', 'rabt-tasks',
  ];
  for (const slot of slots) {
    setSlot(root, slot, value);
  }
}

function renderRecent(root, events) {
  const list = qs('[data-slot="recent"]', root);
  clear(list);

  if (events.length === 0) {
    list.appendChild(
      make('li', { className: 'dash-recent__empty', text: 'لا توجد نشاطات بعد.' })
    );
    return;
  }

  for (const event of events) {
    const item = make('li', { className: 'dash-recent__item' });
    item.appendChild(make('span', { className: 'dash-recent__text', text: describeEvent(event) }));
    item.appendChild(make('time', { className: 'dash-recent__time', text: formatEventTime(event.occurred_at) }));
    list.appendChild(item);
  }
}

const FLIP_RESULTS = {
  recalled: 'متذكرة',
  partial: 'جزئية',
  forgotten: 'منسية',
};

/** Neutral labels only — never praise, scores, or comparisons. */
function describeEvent(event) {
  switch (event.type) {
    case 'memorization':
      return 'حفظ: صفحة ' + event.page_number;
    case 'revision':
      return 'مراجعة: ' + event.pages_completed + ' من ' + event.total_pages;
    case 'flip':
      return 'بطاقة: ' + (FLIP_RESULTS[event.result] ?? event.result);
    case 'task':
      return 'مهمة: ' + event.title;
    default:
      return '';
  }
}

function formatEventTime(value) {
  const date = new Date(String(value).replace(' ', 'T') + 'Z');
  if (Number.isNaN(date.getTime())) {
    return '';
  }
  return new Intl.DateTimeFormat('ar-u-nu-latn', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

// ---------------------------------------------------------------------

function formatToday() {
  // Western digits (ar-u-nu-latn) to match every API-rendered number.
  return new Intl.DateTimeFormat('ar-u-nu-latn', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date());
}
