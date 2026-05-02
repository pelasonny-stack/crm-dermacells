import * as Notifications from 'expo-notifications';

/**
 * Configure how notifications are displayed when the app is in the foreground.
 * Call this once at app startup (before any Notifications API usage).
 */
export function configureNotificationHandler(): void {
  Notifications.setNotificationHandler({
    handleNotification: async (notification) => {
      const { categoryIdentifier } = notification.request.content;

      // Critical alerts (stock, auth pending) shown always
      const isCritical = ['stock_low', 'authorization_pending', 'customer_inactive'].includes(
        categoryIdentifier ?? ''
      );

      return {
        shouldShowAlert: true,
        shouldPlaySound: isCritical,
        shouldSetBadge: true,
        priority: isCritical
          ? Notifications.AndroidNotificationPriority.HIGH
          : Notifications.AndroidNotificationPriority.DEFAULT,
      };
    },
  });
}

/**
 * Subscribe to foreground notification tap events.
 * Returns an unsubscribe function to call on cleanup.
 */
export function subscribeToNotificationResponse(
  onResponse: (response: Notifications.NotificationResponse) => void
): () => void {
  const subscription = Notifications.addNotificationResponseReceivedListener(onResponse);
  return () => subscription.remove();
}
