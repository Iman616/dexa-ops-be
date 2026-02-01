<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    /**
     * Display a listing of roles with filters
     */
    public function index(Request $request)
    {
        $query = Role::withCount('users');

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('role_name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'role_id');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        
        if ($request->has('all') && $request->all === 'true') {
            $roles = $query->get();
            return response()->json([
                'success' => true,
                'message' => 'All roles retrieved successfully',
                'data' => $roles
            ], 200);
        }

        $roles = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Roles retrieved successfully',
            'data' => $roles
        ], 200);
    }

    /**
     * ✅ Dropdown untuk User Management (tanpa pagination)
     */
    public function dropdown()
    {
        try {
            $roles = Role::select('role_id', 'role_name', 'description')
                ->orderBy('role_name', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $roles
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created role
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'role_name' => 'required|string|max:100|unique:roles,role_name',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $role = Role::create([
                'role_name' => $request->role_name,
                'description' => $request->description,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'data' => $role
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified role with user count
     */
    public function show($id)
    {
        $role = Role::withCount('users')
            ->with(['users' => function($q) {
                $q->select('user_id', 'username', 'full_name', 'email', 'role_id', 'is_active')
                  ->where('is_active', true)
                  ->latest()
                  ->take(10);
            }])
            ->find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Role retrieved successfully',
            'data' => $role
        ], 200);
    }

    /**
     * Update the specified role
     */
    public function update(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'role_name' => 'sometimes|required|string|max:100|unique:roles,role_name,' . $id . ',role_id',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $role->update($request->only(['role_name', 'description']));

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => $role
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified role
     */
    public function destroy($id)
    {
        $role = Role::withCount('users')->find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found'
            ], 404);
        }

        // Prevent deleting role if it has users
        if ($role->users_count > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete role with active users. Please reassign users first.',
                'users_count' => $role->users_count
            ], 403);
        }

        try {
            $role->delete();

            return response()->json([
                'success' => true,
                'message' => 'Role deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ Get role statistics
     */
    public function statistics()
    {
        try {
            $stats = [
                'total_roles' => Role::count(),
                'roles_with_users' => Role::has('users')->count(),
                'roles_without_users' => Role::doesntHave('users')->count(),
                'role_distribution' => Role::withCount('users')
                    ->get()
                    ->map(function($role) {
                        return [
                            'role_id' => $role->role_id,
                            'role_name' => $role->role_name,
                            'user_count' => $role->users_count
                        ];
                    })
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ Get users by role
     */
    public function users($id, Request $request)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found'
            ], 404);
        }

        $query = $role->users()->with('role:role_id,role_name');

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'full_name');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $users = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully',
            'role' => [
                'role_id' => $role->role_id,
                'role_name' => $role->role_name,
                'description' => $role->description
            ],
            'data' => $users
        ], 200);
    }
}
