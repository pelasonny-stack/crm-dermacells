import { useCallback } from 'react';
import { View, Text, StyleSheet, TouchableOpacity, ActivityIndicator } from 'react-native';
import { FlashList } from '@shopify/flash-list';
import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useAuthStore } from '@/auth/auth-store';
import { getApiClient } from '@/api/client';
import type { Sale } from '@/api/types';

interface SalesPage {
  data: Sale[];
  meta: { total: number; current_page: number; last_page: number };
}

const STATUS_LABELS: Record<string, string> = {
  draft: 'Borrador',
  confirmed: 'Confirmada',
  delivered: 'Entregada',
  cancelled: 'Cancelada',
};

const STATUS_COLORS: Record<string, string> = {
  draft: '#f59e0b',
  confirmed: '#3b82f6',
  delivered: '#22c55e',
  cancelled: '#ef4444',
};

function SaleRow({ sale, onPress }: { sale: Sale; onPress: () => void }) {
  const color = STATUS_COLORS[sale.status] ?? '#94a3b8';
  return (
    <TouchableOpacity style={styles.row} onPress={onPress}>
      <View style={styles.rowMain}>
        <Text style={styles.rowId}>Venta #{sale.id}</Text>
        <Text style={styles.rowSub}>
          {sale.currency} {sale.total?.toLocaleString('es-AR')}
        </Text>
      </View>
      <View style={[styles.badge, { backgroundColor: color + '20' }]}>
        <Text style={[styles.badgeText, { color }]}>
          {STATUS_LABELS[sale.status] ?? sale.status}
        </Text>
      </View>
    </TouchableOpacity>
  );
}

export default function SalesScreen() {
  const router = useRouter();
  const { token } = useAuthStore();

  const { data, isLoading, isError } = useQuery<SalesPage>({
    queryKey: ['sales'],
    queryFn: async () => {
      const client = getApiClient(token ?? '');
      const res = await client.get<SalesPage>('/sales', {
        params: { per_page: 50 },
      });
      return res.data;
    },
    enabled: !!token,
  });

  const renderItem = useCallback(
    ({ item }: { item: Sale }) => (
      <SaleRow sale={item} onPress={() => {}} />
    ),
    []
  );

  return (
    <View style={styles.container}>
      <TouchableOpacity
        style={styles.newButton}
        onPress={() => router.push('/(app)/sales/new')}
      >
        <Text style={styles.newButtonText}>+ Nueva venta</Text>
      </TouchableOpacity>

      {isLoading && (
        <View style={styles.center}>
          <ActivityIndicator size="large" color="#4285f4" />
        </View>
      )}

      {isError && (
        <View style={styles.center}>
          <Text style={styles.errorText}>No se pudieron cargar las ventas.</Text>
        </View>
      )}

      {data && (
        <FlashList
          data={data.data}
          renderItem={renderItem}
          estimatedItemSize={72}
          keyExtractor={(item) => String(item.id)}
          ListEmptyComponent={
            <View style={styles.center}>
              <Text style={styles.emptyText}>No hay ventas que mostrar.</Text>
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
  },
  newButton: {
    margin: 16,
    backgroundColor: '#4285f4',
    borderRadius: 10,
    height: 44,
    justifyContent: 'center',
    alignItems: 'center',
  },
  newButtonText: {
    color: '#fff',
    fontWeight: '600',
    fontSize: 15,
  },
  center: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    paddingTop: 64,
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
  rowMain: {
    flex: 1,
  },
  rowId: {
    fontSize: 15,
    fontWeight: '600',
    color: '#0f172a',
  },
  rowSub: {
    fontSize: 13,
    color: '#64748b',
    marginTop: 2,
  },
  badge: {
    borderRadius: 6,
    paddingHorizontal: 8,
    paddingVertical: 4,
  },
  badgeText: {
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
