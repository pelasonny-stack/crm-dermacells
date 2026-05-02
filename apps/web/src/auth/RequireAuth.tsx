import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuthStore } from './auth-store';

interface Props {
  children: React.ReactNode;
}

/**
 * Auth gate for protected routes.
 * While bootstrapping (isLoading) renders nothing to avoid a flash.
 * Once resolved: renders children if authenticated, otherwise redirects to /login
 * with the current path in `state.from` so LoginPage can redirect back.
 */
export function RequireAuth({ children }: Props) {
  const { isAuthenticated, isLoading } = useAuthStore();
  const location = useLocation();

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center bg-neutral-50">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-brand-600 border-t-transparent" />
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  return <>{children}</>;
}
