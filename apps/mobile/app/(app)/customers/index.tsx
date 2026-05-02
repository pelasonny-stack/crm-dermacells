import { useState, useCallback } from 'react';
import { View, Text, TextInput, StyleSheet, TouchableOpacity, ActivityIndicator } from 'react-native';
import { FlashList } from '@shopify/flash-list';
import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useAuthStore } from '@/auth/auth-store';
import { getApiClient } from '@/api/client';
import type { Customer } from '@/api/types';

interface CustomersPage {
  data: Customer[];
  meta: { total: number; current_page: number; last_page: number };
}

function CustomerRow({ customer, onPress }: { customer: Customer; onPress: () => void }) {
  return (
    <TouchableOpacity style={styles.row} onPress={onPress}>
      <View style={styles.rowMain}>
        <Text style={styles.rowName}>{customer.name} {customer.last_name}</Text>
        <Text style={styles.rowSub}>CUIT: {customer.cuit}</Text>
      </View>
      <View style={[styles.badge, { backgroundColor: customer.category === 'D' ? '#fef2f2' : '#eff6ff' }]}>
        <Text style={[styles.badgeText, { color: customer.category === 'D' ? '#ef4444' : '#2563eb' }]}>
          {customer.category}
        </Text>
      </View>
    </TouchableOpacity>
  );
}

export default function CustomersScreen() {
  const router = useRouter();
  const { token } = useAuthStore();
  const [search, setSearch] = useState('');

  const { data, isLoading, isError } = useQuery<CustomersPage>({
    queryKey: ['customers', search],
    queryFn: async () => {
      const client = getApiClient(token ?? '');
      const res = await client.get<CustomersPage>('/customers', {
        params: { search, per_page: 50 },
      });
      return res.data;
    },
    enabled: !!token,
  });

  const renderItem = useCallback(
    ({ item }: { item: Customer }) => (
      <CustomerRow
        customer={item}
        onPress={() => router.push(`/(app)/customers/${item.id}`)}
      />
    ),
    [router]
  );

  return (
    <View style={styles.container}>
      <TextInput
        style={styles.searchInput}
        placeholder="Buscar por nombre, CUIT..."
        placeholderTextColor="#94a3b8"
        value={search}
        onChangeText={setSearch}
        returnKeyType="search"
      />

      {isLoading && (
        <View style={styles.center}>
          <ActivityIndicator size="large" color="#4285f4" />
        </View>
      )}

      {isError && (
        <View style={styles.center}>
          <Text style={styles.errorText}>No se pudieron cargar los clientes.</Text>
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
              <Text style={styles.emptyText}>No hay clientes que mostrar.</Text>
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
  searchInput: {
    margin: 16,
    height: 44,
    backgroundColor: '#fff',
    borderRadius: 10,
    paddingHorizontal: 14,
    fontSize: 15,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    color: '#0f172a',
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
  rowName: {
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
