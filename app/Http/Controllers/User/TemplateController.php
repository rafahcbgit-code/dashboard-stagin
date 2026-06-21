<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\TemplateResource;
use App\Models\Organization;
use App\Models\Template;
use App\Services\TemplateService;
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
        ]);
    }

    public function copy($uuid)
    {
        $organizationId = session()->get('current_organization');
        $template = Template::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        return Inertia::render('User/Templates/Add', [
            'title' => __('Copy') . ' ' . __('Template'),
            'languages' => config('languages'),
            'settings' => Organization::findOrFail($organizationId),
            'copyTemplate' => $template,
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
}
