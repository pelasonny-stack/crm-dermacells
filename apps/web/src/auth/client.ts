/**
 * Axios instance for Sanctum SPA cookie auth.
 *
 * Flow:
 *   1. On app boot call initCsrf() once to obtain the XSRF-TOKEN cookie.
 *   2. Axios automatically picks up XSRF-TOKEN and sends it as X-XSRF-TOKEN.
 *   3. All subsequent requests carry the session cookie (withCredentials: true).
 */
import axios from 'axios';

export const apiClient = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

/**
 * Bootstrap Sanctum SPA auth. Must be called once before any authenticated
 * request. Typically invoked at app startup inside AuthProvider.
 */
export async function initCsrf(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', {
    withCredentials: true,
    baseURL: '',
  });
}
