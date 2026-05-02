import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';
import { getApiClient } from '@/api/client';

const DEVICE_ID_KEY = 'dermacells_device_id';

interface DeviceRegistrationResponse {
  id: number;
  fcm_token: string;
  platform: string;
}

/**
 * Request push permissions, obtain the Expo push token (which wraps FCM on
 * Android and APNs on iOS), POST to /devices, and persist the returned
 * device_id for later unregistration.
 *
 * Safe to call multiple times — returns early if permission is denied.
 */
export async function registerForFcm(authToken: string): Promise<void> {
  const { status: existing } = await Notifications.getPermissionsAsync();
  let finalStatus = existing;

  if (existing !== 'granted') {
    const { status } = await Notifications.requestPermissionsAsync();
    finalStatus = status;
  }

  if (finalStatus !== 'granted') {
    console.warn('[FCM] Notification permission not granted — skipping registration.');
    return;
  }

  let pushToken: string;
  try {
    const tokenData = await Notifications.getExpoPushTokenAsync({
      projectId: process.env.EXPO_PUBLIC_EAS_PROJECT_ID,
    });
    pushToken = tokenData.data;
  } catch (err) {
    console.warn('[FCM] Could not obtain push token:', err);
    return;
  }

  try {
    const client = getApiClient(authToken);
    const deviceName = `${Platform.OS === 'ios' ? 'iOS' : 'Android'} device`;
    const res = await client.post<DeviceRegistrationResponse>('/devices', {
      fcm_token: pushToken,
      platform: Platform.OS,
      device_name: deviceName,
    });

    const deviceId = res.data.id;
    await SecureStore.setItemAsync(DEVICE_ID_KEY, String(deviceId), {
      keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
    });
    console.log('[FCM] Device registered, id:', deviceId);
  } catch (err) {
    // Non-fatal: app continues without push — will retry on next login
    console.warn('[FCM] Failed to register device on backend:', err);
  }
}

/**
 * DELETE /devices/{id} and remove the stored device_id.
 * Called on logout so the backend stops delivering pushes to this device.
 */
export async function unregisterFcm(authToken: string): Promise<void> {
  const deviceId = await SecureStore.getItemAsync(DEVICE_ID_KEY);
  if (!deviceId) return;

  try {
    const client = getApiClient(authToken);
    await client.delete(`/devices/${deviceId}`);
    console.log('[FCM] Device unregistered, id:', deviceId);
  } catch (err) {
    console.warn('[FCM] Failed to unregister device:', err);
  } finally {
    await SecureStore.deleteItemAsync(DEVICE_ID_KEY);
  }
}
