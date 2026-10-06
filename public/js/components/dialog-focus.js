/** Focus management for modal dialogs (Prompt 20).
 *
 * One document-level listener drives a stack of traps: only the top-most
 * dialog cycles Tab/Shift+Tab and receives Escape, so nested dialogs
 * (settings sheet → delete modal) behave. Releasing the trap detaches it
 * and restores focus to the element that opened the dialog. */

const ESCAPE_KEY = 'Escape';
const TAB_KEY = 'Tab';

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ');

const stack = [];

function focusables(container) {
  return Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR)).filter(
    (element) => element.getClientRects().length > 0
  );
}

function onKeydown(event) {
  const top = stack[stack.length - 1];
  if (!top) {
    return;
  }

  if (event.key === ESCAPE_KEY) {
    top.onEscape();
    return;
  }

  if (event.key !== TAB_KEY) {
    return;
  }

  const items = focusables(top.container);
  if (items.length === 0) {
    event.preventDefault();
    return;
  }

  const first = items[0];
  const last = items[items.length - 1];
  const active = document.activeElement;
  const inside = top.container.contains(active);

  if (event.shiftKey && (!inside || active === first)) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && (!inside || active === last)) {
    event.preventDefault();
    first.focus();
  }
}

/** Traps keyboard focus inside `container`; Escape calls `onEscape`.
 *  Returns `{release}` — detaches the trap and restores focus to the opener. */
export function trapFocus(container, onEscape) {
  const opener = document.activeElement;
  const trap = { container, onEscape };

  stack.push(trap);
  if (stack.length === 1) {
    document.addEventListener('keydown', onKeydown);
  }

  let released = false;

  return {
    release() {
      if (released) {
        return;
      }
      released = true;

      const index = stack.indexOf(trap);
      if (index !== -1) {
        stack.splice(index, 1);
      }
      if (stack.length === 0) {
        document.removeEventListener('keydown', onKeydown);
      }

      if (opener !== null && opener.isConnected && typeof opener.focus === 'function') {
        opener.focus();
      }
    },
  };
}
