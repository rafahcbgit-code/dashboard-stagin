<?php

namespace App\Services\ChatbotStudio;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BlueprintCompiler
{
    public function validate(array $blueprint): array
    {
        $data = Validator::make($blueprint, [
            'name' => 'required|string|max:150',
            'start' => 'required|string|max:64',
            'trigger' => 'required|string|max:20',
            'issues' => 'present|array|max:100',
            'issues.*' => 'string|max:1000',
            'adjustments' => 'sometimes|array|max:2500',
            'adjustments.*' => 'array:screen,field,before,after,reason',
            'adjustments.*.screen' => 'required|string|max:64',
            'adjustments.*.field' => 'required|string|max:100',
            'adjustments.*.before' => 'present|nullable|string|max:4096',
            'adjustments.*.after' => 'required|string|max:4096',
            'adjustments.*.reason' => 'required|in:distinct_trigger,short_label,list_title,split_cta',
            'screens' => 'required|array|min:1|max:200',
            'screens.*' => 'array:key,name,messages,questions,choices,cta,next,list_label',
            'screens.*.key' => 'required|string|regex:/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/|distinct',
            'screens.*.name' => 'required|string|max:150',
            'screens.*.messages' => 'present|array|max:30',
            'screens.*.messages.*' => 'required|string|max:4096',
            'screens.*.questions' => 'present|array|max:30',
            'screens.*.questions.*' => 'array:text,variable,input_type,retry_message',
            'screens.*.questions.*.text' => 'required|string|max:1024',
            'screens.*.questions.*.variable' => 'required|string|regex:/^[a-zA-Z][a-zA-Z0-9_]{0,49}$/',
            'screens.*.questions.*.input_type' => 'required|in:text,phone,email,number,date',
            'screens.*.questions.*.retry_message' => 'required|string|max:1024',
            'screens.*.choices' => 'present|array|max:10',
            'screens.*.choices.*' => 'array:label,target',
            'screens.*.choices.*.label' => 'required|string|max:20',
            'screens.*.choices.*.target' => 'required|string|max:64',
            'screens.*.list_label' => 'nullable|string|max:20',
            'screens.*.next' => 'nullable|string|max:64',
            'screens.*.cta' => 'nullable|array:label,url',
            'screens.*.cta.label' => 'required_with:screens.*.cta|string|max:20',
            'screens.*.cta.url' => 'required_with:screens.*.cta|url:https|max:2048',
        ])->validate();
        $screens = array_column($data['screens'], null, 'key');
        $errors = [];
        $keywords = [mb_strtolower(trim($data['trigger'])) => $data['start']];
        if (!isset($screens[$data['start']])) {
            $errors[] = 'The start screen does not exist.';
        }
        foreach ($screens as $key => $screen) {
            if (mb_strlen($data['name'].' | '.$screen['name']) > 255) {
                $errors[] = "$key: combined bot and flow name exceeds 255 characters.";
            }
            if (!$screen['messages'] && !$screen['questions'] && empty($screen['next'])) {
                $errors[] = "$key: add message text or a question.";
            }
            if (($screen['choices'] || !empty($screen['cta'])) && empty($screen['messages'])) {
                $errors[] = "$key: interactive messages require a message body.";
            }
            if ($screen['choices'] && !empty($screen['next'])) {
                $errors[] = "$key: choose buttons or an automatic next screen, not both.";
            }
            if (!empty($screen['cta']) && ($screen['choices'] || $screen['questions'])) {
                $errors[] = "$key: put the website CTA in its own screen.";
            }
            if (count($screen['choices']) > 3 && empty($screen['list_label'])) {
                $errors[] = "$key: a list with more than three choices needs list_label.";
            }
            $labels = [];
            foreach ($screen['choices'] as $choice) {
                $label = mb_strtolower(trim($choice['label']));
                if (isset($labels[$label])) {
                    $errors[] = "$key: duplicate choice label {$choice['label']}.";
                }
                $labels[$label] = true;
                if (isset($keywords[$label]) && $keywords[$label] !== $choice['target']) {
                    $errors[] = "Ambiguous trigger '{$choice['label']}': use a distinct label for each destination.";
                }
                $keywords[$label] = $choice['target'];
            }
            foreach (array_filter(array_merge(array_column($screen['choices'], 'target'), [$screen['next'] ?? null])) as $target) {
                if (!isset($screens[$target])) {
                    $errors[] = "$key: missing target $target.";
                }
            }
            $buttonLabels = array_merge(array_column($screen['choices'], 'label'), [$screen['list_label'] ?? '', $screen['cta']['label'] ?? '']);
            foreach ($buttonLabels as $label) {
                // The editor counts UTF-16 units, so emoji must fit that limit too.
                if (strlen(mb_convert_encoding($label, 'UTF-16LE', 'UTF-8')) / 2 > 20) {
                    $errors[] = "$key: button '$label' exceeds 20 characters including emoji.";
                }
            }
            if ($screen['choices'] || !empty($screen['cta'])) {
                $body = end($screen['messages']);
                if (mb_strlen((string) $body) > 1024) {
                    $errors[] = "$key: the interactive message body exceeds 1024 characters.";
                }
            }
            $variables = array_column($screen['questions'], 'variable');
            if (count($variables) !== count(array_unique($variables))) {
                $errors[] = "$key: question variables must be unique within a screen.";
            }
        }
        $visited = [];
        $queue = [$data['start']];
        while ($queue) {
            $key = array_pop($queue);
            if (isset($visited[$key]) || !isset($screens[$key])) {
                continue;
            }
            $visited[$key] = true;
            $screen = $screens[$key];
            array_push($queue, ...array_column($screen['choices'], 'target'));
            if (!empty($screen['next'])) {
                $queue[] = $screen['next'];
            }
        }
        foreach (array_diff(array_keys($screens), array_keys($visited)) as $key) {
            $errors[] = "$key: screen is unreachable from the start.";
        }
        foreach ($screens as $key => $screen) {
            $chain = [];
            while (!empty($screen['next']) && !$screen['questions'] && !$screen['choices']) {
                if (isset($chain[$key])) {
                    $errors[] = "$key: automatic portal cycle requires a user interaction.";
                    break;
                }
                $chain[$key] = true;
                $key = $screen['next'];
                $screen = $screens[$key] ?? ['questions' => [], 'choices' => []];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['blueprint' => array_values(array_unique($errors))]);
        }
        return $data;
    }

