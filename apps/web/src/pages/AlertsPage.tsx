import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import type { Alert, AlertSeverity, PaginatedResponse } from '@dermacells/api-client';
import { apiClient } from '@/auth/client';
import { ALERTS_QUERY_KEY } from '@/api/alerts';

// ---------------------------------------------------------------------------
// Data layer (full list for the page — no limit, includes read ones)
// ---------------------------------------------------------------------------

const PAGE_QUERY_KEY = ['alerts', 'page'] as const;

interface AlertsFilter {
  severity: AlertSeverity | 'all';
  unreadOnly: boolean;
  dateFrom: string;
  dateTo: string;
}

function usePageAlerts(filters: AlertsFilter) {
  return useQuery({
    queryKey: [...PAGE_QUERY_KEY, filters],
    queryFn: async () => {
      const params: Record<string, string | boolean | undefined> = {
        limit: '100',
        ...(filters.unreadOnly && { delivered: false }),
        ...(filters.severity !== 'all' && { severity: filters.severity }),
        ...(filters.dateFrom && { date_from: filters.dateFrom }),
        ...(filters.dateTo && { date_to: filters.dateTo }),
      };
      const res = await apiClient.get<PaginatedResponse<Alert>>('/alerts/me', { params });
      return res.data;
    },
    refetchInterval: 30_000,
    refetchOnWindowFocus: true,
  });
}

function useMarkAllRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (ids: number[]) => {
      await Promise.all(ids.map((id) => apiClient.patch(`/alerts/${id}/read`)));
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: PAGE_QUERY_KEY });
      void qc.invalidateQueries({ queryKey: ALERTS_QUERY_KEY });
    },
  });
}

function useMarkOneRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await apiClient.patch(`/alerts/${id}/read`);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: PAGE_QUERY_KEY });
      void qc.invalidateQueries({ queryKey: ALERTS_QUERY_KEY });
    },
  });
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function relativeTime(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.floor(diff / 60_000);
  if (mins < 1) return 'ahora';
  if (mins < 60) return `hace ${mins}m`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `hace ${hrs}h`;
  const days = Math.floor(hrs / 24);
  return `hace ${days}d`;
}

const SEVERITY_PILL: Record<AlertSeverity, string> = {
  info: 'bg-blue-100 text-blue-700',
  warning: 'bg-amber-100 text-amber-700',
  critical: 'bg-red-100 text-red-700',
};

const SEVERITY_LABEL: Record<AlertSeverity, string> = {
  info: 'Info',
  warning: 'Atención',
  critical: 'Crítico',
};

function payloadSummary(payload: Record<string, unknown>): string {
  const val = payload['message'] ?? payload['summary'] ?? payload['title'];
  if (typeof val === 'string') return val;
  return Object.entries(payload)
    .slice(0, 3)
    .map(([k, v]) => `${k}: ${String(v)}`)
    .join(' · ');
}

// ---------------------------------------------------------------------------
// AlertCard
// ---------------------------------------------------------------------------

