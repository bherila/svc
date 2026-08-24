# Client Management — Setup

> **Legacy reference.** This describes the pre-extraction implementation in [`bherila/2025-website`](https://github.com/bherila/2025-website) (removed there in PR #2123). Preserved here for domain continuity while SVC finishes cutover (issue [#14](https://github.com/bherila/svc/issues/14)); file paths, artisan commands, and route prefixes below refer to that legacy app, not SVC's own code. See [`../domain-contract.md`](../domain-contract.md) for SVC's current schema.

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
