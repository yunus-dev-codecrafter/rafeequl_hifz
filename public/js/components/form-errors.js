/** Renders server validation errors ({field, message}) under form inputs.
 *  Each visible error is announced (role="alert") and linked to its input
 *  via aria-describedby (any static hint reference is kept); focus moves to
 *  the first invalid field. */

import { qsa, qs } from '../core/dom.js';

function errorId(form, field) {
  return `${form.id || 'form'}-error-${field}`;
}

/** The input's authored aria-describedby, captured before we touch it. */
function hintRef(input) {
  if (!('defaultDescribedby' in input.dataset)) {
    input.dataset.defaultDescribedby = input.getAttribute('aria-describedby') || '';
  }
  return input.dataset.defaultDescribedby;
}

function setDescribedBy(input, ids) {
  const value = [hintRef(input), ...ids].filter((id) => id !== '').join(' ');
  if (value === '') {
    input.removeAttribute('aria-describedby');
  } else {
    input.setAttribute('aria-describedby', value);
  }
}

export function clearFieldErrors(form) {
  qsa('[data-error-for]', form).forEach((node) => {
    node.hidden = true;
    node.textContent = '';
    node.removeAttribute('id');
    node.removeAttribute('role');
  });
  qsa('.form__input', form).forEach((input) => {
    input.removeAttribute('aria-invalid');
    setDescribedBy(input, []);
  });
}

export function showFieldErrors(form, errors) {
  clearFieldErrors(form);
  let hasVisible = false;

  for (const error of errors) {
    const node = qs(`[data-error-for="${error.field}"]`, form);
    if (!node) {
      continue;
    }
    node.id = errorId(form, error.field);
    node.setAttribute('role', 'alert');
    node.textContent = error.message;
    node.hidden = false;
    hasVisible = true;

    const input = qs(`[name="${error.field}"]`, form);
    if (input) {
      input.setAttribute('aria-invalid', 'true');
      setDescribedBy(input, [node.id]);
    }
  }

  return hasVisible;
}

/** Moves focus to the first invalid field of a form (after showFieldErrors). */
export function focusFirstInvalid(form) {
  const input = qs('[aria-invalid="true"]', form);
  if (input) {
    input.focus();
  }
}
