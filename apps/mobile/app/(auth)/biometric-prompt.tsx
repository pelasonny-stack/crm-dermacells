import { useEffect, useState } from 'react';
import { View, Text, TouchableOpacity, StyleSheet, Alert, ActivityIndicator } from 'react-native';
import { useRouter } from 'expo-router';
import * as LocalAuthentication from 'expo-local-authentication';
import { useAuthStore } from '@/auth/auth-store';
import { savePendingToken } from '@/auth/secure-storage';

export default function BiometricPromptScreen() {
  const router = useRouter();
  const { pendingToken, setPendingToken, setToken } = useAuthStore();
  const [hasBiometrics, setHasBiometrics] = useState<boolean | null>(null);
  const [isAuthenticating, setIsAuthenticating] = useState(false);

  useEffect(() => {
    (async () => {
      const compatible = await LocalAuthentication.hasHardwareAsync();
      const enrolled = await LocalAuthentication.isEnrolledAsync();
      setHasBiometrics(compatible && enrolled);
    })();
  }, []);

  const handleBiometricAuth = async () => {
    if (!pendingToken) {
      Alert.alert('Error', 'No hay sesión pendiente. Por favor iniciá sesión de nuevo.');
      router.replace('/(auth)/login');
      return;
    }

    setIsAuthenticating(true);
    try {
      const result = await LocalAuthentication.authenticateAsync({
        promptMessage: 'Verificá tu identidad para continuar',
        fallbackLabel: 'Usar código',
        cancelLabel: 'Cancelar',
      });

      if (result.success) {
        // Biometric passed: persist the token securely
        await savePendingToken(pendingToken);
        setToken(pendingToken);
        setPendingToken(null);
        router.replace('/(app)');
      } else {
        Alert.alert('Autenticación fallida', 'Por favor intenta de nuevo.');
      }
    } catch {
      Alert.alert('Error', 'No se pudo verificar tu identidad.');
    } finally {
      setIsAuthenticating(false);
    }
  };

  const handleSkip = async () => {
    // Device has no biometrics — proceed without it
    if (!pendingToken) {
      router.replace('/(auth)/login');
      return;
    }
    await savePendingToken(pendingToken);
    setToken(pendingToken);
    setPendingToken(null);
    router.replace('/(app)');
  };

  if (hasBiometrics === null) {
    return (
      <View style={styles.container}>
        <ActivityIndicator size="large" color="#4285f4" />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <View style={styles.content}>
        <Text style={styles.title}>Activar biométrico</Text>
        <Text style={styles.description}>
          {hasBiometrics
            ? 'Activá Face ID / huella digital para proteger tu sesión en futuras aperturas.'
            : 'Tu dispositivo no tiene biometría configurada. Continuarás con OAuth en cada sesión.'}
        </Text>

        {hasBiometrics ? (
          <TouchableOpacity
            style={styles.button}
            onPress={handleBiometricAuth}
            disabled={isAuthenticating}
          >
            {isAuthenticating ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.buttonText}>Activar y continuar</Text>
            )}
          </TouchableOpacity>
        ) : (
          <TouchableOpacity style={styles.button} onPress={handleSkip}>
            <Text style={styles.buttonText}>Continuar sin biométrico</Text>
          </TouchableOpacity>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f8fafc',
    justifyContent: 'center',
    paddingHorizontal: 32,
  },
  content: {
    alignItems: 'center',
    gap: 24,
  },
  title: {
    fontSize: 24,
    fontWeight: '700',
    color: '#0f172a',
    textAlign: 'center',
  },
  description: {
    fontSize: 15,
    color: '#64748b',
    textAlign: 'center',
    lineHeight: 22,
  },
  button: {
    height: 52,
    backgroundColor: '#4285f4',
    borderRadius: 12,
    justifyContent: 'center',
    alignItems: 'center',
    paddingHorizontal: 40,
  },
  buttonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
  },
});
