import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';
import { getApiClient } from '@/api/client';

/**
 * Request push notification permissions, obtain the FCM/APNs token,
 * and register it on the backend via POST /api/v1/devices.
 *
 * NOTE: The /devices endpoint is to be implemented in Phase 14 (Tableros)
 * or as a standalone addition before Phase 15 go-live.
 */
export async function registerDeviceForPushNotifications(
  authToken: string
): Promise<void> {
  // 1. Request permission
  const { status: existingStatus } = await Notifications.getPermissionsAsync();
  let finalStatus = existingStatus;

  if (existingStatus !== 'granted') {
    const { status } = await Notifications.requestPermissionsAsync();
    finalStatus = status;
  }

  if (finalStatus !== 'granted') {
    console.warn('[Notifications] Permission not granted — skipping device registration.');
    return;
  }

  // 2. Get Expo push token (wraps FCM on Android / APNs on iOS)
  const tokenData = await Notifications.getExpoPushTokenAsync({
    projectId: process.env.EXPO_PUBLIC_EAS_PROJECT_ID,
  });

  const pushToken = tokenData.data;

  // 3. POST to backend
  try {
    const client = getApiClient(authToken);
    await client.post('/devices', {
      token: pushToken,
      platform: Platform.OS, // 'ios' | 'android'
      device_name: `${Platform.OS} device`,
    });
    console.log('[Notifications] Device registered successfully.');
  } catch (err) {
    // Non-fatal: app continues without push — retry on next launch
    console.warn('[Notifications] Failed to register device on backend:', err);
  }
}
