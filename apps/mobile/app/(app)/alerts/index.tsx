import { useCallback } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { FlashList } from '@shopify/flash-list';
import { useQuery } from '@tanstack/react-query';
import { useAuthStore } from '@/auth/auth-store';
import { getApiClient } from '@/api/client';

interface Alert {
  id: number;
  type: string;
  message: string;
  read_at: string | null;
  created_at: string;
}

interface AlertsPage {
  data: Alert[];
  meta: { total: number };
}

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

function AlertRow({ alert }: { alert: Alert }) {
  const isUnread = !alert.read_at;
  return (
    <View style={[styles.row, isUnread && styles.rowUnread]}>
      {isUnread && <View style={styles.unreadDot} />}
      <View style={styles.rowContent}>
        <Text style={styles.rowType}>
          {ALERT_TYPE_LABELS[alert.type] ?? alert.type}
        </Text>
        <Text style={styles.rowMessage}>{alert.message}</Text>
        <Text style={styles.rowDate}>{alert.created_at}</Text>
      </View>
    </View>
  );
}

export default function AlertsScreen() {
  const { token } = useAuthStore();

  const { data, isLoading, isError } = useQuery<AlertsPage>({
    queryKey: ['alerts'],
    queryFn: async () => {
      const client = getApiClient(token ?? '');
      const res = await client.get<AlertsPage>('/alerts', {
        params: { per_page: 50 },
      });
      return res.data;
    },
    enabled: !!token,
  });

  const renderItem = useCallback(
    ({ item }: { item: Alert }) => <AlertRow alert={item} />,
    []
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
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {data && (
        <FlashList
          data={data.data}
          renderItem={renderItem}
          estimatedItemSize={80}
          keyExtractor={(item) => String(item.id)}
          ListEmptyComponent={
            <View style={styles.center}>
              <Text style={styles.emptyText}>Sin alertas pendientes.</Text>
            </View>
          }
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f8fafc',
    paddingTop: 8,
  },
  center: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
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
    gap: 10,
  },
  rowUnread: {
    borderColor: '#bfdbfe',
    backgroundColor: '#f0f7ff',
  },
  unreadDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: '#4285f4',
    marginTop: 5,
  },
  rowContent: {
    flex: 1,
    gap: 4,
  },
  rowType: {
    fontSize: 13,
    fontWeight: '700',
    color: '#1e40af',
  },
  rowMessage: {
    fontSize: 14,
    color: '#0f172a',
    lineHeight: 20,
  },
  rowDate: {
    fontSize: 12,
    color: '#94a3b8',
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
