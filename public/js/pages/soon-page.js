/** Reserved placeholder screen for Quran activities shipping in a later prompt. */

import { renderInto, setSlot } from '../core/dom.js';

export function renderSoon(container, title, text) {
  renderInto(container, 'view-soon');
  setSlot(container, 'title', title);
  setSlot(container, 'text', text);
}