    public function compile(array $blueprint): array
    {
        $blueprint = $this->validate($blueprint);
        $flows = [];
        $triggers = [];
        foreach ($blueprint['screens'] as $screen) {
            $flows[$screen['key']] = ['uuid' => (string) Str::uuid(), 'name' => $blueprint['name'].' | '.$screen['name']];
        }
        $trigger = function (string $label, string $target) use (&$triggers): string {
            $key = mb_strtolower(trim($label));
            $triggers[$key] ??= ['uuid' => (string) Str::uuid(), 'name' => $label, 'target' => $target];
            return $triggers[$key]['uuid'];
        };
        $trigger($blueprint['trigger'], $blueprint['start']);
        foreach ($blueprint['screens'] as $screen) {
            foreach ($screen['choices'] as $choice) {
                $trigger($choice['label'], $choice['target']);
            }
        }
        foreach ($blueprint['screens'] as $screen) {
            $uuid = $flows[$screen['key']]['uuid'];
            $nodes = [];
            $edges = [];
            $append = function (string $id, string $type, array $fields) use (&$nodes, &$edges, $uuid): void {
                if ($nodes) {
                    $previous = end($nodes);
                    $edge = ['id' => $previous['id'].'-'.$id, 'source' => $previous['id'], 'target' => $id];
                    if ($previous['type'] === 'question') {
                        $edge['sourceHandle'] = 'success|'.$previous['id'];
                    }
                    $edges[] = $edge;
                }
                $nodes[] = $this->node($uuid, $id, $type, $fields, count($nodes));
            };
            $append('start', 'start', []);
            $messages = $screen['messages'];
            $interactiveBody = ($screen['choices'] || !empty($screen['cta'])) ? array_pop($messages) : null;
            foreach ($messages as $index => $body) {
                $append('message_'.$index, 'text', ['type' => 'text', 'body' => $body]);
            }
            foreach ($screen['questions'] as $index => $question) {
                $append('question_'.$index, 'question', [
                    'type' => 'question', 'question_text' => $question['text'], 'variable_name' => $question['variable'],
                    'input_type' => $question['input_type'], 'retry_message' => $question['retry_message'], 'max_retries' => 3,
                ]);
            }
            if ($screen['choices']) {
                $fields = $this->interactiveFields($interactiveBody);
                $isList = count($screen['choices']) > 3;
                if ($isList) {
                    $fields['type'] = 'interactive list';
                    $fields['buttonLabel'] = $screen['list_label'];
                    $fields['sections'] = [['title' => $screen['list_label'], 'rows' => array_map(fn ($choice, $i) => [
                        'id' => 'choice_'.$i, 'title' => $choice['label'], 'description' => '',
                    ], $screen['choices'], array_keys($screen['choices']))]];
                } else {
                    $fields['buttonType'] = 'buttons';
                    $fields['buttons'] = ['button1' => '', 'button2' => '', 'button3' => ''];
                    foreach ($screen['choices'] as $i => $choice) {
                        $fields['buttons']['button'.($i + 1)] = $choice['label'];
                    }
                }
                $append('choices', $isList ? 'list' : 'buttons', $fields);
                foreach ($screen['choices'] as $i => $choice) {
                    $portalId = 'portal_'.$i;
                    $nodes[] = $this->node($uuid, $portalId, 'portal', $this->portal(
                        $flows[$choice['target']], $choice['label'], $trigger($choice['label'], $choice['target'])
                    ), count($nodes));
                    $edges[] = ['id' => 'choice-'.$i, 'source' => 'choices', 'target' => $portalId,
                        'sourceHandle' => $isList ? 'a0'.$i : chr(97 + $i)];
                }
            } elseif (!empty($screen['cta'])) {
                $append('website', 'buttons', array_merge($this->interactiveFields($interactiveBody), [
                    'buttonType' => 'cta_url', 'buttons' => ['button1' => '', 'button2' => '', 'button3' => ''],
                    'ctaUrlButton' => ['displayText' => $screen['cta']['label'], 'url' => $screen['cta']['url']],
                ]));
            }
            if (!empty($screen['next'])) {
                $append('next', 'portal', $this->portal($flows[$screen['next']]));
            }
            $flows[$screen['key']]['metadata'] = ['nodes' => $nodes, 'edges' => $edges];
        }
        return ['flows' => $flows, 'triggers' => array_values($triggers), 'start' => $blueprint['start']];
    }

    private function node(string $uuid, string $id, string $type, array $fields, int $index): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $type,
            'position' => ['x' => ($index % 3) * 400, 'y' => intdiv($index, 3) * 350],
            'data' => ['uuid' => $uuid, 'title' => $type, 'metadata' => ['fields' => $fields]]];
    }

    private function interactiveFields(string $body): array
    {
        return ['type' => 'interactive buttons', 'body' => $body, 'headerType' => 'none',
            'headerText' => '', 'headerMedia' => [], 'footer' => ''];
    }

    private function portal(array $flow, ?string $label = null, ?string $triggerUuid = null): array
    {
        return ['type' => 'portal', 'target_flow_uuid' => $flow['uuid'], 'target_flow_name' => $flow['name'],
            'target_flow_id' => null, 'connection_mode' => $label === null ? 'simple' : 'with_trigger',
            'trigger_name' => $label, 'trigger_keyword' => $label, 'trigger_created' => $label !== null, 'trigger_uuid' => $triggerUuid];
    }
}
