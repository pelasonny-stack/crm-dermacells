import React, { lazy, Suspense } from 'react';
import { createBrowserRouter } from 'react-router-dom';
import { RequireAuth } from '@/auth/RequireAuth';
import { AppShell } from '@/layout/AppShell';

const LoginPage = lazy(() => import('@/pages/LoginPage'));
const DashboardPage = lazy(() => import('@/pages/DashboardPage'));
const CustomersPage = lazy(() => import('@/pages/CustomersPage'));
const CustomerDetailPage = lazy(() => import('@/pages/CustomerDetailPage'));
const SalesPage = lazy(() => import('@/pages/SalesPage'));
const SaleDetailPage = lazy(() => import('@/pages/SaleDetailPage'));
const PaymentsPage = lazy(() => import('@/pages/PaymentsPage'));
const AlertsPage = lazy(() => import('@/pages/AlertsPage'));
const ProfilePage = lazy(() => import('@/pages/ProfilePage'));

function PageLoader() {
  return (
    <div className="flex h-64 items-center justify-center">
      <div className="h-6 w-6 animate-spin rounded-full border-4 border-brand-600 border-t-transparent" />
    </div>
  );
}

function withSuspense(node: React.ReactNode) {
  return <Suspense fallback={<PageLoader />}>{node}</Suspense>;
}

export const router = createBrowserRouter([
  {
    path: '/login',
    element: withSuspense(<LoginPage />),
  },
  {
    path: '/',
    element: (
      <RequireAuth>
        <AppShell />
      </RequireAuth>
    ),
    children: [
      {
        index: true,
        element: withSuspense(<DashboardPage />),
      },
      {
        path: 'customers',
        element: withSuspense(<CustomersPage />),
      },
      {
        path: 'customers/:id',
        element: withSuspense(<CustomerDetailPage />),
      },
      {
        path: 'sales',
        element: withSuspense(<SalesPage />),
      },
      {
        path: 'sales/:id',
        element: withSuspense(<SaleDetailPage />),
      },
      {
        path: 'payments',
        element: withSuspense(<PaymentsPage />),
      },
      {
        path: 'alerts',
        element: withSuspense(<AlertsPage />),
      },
      {
        path: 'me',
        element: withSuspense(<ProfilePage />),
      },
    ],
  },
]);
