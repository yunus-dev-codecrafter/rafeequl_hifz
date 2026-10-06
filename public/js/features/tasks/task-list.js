/** Today's task groups and the quick status transitions (start/complete/skip). */

import { qs, clear, make } from '../../core/dom.js';
import { ApiError } from '../../core/api-client.js';
import { navigate } from '../../core/router.js';
import { notify } from '../../components/notifications.js';
import { setTaskStatus } from './tasks-api.js';

const GROUP_SELECTORS = {
  pending: '[data-slot="list-pending"]',
  active: '[data-slot="list-active"]',
  completed: '[data-slot="list-completed"]',
};

const EMPTY_TEXT = {
  pending: 'لا توجد مهام قيد الانتظار',
  active: 'لا توجد مهام قيد التنفيذ',
  completed: 'لم تُكتمل مهام بعد',
};

let isBusy = false;

/** Fills the three task groups from the server-provided day list. */
export function renderTaskGroups(root, tasks) {
  const groups = {
    pending: tasks.filter((task) => task.status === 'pending' || task.status === 'skipped'),
    active: tasks.filter((task) => task.status === 'active'),
    completed: tasks.filter((task) => task.status === 'completed'),
  };

  for (const [name, items] of Object.entries(groups)) {
    const list = qs(GROUP_SELECTORS[name], root);
    const empty = qs(`[data-slot="empty-${name}"]`, root);
    const count = qs(`[data-slot="count-${name}"]`, root);

    clear(list);
    count.textContent = String(items.length);
    empty.hidden = items.length > 0;

    for (const task of items) {
      list.appendChild(renderItem(task));
    }
  }
}

function renderItem(task) {
  const item = cloneItem();
  const title = qs('[data-slot="title"]', item);
  const meta = qs('[data-slot="meta"]', item);
  const badge = qs('[data-slot="badge"]', item);
  const actions = qs('[data-slot="actions"]', item);

  item.dataset.taskId = String(task.id);
  title.textContent = task.title !== null && task.title !== '' ? task.title : task.type_name_ar;
  // English type name as an LTR island (lang="en"); duration isolated in bdi.
  meta.replaceChildren(
    make('span', { text: task.type_name_en, attrs: { lang: 'en', dir: 'ltr' } }),
    document.createTextNode(' · '),
    make('bdi', { text: `${task.duration_minutes} دقيقة` })
  );
  badge.hidden = task.status !== 'skipped';

  for (const [label, action, variant] of actionsFor(task.status)) {
    const button = make('button', {
      className: 'btn btn--sm' + (variant ? ' ' + variant : ''),
      attrs: { type: 'button', 'data-action': action },
    });
    button.textContent = label;
    actions.appendChild(button);
  }

  return item;
}

function cloneItem() {
  const template = document.getElementById('item-task');
  return template.content.firstElementChild.cloneNode(true);
}

function actionsFor(status) {
  switch (status) {
    case 'pending':
      return [
        ['ابدأ', 'start', 'btn--primary'],
        ['إنهاء', 'complete', 'btn--success'],
        ['تجاهل', 'skip', 'btn--ghost'],
      ];
    case 'active':
      return [
        ['إنهاء', 'complete', 'btn--success'],
        ['تجاهل', 'skip', 'btn--ghost'],
      ];
    case 'skipped':
      return [['استئناف', 'revert', '']];
    default:
      return [];
  }
}

const ACTION_STATUS = {
  start: 'active',
  complete: 'completed',
  skip: 'skipped',
  revert: 'pending',
};

const ACTION_TOAST = {
  start: 'بدأت المهمة',
  complete: 'أُنجزت المهمة',
  skip: 'تم تأجيل المهمة',
  revert: 'عادت المهمة إلى الانتظار',
};

/** Delegated click handling for the quick actions; onChanged reloads the day.
 *  The region is marked aria-busy while the write is in flight, and focus
 *  returns to the same task's control after the list re-renders (Prompt 20). */
export function wireTaskActions(root, onChanged) {
  const content = qs('[data-slot="content"]', root);
  content.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    const item = event.target.closest('[data-task-id]');
    if (!button || !item) {
      return;
    }

    const status = ACTION_STATUS[button.dataset.action];
    if (!status || isBusy) {
      return;
    }

    const taskId = item.dataset.taskId;
    const action = button.dataset.action;

    isBusy = true;
    content.setAttribute('aria-busy', 'true');
    setActionsDisabled(root, true);
    try {
      await setTaskStatus(Number(taskId), status);
      notify.success(ACTION_TOAST[action]);
      await onChanged();
      restoreActionFocus(content, taskId, action);
    } catch (error) {
      handleActionError(error);
      restoreActionFocus(content, taskId, action);
    } finally {
      isBusy = false;
      content.removeAttribute('aria-busy');
      setActionsDisabled(root, false);
    }
  });
}

/** After a re-render, put focus back on the same task (its equivalent
 *  action, else any action, else the item itself). */
function restoreActionFocus(content, taskId, action) {
  const item = content.querySelector('[data-task-id="' + taskId + '"]');
  if (!item) {
    return;
  }
  const target =
    item.querySelector('button[data-action="' + action + '"]') ??
    item.querySelector('button[data-action]') ??
    item;
  target.focus();
}

function setActionsDisabled(root, disabled) {
  root.querySelectorAll('.task-item__actions button').forEach((button) => {
    button.disabled = disabled;
  });
}

function handleActionError(error) {
  if (error instanceof ApiError && error.status === 401) {
    navigate('/login');
    return;
  }
  notify.error(error instanceof ApiError ? error.message : 'تعذر حفظ التغيير');
}
