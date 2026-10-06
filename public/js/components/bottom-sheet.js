/** Modal bottom sheet cloned from a <template>; Escape/backdrop dismiss.
 *  Focus is trapped in the sheet and restored to the opener on close. */

import { byId, qs } from '../core/dom.js';
import { trapFocus } from './dialog-focus.js';

export function openSheet(templateId) {
  const template = byId(templateId);
  const host = document.createElement('div');
  host.className = 'sheet-host';
  host.appendChild(template.content.cloneNode(true));
  document.body.appendChild(host);

  const sheet = qs('.bottom-sheet', host);

  let closeHandler = null;
  let trap = null;

  const close = () => {
    trap?.release();
    host.remove();
    if (closeHandler) {
      closeHandler();
    }
  };

  // Trap before the initial focus move so release() restores the opener.
  trap = trapFocus(sheet, close);

  const focusTarget = qs('input, button', sheet);
  if (focusTarget) {
    focusTarget.focus();
  }

  host.addEventListener('click', (event) => {
    const action = event.target.closest('[data-action]')?.dataset.action;
    if (action === 'dismiss' || action === 'cancel') {
      close();
    }
  });

  return {
    element: host,
    close,
    onClose: (handler) => {
      closeHandler = handler;
    },
  };
}
