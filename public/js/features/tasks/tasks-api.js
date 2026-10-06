/** Daily task endpoints (Prompt 14 contract). The server owns every summary
 *  number — these wrappers only move payloads. */

import { api } from '../../core/api-client.js';

/** One scheduled day (default: today, server UTC) with its summary. */
export function dayTasks(date) {
  return api.get(date ? '/tasks?date=' + encodeURIComponent(date) : '/tasks');
}

export function taskHistory(from, to, limit) {
  const params = new URLSearchParams({ from, to });
  if (limit !== undefined && limit !== null) {
    params.set('limit', String(limit));
  }
  return api.get('/tasks/history?' + params.toString());
}

export function taskDetail(taskId) {
  return api.get('/tasks/' + taskId);
}

export function taskTypes() {
  return api.get('/task-types');
}

export function createTask(payload) {
  return api.post('/tasks', payload);
}

export function updateTask(taskId, payload) {
  return api.put('/tasks/' + taskId, payload);
}

export function setTaskStatus(taskId, status) {
  return api.put('/tasks/' + taskId + '/status', { status });
}

export function deleteTask(taskId) {
  return api.delete('/tasks/' + taskId);
}
