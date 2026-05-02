import { useState } from 'react';
import {
  View,
  Text,
  TextInput,
  TouchableOpacity,
  StyleSheet,
  Alert,
  ScrollView,
  ActivityIndicator,
} from 'react-native';
import { useRouter } from 'expo-router';
import { useMutation } from '@tanstack/react-query';
import { useAuthStore } from '@/auth/auth-store';
import { getApiClient } from '@/api/client';
import type { Sale } from '@/api/types';

type Currency = 'ARS' | 'USD';

interface DraftPayload {
  customer_id: string;
  currency: Currency;
  notes: string;
}

export default function NewSaleScreen() {
  const router = useRouter();
  const { token } = useAuthStore();

  const [customerId, setCustomerId] = useState('');
  const [currency, setCurrency] = useState<Currency>('ARS');
  const [notes, setNotes] = useState('');

  const { mutate, isPending } = useMutation<Sale, Error, DraftPayload>({
    mutationFn: async (payload) => {
      const client = getApiClient(token ?? '');
      const res = await client.post<Sale>('/sales', {
        customer_id: Number(payload.customer_id),
        currency: payload.currency,
        notes: payload.notes,
        status: 'draft',
      });
      return res.data;
    },
    onSuccess: (sale) => {
      Alert.alert('Venta creada', `Borrador #${sale.id} creado exitosamente.`, [
        { text: 'OK', onPress: () => router.back() },
      ]);
    },
    onError: (err) => {
      Alert.alert('Error', err.message || 'No se pudo crear la venta.');
    },
  });

  const handleSubmit = () => {
    if (!customerId.trim()) {
      Alert.alert('Validacion', 'Ingresa el ID del cliente.');
      return;
    }
    mutate({ customer_id: customerId.trim(), currency, notes });
  };

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.sectionTitle}>Nueva venta (borrador)</Text>

      <View style={styles.field}>
        <Text style={styles.label}>ID de cliente</Text>
        <TextInput
          style={styles.input}
          placeholder="Ej: 42"
          placeholderTextColor="#94a3b8"
          keyboardType="numeric"
          value={customerId}
          onChangeText={setCustomerId}
        />
        <Text style={styles.hint}>
          Fase 15 scaffold — busqueda por nombre se agrega en Phase 5
        </Text>
      </View>

      <View style={styles.field}>
        <Text style={styles.label}>Moneda de cierre</Text>
        <View style={styles.currencyRow}>
          {(['ARS', 'USD'] as Currency[]).map((c) => (
            <TouchableOpacity
              key={c}
              style={[styles.currencyButton, currency === c && styles.currencyButtonActive]}
              onPress={() => setCurrency(c)}
            >
              <Text style={[styles.currencyButtonText, currency === c && styles.currencyButtonTextActive]}>
                {c}
              </Text>
            </TouchableOpacity>
          ))}
        </View>
      </View>

      <View style={styles.field}>
        <Text style={styles.label}>Observaciones</Text>
        <TextInput
          style={[styles.input, styles.textArea]}
          placeholder="Notas opcionales..."
          placeholderTextColor="#94a3b8"
          multiline
          numberOfLines={3}
          value={notes}
          onChangeText={setNotes}
        />
      </View>

      <TouchableOpacity
        style={[styles.submitButton, isPending && styles.submitButtonDisabled]}
        onPress={handleSubmit}
        disabled={isPending}
      >
        {isPending ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <Text style={styles.submitButtonText}>Crear borrador</Text>
        )}
      </TouchableOpacity>
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
    gap: 20,
  },
  sectionTitle: {
    fontSize: 18,
    fontWeight: '700',
    color: '#0f172a',
    marginBottom: 4,
  },
  field: {
    gap: 6,
  },
  label: {
    fontSize: 14,
    fontWeight: '600',
    color: '#374151',
  },
  hint: {
    fontSize: 12,
    color: '#94a3b8',
  },
  input: {
    backgroundColor: '#fff',
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    color: '#0f172a',
  },
  textArea: {
    height: 80,
    textAlignVertical: 'top',
  },
  currencyRow: {
    flexDirection: 'row',
    gap: 12,
  },
  currencyButton: {
    flex: 1,
    height: 44,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#fff',
  },
  currencyButtonActive: {
    backgroundColor: '#4285f4',
    borderColor: '#4285f4',
  },
  currencyButtonText: {
    fontSize: 15,
    fontWeight: '600',
    color: '#64748b',
  },
  currencyButtonTextActive: {
    color: '#fff',
  },
  submitButton: {
    height: 52,
    backgroundColor: '#4285f4',
    borderRadius: 12,
    justifyContent: 'center',
    alignItems: 'center',
    marginTop: 8,
  },
  submitButtonDisabled: {
    opacity: 0.6,
  },
  submitButtonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
  },
});
