import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/auth/client';
import type { Payment, PaginatedResponse } from '@dermacells/api-client';

function usePayments(page: number) {
  return useQuery({
    queryKey: ['payments', page],
    queryFn: async () => {
      const res = await apiClient.get<PaginatedResponse<Payment>>('/payments', {
        params: { page, per_page: 20 },
      });
      return res.data;
    },
    placeholderData: (prev) => prev,
  });
}

const methodLabel: Record<string, string> = {
  transfer_dermacells: 'Transf. Dermacells',
  transfer_distributor: 'Transf. Distribuidor',
  cash: 'Efectivo',
  credit_card: 'Tarjeta',
  check: 'Cheque',
};

export default function PaymentsPage() {
  const [page, setPage] = useState(1);
  const { data, isLoading, error } = usePayments(page);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-bold text-neutral-900">Cobros</h2>
        <button
          type="button"
          className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
        >
          Registrar cobro
        </button>
      </div>

      {error && <p className="text-sm text-danger-600">Error al cargar cobros.</p>}

      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-neutral-200 bg-neutral-50 text-left">
              <th className="px-4 py-3 font-semibold text-neutral-600">ID</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Venta</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Medio</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Monto</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Moneda</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Vencimiento</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Cobrado</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-neutral-100">
            {isLoading &&
              Array.from({ length: 5 }).map((_, i) => (
                <tr key={i}>
                  {Array.from({ length: 7 }).map((_, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 animate-pulse rounded bg-neutral-200" />
                    </td>
                  ))}
                </tr>
              ))}
            {data?.data.map((payment) => (
              <tr key={payment.id} className="hover:bg-neutral-50">
                <td className="px-4 py-3 text-neutral-600">#{payment.id}</td>
                <td className="px-4 py-3 text-neutral-600">#{payment.sale_id}</td>
                <td className="px-4 py-3 text-neutral-600">
                  {methodLabel[payment.method] ?? payment.method}
                </td>
                <td className="px-4 py-3 font-medium text-neutral-800">
                  {payment.amount}
                </td>
                <td className="px-4 py-3 text-neutral-600">{payment.currency}</td>
                <td className="px-4 py-3 text-neutral-500">
                  {payment.due_date ?? '—'}
                </td>
                <td className="px-4 py-3">
                  {payment.paid_at ? (
                    <span className="text-success-600 font-medium text-xs">
                      {payment.paid_at.slice(0, 10)}
                    </span>
                  ) : (
                    <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-500">
                      Pendiente
                    </span>
                  )}
                </td>
              </tr>
            ))}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-neutral-400">
                  No hay cobros registrados.
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
