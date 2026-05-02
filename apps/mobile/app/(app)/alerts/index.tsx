import { useCallback, useState } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ActivityIndicator,
  TouchableOpacity,
  Pressable,
} from 'react-native';
import { FlashList } from '@shopify/flash-list';
import { useRouter } from 'expo-router';
import { useNavigation } from 'expo-router';
import { useLayoutEffect } from 'react';
import {
  useAlerts,
  useMarkAlertRead,
  useMarkAllAlertsRead,
  type Alert,
  type AlertSeverity,
} from '@/api/alerts';

// ─── Constants ───────────────────────────────────────────────────────────────

const ALERT_TYPE_LABELS: Record<string, string> = {
  stock_low: 'Stock bajo',
  payment_overdue: 'Cobro vencido',
  customer_inactive: 'Cliente inactivo',
  birthday: 'Cumpleanos',
  cycle_expiring: 'Ciclo por vencer',
  authorization_pending: 'Autorizacion pendiente',
  authorization_resolved: 'Autorizacion resuelta',
  draft_stale: 'Borrador sin actividad',
  zone_at_risk: 'Zona en riesgo',
  action_due: 'Accion programada vencida',
};

const SEVERITY_CONFIG: Record<AlertSeverity, { label: string; color: string; bg: string }> = {
  info: { label: 'Info', color: '#1d4ed8', bg: '#dbeafe' },
  warning: { label: 'Aviso', color: '#92400e', bg: '#fef3c7' },
  critical: { label: 'Critico', color: '#991b1b', bg: '#fee2e2' },
};

// Route helper for reference entities
function buildEntityRoute(type: string | null, id: number | null): string | null {
  if (!type || !id) return null;
  switch (type) {
    case 'customer':
      return `/(app)/customers/${id}`;
    case 'sale':
      return `/(app)/sales/${id}`;
    case 'payment':
      return `/(app)/payments/${id}`;
    default:
      return null;
  }
}

// ─── Relative timestamp ───────────────────────────────────────────────────────

function relativeTime(iso: string): string {
  const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (diff < 60) return 'Hace un momento';
  if (diff < 3600) return `Hace ${Math.floor(diff / 60)} min`;
  if (diff < 86400) return `Hace ${Math.floor(diff / 3600)} h`;
  if (diff < 604800) return `Hace ${Math.floor(diff / 86400)} d`;
  return new Date(iso).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit' });
}

// ─── Payload summary ─────────────────────────────────────────────────────────

function payloadSummary(payload: Record<string, unknown> | null): string | null {
  if (!payload) return null;
  const entries = Object.entries(payload).slice(0, 2);
  return entries.map(([k, v]) => `${k}: ${v}`).join(' · ') || null;
}

// ─── Alert row ────────────────────────────────────────────────────────────────

interface AlertRowProps {
  alert: Alert;
  onPress: (alert: Alert) => void;
}

function AlertRow({ alert, onPress }: AlertRowProps) {
  const isUnread = !alert.read_at;
  const severity = SEVERITY_CONFIG[alert.severity] ?? SEVERITY_CONFIG.info;
  const summary = payloadSummary(alert.payload);

  return (
    <Pressable
      style={({ pressed }) => [styles.row, isUnread && styles.rowUnread, pressed && styles.rowPressed]}
      onPress={() => onPress(alert)}
      accessibilityRole="button"
      accessibilityLabel={`Alerta: ${ALERT_TYPE_LABELS[alert.alert_type] ?? alert.alert_type}`}
    >
      <View style={styles.rowLeft}>
        {isUnread && <View style={styles.unreadDot} />}
      </View>
      <View style={styles.rowContent}>
        <View style={styles.rowHeader}>
          <View style={[styles.severityBadge, { backgroundColor: severity.bg }]}>
            <Text style={[styles.severityText, { color: severity.color }]}>
              {severity.label}
            </Text>
          </View>
          <Text style={styles.rowType}>
            {ALERT_TYPE_LABELS[alert.alert_type] ?? alert.alert_type}
          </Text>
          <Text style={styles.rowDate}>{relativeTime(alert.created_at)}</Text>
        </View>
        <Text style={styles.rowMessage} numberOfLines={3}>{alert.message}</Text>
        {summary && <Text style={styles.rowPayload} numberOfLines={1}>{summary}</Text>}
      </View>
    </Pressable>
  );
}

// ─── Filter bar ───────────────────────────────────────────────────────────────

type SeverityFilter = AlertSeverity | 'all';

interface FilterBarProps {
  severity: SeverityFilter;
  onSeverity: (s: SeverityFilter) => void;
  unreadOnly: boolean;
  onUnreadOnly: (v: boolean) => void;
}

const SEVERITY_FILTERS: { value: SeverityFilter; label: string }[] = [
  { value: 'all', label: 'Todas' },
  { value: 'info', label: 'Info' },
  { value: 'warning', label: 'Aviso' },
  { value: 'critical', label: 'Critico' },
];

function FilterBar({ severity, onSeverity, unreadOnly, onUnreadOnly }: FilterBarProps) {
  return (
    <View style={styles.filterBar}>
      {SEVERITY_FILTERS.map((f) => (
        <TouchableOpacity
          key={f.value}
          style={[styles.filterChip, severity === f.value && styles.filterChipActive]}
          onPress={() => onSeverity(f.value)}
        >
          <Text style={[styles.filterChipText, severity === f.value && styles.filterChipTextActive]}>
            {f.label}
          </Text>
        </TouchableOpacity>
      ))}
      <TouchableOpacity
        style={[styles.filterChip, unreadOnly && styles.filterChipActive]}
        onPress={() => onUnreadOnly(!unreadOnly)}
      >
        <Text style={[styles.filterChipText, unreadOnly && styles.filterChipTextActive]}>
          No leidas
        </Text>
      </TouchableOpacity>
    </View>
  );
}

