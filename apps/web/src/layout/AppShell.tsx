import { Outlet, NavLink, useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/auth/auth-store';
import type { UserRole } from '@dermacells/api-client';

interface NavItem {
  to: string;
  label: string;
  roles: UserRole[];
}

const NAV_ITEMS: NavItem[] = [
  { to: '/', label: 'Tablero', roles: ['director', 'distributor', 'seller'] },
  { to: '/customers', label: 'Clientes', roles: ['director', 'distributor', 'seller'] },
  { to: '/sales', label: 'Ventas', roles: ['director', 'distributor', 'seller'] },
  { to: '/payments', label: 'Cobros', roles: ['director', 'distributor', 'seller'] },
  { to: '/alerts', label: 'Alertas', roles: ['director', 'distributor', 'seller'] },
];

export function AppShell() {
  const { user, logout } = useAuthStore();
  const navigate = useNavigate();

  const visibleNav = NAV_ITEMS.filter(
    (item) => user && item.roles.includes(user.role),
  );

  async function handleLogout() {
    await logout();
    navigate('/login', { replace: true });
  }

  const roleBadge: Record<UserRole, string> = {
    director: 'Director',
    distributor: 'Distribuidor',
    seller: 'Vendedor',
  };

  return (
    <div className="flex h-screen overflow-hidden bg-neutral-50">
      {/* Sidebar */}
      <aside className="flex w-56 flex-col border-r border-neutral-200 bg-white">
        {/* Logo / Brand */}
        <div className="flex h-16 items-center gap-2 border-b border-neutral-200 px-4">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600">
            <span className="text-sm font-bold text-white">DC</span>
          </div>
          <span className="text-sm font-semibold text-neutral-800">CRM Dermacells</span>
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto p-3">
          <ul className="space-y-1">
            {visibleNav.map((item) => (
              <li key={item.to}>
                <NavLink
                  to={item.to}
                  end={item.to === '/'}
                  className={({ isActive }) =>
                    [
                      'block rounded-md px-3 py-2 text-sm font-medium transition-colors',
                      isActive
                        ? 'bg-brand-50 text-brand-700'
                        : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900',
                    ].join(' ')
                  }
                >
                  {item.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>

        {/* User info + Profile link */}
        <div className="border-t border-neutral-200 p-3">
          <NavLink
            to="/me"
            className="flex w-full items-center gap-2 rounded-md px-2 py-2 text-sm hover:bg-neutral-100"
          >
            <div className="flex h-7 w-7 items-center justify-center rounded-full bg-brand-100 text-brand-700 text-xs font-semibold">
              {user?.name.charAt(0).toUpperCase() ?? '?'}
            </div>
            <div className="min-w-0 flex-1">
              <p className="truncate font-medium text-neutral-800">{user?.name}</p>
              <p className="truncate text-xs text-neutral-500">
                {user ? roleBadge[user.role] : ''}
              </p>
            </div>
          </NavLink>
        </div>
      </aside>

      {/* Main area */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Top bar */}
        <header className="flex h-16 items-center justify-between border-b border-neutral-200 bg-white px-6">
          <h1 className="text-base font-semibold text-neutral-800">
            {/* Page title comes from the route — placeholder */}
          </h1>
          <button
            type="button"
            onClick={() => void handleLogout()}
            className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
          >
            Cerrar sesion
          </button>
        </header>

        {/* Page content */}
        <main className="flex-1 overflow-y-auto p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
