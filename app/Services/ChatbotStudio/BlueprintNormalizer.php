<?php

namespace App\Services\ChatbotStudio;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BlueprintNormalizer
{
    public const REVIEW_ISSUE = 'Automatic flow adjustments require review before publishing. Check the original and adjusted values in Flow adjustments.';

    public function normalize(array $blueprint): array
    {
        Validator::make($blueprint, [
            'start' => 'required|string|max:64', 'trigger' => 'required|string|max:20',
            'issues' => 'present|array|max:100', 'issues.*' => 'string|max:1000',
            'screens' => 'required|array|min:1|max:200',
            'screens.*.key' => 'required|string|regex:/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/|distinct',
            'screens.*.name' => 'required|string|max:150',
            'screens.*.choices' => 'present|array|max:10',
            'screens.*.choices.*' => 'array:label,target',
            'screens.*.choices.*.label' => 'required|string|max:4096',
            'screens.*.choices.*.target' => 'required|string|max:64',
            'screens.*.list_label' => 'nullable|string|max:4096',
            'screens.*.next' => 'nullable|string|max:64',
            'screens.*.cta' => 'nullable|array:label,url',
            'screens.*.cta.label' => 'required_with:screens.*.cta|string|max:4096',
            'screens.*.cta.url' => 'required_with:screens.*.cta|url:https|max:2048',
            'screens.*.messages' => 'sometimes|array|max:30',
            'screens.*.messages.*' => 'required|string|max:4096',
            'screens.*.questions' => 'sometimes|array|max:30',
            'adjustments' => 'sometimes|array|max:2500',
        ])->validate();

        $changes = [];
        $blueprint['screens'] = $this->separateCtas($blueprint['screens'], $changes);
        $names = array_column($blueprint['screens'], 'name', 'key');
        $destinations = [$this->keyword($blueprint['trigger']) => [$blueprint['start'] => true]];
        foreach ($blueprint['screens'] as $screen) {
            foreach ($screen['choices'] as $choice) {
                $destinations[$this->keyword($choice['label'])][$choice['target']] = true;
            }
        }
        // Reserve existing unambiguous names before allocating any shortened names.
        $assigned = [];
        foreach ($destinations as $label => $targets) {
            if (count($targets) === 1) {
                $assigned[$label] = array_key_first($targets);
            }
        }
        $assigned[$this->keyword($blueprint['trigger'])] = $blueprint['start'];
        $reused = [];
        foreach ($blueprint['screens'] as &$screen) {
            $local = [];
            foreach ($screen['choices'] as $index => &$choice) {
                $original = $choice['label'];
                $target = $choice['target'];
                $pair = json_encode([$this->keyword($original), $target], JSON_THROW_ON_ERROR);
                $label = $reused[$pair] ?? $this->fit($original, 20);
                $ambiguous = count($destinations[$this->keyword($original)]) > 1;
                if ((!isset($reused[$pair]) && $ambiguous) || !$this->available($label, $target, $assigned, $local)) {
                    $context = $this->compact($names[$target] ?? $target);
                    $label = $this->fit($this->compact($original), 8).' - '.$this->fit($context, 9);
                    $base = $label;
                    for ($number = 2; !$this->available($label, $target, $assigned, $local); $number++) {
                        $suffix = ' '.$number;
                        $label = $this->fit($base, 20 - strlen($suffix)).$suffix;
                    }
                }
                $reused[$pair] ??= $label;
                $assigned[$this->keyword($label)] = $target;
                $local[$this->keyword($label)] = true;
                $choice['label'] = $label;
                $this->change($changes, $screen['key'], 'choices.'.$index.'.label', $original, $label,
                    $ambiguous || $this->length($original) <= 20 ? 'distinct_trigger' : 'short_label');
            }
            unset($choice);
            $original = $screen['list_label'] ?? null;
            if (count($screen['choices']) > 3 && ($original === null || trim($original) === '')) {
                $screen['list_label'] = $this->arabic($screen) ? 'عرض الخيارات' : 'View options';
                $this->change($changes, $screen['key'], 'list_label', $original, $screen['list_label'], 'list_title');
            } elseif (is_string($original) && $this->length($original) > 20) {
                $screen['list_label'] = $this->fit($original, 20);
                $this->change($changes, $screen['key'], 'list_label', $original, $screen['list_label'], 'short_label');
            }
            if (!empty($screen['cta'])) {
                $original = $screen['cta']['label'];
                $screen['cta']['label'] = $this->fit($original, 20);
                $this->change($changes, $screen['key'], 'cta.label', $original, $screen['cta']['label'], 'short_label');
            }
        }
        unset($screen);
        if ($changes) {
            $blueprint['adjustments'] = array_merge($blueprint['adjustments'] ?? [], $changes);
            if (!in_array(self::REVIEW_ISSUE, $blueprint['issues'], true)) {
                $blueprint['issues'][] = self::REVIEW_ISSUE;
            }
        }
        return $blueprint;
    }

