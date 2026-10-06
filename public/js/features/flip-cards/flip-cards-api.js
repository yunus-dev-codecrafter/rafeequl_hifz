/** Flip-card endpoints (Prompt 13 contract) — scaffold for the future screen;
 *  the dashboard queue card consumes listQueue() today. */

import { api } from '../../core/api-client.js';

export function listCategories() {
  return api.get('/flip-cards/categories');
}

/** Active review queue: never-reviewed → least recently reviewed → oldest. */
export function listQueue(limit) {
  const params = limit !== undefined && limit !== null ? '?limit=' + Number(limit) : '';
  return api.get('/flip-cards/queue' + params);
}

export function listCards(query = {}) {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== '') {
      params.set(key, String(value));
    }
  }
  const suffix = params.toString();
  return api.get('/flip-cards' + (suffix ? '?' + suffix : ''));
}

export function cardDetail(cardId) {
  return api.get('/flip-cards/' + cardId);
}

export function createCard(payload) {
  return api.post('/flip-cards', payload);
}

export function reviewCard(cardId, payload) {
  return api.post('/flip-cards/' + cardId + '/review', payload);
}

export function setCardStatus(cardId, status) {
  return api.put('/flip-cards/' + cardId + '/status', { status });
}

export function deleteCard(cardId) {
  return api.delete('/flip-cards/' + cardId);
}
