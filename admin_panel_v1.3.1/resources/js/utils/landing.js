// Custom (Lebazone): one login page for everyone - each account lands on its own screen.
import Auth from '../Auth.js';

export function landingFor(user) {
    if (!user) return '/login';
    if (Number(user.role_id) === 3) return '/delivery_boy';
    const role = (user.role && user.role.name) || window.Role || '';
    const perms = user.allPermissions || [];
    if (role === 'Order Station') return '/station';
    if (role === 'Packer') return '/warehouse?tab=station';
    if (role === 'Product entry') return '/scan';
    if (role === 'Seller') return '/seller'; // Custom (Lebazone)
    if (role === 'Packer + products') return '/app';
    if (perms.includes('manage_dashboard')) return '/dashboard';
    return '/app';
}

/** Store whatever the login API returned (admin/employee shape or driver shape) and say where to go. */
export function finishLogin(data) {
    if (data.user) {
        Auth.login(data.access_token, data.user);
        return landingFor(data.user);
    }
    if (data.access_token) { // driver: the API returns the delivery-boy record itself
        const user = {
            ...data, role_id: 3, delivery_boy_status: data.status ?? 1, role: { name: 'Delivery Boy' },
            allPermissions: data.allPermissions || [], delivery_boy: { id: data.id, status: data.status ?? 1 },
        };
        Auth.login(data.access_token, user);
        return '/delivery_boy';
    }
    return null;
}
