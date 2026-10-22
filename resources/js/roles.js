// Espaces de l'application et rôles autorisés (miroir de App\Enums\Role)
export const SPACES = {
  admin: ['admin', 'activity_manager', 'dispatcher', 'cashier', 'hub_agent'],
  livreur: ['courier'],
  marchand: ['merchant_owner', 'merchant_staff'],
}

export function homeFor(role) {
  if (SPACES.admin.includes(role)) return '/admin'
  if (SPACES.livreur.includes(role)) return '/livreur'
  if (SPACES.marchand.includes(role)) return '/marchand'
  return '/'
}
