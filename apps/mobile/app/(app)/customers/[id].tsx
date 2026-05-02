import { useState } from 'react';
import { View, Text, StyleSheet, ScrollView, TouchableOpacity, ActivityIndicator } from 'react-native';
import { useLocalSearchParams, useNavigation } from 'expo-router';
import { useQuery } from '@tanstack/react-query';
import { useAuthStore } from '@/auth/auth-store';
import { getApiClient } from '@/api/client';
import type { Customer } from '@/api/types';

type Tab = 'info' | 'sales' | 'payments' | 'actions';

const TABS: { key: Tab; label: string }[] = [
  { key: 'info', label: 'Principal' },
  { key: 'sales', label: 'Ventas' },
  { key: 'payments', label: 'Cobros' },
  { key: 'actions', label: 'Acciones' },
];

export default function CustomerDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { token } = useAuthStore();
  const [activeTab, setActiveTab] = useState<Tab>('info');

  const { data: customer, isLoading, isError } = useQuery<Customer>({
    queryKey: ['customer', id],
    queryFn: async () => {
      const client = getApiClient(token ?? '');
      const res = await client.get<Customer>(`/customers/${id}`);
      return res.data;
    },
    enabled: !!token && !!id,
  });

  if (isLoading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color="#4285f4" />
      </View>
    );
  }

  if (isError || !customer) {
    return (
      <View style={styles.center}>
        <Text style={styles.errorText}>No se pudo cargar el cliente.</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {/* Header */}
      <View style={styles.header}>
        <Text style={styles.headerName}>{customer.name} {customer.last_name}</Text>
        <Text style={styles.headerSub}>CUIT: {customer.cuit}</Text>
      </View>

      {/* Tab bar */}
      <View style={styles.tabBar}>
        {TABS.map((tab) => (
          <TouchableOpacity
            key={tab.key}
            style={[styles.tab, activeTab === tab.key && styles.tabActive]}
            onPress={() => setActiveTab(tab.key)}
          >
            <Text style={[styles.tabText, activeTab === tab.key && styles.tabTextActive]}>
              {tab.label}
            </Text>
          </TouchableOpacity>
        ))}
      </View>

      {/* Tab content */}
      <ScrollView style={styles.tabContent} contentContainerStyle={{ padding: 16 }}>
        {activeTab === 'info' && <CustomerInfoTab customer={customer} />}
        {activeTab === 'sales' && <PlaceholderTab label="Ventas del cliente (Phase 5)" />}
        {activeTab === 'payments' && <PlaceholderTab label="Cobros del cliente (Phase 7)" />}
        {activeTab === 'actions' && <PlaceholderTab label="Acciones programadas (Phase 3)" />}
      </ScrollView>
    </View>
  );
}

function CustomerInfoTab({ customer }: { customer: Customer }) {
  return (
    <View style={{ gap: 12 }}>
      <InfoRow label="Email" value={customer.email} />
      <InfoRow label="Telefono" value={customer.phone} />
      <InfoRow label="Direccion" value={customer.address} />
      <InfoRow label="Categoria" value={customer.category} />
      <InfoRow label="Zona" value={String(customer.zone_id ?? '—')} />
      <InfoRow label="Condicion de pago" value={customer.payment_term ?? '—'} />
      {customer.reference_price_usd != null && (
        <InfoRow label="Precio referencia" value={`USD ${customer.reference_price_usd}`} />
      )}
    </View>
  );
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      <Text style={styles.infoValue}>{value}</Text>
    </View>
  );
}

function PlaceholderTab({ label }: { label: string }) {
  return (
    <View style={styles.placeholder}>
      <Text style={styles.placeholderText}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f8fafc',
  },
  center: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  header: {
    backgroundColor: '#fff',
    padding: 16,
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0',
  },
  headerName: {
    fontSize: 20,
    fontWeight: '700',
    color: '#0f172a',
  },
  headerSub: {
    fontSize: 13,
    color: '#64748b',
    marginTop: 4,
  },
  tabBar: {
    flexDirection: 'row',
    backgroundColor: '#fff',
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0',
  },
  tab: {
    flex: 1,
    paddingVertical: 12,
    alignItems: 'center',
  },
  tabActive: {
    borderBottomWidth: 2,
    borderBottomColor: '#4285f4',
  },
  tabText: {
    fontSize: 13,
    color: '#94a3b8',
    fontWeight: '500',
  },
  tabTextActive: {
    color: '#4285f4',
    fontWeight: '700',
  },
  tabContent: {
    flex: 1,
  },
  infoRow: {
    backgroundColor: '#fff',
    borderRadius: 8,
    padding: 12,
    flexDirection: 'row',
    justifyContent: 'space-between',
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  infoLabel: {
    fontSize: 13,
    color: '#64748b',
    flex: 1,
  },
  infoValue: {
    fontSize: 13,
    fontWeight: '600',
    color: '#0f172a',
    flex: 2,
    textAlign: 'right',
  },
  placeholder: {
    padding: 32,
    alignItems: 'center',
  },
  placeholderText: {
    fontSize: 14,
    color: '#94a3b8',
    textAlign: 'center',
  },
  errorText: {
    color: '#ef4444',
    fontSize: 15,
  },
});
