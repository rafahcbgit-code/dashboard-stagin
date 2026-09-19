<?php

namespace App\Services\ChatbotStudio;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class BlueprintRecovery
{
    public function __construct(private StudioAiService $ai, private BlueprintCompiler $compiler) {}

    public function plan(string $source, string $instruction, ?array $previous, ?int $limit = null, ?string $usageDraftId = null): array
    {
        return $this->request(
            "Create only the COMPLETE routing inventory, not full message bodies. Read every branch of the source and previous draft. Each screen needs key, name, choices, list_label, next and a short source_hint locating its exact content. Include all questions/CTA/return-menu screens. Plan enough screens for long messages and menus. Do not summarize away branches. Return name, start, trigger, issues and screens. The routing inventory is fixed for later content batches.",
            ['source' => $source, 'admin_instruction' => $instruction, 'previous_blueprint' => $previous],
            BlueprintSchema::blueprint(true),
            function (array $plan) {
                Validator::make($plan, [
                    'screens' => 'required|array|min:1|max:200',
                    'screens.*.key' => 'required|string|distinct',
                    'screens.*.name' => 'required|string',
                    'screens.*.choices' => 'present|array',
                    'screens.*.source_hint' => 'required|string|max:400',
                ])->validate();
                $plan = app(BlueprintNormalizer::class)->normalize($plan);
                $this->compiler->validate($this->assemble($plan, []));
                return $plan;
            }, $limit, $usageDraftId,
        );
    }

    public function batch(string $source, string $instruction, ?array $previous, array $plan, array $completed, array $keys, int $limit, ?string $usageDraftId = null): array
    {
        return $this->request(
            'Generate full content ONLY for requested_keys, each exactly once. Keep key, name, choices, next and list_label EXACTLY as in routing_inventory. Preserve all original messages and questions from source for these screens; never shorten messages to fit output. Other screens are handled in separate batches. Return {"screens":[...],"issues":[]}. Put unsupported actions or uncertain source details in issues. Do not return other screens or the full blueprint.',
            ['source' => $source, 'admin_instruction' => $instruction, 'previous_blueprint' => $previous,
                'routing_inventory' => $plan, 'requested_keys' => $keys],
            BlueprintSchema::batch(),
            function (array $result) use ($plan, $completed, $keys) {
                Validator::make($result, ['screens' => 'required|array', 'screens.*.key' => 'required|string|distinct',
                    'issues' => 'present|array|max:100', 'issues.*' => 'required|string|max:1000'])->validate();
                $returned = array_column($result['screens'], 'key');
                if (count($returned) !== count($keys) || array_diff($returned, $keys) || array_diff($keys, $returned)) {
                    throw ValidationException::withMessages(['generation' => 'Batch must contain every requested screen exactly once.']);
                }
                $inventory = array_column($plan['screens'], null, 'key');
                foreach ($result['screens'] as &$screen) {
                    $planned = $inventory[$screen['key']];
                    Validator::make($screen, ['choices' => 'present|array', 'choices.*' => 'array:label,target',
                        'choices.*.target' => 'required|string', 'choices.*.label' => 'required|string'])->validate();
                    if (array_column($screen['choices'], 'target') !== array_column($planned['choices'], 'target')) {
                        throw ValidationException::withMessages(['generation' => 'Batch changed planned choice destinations for '.$screen['key'].'.']);
                    }
                    // The validated plan owns button names; models may echo the original source labels.
                    foreach ($screen['choices'] as $index => &$choice) {
                        $choice['label'] = $planned['choices'][$index]['label'];
                    }
                    unset($choice);
                    if (($screen['list_label'] ?? null) !== ($planned['list_label'] ?? null)) {
                        $screen['list_label'] = $planned['list_label'] ?? null;
                    }
                    foreach (['name', 'next'] as $field) {
                        if (($screen[$field] ?? null) != ($inventory[$screen['key']][$field] ?? null)) {
                            throw ValidationException::withMessages(['generation' => 'Batch changed planned routing for '.$screen['key'].'.']);
                        }
                    }
                }
                unset($screen);
                $this->compiler->validate(app(BlueprintNormalizer::class)->normalize($this->assemble($plan, array_merge($completed, $result['screens']))));
                return $result;
            }, $limit, $usageDraftId,
        );
    }

    // Placeholders validate cross-batch topology only; they must never be published.
    public function assemble(array $plan, array $completed, bool $final = false): array
    {
        $byKey = array_column($completed, null, 'key');
        $blueprint = $plan;
        $blueprint['screens'] = [];
        foreach ($plan['screens'] as $screen) {
            if (!isset($byKey[$screen['key']]) && $final) {
                throw ValidationException::withMessages(['generation' => 'Generation is missing a planned screen. Publication is blocked.']);
            }
            unset($screen['source_hint']);
            $blueprint['screens'][] = $byKey[$screen['key']] ?? array_merge($screen, ['messages' => ['Pending content'], 'questions' => []]);
        }
        return $final ? $this->compiler->validate(app(BlueprintNormalizer::class)->normalize($blueprint)) : $blueprint;
    }

    private function request(string $task, array $input, array $schema, callable $validate, ?int $limit = null, ?string $usageDraftId = null): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $this->ai->chat(BlueprintGenerator::INSTRUCTIONS."\n\nCURRENT STAGE (overrides full-blueprint output format only):\n".$task,
                json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ['schema' => $schema, 'max_tokens' => $limit ?? config('chatbot_studio.output_tokens'), 'usage_draft_id' => $usageDraftId]);
            try {
                $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new JsonException('Expected an object');
                }
                return $validate($data);
            } catch (JsonException | ValidationException $e) {
                $errors = $e instanceof ValidationException ? $e->errors() : ['generation' => ['Invalid JSON in generation batch.']];
                if ($attempt === 1) {
                    throw ValidationException::withMessages($errors);
                }
                $input['repair_errors'] = $errors;
                $input['invalid_output'] = $response;
                $input['repair_instruction'] = 'Correct the errors without omitting any requested content.';
            }
        }
        throw ValidationException::withMessages(['generation' => 'Batch generation did not finish.']);
    }
}
