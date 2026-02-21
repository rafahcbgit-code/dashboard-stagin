<?php

namespace Modules\MetaFlows\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Modules\MetaFlows\Models\MetaFlow;
use Modules\MetaFlows\Requests\StoreMetaFlowRequest;
use Modules\MetaFlows\Services\MetaFlowService;

class MetaFlowController extends BaseController
{
    // Build a service instance using the current session's organization credentials
    private function service(): MetaFlowService
    {
        $organizationId = session()->get('current_organization');

        return MetaFlowService::fromOrganization($organizationId);
    }

    // List all Meta Flows for the current organization
    public function index(Request $request)
    {
        if (!CustomHelper::isModuleEnabled('Meta Flows')) {
            abort(404);
        }

        $data['title']   = __('Meta Flows');
        $data['rows']    = $this->service()->getRows($request);
        $data['filters'] = $request->all();

        return Inertia::render('MetaFlows::User/Index', $data);
    }

    // Show the editor for a single Meta Flow
    public function view(Request $request, string $uuid)
    {
        if (!CustomHelper::isModuleEnabled('Meta Flows')) {
            abort(404);
        }

        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        $data['flow'] = $flow;

        return Inertia::render('MetaFlows::User/View', $data);
    }

    // Create a new Meta Flow on Meta's platform and redirect to the editor
    public function store(StoreMetaFlowRequest $request)
    {
        $flow = $this->service()->createFlow($request->validated());

        return redirect('/automation/meta-flows/' . $flow->uuid)->with(
            'status', [
                'type'    => 'success',
                'message' => __('Meta Flow created successfully!'),
            ]
        );
    }

    // Save updated Flow JSON to Meta and to the local database
    public function update(Request $request, string $uuid)
    {
        $request->validate([
            'asset_content' => 'required|string',
        ]);

        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        $result = $this->service()->updateFlowAsset($flow, $request->asset_content);

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 422);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('Flow JSON saved successfully.')]);
        }

        return Redirect::back()->with(
            'status', [
                'type'    => 'success',
                'message' => __('Flow JSON saved successfully!'),
            ]
        );
    }

    // Publish the flow on Meta so it can be sent to users
    public function publish(string $uuid)
    {
        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        try {
            $flow = $this->service()->publishFlow($flow);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Flow published successfully!'),
            'status'  => $flow->status,
        ]);
    }

    // Deprecate the flow so it can no longer be sent to new users
    public function deprecate(string $uuid)
    {
        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        try {
            $flow = $this->service()->deprecateFlow($flow);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Flow deprecated successfully.'),
            'status'  => $flow->status,
        ]);
    }

    // Pull the latest state from Meta and refresh the local record
    public function sync(string $uuid)
    {
        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        $flow = $this->service()->syncFlowFromMeta($flow);

        return response()->json([
            'message' => __('Flow synced from Meta.'),
            'flow'    => $flow,
        ]);
    }

    // Return a flat list of published flows for this organization — used by the chat flow picker
    public function list()
    {
        if (!CustomHelper::isModuleEnabled('Meta Flows')) {
            abort(404);
        }

        $orgId = session()->get('current_organization');

        $flows = MetaFlow::where('organization_id', $orgId)
            ->where('status', 'published')
            ->whereNotNull('meta_flow_id')
            ->orderBy('name')
            ->get(['uuid', 'name', 'categories', 'status']);

        return response()->json($flows);
    }

    // Pull all flows from Meta and import/update local records
    public function pull()
    {
        if (!CustomHelper::isModuleEnabled('Meta Flows')) {
            abort(404);
        }

        try {
            $result = $this->service()->pullFlowsFromMeta();

            return response()->json([
                'message'  => __("Pulled :imported new flow(s), updated :updated existing.", $result),
                'imported' => $result['imported'],
                'updated'  => $result['updated'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // Delete the flow from Meta and soft-delete the local record
    public function destroy(string $uuid)
    {
        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        try {
            $this->service()->deleteFlow($flow);
        } catch (\Throwable $e) {
            // Meta rejected the deletion — keep the local record intact and tell the user why
            return Redirect::back()->with(
                'status', [
                    'type'    => 'error',
                    'message' => $e->getMessage(),
                ]
            );
        }

        return Redirect::back()->with(
            'status', [
                'type'    => 'success',
                'message' => __('Meta Flow deleted successfully!'),
            ]
        );
    }
}
