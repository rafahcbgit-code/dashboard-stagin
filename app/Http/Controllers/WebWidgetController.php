<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebWidgetRequest;
use App\Models\Organization;
use App\Models\Team;
use App\Services\WebWidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WebWidgetController extends Controller
{
    public function __construct(private readonly WebWidgetService $webWidgetService)
    {
    }

    public function index(Request $request): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        $widget = $organization->webWidget;

        $payload = $widget
            ? $this->webWidgetService->toPublicConfig($widget)
            : $this->webWidgetService->defaults();

        return Inertia::render('User/WebWidget/Index', [
            'title' => __('Web Widget'),
            'widget' => $payload,
            'organization_ref' => $organization->identifier,
            'embed_script_url' => url('/widget.js'),
            'embed_config_url' => url('/widget/'.$organization->identifier),
        ]);
    }

    public function store(StoreWebWidgetRequest $request): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        $payload = $request->safe()->except('logo');

        $this->webWidgetService->upsertForOrganization(
            $organization,
            $payload,
            $request->file('logo')
        );

        return back()->with('status', [
            'type' => 'success',
            'message' => __('Web widget settings updated successfully.'),
        ]);
    }

    public function showPublicConfig(string $organization): JsonResponse
    {
        $organizationModel = $this->webWidgetService->resolveOrganization($organization);

        if (! $organizationModel) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        $widget = $organizationModel->webWidget;
        if (! $widget) {
            return response()->json(['message' => 'Widget configuration not found.'], 404);
        }

        return response()->json([
            'organization' => [
                'id' => $organizationModel->id,
                'identifier' => $organizationModel->identifier,
                'name' => $organizationModel->name,
            ],
            'widget' => $this->webWidgetService->toPublicConfig($widget),
        ]);
    }

    public function script(): SymfonyResponse
    {
        return response($this->webWidgetService->script(), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function resolveCurrentOrganization(Request $request): Organization
    {
        $organizationId = (int) $request->session()->get('current_organization');
        abort_if($organizationId <= 0, 403, 'Organization not selected.');

        $user = $request->user();
        abort_if(! $user, 401);

        $team = Team::where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->first();

        abort_if(! $team || $team->status !== 'active', 403, 'Unauthorized organization access.');

        return Organization::query()->findOrFail($organizationId);
    }
}
