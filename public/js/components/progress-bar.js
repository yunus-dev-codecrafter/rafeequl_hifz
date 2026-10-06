/** Determinate progress bar driven by a server-provided percentage. */

export function updateProgressBar(bar, percent) {
  const value = Math.min(100, Math.max(0, Number(percent) || 0));
  bar.style.setProperty('--fill', value + '%');
  bar.setAttribute('aria-valuenow', String(value));
  bar.setAttribute('aria-valuetext', value + '%');
}
