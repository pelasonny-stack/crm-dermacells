import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { AppState, type AppStateStatus } from 'react-native';
import { useEffect, useRef } from 'react';
import { useAuthStore } from '@/auth/auth-store';
import { getApiClient } from '@/api/client';

export type AlertSeverity = 'info' | 'warning' | 'critical';

export interface Alert {
  id: number;
  alert_type: string;
  severity: AlertSeverity;
  message: string;
  payload: Record<string, unknown> | null;
  reference_entity_type: string | null;
  reference_entity_id: number | null;
  delivered_at: string | null;
  read_at: string | null;
  created_at: string;
}

export interface AlertsPage {
  data: Alert[];
  meta: { total: number; unread_count: number };
}

export const ALERTS_QUERY_KEY = ['alerts'] as const;
export const UNREAD_ALERTS_QUERY_KEY = ['alerts', 'unread'] as const;

/**
 * Poll unread alerts every 60s and refresh on app foreground.
 */
export function useUnreadAlerts() {
  const { token } = useAuthStore();
  const queryClient = useQueryClient();
  const appStateRef = useRef<AppStateStatus>(AppState.currentState);

  useEffect(() => {
    const sub = AppState.addEventListener('change', (nextState) => {
      if (
        appStateRef.current.match(/inactive|background/) &&
        nextState === 'active'
      ) {
        queryClient.invalidateQueries({ queryKey: UNREAD_ALERTS_QUERY_KEY });
      }
      appStateRef.current = nextState;
    });
    return () => sub.remove();
  }, [queryClient]);

  return useQuery<AlertsPage>({
    queryKey: UNREAD_ALERTS_QUERY_KEY,
    queryFn: async () => {
      const client = getApiClient(token ?? '');
      const res = await client.get<AlertsPage>('/alerts/me', {
        params: { delivered: false },
      });
      return res.data;
    },
    enabled: !!token,
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
  });
}

/**
 * Fetch all alerts (unread + read) for the list screen.
 */
export function useAlerts(params?: { severity?: AlertSeverity; unread_only?: boolean }) {
  const { token } = useAuthStore();

  return useQuery<AlertsPage>({
    queryKey: [...ALERTS_QUERY_KEY, params],
    queryFn: async () => {
      const client = getApiClient(token ?? '');
      const query: Record<string, unknown> = { per_page: 100 };
      if (params?.severity) query.severity = params.severity;
      if (params?.unread_only) query.delivered = false;
      const res = await client.get<AlertsPage>('/alerts/me', { params: query });
      return res.data;
    },
    enabled: !!token,
  });
}

/**
 * Mark a single alert as read via PATCH /alerts/{id}/read.
 * Optimistically invalidates unread count.
 */
export function useMarkAlertRead() {
  const { token } = useAuthStore();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (alertId: number) => {
      const client = getApiClient(token ?? '');
      await client.patch(`/alerts/${alertId}/read`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: UNREAD_ALERTS_QUERY_KEY });
      queryClient.invalidateQueries({ queryKey: ALERTS_QUERY_KEY });
    },
  });
}

/**
 * Mark all unread alerts as read.
 */
export function useMarkAllAlertsRead() {
  const { token } = useAuthStore();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      const client = getApiClient(token ?? '');
      await client.post('/alerts/me/read-all');
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: UNREAD_ALERTS_QUERY_KEY });
      queryClient.invalidateQueries({ queryKey: ALERTS_QUERY_KEY });
    },
  });
}
