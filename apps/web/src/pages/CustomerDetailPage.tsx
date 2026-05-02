import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/auth/client';
import type { Customer, Sale, Payment, PaginatedResponse } from '@dermacells/api-client';

type Tab = 'datos' | 'ventas' | 'cobros' | 'acciones';

function useCustomer(id: string) {
  return useQuery({
    queryKey: ['customer', id],
    queryFn: async () => {
      const res = await apiClient.get<Customer>(`/customers/${id}`);
      return res.data;
    },
  });
}

function useSales(customerId: string) {
  return useQuery({
    queryKey: ['customer-sales', customerId],
    queryFn: async () => {
      const res = await apiClient.get<PaginatedResponse<Sale>>('/sales', {
        params: { customer_id: customerId },
      });
      return res.data;
    },
  });
}

function usePayments(customerId: string) {
  return useQuery({
    queryKey: ['customer-payments', customerId],
    queryFn: async () => {
      const res = await apiClient.get<PaginatedResponse<Payment>>('/payments', {
        params: { customer_id: customerId },
      });
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

const statusColor: Record<string, string> = {
  draft: 'bg-neutral-100 text-neutral-700',
  confirmed: 'bg-brand-100 text-brand-800',
  delivered: 'bg-success-500/10 text-success-600',
  cancelled: 'bg-danger-500/10 text-danger-600',
};

export default function CustomerDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [tab, setTab] = useState<Tab>('datos');
  const { data: customer, isLoading, error } = useCustomer(id!);
  const { data: sales } = useSales(id!);
  const { data: payments } = usePayments(id!);

  const tabs: { id: Tab; label: string }[] = [
    { id: 'datos', label: 'Datos' },
    { id: 'ventas', label: 'Ventas' },
    { id: 'cobros', label: 'Cobros' },
    { id: 'acciones', label: 'Acciones' },
  ];

  if (isLoading) {
    return (
      <div className="space-y-4">
        <div className="h-8 w-64 animate-pulse rounded bg-neutral-200" />
        <div className="h-48 animate-pulse rounded-xl bg-neutral-200" />
      </div>
    );
  }

  if (error || !customer) {
    return (
      <div className="rounded-lg border border-danger-500/30 bg-danger-500/10 p-4 text-sm text-danger-600">
        No se pudo cargar el cliente.{' '}
        <Link to="/customers" className="underline">
          Volver
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex items-center gap-2">
        <Link to="/customers" className="text-sm text-brand-600 hover:underline">
          Clientes
        </Link>
        <span className="text-neutral-400">/</span>
        <span className="text-sm font-medium text-neutral-800">
          {customer.name} {customer.last_name}
        </span>
      </div>

      <h2 className="text-2xl font-bold text-neutral-900">
        {customer.name} {customer.last_name}
      </h2>

      {/* Tabs */}
      <div className="flex gap-1 border-b border-neutral-200">
        {tabs.map((t) => (
          <button
            key={t.id}
            type="button"
            onClick={() => setTab(t.id)}
            className={[
              'px-4 py-2 text-sm font-medium transition-colors',
              tab === t.id
                ? 'border-b-2 border-brand-600 text-brand-700'
                : 'text-neutral-500 hover:text-neutral-700',
            ].join(' ')}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Tab content */}
      {tab === 'datos' && (
        <div className="grid grid-cols-1 gap-4 rounded-xl border border-neutral-200 bg-white p-5 shadow-sm sm:grid-cols-2">
          <Field label="CUIT" value={customer.cuit} />
          <Field label="Email" value={customer.email} />
          <Field label="Telefono" value={customer.phone} />
          <Field label="Direccion" value={customer.address} />
          <Field label="Categoria" value={customer.category} />
          <Field
            label="Precio ref. (USD/caja)"
            value={customer.reference_price_usd?.toString() ?? 'Sin precio — aplica base USD 750'}
          />
        </div>
      )}

      {tab === 'ventas' && (
        <div className="space-y-2">
          {sales?.data.map((sale) => (
            <div
              key={sale.id}
              className="flex items-center justify-between rounded-lg border border-neutral-200 bg-white px-4 py-3 shadow-sm"
            >
              <div className="text-sm">
                <Link
                  to={`/sales/${sale.id}`}
                  className="font-medium text-brand-700 hover:underline"
                >
                  Venta #{sale.id}
                </Link>
                <span className="ml-2 text-neutral-400 text-xs">{sale.created_at.slice(0, 10)}</span>
              </div>
              <span
                className={[
                  'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                  statusColor[sale.status] ?? 'bg-neutral-100 text-neutral-700',
                ].join(' ')}
              >
                {statusLabel[sale.status] ?? sale.status}
              </span>
            </div>
          ))}
          {!sales?.data.length && (
            <p className="text-sm text-neutral-400">Sin ventas registradas.</p>
          )}
        </div>
      )}

      {tab === 'cobros' && (
        <div className="space-y-2">
          {payments?.data.map((payment) => (
            <div
              key={payment.id}
              className="flex items-center justify-between rounded-lg border border-neutral-200 bg-white px-4 py-3 shadow-sm"
            >
              <div className="text-sm">
                <span className="font-medium text-neutral-800">
                  {payment.currency} {payment.amount}
                </span>
                <span className="ml-2 text-neutral-400 text-xs">{payment.method}</span>
              </div>
              <span className="text-xs text-neutral-500">
                {payment.paid_at ? payment.paid_at.slice(0, 10) : 'Pendiente'}
              </span>
            </div>
          ))}
          {!payments?.data.length && (
            <p className="text-sm text-neutral-400">Sin cobros registrados.</p>
          )}
        </div>
      )}

      {tab === 'acciones' && (
        <div className="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm">
          <p className="text-sm text-neutral-400">
            Acciones futuras programadas — disponible en Phase 3.
          </p>
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
      <dd className="mt-0.5 text-sm text-neutral-800">{value || '—'}</dd>
    </div>
  );
}
