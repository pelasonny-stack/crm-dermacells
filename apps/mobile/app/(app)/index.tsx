import { View, Text, StyleSheet, ScrollView, ActivityIndicator } from 'react-native';
import { useQuery } from '@tanstack/react-query';
import { useAuthStore } from '@/auth/auth-store';
import { getApiClient } from '@/api/client';
import type { DashboardResponse } from '@/api/types';

export default function TableroScreen() {
  const { token, user } = useAuthStore();

  const { data, isLoading, isError } = useQuery<DashboardResponse>({
    queryKey: ['dashboard', 'me'],
    queryFn: async () => {
      const client = getApiClient(token ?? '');
      const res = await client.get<DashboardResponse>('/dashboards/me');
      return res.data;
    },
    enabled: !!token,
  });

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
        <Text style={styles.errorText}>No se pudo cargar el tablero.</Text>
      </View>
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.greeting}>
        Hola, {user?.name ?? 'Usuario'}
      </Text>
      <Text style={styles.role}>Rol: {user?.role ?? '—'}</Text>

      {/* Placeholder sections — Phase 14 will fill these with real data */}
      <View style={styles.card}>
        <Text style={styles.cardTitle}>El dia de hoy</Text>
        <Text style={styles.cardPlaceholder}>Alertas y prioridades (Phase 14)</Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Mi mes</Text>
        <Text style={styles.cardPlaceholder}>Comisiones y ventas (Phase 14)</Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Mi operacion</Text>
        <Text style={styles.cardPlaceholder}>Stock y ventas confirmadas (Phase 14)</Text>
      </View>

      {data && (
        <View style={styles.card}>
          <Text style={styles.cardTitle}>Datos del tablero (JSON)</Text>
          <Text style={styles.cardPlaceholder}>{JSON.stringify(data, null, 2)}</Text>
        </View>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f8fafc',
  },
  content: {
    padding: 16,
    gap: 16,
  },
  center: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  greeting: {
    fontSize: 22,
    fontWeight: '700',
    color: '#0f172a',
  },
  role: {
    fontSize: 14,
    color: '#64748b',
    marginBottom: 8,
  },
  card: {
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 16,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  cardTitle: {
    fontSize: 16,
    fontWeight: '600',
    color: '#0f172a',
    marginBottom: 8,
  },
  cardPlaceholder: {
    fontSize: 13,
    color: '#94a3b8',
  },
  errorText: {
    color: '#ef4444',
    fontSize: 16,
  },
});
