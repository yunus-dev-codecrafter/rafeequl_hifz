/** Transient status messages rendered into the shell's live region. */

const VARIANTS = ['info', 'error', 'success'];
const DEFAULT_DURATION_MS = 3200;
const ERROR_DURATION_MS = 5000;
const LEAVE_MS = 200;

export function showToast(message, variant = 'info') {
  const region = document.getElementById('toast-region');
  if (!region) {
    return;
  }

  const safeVariant = VARIANTS.includes(variant) ? variant : 'info';
  const toast = document.createElement('div');
  toast.className = 'toast toast--' + safeVariant;
  // Errors interrupt (assertive); info/success stay polite in the region.
  if (safeVariant === 'error') {
    toast.setAttribute('role', 'alert');
  }
  toast.textContent = message;
  region.appendChild(toast);

  const duration = safeVariant === 'error' ? ERROR_DURATION_MS : DEFAULT_DURATION_MS;
  window.setTimeout(() => {
    toast.classList.add('toast--leaving');
    window.setTimeout(() => toast.remove(), LEAVE_MS);
  }, duration);
}
