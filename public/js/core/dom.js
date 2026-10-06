/** Small DOM helpers shared by every view (no DOM access at import time). */

export function byId(id) {
  return document.getElementById(id);
}

export function qs(selector, root = document) {
  return root.querySelector(selector);
}

export function qsa(selector, root = document) {
  return Array.from(root.querySelectorAll(selector));
}

export function clear(node) {
  while (node.firstChild) {
    node.removeChild(node.firstChild);
  }
}

/** Clones a <template> by id into the target and returns the target. */
export function renderInto(target, templateId) {
  clear(target);
  const template = byId(templateId);
  target.appendChild(template.content.cloneNode(true));
  return target;
}

/** Returns the single root element cloned out of a template. */
export function cloneTemplate(templateId) {
  const template = byId(templateId);
  return template.content.firstElementChild.cloneNode(true);
}

export function setSlot(root, slotName, value) {
  const node = qs(`[data-slot="${slotName}"]`, root);
  if (node) {
    node.textContent = value;
  }
  return node;
}

/** Sets a slot's text inside a <bdi> isolate so mixed Arabic/Latin/numeric
 *  fragments cannot reorder against their surroundings (Prompt 20).
 *  `attrs` goes on the isolate, e.g. { dir: 'ltr' } for ISO dates. */
export function setSlotBdi(root, slotName, value, attrs = null) {
  const node = qs(`[data-slot="${slotName}"]`, root);
  if (node) {
    node.replaceChildren(make('bdi', { text: value, attrs: attrs ?? undefined }));
  }
  return node;
}

export function make(tagName, options = {}) {
  const node = document.createElement(tagName);
  if (options.className) {
    node.className = options.className;
  }
  if (options.text !== undefined) {
    node.textContent = options.text;
  }
  if (options.attrs) {
    for (const [name, value] of Object.entries(options.attrs)) {
      node.setAttribute(name, value);
    }
  }
  if (options.children) {
    for (const child of options.children) {
      node.appendChild(child);
    }
  }
  return node;
}
