<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class MenuController extends Controller
{
    // GET /api/menus
    public function index(): JsonResponse
    {
        $tree = Menu::with('children')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get()
            ->map(fn($m) => $this->buildTree($m));

        $flat = Menu::orderByRaw('COALESCE(parent_id, menu_id)')
            ->orderBy('sort_order')
            ->get(['menu_id', 'menu_name', 'menu_key', 'icon', 'url', 'sort_order', 'parent_id']);

        return response()->json([
            'success' => true,
            'data' => ['tree' => $tree, 'flat' => $flat],
        ]);
    }

    // GET /api/menus/{id}
    public function show(int $id): JsonResponse
    {
        $menu = Menu::with(['parent', 'children'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $menu]);
    }

    // POST /api/menus
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'menu_name' => 'required|string|max:100',
            'menu_key' => 'required|string|max:50|unique:menus,menu_key',
            'icon' => 'nullable|string|max:50',
            'url' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'parent_id' => 'nullable|exists:menus,menu_id',
        ]);

        if (!isset($validated['sort_order'])) {
            $validated['sort_order'] = $this->nextSortOrder($validated['parent_id'] ?? null);
        }

        $menu = Menu::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Menu berhasil dibuat',
            'data' => $menu,
        ], 201);
    }

    // PUT /api/menus/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $menu = Menu::findOrFail($id);

        $validated = $request->validate([
            'menu_name' => 'sometimes|required|string|max:100',
            'menu_key' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('menus', 'menu_key')->ignore($menu->menu_id, 'menu_id'),
            ],
            'icon' => 'nullable|string|max:50',
            'url' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'parent_id' => [
                'nullable',
                'exists:menus,menu_id',
                Rule::notIn([$menu->menu_id]),
            ],
        ]);

        if (!empty($validated['parent_id']) && $this->isDescendant($menu->menu_id, $validated['parent_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa memindahkan menu ke dalam child-nya sendiri',
            ], 422);
        }

        $menu->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Menu berhasil diupdate',
            'data' => $menu->fresh(['parent', 'children']),
        ]);
    }

    // DELETE /api/menus/{id}
    public function destroy(int $id): JsonResponse
    {
        $menu = Menu::findOrFail($id);

        if ($menu->children()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Hapus child menu terlebih dahulu',
            ], 422);
        }

        DB::table('role_menus')->where('menu_id', $menu->menu_id)->delete();
        $menu->delete();

        return response()->json([
            'success' => true,
            'message' => 'Menu berhasil dihapus',
        ]);
    }

    // PUT /api/menus/reorder
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.menu_id' => 'required|exists:menus,menu_id',
            'items.*.sort_order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($request) {
            foreach ($request->items as $item) {
                Menu::where('menu_id', $item['menu_id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        return response()->json(['success' => true, 'message' => 'Urutan berhasil disimpan']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function buildTree(Menu $menu): array
    {
        return [
            'menu_id' => $menu->menu_id,
            'menu_name' => $menu->menu_name,
            'menu_key' => $menu->menu_key,
            'icon' => $menu->icon,
            'url' => $menu->url,
            'sort_order' => $menu->sort_order,
            'parent_id' => $menu->parent_id,
            'children' => $menu->children->map(fn($c) => $this->buildTree($c))->values(),
        ];
    }

    private function nextSortOrder(?int $parentId): int
    {
        return (int) Menu::where('parent_id', $parentId)->max('sort_order') + 1;
    }

    private function isDescendant(int $menuId, int $targetId): bool
    {
        $children = Menu::where('parent_id', $menuId)->pluck('menu_id');
        if ($children->contains($targetId))
            return true;
        foreach ($children as $childId) {
            if ($this->isDescendant($childId, $targetId))
                return true;
        }
        return false;
    }
}
