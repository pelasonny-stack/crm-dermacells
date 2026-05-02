import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { apiClient } from '@/auth/client';
import type { Sale, PaginatedResponse } from '@dermacells/api-client';

function useSales(page: number) {
  return useQuery({
    queryKey: ['sales', page],
    queryFn: async () => {
      const res = await apiClient.get<PaginatedResponse<Sale>>('/sales', {
        params: { page, per_page: 20 },
      });
      return res.data;
    },
    placeholderData: (prev) => prev,
  });
}

const statusLabel: Record<string, string> = {
  draft: 'Borrador',
  confirmed: 'Confirmada',
  delivered: 'Entregada',
  cancelled: 'Cancelada',
};

const statusColor: Record<string, string> = {
  draft: 'bg-neutral-100 text-neutral-700',
  confirmed: 'bg-brand-100 text-brand-800',
  delivered: 'bg-success-500/10 text-success-600',
  cancelled: 'bg-danger-500/10 text-danger-600',
};

export default function SalesPage() {
  const [page, setPage] = useState(1);
  const { data, isLoading, error } = useSales(page);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-bold text-neutral-900">Ventas</h2>
        <Link
          to="/sales/new"
          className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
        >
          Nueva venta
        </Link>
      </div>

      {error && (
        <p className="text-sm text-danger-600">Error al cargar ventas.</p>
      )}

      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-neutral-200 bg-neutral-50 text-left">
              <th className="px-4 py-3 font-semibold text-neutral-600">ID</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Cliente</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Estado</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Moneda</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Total</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Fecha</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-neutral-100">
            {isLoading &&
              Array.from({ length: 5 }).map((_, i) => (
                <tr key={i}>
                  {Array.from({ length: 6 }).map((_, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 animate-pulse rounded bg-neutral-200" />
                    </td>
                  ))}
                </tr>
              ))}
            {data?.data.map((sale) => (
              <tr key={sale.id} className="hover:bg-neutral-50">
                <td className="px-4 py-3">
                  <Link
                    to={`/sales/${sale.id}`}
                    className="font-medium text-brand-700 hover:underline"
                  >
                    #{sale.id}
                  </Link>
                </td>
                <td className="px-4 py-3 text-neutral-600">#{sale.customer_id}</td>
                <td className="px-4 py-3">
                  <span
                    className={[
                      'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                      statusColor[sale.status] ?? 'bg-neutral-100 text-neutral-700',
                    ].join(' ')}
                  >
                    {statusLabel[sale.status] ?? sale.status}
                  </span>
                </td>
                <td className="px-4 py-3 text-neutral-600">{sale.currency}</td>
                <td className="px-4 py-3 font-medium text-neutral-800">
                  {sale.total != null ? sale.total : '—'}
                </td>
                <td className="px-4 py-3 text-neutral-500">
                  {sale.created_at.slice(0, 10)}
                </td>
              </tr>
            ))}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-neutral-400">
                  No hay ventas registradas.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {data && data.meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-neutral-600">
          <span>
            Pagina {data.meta.current_page} de {data.meta.last_page}
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={page === 1}
              onClick={() => setPage((p) => p - 1)}
              className="rounded border border-neutral-300 px-3 py-1 disabled:opacity-40"
            >
              Anterior
            </button>
            <button
              type="button"
              disabled={page === data.meta.last_page}
              onClick={() => setPage((p) => p + 1)}
              className="rounded border border-neutral-300 px-3 py-1 disabled:opacity-40"
            >
              Siguiente
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
