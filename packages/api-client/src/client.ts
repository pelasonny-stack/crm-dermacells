import axios, {
  type AxiosInstance,
  type InternalAxiosRequestConfig,
  type AxiosError,
} from 'axios';

export type GetTokenFn = () => string | null | Promise<string | null>;
export type OnUnauthorizedFn = () => void | Promise<void>;

export interface ApiClientOptions {
  baseUrl: string;
  getToken: GetTokenFn;
  onUnauthorized?: OnUnauthorizedFn;
  timeoutMs?: number;
}

/**
 * Factory that creates a configured Axios instance for the Dermacells API.
 *
 * Usage (web):
 *   const client = createApiClient({
 *     baseUrl: 'https://api.dermacells.com.ar/api/v1',
 *     getToken: () => localStorage.getItem('token'),
 *     onUnauthorized: () => { window.location.href = '/login'; },
 *   });
 *
 * Usage (mobile, expo-secure-store):
 *   const client = createApiClient({
 *     baseUrl: process.env.EXPO_PUBLIC_API_BASE_URL,
 *     getToken: getTokenWithBiometricGate,
 *     onUnauthorized: () => authStore.signOut(),
 *   });
 */
export function createApiClient(options: ApiClientOptions): AxiosInstance {
  const { baseUrl, getToken, onUnauthorized, timeoutMs = 15_000 } = options;

  const instance = axios.create({
    baseURL: baseUrl,
    timeout: timeoutMs,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
  });

  // Request interceptor: attach Bearer token
  instance.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
    const token = await Promise.resolve(getToken());
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  });

  // Response interceptor: handle 401 by triggering re-auth
  instance.interceptors.response.use(
    (response) => response,
    async (error: AxiosError) => {
      if (error.response?.status === 401 && onUnauthorized) {
        await Promise.resolve(onUnauthorized());
      }
      return Promise.reject(error);
    }
  );

  return instance;
}

export default createApiClient;
