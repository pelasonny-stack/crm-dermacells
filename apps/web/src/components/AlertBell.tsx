import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { Alert } from '@dermacells/api-client';
import { useUnreadAlerts, useMarkAlertRead } from '@/api/alerts';

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

function BadgeCount({ count }: { count: number }) {
  if (count === 0) return null;
  const label = count >= 100 ? '99+' : String(count);
  return (
    <span className="absolute -top-1 -right-1 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-red-500 px-0.5 text-[10px] font-bold leading-none text-white">
      {label}
    </span>
  );
}

const SEVERITY_CLASSES: Record<string, string> = {
  info: 'bg-blue-100 text-blue-700',
  warning: 'bg-amber-100 text-amber-700',
  critical: 'bg-red-100 text-red-700',
};

const SEVERITY_LABEL: Record<string, string> = {
  info: 'Info',
  warning: 'Atención',
  critical: 'Crítico',
};

function payloadSummary(payload: Record<string, unknown>): string {
  const val = payload['message'] ?? payload['summary'] ?? payload['title'];
  if (typeof val === 'string') return val;
  const entries = Object.entries(payload).slice(0, 2);
  return entries.map(([k, v]) => `${k}: ${String(v)}`).join(' · ');
}

// ---------------------------------------------------------------------------
// AlertRow
// ---------------------------------------------------------------------------

interface AlertRowProps {
  alert: Alert;
  onRead: (id: number) => void;
}

function AlertRow({ alert, onRead }: AlertRowProps) {
  const navigate = useNavigate();
  const severityClass = SEVERITY_CLASSES[alert.severity] ?? SEVERITY_CLASSES['info'];
  const severityLabel = SEVERITY_LABEL[alert.severity] ?? alert.severity;
  const isUnread = !alert.read_at;

  function handleClick() {
    onRead(alert.id);
    if (alert.reference_entity_type && alert.reference_entity_id != null) {
      const entityMap: Record<string, string> = {
        customer: '/customers',
        sale: '/sales',
        payment: '/payments',
      };
      const base = entityMap[alert.reference_entity_type];
      if (base) {
        navigate(`${base}/${alert.reference_entity_id}`);
      }
    }
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      className={[
        'w-full text-left px-4 py-3 flex gap-3 items-start transition-colors hover:bg-neutral-50',
        isUnread ? 'bg-brand-50/40' : '',
      ].join(' ')}
    >
      {/* Unread dot */}
      <span
        className={[
          'mt-1.5 h-2 w-2 shrink-0 rounded-full',
          isUnread ? 'bg-brand-500' : 'bg-transparent',
        ].join(' ')}
      />
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2 mb-0.5">
          <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${severityClass}`}>
            {severityLabel}
          </span>
          <span className="text-xs text-neutral-400 font-mono">
            {alert.alert_type.replace(/_/g, ' ')}
          </span>
        </div>
        <p className="text-sm text-neutral-700 truncate">
          {payloadSummary(alert.payload)}
        </p>
        <p className="text-xs text-neutral-400 mt-0.5">
          {relativeTime(alert.created_at)}
        </p>
      </div>
    </button>
  );
}

// ---------------------------------------------------------------------------
// AlertBell
// ---------------------------------------------------------------------------

export function AlertBell() {
  const [open, setOpen] = useState(false);
  const panelRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const navigate = useNavigate();

  const { data } = useUnreadAlerts();
  const markRead = useMarkAlertRead();

  const alerts: Alert[] = data?.data ?? [];
  const unreadCount = alerts.filter((a) => !a.read_at).length;

  // Close on outside click
  useEffect(() => {
    if (!open) return;
    function handlePointerDown(e: PointerEvent) {
      if (
        panelRef.current &&
        !panelRef.current.contains(e.target as Node) &&
        buttonRef.current &&
        !buttonRef.current.contains(e.target as Node)
      ) {
        setOpen(false);
      }
    }
    document.addEventListener('pointerdown', handlePointerDown);
    return () => document.removeEventListener('pointerdown', handlePointerDown);
  }, [open]);

  // Close on Escape
  useEffect(() => {
    if (!open) return;
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false);
    }
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  }, [open]);

  function handleMarkRead(id: number) {
    markRead.mutate(id);
    setOpen(false);
  }

  return (
    <div className="relative">
      {/* Bell button */}
      <button
        ref={buttonRef}
        type="button"
        aria-label="Alertas"
        aria-haspopup="true"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className="relative flex h-9 w-9 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
      >
        {/* Bell SVG */}
        <svg
          xmlns="http://www.w3.org/2000/svg"
          className="h-5 w-5"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
        >
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
          <path d="M13.73 21a2 2 0 0 1-3.46 0" />
        </svg>
        <BadgeCount count={unreadCount} />
      </button>

      {/* Dropdown panel */}
      {open && (
        <div
          ref={panelRef}
          role="dialog"
          aria-label="Panel de alertas"
          className="absolute right-0 top-full mt-2 w-80 rounded-xl border border-neutral-200 bg-white shadow-lg z-50 overflow-hidden"
        >
          {/* Header */}
          <div className="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
            <span className="text-sm font-semibold text-neutral-800">
              Alertas {unreadCount > 0 && <span className="text-brand-600">({unreadCount})</span>}
            </span>
            <button
              type="button"
              onClick={() => { setOpen(false); navigate('/alerts'); }}
              className="text-xs text-brand-600 hover:underline"
            >
              Ver todas
            </button>
          </div>

          {/* Alert list */}
          <div className="max-h-96 overflow-y-auto divide-y divide-neutral-100">
            {alerts.length === 0 ? (
              <p className="px-4 py-8 text-center text-sm text-neutral-400">
                No tenes alertas pendientes.
              </p>
            ) : (
              alerts.map((alert) => (
                <AlertRow key={alert.id} alert={alert} onRead={handleMarkRead} />
              ))
            )}
          </div>

          {/* Footer */}
          {alerts.length > 0 && (
            <div className="border-t border-neutral-100 px-4 py-2.5 text-right">
              <button
                type="button"
                onClick={() => { setOpen(false); navigate('/alerts'); }}
                className="text-xs text-brand-600 hover:underline"
              >
                Ir a Alertas →
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
