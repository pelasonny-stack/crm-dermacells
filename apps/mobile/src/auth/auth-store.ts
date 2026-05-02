import { create } from 'zustand';
import type { User } from '@/api/types';

interface AuthState {
  token: string | null;
  user: User | null;
  /** Token received from OAuth, not yet confirmed by biometric */
  pendingToken: string | null;

  setToken: (token: string | null) => void;
  setUser: (user: User | null) => void;
  setPendingToken: (token: string | null) => void;
  signOut: () => void;
}

export const useAuthStore = create<AuthState>()((set) => ({
  token: null,
  user: null,
  pendingToken: null,

  setToken: (token) => set({ token }),
  setUser: (user) => set({ user }),
  setPendingToken: (pendingToken) => set({ pendingToken }),

  signOut: () =>
    set({
      token: null,
      user: null,
      pendingToken: null,
    }),
}));
