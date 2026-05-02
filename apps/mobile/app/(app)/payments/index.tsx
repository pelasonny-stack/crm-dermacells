import { useCallback } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { FlashList } from '@shopify/flash-list';
import { useQuery } from '@tanstack/react-query';
import { useAuthStore } from '@/auth/auth-store';
import { getApiClient } from '@/api/client';
import type { Payment } from '@/api/types';

interface PaymentsPage {
  data: Payment[];
  meta: { total: number; current_page: number; last_page: number };
}

function PaymentRow({ payment }: { payment: Payment }) {
  const overdue = payment.due_date && new Date(payment.due_date) < new Date();
  return (
    <View style={[styles.row, overdue && styles.rowOverdue]}>
      <View style={styles.rowMain}>
        <Text style={styles.rowLabel}>
          {payment.currency} {payment.amount?.toLocaleString('es-AR')}
        </Text>
        <Text style={styles.rowSub}>
          {payment.method} — Vto: {payment.due_date ?? '—'}
        </Text>
      </View>
      {overdue && (
        <View style={styles.overdueBadge}>
          <Text style={styles.overdueBadgeText}>Vencido</Text>
        </View>
      )}
    </View>
  );
}

export default function PaymentsScreen() {
  const { token } = useAuthStore();

  const { data, isLoading, isError } = useQuery<PaymentsPage>({
    queryKey: ['payments'],
    queryFn: async () => {
      const client = getApiClient(token ?? '');
      const res = await client.get<PaymentsPage>('/payments', {
        params: { per_page: 50 },
      });
      return res.data;
    },
    enabled: !!token,
  });

  const renderItem = useCallback(
    ({ item }: { item: Payment }) => <PaymentRow payment={item} />,
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
        <Text style={styles.errorText}>No se pudieron cargar los cobros.</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {data && (
        <FlashList
          data={data.data}
          renderItem={renderItem}
          estimatedItemSize={72}
          keyExtractor={(item) => String(item.id)}
          ListEmptyComponent={
            <View style={styles.center}>
              <Text style={styles.emptyText}>No hay cobros pendientes.</Text>
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
    alignItems: 'center',
    backgroundColor: '#fff',
    marginHorizontal: 16,
    marginBottom: 8,
    borderRadius: 10,
    padding: 14,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  rowOverdue: {
    borderColor: '#fca5a5',
    backgroundColor: '#fff7f7',
  },
  rowMain: {
    flex: 1,
  },
  rowLabel: {
    fontSize: 15,
    fontWeight: '600',
    color: '#0f172a',
  },
  rowSub: {
    fontSize: 13,
    color: '#64748b',
    marginTop: 2,
  },
  overdueBadge: {
    backgroundColor: '#fef2f2',
    borderRadius: 6,
    paddingHorizontal: 8,
    paddingVertical: 4,
  },
  overdueBadgeText: {
    color: '#ef4444',
    fontSize: 12,
    fontWeight: '700',
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
