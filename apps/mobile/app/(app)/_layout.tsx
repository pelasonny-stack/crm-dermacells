import { Tabs } from 'expo-router';
import { Text } from 'react-native';

function TabIcon({ label, focused }: { label: string; focused: boolean }) {
  return (
    <Text style={{ fontSize: 11, color: focused ? '#4285f4' : '#94a3b8', fontWeight: focused ? '600' : '400' }}>
      {label}
    </Text>
  );
}

export default function AppLayout() {
  return (
    <Tabs
      screenOptions={{
        headerShown: true,
        tabBarActiveTintColor: '#4285f4',
        tabBarInactiveTintColor: '#94a3b8',
        tabBarStyle: {
          borderTopColor: '#e2e8f0',
          backgroundColor: '#fff',
        },
      }}
    >
      <Tabs.Screen
        name="index"
        options={{
          title: 'Tablero',
          tabBarLabel: ({ focused }) => <TabIcon label="Tablero" focused={focused} />,
        }}
      />
      <Tabs.Screen
        name="customers"
        options={{
          title: 'Clientes',
          tabBarLabel: ({ focused }) => <TabIcon label="Clientes" focused={focused} />,
        }}
      />
      <Tabs.Screen
        name="sales"
        options={{
          title: 'Ventas',
          tabBarLabel: ({ focused }) => <TabIcon label="Ventas" focused={focused} />,
        }}
      />
      <Tabs.Screen
        name="payments"
        options={{
          title: 'Cobros',
          tabBarLabel: ({ focused }) => <TabIcon label="Cobros" focused={focused} />,
        }}
      />
      <Tabs.Screen
        name="alerts"
        options={{
          title: 'Alertas',
          tabBarLabel: ({ focused }) => <TabIcon label="Alertas" focused={focused} />,
        }}
      />
    </Tabs>
  );
}
