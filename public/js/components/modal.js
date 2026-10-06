/** Centered modal dialog cloned from a <template>; Escape/backdrop dismiss.
 *  The bottom sheet stays the primary overlay — modals are for confirmations.
 *  Focus is trapped (top-most dialog only) and restored to the opener. */

import { byId, qs } from '../core/dom.js';
import { trapFocus } from './dialog-focus.js';

export function openModal(templateId) {
  const template = byId(templateId);
  const host = document.createElement('div');
  host.className = 'modal-host';
  host.appendChild(template.content.cloneNode(true));
  document.body.appendChild(host);

  const modal = qs('.modal', host);

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
  trap = trapFocus(modal, close);

  const focusTarget = qs('input, button', modal);
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
    modal,
    close,
    onClose: (handler) => {
      closeHandler = handler;
    },
  };
}
