/**
 * Zustand auth store.
 *
 * State:
 *   user            — authenticated User or null
 *   isAuthenticated — derived from user != null
 *   isLoading       — true while loadCurrentUser() is in flight (bootstrap)
 *
 * Actions:
 *   loadCurrentUser() — called once at app boot; hits /api/v1/auth/me,
 *                       falling back to /api/v1/dashboards/me to check
 *                       whether a session cookie is already valid.
 *   logout()          — POST /auth/logout then clears local state.
 */
import { create } from 'zustand';
import type { User } from '@dermacells/api-client';
import { apiClient, initCsrf } from './client';
import { serverLogout } from './oauth';

interface AuthState {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;

  loadCurrentUser: () => Promise<void>;
  logout: () => Promise<void>;
  _setUser: (user: User | null) => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  isAuthenticated: false,
  isLoading: true,

  _setUser: (user) =>
    set({ user, isAuthenticated: user !== null, isLoading: false }),

  loadCurrentUser: async () => {
    set({ isLoading: true });
    try {
      // Bootstrap CSRF first so subsequent POST requests are protected.
      await initCsrf();

      // Try the dedicated /auth/me endpoint if available, otherwise use
      // /dashboards/me as a session health probe (returns 401 if not auth'd).
      const response = await apiClient.get<User>('/auth/me');
      set({ user: response.data, isAuthenticated: true, isLoading: false });
    } catch (err: unknown) {
      // 401 = no valid session. Any other error (network) — stay unauthenticated
      // but don't crash so the login page can render.
      set({ user: null, isAuthenticated: false, isLoading: false });
    }
  },

  logout: async () => {
    try {
      await serverLogout();
    } finally {
      set({ user: null, isAuthenticated: false, isLoading: false });
    }
  },
}));
