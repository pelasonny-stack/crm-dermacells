import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import type { Alert, PaginatedResponse } from '@dermacells/api-client';
import { apiClient } from '@/auth/client';

export const ALERTS_QUERY_KEY = ['alerts', 'unread'] as const;

export function useUnreadAlerts() {
  return useQuery({
    queryKey: ALERTS_QUERY_KEY,
    queryFn: async () => {
      const res = await apiClient.get<PaginatedResponse<Alert>>(
        '/alerts/me',
        { params: { delivered: false, limit: 10 } },
      );
      return res.data;
    },
    refetchInterval: 30_000,
    refetchOnWindowFocus: true,
  });
}

export function useMarkAlertRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await apiClient.patch(`/alerts/${id}/read`);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ALERTS_QUERY_KEY });
    },
  });
}
