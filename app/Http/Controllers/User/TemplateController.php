<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\TemplateResource;
use App\Models\Organization;
use App\Models\Template;
use App\Services\TemplateService;
use App\Jobs\SyncWhatsappTemplatesJob;
use App\Services\Templates\TemplateAccessService;
use App\Services\Templates\TemplateConnectionService;
use App\Support\WhatsappTemplateSyncStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Validator;

class TemplateController extends BaseController
{
    private function templateService()
    {
        return new TemplateService(session()->get('current_organization'));
    }

    public function index(Request $request, $uuid = null)
    {
        return $this->templateService()->getTemplates($request, $uuid, $request->query('search'));
    }

    public function create(Request $request)
    {
        return $this->templateService()->createTemplate($request);
    }

    public function show($uuid)
    {
        $template = Template::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->whereNull('deleted_at')
            ->firstOrFail();

        return Inertia::render('User/Templates/Show', [
            'title' => __('View') . ' ' . __('Template'),
            'template' => $template,
            'history' => $template->statusEvents()->limit(30)->get(),
            'capabilities' => app(TemplateAccessService::class)->capabilities(auth()->user(), (int) session()->get('current_organization')),
        ]);
    }

    public function copy($uuid)
    {
        $organizationId = session()->get('current_organization');
        app(TemplateAccessService::class)->authorize('copy', auth()->user(), (int) $organizationId);
        $template = Template::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $organization = Organization::findOrFail($organizationId);
        app(TemplateConnectionService::class)->assertConnected($organization);

        return Inertia::render('User/Templates/Add', [
            'title' => __('Copy') . ' ' . __('Template'),
            'languages' => config('languages'),
            'settings' => $organization,
            'copyTemplate' => $template,
            'connection' => app(TemplateConnectionService::class)->forOrganization($organization),
            'rules' => app(\App\Services\Templates\TemplatePolicyService::class)->rulesFor($organization),
            'capabilities' => app(TemplateAccessService::class)->capabilities(auth()->user(), (int) $organizationId),
        ]);
    }

    public function update(Request $request, $uuid)
    {
        return $this->templateService()->updateTemplate($request, $uuid);
    }

    public function delete($uuid)
    {
        return $this->templateService()->deleteTemplate($uuid);
    }

    public function sync()
    {
        $organizationId = (int) session()->get('current_organization');
        app(TemplateAccessService::class)->authorize('sync', auth()->user(), $organizationId);
        $organization = Organization::findOrFail($organizationId);
        app(TemplateConnectionService::class)->assertConnected($organization);
        $metadata = json_decode($organization->metadata ?: '{}', true) ?: [];
        $wabaId = (string) data_get($metadata, 'whatsapp.waba_id');

        WhatsappTemplateSyncStatus::update($organizationId, $wabaId, 'queued');
        SyncWhatsappTemplatesJob::dispatch($organizationId, $wabaId)->onQueue('whatsapp-template-sync');

        return response()->json(['status' => 'queued'], 202);
    }

    public function syncStatus()
    {
        $organizationId = (int) session()->get('current_organization');
        $organization = Organization::findOrFail($organizationId);
        $metadata = json_decode($organization->metadata ?: '{}', true) ?: [];
        $wabaId = (string) data_get($metadata, 'whatsapp.waba_id', '');

        return response()->json($wabaId === ''
            ? ['status' => 'idle']
            : WhatsappTemplateSyncStatus::get($organizationId, $wabaId));
    }
}
