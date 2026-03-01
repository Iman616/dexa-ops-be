<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Menu;
use App\Models\Role;
use App\Models\RolePermission as RoleMenu;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RoleMenuController extends Controller
{
    // GET /api/roles/{roleId}/menus
    // Semua menu tree + status permission untuk role ini
    public function getByRole(int $roleId): JsonResponse
    {
        $role = Role::findOrFail($roleId);

        // Ambil semua permission role ini, index by menu_id
        $perms = RoleMenu::where('role_id', $roleId)
            ->get()
            ->keyBy('menu_id');

        // Build tree dengan permission di tiap node
        $menus = Menu::with('children')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        $tree = $menus->map(fn($m) => $this->buildTreeWithPerms($m, $perms));

        return response()->json([
            'success' => true,
            'data'    => [
                'role'        => $role,
                'permissions' => $tree,
            ],
        ]);
    }

    // GET /api/permissions/my
    // Untuk canAccessMenu() di frontend — dipanggil saat login/refresh
    public function myPermissions(Request $request): JsonResponse
    {
        $user   = $request->user();
        $roleId = $user->role_id;

        $role = Role::find($roleId);

        // Super Admin — akses semua menu
        if ($role && strtolower($role->role_name) === 'super admin') {
            return response()->json([
                'success' => true,
                'data'    => [
                    'is_superadmin' => true,
                    'menu_keys'     => Menu::pluck('menu_key'),
                    'permissions'   => [],
                ],
            ]);
        }

        // Role biasa — hanya menu yang can_read = 1
        $perms = DB::table('role_menus as rm')
            ->join('menus as m', 'm.menu_id', '=', 'rm.menu_id')
            ->where('rm.role_id', $roleId)
            ->where('rm.can_read', 1)
            ->select(
                'm.menu_key',
                'rm.can_create',
                'rm.can_read',
                'rm.can_update',
                'rm.can_delete'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'is_superadmin' => false,
                'menu_keys'     => $perms->pluck('menu_key'),
                'permissions'   => $perms->keyBy('menu_key'),
            ],
        ]);
    }

    // POST /api/roles/{roleId}/menus
    // Bulk upsert semua permission untuk 1 role sekaligus
    // Body: { permissions: [{ menu_id: 1, can_create: 1, can_read: 1, can_update: 0, can_delete: 0 }] }
    public function saveByRole(Request $request, int $roleId): JsonResponse
    {
        Role::findOrFail($roleId);

        $request->validate([
            'permissions'               => 'required|array',
            'permissions.*.menu_id'     => 'required|exists:menus,menu_id',
            'permissions.*.can_create'  => 'boolean',
            'permissions.*.can_read'    => 'boolean',
            'permissions.*.can_update'  => 'boolean',
            'permissions.*.can_delete'  => 'boolean',
        ]);

        DB::transaction(function () use ($request, $roleId) {
            foreach ($request->permissions as $perm) {
                RoleMenu::updateOrCreate(
                    [
                        'role_id' => $roleId,
                        'menu_id' => $perm['menu_id'],
                    ],
                    [
                        'can_create' => $perm['can_create'] ?? 0,
                        'can_read'   => $perm['can_read']   ?? 1,
                        'can_update' => $perm['can_update'] ?? 0,
                        'can_delete' => $perm['can_delete'] ?? 0,
                    ]
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Permission role ID {$roleId} berhasil disimpan",
            'data'    => RoleMenu::with('menu')->where('role_id', $roleId)->get(),
        ]);
    }

    // DELETE /api/roles/{roleId}/menus/{menuId}
    // Cabut 1 permission dari role
    public function revokeOne(int $roleId, int $menuId): JsonResponse
    {
        Role::findOrFail($roleId);

        $deleted = RoleMenu::where('role_id', $roleId)
            ->where('menu_id', $menuId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Permission tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Permission berhasil dicabut',
        ]);
    }

    // DELETE /api/roles/{roleId}/menus
    // Reset semua permission role (hapus semua, isi ulang)
    public function resetByRole(int $roleId): JsonResponse
    {
        Role::findOrFail($roleId);

        RoleMenu::where('role_id', $roleId)->delete();

        return response()->json([
            'success' => true,
            'message' => "Semua permission role ID {$roleId} berhasil direset",
        ]);
    }

    // GET /api/roles
    // List semua role + jumlah menu yang diakses
    public function listRoles(): JsonResponse
    {
        $roles = Role::withCount('roleMenus')->get([
            'role_id', 'role_name', 'description', 'created_at',
        ]);

        return response()->json(['success' => true, 'data' => $roles]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function buildTreeWithPerms(Menu $menu, $perms): array
    {
        $perm = $perms->get($menu->menu_id);

        return [
            'menu_id'    => $menu->menu_id,
            'menu_name'  => $menu->menu_name,
            'menu_key'   => $menu->menu_key,
            'icon'       => $menu->icon,
            'url'        => $menu->url,
            'sort_order' => $menu->sort_order,
            'parent_id'  => $menu->parent_id,
            'can_create' => $perm ? (bool) $perm->can_create : false,
            'can_read'   => $perm ? (bool) $perm->can_read   : false,
            'can_update' => $perm ? (bool) $perm->can_update : false,
            'can_delete' => $perm ? (bool) $perm->can_delete : false,
            'has_access' => $perm !== null,
            'children'   => $menu->children
                ->map(fn($c) => $this->buildTreeWithPerms($c, $perms))
                ->values(),
        ];
    }
}
