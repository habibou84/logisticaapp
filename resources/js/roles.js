// Espaces de l'application et rôles autorisés (miroir de App\Enums\Role) ; « staff_custom » : rôle sur mesure de
// l'équipe (écran Rôles), voir le getter role du store auth
export const SPACES = {
  admin: ['admin', 'activity_manager', 'dispatcher', 'cashier', 'hub_agent', 'staff_custom'],
  livreur: ['courier'],
  marchand: ['merchant_owner', 'merchant_manager', 'merchant_staff', 'merchant_viewer'],
}

export function homeFor(role) {
  if (SPACES.admin.includes(role)) return '/admin'
  if (SPACES.livreur.includes(role)) return '/livreur'
  if (SPACES.marchand.includes(role)) return '/marchand'
  return '/'
}
