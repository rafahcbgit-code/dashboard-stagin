<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Jobs\Webhook\ProcessWebhookJob;
use App\Models\WebhookFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WebhookFailureController - Admin controller for viewing and retrying failed webhooks.
 *
 * AC-1.5.4: Admin can view failed webhooks at /admin/webhook-failures
 * AC-1.5.5: Admin can manually retry failed webhooks via POST /admin/webhook-failures/{id}/retry
 *
 * @see docs/sprint-artifacts/1-5-implement-webhook-retry-and-failure-handling.md
 */
class WebhookFailureController extends BaseController
{
    /**
     * Display a paginated list of webhook failures.
     *
     * AC-1.5.4: Admin can view failed webhooks at /admin/webhook-failures
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $organizationId = $request->input('organization_id');
        $jobType = $request->input('job_type');

        $query = WebhookFailure::query()
            ->with('organization:id,name')
            ->orderBy('created_at', 'desc');

        // Optional filters
        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($jobType) {
            $query->where('job_type', $jobType);
        }

        $failures = $query->paginate($perPage);

        return response()->json([
            'data' => $failures->items(),
            'meta' => [
                'current_page' => $failures->currentPage(),
                'last_page' => $failures->lastPage(),
                'per_page' => $failures->perPage(),
                'total' => $failures->total(),
            ],
        ]);
    }

    /**
     * Retry a failed webhook by re-dispatching the job.
     *
     * AC-1.5.5: Admin can manually retry failed webhooks via POST /admin/webhook-failures/{id}/retry
     *
     * @param  int  $id The WebhookFailure ID to retry
     */
    public function retry(int $id): JsonResponse
    {
        $failure = WebhookFailure::find($id);

        if (! $failure) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook failure record not found',
            ], 404);
        }

        // Parse the stored payload
        $payload = is_string($failure->payload) ? json_decode($failure->payload, true) : $failure->payload;

        if (empty($payload)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot retry: payload is empty or invalid',
            ], 422);
        }

        // Determine webhook type from payload or use default
        $webhookType = $payload['field'] ?? 'messages';

        // Dispatch a new ProcessWebhookJob with the stored payload
        $job = new ProcessWebhookJob(
            $payload,
            $failure->organization_id,
            $webhookType
        );

        dispatch($job);

        // Update the failure record to track retry attempt
        $failure->update([
            'attempts' => $failure->attempts + 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Webhook re-queued for processing',
            'webhook_failure_id' => $failure->id,
            'organization_id' => $failure->organization_id,
        ]);
    }

    /**
     * Show a single webhook failure record.
     *
     * @param  int  $id The WebhookFailure ID
     */
    public function show(int $id): JsonResponse
    {
        $failure = WebhookFailure::with('organization:id,name')->find($id);

        if (! $failure) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook failure record not found',
            ], 404);
        }

        return response()->json([
            'data' => $failure,
        ]);
    }

    /**
     * Delete a webhook failure record.
     *
     * @param  int  $id The WebhookFailure ID
     */
    public function destroy(int $id): JsonResponse
    {
        $failure = WebhookFailure::find($id);

        if (! $failure) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook failure record not found',
            ], 404);
        }

        $failure->delete();

        return response()->json([
            'success' => true,
            'message' => 'Webhook failure record deleted',
        ]);
    }
}