    private function separateCtas(array $screens, array &$changes): array
    {
        $used = array_fill_keys(array_column($screens, 'key'), true);
        $result = [];
        foreach ($screens as $screen) {
            if (empty($screen['cta']) || (!$screen['choices'] && empty($screen['questions']))) {
                $result[] = $screen;
                continue;
            }
            if (empty($screen['messages']) || ($screen['choices'] && !empty($screen['next']))) {
                throw ValidationException::withMessages(['blueprint' => [$screen['key'].': cannot separate CTA without a message body or with conflicting choices/next.']]);
            }
            $original = $screen;
            $chain = [];
            if (!empty($screen['questions'])) {
                $cta = $this->auxiliary($original, $this->uniqueKey($screen['key'], '_link', $used), 'Link');
                $cta['cta'] = $screen['cta'];
                $cta['messages'] = [array_pop($screen['messages'])];
                $cta['next'] = $screen['next'] ?? null;
                $screen['cta'] = null;
                $screen['next'] = $cta['key'];
                $screen['choices'] = [];
                $screen['list_label'] = null;
                $chain[] = $screen;
            } else {
                $cta = $screen;
            }
            $cta['choices'] = [];
            $cta['list_label'] = null;
            if ($original['choices']) {
                $menu = $this->auxiliary($original, $this->uniqueKey($original['key'], '_menu', $used), 'Menu');
                $menu['messages'] = [$this->arabic($original) ? 'اختر أحد الخيارات:' : 'Choose an option:'];
                $menu['choices'] = $original['choices'];
                $menu['list_label'] = $original['list_label'] ?? null;
                $cta['next'] = $menu['key'];
                $chain[] = $cta;
                $chain[] = $menu;
            } else {
                $chain[] = $cta;
            }
            array_push($result, ...$chain);
            $this->change($changes, $original['key'], 'cta', 'CTA with replies/questions', implode(' -> ', array_column($chain, 'key')), 'split_cta');
        }
        if (count($result) > 200) {
            throw ValidationException::withMessages(['blueprint' => ['Separating website actions would exceed 200 screens. No screens were dropped; divide the source into smaller chatbots.']]);
        }
        return $result;
    }

    private function auxiliary(array $screen, string $key, string $suffix): array
    {
        return ['key' => $key, 'name' => mb_substr($screen['name'], 0, 95).' | '.$suffix,
            'messages' => [], 'questions' => [], 'choices' => [], 'list_label' => null, 'cta' => null, 'next' => null];
    }

    private function uniqueKey(string $key, string $suffix, array &$used): string
    {
        $base = substr($key, 0, 54).$suffix;
        $candidate = $base;
        for ($index = 2; isset($used[$candidate]); $index++) {
            $candidate = $base.'_'.$index;
        }
        $used[$candidate] = true;
        return $candidate;
    }

    private function available(string $label, string $target, array $assigned, array $local): bool
    {
        $key = $this->keyword($label);
        return $key !== '' && !isset($local[$key]) && (!isset($assigned[$key]) || $assigned[$key] === $target);
    }

    private function keyword(string $label): string
    {
        return mb_strtolower(trim($label));
    }

    private function length(string $text): int
    {
        return (int) (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
    }

    private function compact(string $text): string
    {
        preg_match_all('/\X/u', $text, $matches);
        $parts = array_filter($matches[0], fn ($part) => preg_match('/[\p{L}\p{N}]/u', $part) || preg_match('/^[\p{M}\p{Z}\p{P}\s]+$/u', $part));
        $value = trim(preg_replace('/\s+/u', ' ', implode('', $parts)));
        return $value !== '' ? $value : trim($text);
    }

    private function fit(string $text, int $limit): string
    {
        if ($this->length($text) <= $limit) {
            return $text;
        }
        $text = $this->compact($text);
        if ($this->length($text) <= $limit) {
            return $text;
        }
        preg_match_all('/\X/u', $text, $matches);
        $prefix = '';
        foreach ($matches[0] as $cluster) {
            if ($this->length($prefix.$cluster) > $limit - 1) {
                break;
            }
            $prefix .= $cluster;
        }
        // Prefer whole words; never split a surrogate pair or a joined emoji.
        $wholeWords = preg_replace('/\s+\S*$/u', '', $prefix);
        if ($wholeWords !== $prefix && $this->length($wholeWords) >= (int) ($limit / 2)) {
            $prefix = $wholeWords;
        }
        return rtrim($prefix)."\u{2026}";
    }

    private function arabic(array $screen): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $screen['name'].' '.implode(' ', $screen['messages'] ?? []).' '.implode(' ', array_column($screen['choices'], 'label')));
    }

    private function change(array &$changes, string $screen, string $field, ?string $before, string $after, string $reason): void
    {
        if ($before !== $after) {
            $changes[] = compact('screen', 'field', 'before', 'after', 'reason');
        }
    }
}
