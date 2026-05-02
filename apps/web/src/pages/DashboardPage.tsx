import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/auth/client';
import { useAuthStore } from '@/auth/auth-store';
import type { DashboardResponse } from '@dermacells/api-client';

function useDashboard() {
  return useQuery({
    queryKey: ['dashboard', 'me'],
    queryFn: async () => {
      const res = await apiClient.get<DashboardResponse>('/dashboards/me');
      return res.data;
    },
  });
}

const roleLabel: Record<string, string> = {
  director: 'Director',
  distributor: 'Distribuidor',
  seller: 'Vendedor',
};

export default function DashboardPage() {
  const { user } = useAuthStore();
  const { data, isLoading, error } = useDashboard();

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold text-neutral-900">
          Bienvenido, {user?.name}
        </h2>
        <p className="text-sm text-neutral-500">
          {user ? roleLabel[user.role] : ''} &mdash; Tablero personal
        </p>
      </div>

      {isLoading && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <div
              key={i}
              className="h-32 animate-pulse rounded-xl bg-neutral-200"
            />
          ))}
        </div>
      )}

      {error && (
        <div className="rounded-lg border border-danger-500/30 bg-danger-500/10 p-4 text-sm text-danger-600">
          No se pudo cargar el tablero. El backend puede estar iniciando.
        </div>
      )}

      {data && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <DashboardCard title="Hoy" content={data.today} />
          <DashboardCard title="Mi mes" content={data.month} />
          <DashboardCard title="Operacion" content={data.operation} />
        </div>
      )}
    </div>
  );
}

function DashboardCard({
  title,
  content,
}: {
  title: string;
  content: Record<string, unknown>;
}) {
  const entries = Object.entries(content);

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm">
      <h3 className="mb-3 text-sm font-semibold uppercase tracking-wider text-neutral-500">
        {title}
      </h3>
      {entries.length === 0 ? (
        <p className="text-sm text-neutral-400">Sin datos</p>
      ) : (
        <dl className="space-y-2">
          {entries.map(([k, v]) => (
            <div key={k} className="flex justify-between gap-2 text-sm">
              <dt className="text-neutral-600">{k.replace(/_/g, ' ')}</dt>
              <dd className="font-medium text-neutral-900">
                {typeof v === 'object' ? JSON.stringify(v) : String(v ?? '-')}
              </dd>
            </div>
          ))}
        </dl>
      )}
    </div>
  );
}