// ─── Screen ───────────────────────────────────────────────────────────────────

export default function AlertsScreen() {
  const router = useRouter();
  const navigation = useNavigation();

  const [severity, setSeverity] = useState<SeverityFilter>('all');
  const [unreadOnly, setUnreadOnly] = useState(false);

  const { data, isLoading, isError, refetch, isRefetching } = useAlerts({
    severity: severity !== 'all' ? severity : undefined,
    unread_only: unreadOnly,
  });

  const markRead = useMarkAlertRead();
  const markAllRead = useMarkAllAlertsRead();

  // "Mark all read" header button
  useLayoutEffect(() => {
    navigation.setOptions({
      headerRight: () => (
        <TouchableOpacity
          style={styles.headerBtn}
          onPress={() => markAllRead.mutate()}
          disabled={markAllRead.isPending}
        >
          <Text style={styles.headerBtnText}>Marcar todo leido</Text>
        </TouchableOpacity>
      ),
    });
  }, [navigation, markAllRead]);

  const handlePress = useCallback(
    (alert: Alert) => {
      if (!alert.read_at) {
        markRead.mutate(alert.id);
      }
      const route = buildEntityRoute(alert.reference_entity_type, alert.reference_entity_id);
      if (route) {
        // @ts-expect-error dynamic route string — typed routes not yet enforced here
        router.push(route);
      }
    },
    [markRead, router]
  );

  const renderItem = useCallback(
    ({ item }: { item: Alert }) => <AlertRow alert={item} onPress={handlePress} />,
    [handlePress]
  );

  if (isLoading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color="#4285f4" />
      </View>
    );
  }

  if (isError) {
    return (
      <View style={styles.center}>
        <Text style={styles.errorText}>No se pudieron cargar las alertas.</Text>
        <TouchableOpacity style={styles.retryBtn} onPress={() => refetch()}>
          <Text style={styles.retryText}>Reintentar</Text>
        </TouchableOpacity>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <FilterBar
        severity={severity}
        onSeverity={setSeverity}
        unreadOnly={unreadOnly}
        onUnreadOnly={setUnreadOnly}
      />
      <FlashList
        data={data?.data ?? []}
        renderItem={renderItem}
        estimatedItemSize={100}
        keyExtractor={(item) => String(item.id)}
        onRefresh={refetch}
        refreshing={isRefetching}
        ListEmptyComponent={
          <View style={styles.center}>
            <Text style={styles.emptyText}>Sin alertas.</Text>
          </View>
        }
        contentContainerStyle={styles.listContent}
      />
    </View>
  );
}

// ─── Styles ───────────────────────────────────────────────────────────────────

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f8fafc',
  },
  center: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    paddingTop: 64,
  },
  listContent: {
    paddingTop: 8,
    paddingBottom: 24,
  },
  filterBar: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    paddingHorizontal: 16,
    paddingVertical: 10,
    backgroundColor: '#fff',
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0',
  },
  filterChip: {
    paddingHorizontal: 12,
    paddingVertical: 5,
    borderRadius: 20,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    backgroundColor: '#f8fafc',
  },
  filterChipActive: {
    backgroundColor: '#4285f4',
    borderColor: '#4285f4',
  },
  filterChipText: {
    fontSize: 12,
    fontWeight: '500',
    color: '#64748b',
  },
  filterChipTextActive: {
    color: '#fff',
  },
  row: {
    flexDirection: 'row',
    backgroundColor: '#fff',
    marginHorizontal: 16,
    marginBottom: 8,
    borderRadius: 10,
    padding: 14,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    gap: 8,
  },
  rowUnread: {
    borderColor: '#bfdbfe',
    backgroundColor: '#f0f7ff',
  },
  rowPressed: {
    opacity: 0.8,
  },
  rowLeft: {
    width: 10,
    alignItems: 'center',
    paddingTop: 4,
  },
  unreadDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: '#4285f4',
  },
  rowContent: {
    flex: 1,
    gap: 6,
  },
  rowHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    flexWrap: 'wrap',
  },
  severityBadge: {
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  severityText: {
    fontSize: 10,
    fontWeight: '700',
    textTransform: 'uppercase',
  },
  rowType: {
    fontSize: 12,
    fontWeight: '700',
    color: '#1e40af',
    flex: 1,
  },
  rowDate: {
    fontSize: 11,
    color: '#94a3b8',
  },
  rowMessage: {
    fontSize: 14,
    color: '#0f172a',
    lineHeight: 20,
  },
  rowPayload: {
    fontSize: 11,
    color: '#64748b',
    fontStyle: 'italic',
  },
  headerBtn: {
    marginRight: 16,
    paddingVertical: 4,
    paddingHorizontal: 8,
  },
  headerBtnText: {
    fontSize: 13,
    color: '#4285f4',
    fontWeight: '600',
  },
  retryBtn: {
    marginTop: 12,
    paddingHorizontal: 20,
    paddingVertical: 8,
    backgroundColor: '#4285f4',
    borderRadius: 8,
  },
  retryText: {
    color: '#fff',
    fontSize: 14,
    fontWeight: '600',
  },
  errorText: {
    color: '#ef4444',
    fontSize: 15,
  },
  emptyText: {
    color: '#94a3b8',
    fontSize: 15,
  },
});
