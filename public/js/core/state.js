/** Tiny observable store (no framework): single source for cross-module
 *  state. Feature-local UI state (busy flags, form drafts) stays in features. */

export function createStore(initial = {}) {
  let state = { ...initial };
  const listeners = new Set();

  return {
    get(key) {
      return state[key];
    },

    snapshot() {
      return { ...state };
    },

    /** Sets one key; listeners fire only when the value actually changed. */
    set(key, value) {
      if (Object.is(state[key], value)) {
        return;
      }
      state = { ...state, [key]: value };
      const snapshot = { ...state };
      for (const listener of [...listeners]) {
        listener(snapshot);
      }
    },

    /** Subscribes to changes; returns the unsubscribe function. */
    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
  };
}

/** App-wide session state (currently the signed-in user). */
export const appState = createStore({ user: null });
