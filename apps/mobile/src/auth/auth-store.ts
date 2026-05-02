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

export const useAuthStore = create<AuthState>()((set, get) => ({
  token: null,
  user: null,
  pendingToken: null,

  setToken: (token) => set({ token }),
  setUser: (user) => set({ user }),
  setPendingToken: (pendingToken) => set({ pendingToken }),

  signOut: () => {
    const currentToken = get().token;

    // Fire-and-forget FCM unregistration before clearing state
    if (currentToken) {
      import('@/notifications/fcm-channel')
        .then(({ unregisterFcm }) => unregisterFcm(currentToken))
        .catch((err) => console.warn('[AuthStore] FCM unregister error:', err));
    }

    set({
      token: null,
      user: null,
      pendingToken: null,
    });
  },
}));
