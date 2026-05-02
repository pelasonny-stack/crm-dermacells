import * as SecureStore from 'expo-secure-store';
import * as LocalAuthentication from 'expo-local-authentication';

const TOKEN_KEY = 'dermacells_sanctum_token';

/**
 * Save the Sanctum token in SecureStore.
 * Must be called AFTER biometric verification succeeds.
 */
export async function savePendingToken(token: string): Promise<void> {
  await SecureStore.setItemAsync(TOKEN_KEY, token, {
    requireAuthentication: false, // biometric gate happens before this call
    keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
  });
}

/**
 * Read the stored token, gated behind biometric authentication.
 * Returns null if biometric fails or no token is stored.
 */
export async function getTokenWithBiometricGate(): Promise<string | null> {
  const stored = await SecureStore.getItemAsync(TOKEN_KEY);
  if (!stored) return null;

  const hasBiometrics =
    (await LocalAuthentication.hasHardwareAsync()) &&
    (await LocalAuthentication.isEnrolledAsync());

  if (!hasBiometrics) {
    // Device has no biometrics — return token directly
    return stored;
  }

  const result = await LocalAuthentication.authenticateAsync({
    promptMessage: 'Verificá tu identidad para entrar al CRM',
    fallbackLabel: 'Usar codigo',
    cancelLabel: 'Cancelar',
  });

  return result.success ? stored : null;
}

/**
 * Delete the stored token (logout).
 */
export async function deleteToken(): Promise<void> {
  await SecureStore.deleteItemAsync(TOKEN_KEY);
}
