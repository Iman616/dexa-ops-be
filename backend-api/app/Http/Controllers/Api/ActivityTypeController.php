<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Validator;

class ActivityTypeController extends Controller
{
    /**
     * Get all active activity types
     */
public function index(Request $request)
    {
        /** @var \App\Models\User $user */
        $user   = Auth::user();
        $roleId = (int) $user->role_id;

        $types = ActivityType::active()
            ->forRole($roleId)
            ->orderBy('type_code')
            ->orderBy('type_name')
            ->get(['activity_type_id', 'type_name', 'type_code', 'description']);

        // ✅ Grouping untuk frontend (opsional, berguna untuk dropdown dengan section)
        $grouped = $types->groupBy('type_code')->map(fn($items, $code) => [
            'group_label' => self::groupLabel($code),
            'type_code'   => $code,
            'items'       => $items->values(),
        ])->values();

        return response()->json([
            'success'     => true,
            'data'        => $types,          // flat list — untuk select biasa
            'grouped'     => $grouped,         // grouped — untuk select dengan section headers
            'user_role'   => [
                'role_id'      => $roleId,
                'can_see_tender' => ActivityType::isTenderRole($roleId),
                'can_see_retail' => ActivityType::isRetailRole($roleId),
            ],
        ]);
    }


    /**
     * Get single activity type
     */
   public function show($id)
    {
        $user   = Auth::user();
        $roleId = (int) $user->role_id;

        $type = ActivityType::active()
            ->forRole($roleId)
            ->find($id);

        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Activity type not found or not accessible',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $type]);
    }

       private static function groupLabel(string $typeCode): string
    {
        return match ($typeCode) {
            'TENDER'      => '🏛️ Tender & Pengadaan',
            'RETAIL'      => '🏪 Retail / Offline',
            'ONLINE_SHOP' => '🛒 Online Shop',
            default       => $typeCode,
        };
    }

    /**
     * Create new activity type
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type_name' => 'required|string|max:100|unique:activity_types,type_name',
            'type_code' => 'required|string|max:50|unique:activity_types,type_code',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $activityType = ActivityType::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Activity type created successfully',
            'data' => $activityType
        ], 201);
    }

    /**
     * Update activity type
     */
    public function update(Request $request, $id)
    {
        $activityType = ActivityType::find($id);

        if (!$activityType) {
            return response()->json([
                'success' => false,
                'message' => 'Activity type not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'type_name' => 'required|string|max:100|unique:activity_types,type_name,' . $id . ',activity_type_id',
            'type_code' => 'required|string|max:50|unique:activity_types,type_code,' . $id . ',activity_type_id',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $activityType->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Activity type updated successfully',
            'data' => $activityType
        ]);
    }

    /**
     * Delete activity type
     */
    public function destroy($id)
    {
        $activityType = ActivityType::find($id);

        if (!$activityType) {
            return response()->json([
                'success' => false,
                'message' => 'Activity type not found'
            ], 404);
        }

        // Check if used in quotations
        if ($activityType->quotations()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete activity type that is used in quotations'
            ], 422);
        }

        $activityType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Activity type deleted successfully'
        ]);
    }
}
