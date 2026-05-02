import axios, { type AxiosInstance, type InternalAxiosRequestConfig } from 'axios';

const DEFAULT_BASE_URL =
  process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:8000/api/v1';

let _instance: AxiosInstance | null = null;

/**
 * Get (or create) the configured Axios instance.
 * Pass `token` each time; it updates the Authorization header.
 */
export function getApiClient(token: string | null): AxiosInstance {
  if (!_instance) {
    _instance = axios.create({
      baseURL: DEFAULT_BASE_URL,
      timeout: 15_000,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });

    // Request interceptor: attach token if available
    _instance.interceptors.request.use((config: InternalAxiosRequestConfig) => {
      if (token) {
        config.headers.Authorization = `Bearer ${token}`;
      }
      return config;
    });

    // Response interceptor: handle 401
    _instance.interceptors.response.use(
      (response) => response,
      async (error) => {
        if (error.response?.status === 401) {
          // Token expired or invalid — caller should trigger re-auth
          // We import dynamically to avoid circular deps
          const { useAuthStore } = await import('@/auth/auth-store');
          const { deleteToken } = await import('@/auth/secure-storage');
          await deleteToken();
          useAuthStore.getState().signOut();
        }
        return Promise.reject(error);
      }
    );
  } else {
    // Update the Authorization header on the existing instance
    if (token) {
      _instance.defaults.headers.common.Authorization = `Bearer ${token}`;
    } else {
      delete _instance.defaults.headers.common.Authorization;
    }
  }

  return _instance;
}

/**
 * Reset the singleton (useful for testing or after logout).
 */
export function resetApiClient(): void {
  _instance = null;
}
