/** Progress analytics endpoint (Prompt 22 contract). The server owns
 *  every number — this wrapper only moves the payload. */

import { api } from '../../core/api-client.js';

/** Read-only analytics snapshot for the dashboard's progress section. */
export function progressSummary() {
  return api.get('/progress/summary').then((data) => data.analytics);
}
