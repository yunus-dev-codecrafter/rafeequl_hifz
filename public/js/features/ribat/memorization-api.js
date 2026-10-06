/** Memorization/Hifz endpoints: state, boundary, progress history and ربط.
 *  All Hifz calculations stay server-side — these wrappers only move payloads. */

import { api } from '../../core/api-client.js';

/** Current memorized range, boundary and progress (state.progress from server). */
export function getState() {
  return api.get('/memorization/state');
}

export function establishState(payload) {
  return api.post('/memorization/state', payload);
}

export function correctBoundary(payload) {
  return api.put('/memorization/state', payload);
}

export function markMemorized(payload) {
  return api.post('/memorization/history', payload);
}

/** The ربط window (newest ≤30 memorized pages ending at the boundary). */
export function rabtRange() {
  return api.get('/memorization/rabt');
}
