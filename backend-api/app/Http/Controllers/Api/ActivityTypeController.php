<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ActivityTypeController extends Controller
{
    /**
     * Get all active activity types
     */
    public function index(Request $request)
    {
        $query = ActivityType::query();

        // Filter active only by default
        if ($request->get('include_inactive') !== 'true') {
            $query->active();
        }

        // Search
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function($q) use ($search) {
                $q->where('type_name', 'like', "%{$search}%")
                  ->orWhere('type_code', 'like', "%{$search}%");
            });
        }

        $activityTypes = $query->orderBy('type_name', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $activityTypes
        ]);
    }

    /**
     * Get single activity type
     */
    public function show($id)
    {
        $activityType = ActivityType::find($id);

        if (!$activityType) {
            return response()->json([
                'success' => false,
                'message' => 'Activity type not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $activityType
        ]);
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
