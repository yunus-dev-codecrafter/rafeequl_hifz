/** Revision endpoints (all server-authoritative; Prompt 10 contract). */

import { api } from '../../core/api-client.js';

export function listPlans() {
  return api.get('/revision/plans');
}

export function planDetail(planId) {
  return api.get('/revision/plans/' + planId);
}

export function createPlan() {
  return api.post('/revision/plans', {});
}

export function setPlanStatus(planId, status) {
  return api.put('/revision/plans/' + planId + '/status', { status });
}

export function listSessions(limit) {
  return api.get('/revision/sessions?limit=' + limit);
}

export function startSession(segmentId, resumesSessionId) {
  const payload = { segment_id: segmentId };
  if (resumesSessionId !== null && resumesSessionId !== undefined) {
    payload.resumes_session_id = resumesSessionId;
  }
  return api.post('/revision/sessions', payload);
}

export function reportProgress(sessionId, lastPageReached) {
  return api.post('/revision/sessions/' + sessionId + '/progress', {
    last_page_reached: lastPageReached,
  });
}

export function finishSession(sessionId, payload) {
  return api.put('/revision/sessions/' + sessionId, payload);
}
