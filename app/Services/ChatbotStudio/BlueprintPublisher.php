<?php

namespace App\Services\ChatbotStudio;

use App\Models\ChatbotStudioDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BlueprintPublisher
{
    public function __construct(private BlueprintCompiler $compiler) {}

    public function publish(string $id, int $adminId, int $version): array
    {
        return DB::transaction(function () use ($id, $adminId, $version) {
            $draft = ChatbotStudioDraft::where('admin_id', $adminId)->lockForUpdate()->findOrFail($id);
            if ($draft->status === 'published') {
                return $draft->result;
            }
            abort_if($draft->status !== 'draft' || !$draft->blueprint, 409, 'Finish generation and review the draft before publishing.');
            abort_if($draft->version !== $version, 409, 'The draft has changed. Reload before publishing.');
            if ($draft->blueprint['issues']) {
                throw ValidationException::withMessages(['blueprint' => $draft->blueprint['issues']]);
            }
            $org = DB::table('organizations')->where('id', $draft->organization_id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($org, 404, 'Organization is unavailable.');
            $compiled = $this->compiler->compile($draft->blueprint);
            $keywords = array_map(fn ($t) => mb_strtolower(trim($t['name'])), $compiled['triggers']);
            $existing = DB::table('trigger_groups')->where('organization_id', $org->id)->whereNull('deleted_at')
                ->where('is_enabled', 1)->where('type', 'new_message')->where('match_mode', 'exact')->get();
            $conflicts = [];
            foreach ($existing as $trigger) {
                foreach (json_decode($trigger->keywords ?? '[]', true) ?: [] as $keyword) {
                    if (in_array(mb_strtolower(trim($keyword)), $keywords, true)) {
                        $conflicts[] = $keyword;
                    }
                }
            }
            if ($conflicts) {
                throw ValidationException::withMessages(['triggers' => ['Existing enabled triggers conflict: '.implode(', ', array_unique($conflicts))]]);
            }
            $flowIds = [];
            $uuidIds = [];
            $bundle = 'studio-'.$draft->id;
            foreach ($compiled['flows'] as $key => $flow) {
                $flowIds[$key] = DB::table('flows')->insertGetId([
                    'uuid' => $flow['uuid'], 'organization_id' => $org->id, 'name' => $flow['name'],
                    'description' => 'Chatbot Studio: '.$draft->id, 'metadata' => '{}',
                    'status' => 'active', 'is_builtin' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $uuidIds[$flow['uuid']] = $flowIds[$key];
            }
            foreach ($compiled['flows'] as $key => $flow) {
                $metadata = $flow['metadata'];
                foreach ($metadata['nodes'] as &$node) {
                    if ($node['type'] === 'portal') {
                        $fields = &$node['data']['metadata']['fields'];
                        $fields['target_flow_id'] = $uuidIds[$fields['target_flow_uuid']];
                        unset($fields);
                    }
                }
                unset($node);
                $metadata['import_bundle_id'] = $bundle;
                DB::table('flows')->where('id', $flowIds[$key])->where('organization_id', $org->id)->update([
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);
            }
            foreach ($compiled['triggers'] as $trigger) {
                DB::table('trigger_groups')->insert([
                    'uuid' => $trigger['uuid'], 'organization_id' => $org->id, 'name' => $trigger['name'],
                    'type' => 'new_message', 'keywords' => json_encode([$trigger['name']], JSON_UNESCAPED_UNICODE),
                    'match_mode' => 'exact', 'ai_confidence' => 75, 'flow_id' => $flowIds[$trigger['target']],
                    'priority' => 0, 'is_system' => 0, 'is_enabled' => 1, 'rate_limit_type' => 'none',
                    'metadata' => json_encode(['import_bundle_id' => $bundle, 'source' => 'chatbot_studio']),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $result = ['organization_id' => $org->id, 'organization_name' => $org->name,
                'flows' => count($compiled['flows']), 'triggers' => count($compiled['triggers']),
                'start_flow_id' => $flowIds[$compiled['start']],
                'start_flow_uuid' => $compiled['flows'][$compiled['start']]['uuid'], 'bundle_id' => $bundle];
            $draft->update(['status' => 'published', 'result' => $result]);
            return $result;
        });
    }
}
