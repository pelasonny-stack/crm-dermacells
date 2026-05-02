import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/auth/auth-store';

const roleLabel: Record<string, string> = {
  director: 'Director',
  distributor: 'Distribuidor',
  seller: 'Vendedor',
};

export default function ProfilePage() {
  const { user, logout } = useAuthStore();
  const navigate = useNavigate();

  async function handleLogout() {
    await logout();
    navigate('/login', { replace: true });
  }

  if (!user) return null;

  return (
    <div className="mx-auto max-w-md space-y-6">
      <h2 className="text-xl font-bold text-neutral-900">Mi perfil</h2>

      <div className="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm">
        {/* Avatar */}
        <div className="mb-5 flex items-center gap-4">
          <div className="flex h-14 w-14 items-center justify-center rounded-full bg-brand-100 text-2xl font-bold text-brand-700">
            {user.name.charAt(0).toUpperCase()}
          </div>
          <div>
            <p className="text-lg font-semibold text-neutral-900">{user.name}</p>
            <p className="text-sm text-neutral-500">{user.email}</p>
          </div>
        </div>

        <dl className="space-y-3">
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wider text-neutral-400">
              Rol
            </dt>
            <dd className="mt-0.5 text-sm font-medium text-neutral-800">
              {roleLabel[user.role] ?? user.role}
            </dd>
          </div>
          {user.role === 'director' && (
            <div>
              <dt className="text-xs font-semibold uppercase tracking-wider text-neutral-400">
                Flag puede vender
              </dt>
              <dd className="mt-0.5 text-sm font-medium text-neutral-800">
                {user.can_sell ? 'Activo' : 'Inactivo'}
              </dd>
            </div>
          )}
        </dl>
      </div>

      <button
        type="button"
        onClick={() => void handleLogout()}
        className="w-full rounded-lg border border-danger-500/50 bg-white px-4 py-2.5 text-sm font-medium text-danger-600 hover:bg-danger-500/5"
      >
        Cerrar sesion
      </button>
    </div>
  );
}
