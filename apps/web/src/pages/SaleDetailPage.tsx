import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/auth/client';
import { useAuthStore } from '@/auth/auth-store';
import type { Sale } from '@dermacells/api-client';

function useSale(id: string) {
  return useQuery({
    queryKey: ['sale', id],
    queryFn: async () => {
      const res = await apiClient.get<Sale>(`/sales/${id}`);
      return res.data;
    },
  });
}

const statusLabel: Record<string, string> = {
  draft: 'Borrador',
  confirmed: 'Confirmada',
  delivered: 'Entregada',
  cancelled: 'Cancelada',
};

type Transition = 'confirm' | 'deliver' | 'cancel';

interface TransitionConfig {
  label: string;
  action: string;
  allowedRoles: string[];
  allowedStatuses: string[];
  color: string;
}

const TRANSITIONS: Record<Transition, TransitionConfig> = {
  confirm: {
    label: 'Confirmar',
    action: 'confirm',
    allowedRoles: ['director', 'distributor', 'seller'],
    allowedStatuses: ['draft'],
    color: 'bg-brand-600 hover:bg-brand-700 text-white',
  },
  deliver: {
    label: 'Marcar entregada',
    action: 'deliver',
    allowedRoles: ['director', 'distributor', 'seller'],
    allowedStatuses: ['confirmed'],
    color: 'bg-success-600 hover:bg-success-600/90 text-white',
  },
  cancel: {
    label: 'Cancelar',
    action: 'cancel',
    allowedRoles: ['director', 'distributor', 'seller'],
    allowedStatuses: ['draft', 'confirmed', 'delivered'],
    color: 'bg-danger-600 hover:bg-danger-600/90 text-white',
  },
};

export default function SaleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuthStore();
  const qc = useQueryClient();
  const { data: sale, isLoading, error } = useSale(id!);

  const transition = useMutation({
    mutationFn: async (action: string) => {
      await apiClient.post(`/sales/${id}/${action}`);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['sale', id] });
    },
  });

  if (isLoading) {
    return <div className="h-48 animate-pulse rounded-xl bg-neutral-200" />;
  }

  if (error || !sale) {
    return (
      <p className="text-sm text-danger-600">
        No se pudo cargar la venta.{' '}
        <Link to="/sales" className="underline">
          Volver
        </Link>
      </p>
    );
  }

  const availableTransitions = Object.entries(TRANSITIONS).filter(
    ([, cfg]) =>
      user &&
      cfg.allowedRoles.includes(user.role) &&
      cfg.allowedStatuses.includes(sale.status),
  );

  return (
    <div className="space-y-5">
      {/* Breadcrumb */}
      <div className="flex items-center gap-2 text-sm">
        <Link to="/sales" className="text-brand-600 hover:underline">
          Ventas
        </Link>
        <span className="text-neutral-400">/</span>
        <span className="font-medium text-neutral-800">Venta #{sale.id}</span>
      </div>

      {/* Status + actions */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <h2 className="text-2xl font-bold text-neutral-900">Venta #{sale.id}</h2>
          <span className="rounded-full bg-brand-100 px-3 py-1 text-sm font-medium text-brand-800">
            {statusLabel[sale.status] ?? sale.status}
          </span>
        </div>

        <div className="flex gap-2">
          {availableTransitions.map(([key, cfg]) => (
            <button
              key={key}
              type="button"
              disabled={transition.isPending}
              onClick={() => transition.mutate(cfg.action)}
              className={[
                'rounded-lg px-4 py-2 text-sm font-medium transition-colors disabled:opacity-50',
                cfg.color,
              ].join(' ')}
            >
              {cfg.label}
            </button>
          ))}
        </div>
      </div>

      {transition.isError && (
        <p className="text-sm text-danger-600">Error al ejecutar la accion.</p>
      )}

      {/* Sale details */}
      <div className="grid grid-cols-1 gap-4 rounded-xl border border-neutral-200 bg-white p-5 shadow-sm sm:grid-cols-3">
        <Field label="Cliente ID" value={String(sale.customer_id)} />
        <Field label="Moneda" value={sale.currency} />
        <Field label="TC" value={sale.exchange_rate?.toString() ?? '—'} />
        <Field label="Total" value={sale.total?.toString() ?? '—'} />
        <Field
          label="Entrega delegada"
          value={sale.delegated_delivery ? 'Si' : 'No'}
        />
        <Field label="Notas" value={sale.notes ?? '—'} />
      </div>

      {/* Items */}
      {sale.items.length > 0 && (
        <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-neutral-200 bg-neutral-50 text-left">
                <th className="px-4 py-3 font-semibold text-neutral-600">Producto</th>
                <th className="px-4 py-3 font-semibold text-neutral-600">Cajas</th>
                <th className="px-4 py-3 font-semibold text-neutral-600">Unid. sueltas</th>
                <th className="px-4 py-3 font-semibold text-neutral-600">Precio unit.</th>
                <th className="px-4 py-3 font-semibold text-neutral-600">Subtotal</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-neutral-100">
              {sale.items.map((item) => (
                <tr key={item.id}>
                  <td className="px-4 py-3 font-medium text-neutral-800">{item.product}</td>
                  <td className="px-4 py-3 text-neutral-600">{item.boxes}</td>
                  <td className="px-4 py-3 text-neutral-600">{item.loose_units}</td>
                  <td className="px-4 py-3 text-neutral-600">
                    {item.currency} {item.unit_price_usd}
                  </td>
                  <td className="px-4 py-3 font-medium text-neutral-800">
                    {item.currency} {item.subtotal}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs font-semibold uppercase tracking-wider text-neutral-400">
        {label}
      </dt>
      <dd className="mt-0.5 text-sm text-neutral-800">{value}</dd>
    </div>
  );
}
