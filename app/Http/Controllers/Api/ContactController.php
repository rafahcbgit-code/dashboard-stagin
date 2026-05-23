<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Label;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    /**
     * Get contact labels
     * GET /api/v1/contacts/{contact_uuid}/labels
     */
    public function getLabels(Request $request, $contactUuid)
    {
        try {
            $organizationId = $request->organization_id;

            // Find contact
            $contact = Contact::with('labels')
                ->where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found'
                ], 404);
            }

            // Format labels
            $labels = $contact->labels->map(function($label) {
                return [
                    'id' => $label->id,
                    'title' => $label->title,
                    'color' => $label->color,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'labels' => $labels
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch labels',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign/Update labels to contact
     * PUT /api/v1/contacts/{contact_uuid}/labels
     * 
     * Body: { "labels": [1, 2, 3] }  // Array of label IDs
     */
    public function updateLabels(Request $request, $contactUuid)
    {
        try {
            $organizationId = $request->organization_id;

            // Validation
            $validator = Validator::make($request->all(), [
                'labels' => 'required|array',
                'labels.*' => 'integer|exists:labels,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find contact
            $contact = Contact::where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found'
                ], 404);
            }

            // Verify all labels belong to the organization
            $labelIds = $request->input('labels', []);
            $validLabels = Label::whereIn('id', $labelIds)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->toArray();

            if (count($validLabels) !== count($labelIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more labels do not belong to your organization'
                ], 403);
            }

            // Sync labels (replaces all existing labels)
            $contact->labels()->sync($validLabels);

            // Get updated labels
            $contact->load('labels');
            $labels = $contact->labels->map(function($label) {
                return [
                    'id' => $label->id,
                    'title' => $label->title,
                    'color' => $label->color,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Labels updated successfully',
                'data' => [
                    'labels' => $labels
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update labels',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove specific labels from contact
     * DELETE /api/v1/contacts/{contact_uuid}/labels
     * 
     * Body: { "labels": [1, 2] }  // Array of label IDs to remove
     */
    public function removeLabels(Request $request, $contactUuid)
    {
        try {
            $organizationId = $request->organization_id;

            // Validation
            $validator = Validator::make($request->all(), [
                'labels' => 'required|array',
                'labels.*' => 'integer|exists:labels,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find contact
            $contact = Contact::where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found'
                ], 404);
            }

            // Detach specified labels
            $labelIds = $request->input('labels', []);
            $contact->labels()->detach($labelIds);

            // Get remaining labels
            $contact->load('labels');
            $labels = $contact->labels->map(function($label) {
                return [
                    'id' => $label->id,
                    'title' => $label->title,
                    'color' => $label->color,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Labels removed successfully',
                'data' => [
                    'labels' => $labels
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove labels',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle bot for contact
     * PUT /api/v1/contacts/{contact_uuid}/bot
     * 
     * Body: { "enabled": true }  // or false
     */
    public function toggleBot(Request $request, $contactUuid)
    {
        try {
            $organizationId = $request->organization_id;

            // Validation
            $validator = Validator::make($request->all(), [
                'enabled' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find contact
            $contact = Contact::where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found'
                ], 404);
            }

            // Update bot status
            $contact->bot_enable = $request->input('enabled') ? 1 : 0;
            $contact->updated_at = now();
            $contact->save();

            // Cancel any pending auto-reenable
            \App\Models\ContactBotReenable::where('contact_id', $contact->id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Bot status updated successfully',
                'data' => [
                    'bot_enabled' => (bool) $contact->bot_enable
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update bot status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle block status for contact
     * PUT /api/v1/contacts/{contact_uuid}/block
     *
     * Request body:
     * - enabled: boolean (required)
     */
    public function toggleBlock(Request $request, $contactUuid)
    {
        try {
            $organizationId = $request->organization_id;

            $validator = Validator::make($request->all(), [
                'enabled' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $contact = Contact::where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (! $contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found',
                ], 404);
            }

            $contact->is_blocked = $request->input('enabled') ? 1 : 0;
            $contact->updated_at = now();
            $contact->save();

            return response()->json([
                'success' => true,
                'message' => 'Block status updated successfully',
                'data' => [
                    'is_blocked' => (bool) $contact->is_blocked,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update block status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