function AlertCard({ alert, onMarkRead }: { alert: Alert; onMarkRead: (id: number) => void }) {
  const isUnread = !alert.read_at;
  const severityClass = SEVERITY_PILL[alert.severity] ?? SEVERITY_PILL['info'];
  const severityLabel = SEVERITY_LABEL[alert.severity] ?? alert.severity;

  return (
    <div
      className={[
        'flex items-start gap-4 rounded-xl border bg-white p-4 shadow-sm transition-opacity',
        isUnread ? 'border-brand-200' : 'border-neutral-200 opacity-60',
      ].join(' ')}
    >
      {/* Unread indicator */}
      <span
        className={[
          'mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full',
          isUnread ? 'bg-brand-500' : 'bg-neutral-200',
        ].join(' ')}
        aria-hidden="true"
      />

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2 mb-1">
          <span className={`inline-flex rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide ${severityClass}`}>
            {severityLabel}
          </span>
          <span className="font-mono text-xs text-neutral-400">
            {alert.alert_type.replace(/_/g, ' ')}
          </span>
          {alert.reference_entity_type && (
            <span className="text-xs text-neutral-400">
              · {alert.reference_entity_type} #{alert.reference_entity_id}
            </span>
          )}
        </div>
        <p className="text-sm text-neutral-700">{payloadSummary(alert.payload)}</p>
        <p className="mt-1 text-xs text-neutral-400">{relativeTime(alert.created_at)}</p>
      </div>

      {isUnread && (
        <button
          type="button"
          onClick={() => onMarkRead(alert.id)}
          className="shrink-0 rounded border border-neutral-200 px-2 py-1 text-xs text-neutral-600 hover:bg-neutral-50"
          aria-label="Marcar como leida"
        >
          Leida
        </button>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

const SEVERITY_OPTIONS: { value: AlertsFilter['severity']; label: string }[] = [
  { value: 'all', label: 'Todas las severidades' },
  { value: 'critical', label: 'Crítico' },
  { value: 'warning', label: 'Atención' },
  { value: 'info', label: 'Info' },
];

export default function AlertsPage() {
  const [filters, setFilters] = useState<AlertsFilter>({
    severity: 'all',
    unreadOnly: false,
    dateFrom: '',
    dateTo: '',
  });

  const { data, isLoading, error } = usePageAlerts(filters);
  const markAll = useMarkAllRead();
  const markOne = useMarkOneRead();

  const alerts: Alert[] = data?.data ?? [];
  const unreadIds = alerts.filter((a) => !a.read_at).map((a) => a.id);

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-bold text-neutral-900">Mis alertas</h2>
        {unreadIds.length > 0 && (
          <button
            type="button"
            onClick={() => markAll.mutate(unreadIds)}
            disabled={markAll.isPending}
            className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
          >
            {markAll.isPending ? 'Marcando...' : `Marcar todas como leidas (${unreadIds.length})`}
          </button>
        )}
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
        {/* Severity */}
        <select
          value={filters.severity}
          onChange={(e) => setFilters((f) => ({ ...f, severity: e.target.value as AlertsFilter['severity'] }))}
          className="rounded-md border border-neutral-300 px-2 py-1.5 text-sm text-neutral-700 focus:outline-none focus:ring-2 focus:ring-brand-500"
          aria-label="Filtrar por severidad"
        >
          {SEVERITY_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>

        {/* Unread toggle */}
        <label className="flex cursor-pointer items-center gap-2 text-sm text-neutral-700">
          <input
            type="checkbox"
            checked={filters.unreadOnly}
            onChange={(e) => setFilters((f) => ({ ...f, unreadOnly: e.target.checked }))}
            className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
          />
          Solo no leidas
        </label>

        {/* Date from */}
        <label className="flex items-center gap-1.5 text-sm text-neutral-600">
          Desde
          <input
            type="date"
            value={filters.dateFrom}
            onChange={(e) => setFilters((f) => ({ ...f, dateFrom: e.target.value }))}
            className="rounded-md border border-neutral-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
        </label>

        {/* Date to */}
        <label className="flex items-center gap-1.5 text-sm text-neutral-600">
          Hasta
          <input
            type="date"
            value={filters.dateTo}
            onChange={(e) => setFilters((f) => ({ ...f, dateTo: e.target.value }))}
            className="rounded-md border border-neutral-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
        </label>

        {/* Clear */}
        {(filters.severity !== 'all' || filters.unreadOnly || filters.dateFrom || filters.dateTo) && (
          <button
            type="button"
            onClick={() => setFilters({ severity: 'all', unreadOnly: false, dateFrom: '', dateTo: '' })}
            className="text-xs text-brand-600 hover:underline"
          >
            Limpiar filtros
          </button>
        )}
      </div>

      {/* Error */}
      {error && (
        <p className="text-sm text-red-600">Error al cargar alertas. Intenta nuevamente.</p>
      )}

      {/* Skeletons */}
      {isLoading && (
        <div className="space-y-2">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="h-20 animate-pulse rounded-xl bg-neutral-200" />
          ))}
        </div>
      )}

      {/* Empty state */}
      {!isLoading && !error && alerts.length === 0 && (
        <div className="flex flex-col items-center justify-center rounded-xl border border-neutral-200 bg-white p-12 text-center shadow-sm">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            className="mb-3 h-10 w-10 text-neutral-300"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            aria-hidden="true"
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
            <path strokeLinecap="round" strokeLinejoin="round" d="M13.73 21a2 2 0 0 1-3.46 0" />
          </svg>
          <p className="text-base font-medium text-neutral-400">No tenes alertas pendientes.</p>
          {(filters.severity !== 'all' || filters.unreadOnly || filters.dateFrom || filters.dateTo) && (
            <p className="mt-1 text-sm text-neutral-400">Proba ajustando los filtros.</p>
          )}
        </div>
      )}

      {/* Alert list */}
      {!isLoading && alerts.length > 0 && (
        <div className="space-y-2">
          {alerts.map((alert) => (
            <AlertCard
              key={alert.id}
              alert={alert}
              onMarkRead={(id) => markOne.mutate(id)}
            />
          ))}
        </div>
      )}

      {/* Total */}
      {!isLoading && alerts.length > 0 && (
        <p className="text-right text-xs text-neutral-400">
          {alerts.length} alerta{alerts.length !== 1 ? 's' : ''} · {unreadIds.length} sin leer
        </p>
      )}
    </div>
  );
}
