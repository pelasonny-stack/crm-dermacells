import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { apiClient } from '@/auth/client';
import type { Customer, PaginatedResponse } from '@dermacells/api-client';

function useCustomers(search: string, page: number) {
  return useQuery({
    queryKey: ['customers', search, page],
    queryFn: async () => {
      const res = await apiClient.get<PaginatedResponse<Customer>>(
        '/customers',
        { params: { search, page, per_page: 20 } },
      );
      return res.data;
    },
    placeholderData: (prev) => prev,
  });
}

const categoryLabel: Record<string, string> = {
  A: 'Clinica',
  B: 'Profesional grande',
  C: 'Profesional independiente',
  D: 'Dist.-cliente',
};

export default function CustomersPage() {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const { data, isLoading, error } = useCustomers(search, page);

  function handleSearch(e: React.ChangeEvent<HTMLInputElement>) {
    setSearch(e.target.value);
    setPage(1);
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-bold text-neutral-900">Clientes</h2>
      </div>

      {/* Search */}
      <input
        type="search"
        value={search}
        onChange={handleSearch}
        placeholder="Buscar por nombre, CUIT, email..."
        className="w-full max-w-md rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
      />

      {error && (
        <p className="text-sm text-danger-600">Error al cargar clientes.</p>
      )}

      {/* Table */}
      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-neutral-200 bg-neutral-50 text-left">
              <th className="px-4 py-3 font-semibold text-neutral-600">Nombre</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">CUIT</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Cat.</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Email</th>
              <th className="px-4 py-3 font-semibold text-neutral-600">Telefono</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-neutral-100">
            {isLoading &&
              Array.from({ length: 5 }).map((_, i) => (
                <tr key={i}>
                  {Array.from({ length: 5 }).map((_, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 animate-pulse rounded bg-neutral-200" />
                    </td>
                  ))}
                </tr>
              ))}
            {data?.data.map((customer) => (
              <tr key={customer.id} className="hover:bg-neutral-50">
                <td className="px-4 py-3">
                  <Link
                    to={`/customers/${customer.id}`}
                    className="font-medium text-brand-700 hover:underline"
                  >
                    {customer.name} {customer.last_name}
                  </Link>
                </td>
                <td className="px-4 py-3 text-neutral-600">{customer.cuit}</td>
                <td className="px-4 py-3">
                  <span className="inline-flex items-center rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-800">
                    {categoryLabel[customer.category] ?? customer.category}
                  </span>
                </td>
                <td className="px-4 py-3 text-neutral-600">{customer.email}</td>
                <td className="px-4 py-3 text-neutral-600">{customer.phone}</td>
              </tr>
            ))}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-neutral-400">
                  No se encontraron clientes.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {data && data.meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-neutral-600">
          <span>
            Pagina {data.meta.current_page} de {data.meta.last_page} &mdash;{' '}
            {data.meta.total} clientes
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
