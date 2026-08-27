# Client Management — Setup

## Initial Setup

### 1. Build Assets
```bash
pnpm run build
# or for development
composer run dev
```

### 2. Set First User as Admin
```php
php artisan tinker
$user = User::find(1);
$user->user_role = 'Admin';
$user->save();
```

### 3. Test the Feature
1. Log in as admin user (ID 1 or `user_role='Admin'`)
2. Navigate to Tools → Client Management in the navbar
3. Click "New Company" to create a company
4. Fill in company details on the details page
5. Use "Invite People" to assign users

## File Locations

**Backend:**
- `app/Models/ClientManagement/`
- `app/Http/Controllers/ClientManagement/`
- `app/Providers/AppServiceProvider.php` (Admin Gate)

**Frontend:**
- `resources/views/client-management/`
- `resources/js/client-management/admin.tsx`
- `resources/js/client-management/components/`

**Routes:**
- `routes/web.php` — Client Management section
- `routes/api.php` — Client Management API section
