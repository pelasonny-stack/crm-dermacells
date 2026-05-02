import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/auth/client';

interface Alert {
  id: number;
  type: string;
  title: string;
  body: string;
  read_at: string | null;
  created_at: string;
}

interface AlertsResponse {
  data: Alert[];
}

function useAlerts() {
  return useQuery({
    queryKey: ['alerts'],
    queryFn: async () => {
      const res = await apiClient.get<AlertsResponse>('/alerts');
      return res.data;
    },
    refetchInterval: 30_000, // poll every 30s (Reverb WebSocket replaces this in Phase 14)
  });
}

const typeIcon: Record<string, string> = {
  stock_minimum: 'Stk',
  overdue_payment: 'Vto',
  inactive_customer: 'Inac',
  birthday: 'Cumple',
  authorization_pending: 'Auth',
  default: 'Alerta',
};

export default function AlertsPage() {
  const { data, isLoading, error } = useAlerts();

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-bold text-neutral-900">Mis alertas</h2>

      {error && (
        <p className="text-sm text-danger-600">Error al cargar alertas.</p>
      )}

      {isLoading && (
        <div className="space-y-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-16 animate-pulse rounded-xl bg-neutral-200" />
          ))}
        </div>
      )}

      {data?.data.length === 0 && !isLoading && (
        <div className="rounded-xl border border-neutral-200 bg-white p-8 text-center shadow-sm">
          <p className="text-neutral-400">Sin alertas pendientes.</p>
        </div>
      )}

      <div className="space-y-2">
        {data?.data.map((alert) => (
          <div
            key={alert.id}
            className={[
              'flex items-start gap-3 rounded-xl border bg-white p-4 shadow-sm',
              alert.read_at ? 'border-neutral-200 opacity-60' : 'border-brand-200',
            ].join(' ')}
          >
            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-xs font-bold text-brand-700">
              {typeIcon[alert.type] ?? typeIcon['default']}
            </div>
            <div className="min-w-0 flex-1">
              <p className="font-medium text-neutral-800">{alert.title}</p>
              <p className="text-sm text-neutral-500">{alert.body}</p>
            </div>
            <span className="shrink-0 text-xs text-neutral-400">
              {alert.created_at.slice(0, 10)}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
