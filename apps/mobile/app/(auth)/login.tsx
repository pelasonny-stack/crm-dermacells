import { useCallback } from 'react';
import { View, Text, TouchableOpacity, StyleSheet, Alert, ActivityIndicator } from 'react-native';
import { useRouter } from 'expo-router';
import { useOAuth } from '@/auth/oauth';
import { useAuthStore } from '@/auth/auth-store';

export default function LoginScreen() {
  const router = useRouter();
  const { signInWithGoogle, signInWithMicrosoft, isLoading } = useOAuth();
  const { token } = useAuthStore();

  const handleGoogleSignIn = useCallback(async () => {
    try {
      const result = await signInWithGoogle();
      if (result.success) {
        router.replace('/(auth)/biometric-prompt');
      }
    } catch (err) {
      Alert.alert('Error', 'No se pudo iniciar sesión con Google. Intenta nuevamente.');
    }
  }, [signInWithGoogle, router]);

  const handleMicrosoftSignIn = useCallback(async () => {
    try {
      const result = await signInWithMicrosoft();
      if (result.success) {
        router.replace('/(auth)/biometric-prompt');
      }
    } catch (err) {
      Alert.alert('Error', 'No se pudo iniciar sesión con Microsoft. Intenta nuevamente.');
    }
  }, [signInWithMicrosoft, router]);

  if (token) {
    return null; // AuthGate will redirect
  }

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.title}>CRM Dermacells</Text>
        <Text style={styles.subtitle}>Iniciá sesión para continuar</Text>
      </View>

      <View style={styles.buttons}>
        <TouchableOpacity
          style={[styles.button, styles.googleButton]}
          onPress={handleGoogleSignIn}
          disabled={isLoading}
        >
          {isLoading ? (
            <ActivityIndicator color="#fff" />
          ) : (
            <Text style={styles.buttonText}>Continuar con Google</Text>
          )}
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.button, styles.microsoftButton]}
          onPress={handleMicrosoftSignIn}
          disabled={isLoading}
        >
          {isLoading ? (
            <ActivityIndicator color="#fff" />
          ) : (
            <Text style={styles.buttonText}>Continuar con Microsoft</Text>
          )}
        </TouchableOpacity>
      </View>

      <Text style={styles.note}>
        Solo cuentas pre-registradas por el Director tienen acceso.
      </Text>
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
  header: {
    marginBottom: 48,
    alignItems: 'center',
  },
  title: {
    fontSize: 28,
    fontWeight: '700',
    color: '#0f172a',
    marginBottom: 8,
  },
  subtitle: {
    fontSize: 16,
    color: '#64748b',
  },
  buttons: {
    gap: 16,
  },
  button: {
    height: 52,
    borderRadius: 12,
    justifyContent: 'center',
    alignItems: 'center',
  },
  googleButton: {
    backgroundColor: '#4285f4',
  },
  microsoftButton: {
    backgroundColor: '#2563eb',
  },
  buttonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
  },
  note: {
    marginTop: 32,
    textAlign: 'center',
    fontSize: 13,
    color: '#94a3b8',
  },
});
