<?php

namespace App\Services\Templates;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TemplatePolicyService
{
    public const MAX_QUICK_REPLY_BUTTONS = 3;
    public const MAX_ACTION_BUTTONS = 2;
    public const MAX_PHONE_BUTTONS = 1;
    public const MAX_URL_BUTTONS = 1;
    public const MAX_QUICK_REPLY_TEXT = 25;

    public function rulesFor(Organization $organization): array
    {
        $keyword = $this->primaryUnsubscribeKeyword($organization);

        return [
            'unsubscribe_keyword' => $keyword,
            'unsubscribe_settings_url' => '/settings/campaign',
            'quick_reply_limit' => self::MAX_QUICK_REPLY_BUTTONS,
            'action_button_limit' => self::MAX_ACTION_BUTTONS,
            'phone_button_limit' => self::MAX_PHONE_BUTTONS,
            'url_button_limit' => self::MAX_URL_BUTTONS,
        ];
    }

    public function prepareAndValidate(Request $request, Organization $organization): void
    {
        $category = strtoupper((string) $request->input('category'));

        if ($category !== 'AUTHENTICATION') {
            $request->request->remove('message_send_ttl_seconds');
            $request->request->set('customize_ttl', false);
        }

        $this->moveUnsubscribeButtonsLast($request);
        $this->validateButtonGroups($request);
    }

    public function primaryUnsubscribeKeyword(Organization $organization): string
    {
        $metadata = json_decode($organization->metadata ?: '{}', true) ?: [];
        $configured = (string) data_get($metadata, 'campaign_settings.unsubscribe_trigger', 'stop');
        $keywords = preg_split('/\s*,\s*/u', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return trim((string) ($keywords[0] ?? 'stop')) ?: 'stop';
    }

    public function hasUnsubscribeAction(Request $request): bool
    {
        foreach ($this->buttonGroups($request) as $buttons) {
            foreach ($buttons as $button) {
                if ($this->isUnsubscribeButton($button)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function unsubscribeKeywordsFromRequest(Request $request): array
    {
        $keywords = [];
        foreach ($this->buttonGroups($request) as $buttons) {
            foreach ($buttons as $button) {
                if ($this->isUnsubscribeButton($button)) {
                    $keyword = trim((string) ($button['text'] ?? ''));
                    if ($keyword !== '') {
                        $keywords[] = $keyword;
                    }
                }
            }
        }

        return array_values(array_unique($keywords));
    }

    private function moveUnsubscribeButtonsLast(Request $request): void
    {
        if (is_array($request->input('buttons'))) {
            $request->merge(['buttons' => $this->moveMarkedButtonLast($request->input('buttons'))]);
        }

        foreach (['cards', 'carousel_cards'] as $cardsKey) {
            $cards = $request->input($cardsKey);
            if (! is_array($cards)) {
                continue;
            }

            foreach ($cards as $index => $card) {
                if (is_array($card['buttons'] ?? null)) {
                    $cards[$index]['buttons'] = $this->moveMarkedButtonLast($card['buttons']);
                }
            }

            $request->merge([$cardsKey => $cards]);
        }
    }

    private function moveMarkedButtonLast(array $buttons): array
    {
        $marked = [];
        $ordinary = [];
        foreach ($buttons as $button) {
            if ($this->isUnsubscribeButton($button)) {
                $button['type'] = 'QUICK_REPLY';
                $marked[] = $button;
            } else {
                $ordinary[] = $button;
            }
        }

        return array_values(array_merge($ordinary, $marked));
    }

    private function isUnsubscribeButton(array $button): bool
    {
        return strtoupper((string) ($button['type'] ?? '')) === 'QUICK_REPLY'
            && filter_var($button['is_unsubscribe'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function validateButtonGroups(Request $request): void
    {
        foreach ($this->buttonGroups($request) as $path => $buttons) {
            $quickReplies = 0;
            $unsubscribeButtons = 0;
            $phoneButtons = 0;
            $urlButtons = 0;

            foreach ($buttons as $index => $button) {
                $type = strtoupper((string) ($button['type'] ?? ''));
                $text = trim((string) ($button['text'] ?? ''));

                if ($type === 'QUICK_REPLY') {
                    $quickReplies++;
                    if ($this->isUnsubscribeButton($button)) {
                        $unsubscribeButtons++;
                    }
                    if ($text === '' || mb_strlen($text) > self::MAX_QUICK_REPLY_TEXT) {
                        throw ValidationException::withMessages([
                            "{$path}.{$index}.text" => __('Quick reply text must contain between 1 and :max characters.', ['max' => self::MAX_QUICK_REPLY_TEXT]),
                        ]);
                    }
                } elseif ($type === 'PHONE_NUMBER') {
                    $phoneButtons++;
                } elseif ($type === 'URL') {
                    $urlButtons++;
                }
            }

            $errors = [];
            if ($quickReplies > self::MAX_QUICK_REPLY_BUTTONS) {
                $errors[$path] = __('You can add up to :count quick reply buttons.', ['count' => self::MAX_QUICK_REPLY_BUTTONS]);
            }
            if ($unsubscribeButtons > 1) {
                $errors[$path] = __('Only one unsubscribe button can be added to each button group.');
            }
            if (($phoneButtons + $urlButtons) > self::MAX_ACTION_BUTTONS || $phoneButtons > self::MAX_PHONE_BUTTONS || $urlButtons > self::MAX_URL_BUTTONS) {
                $errors[$path] = __('You can add one phone button and one website button.');
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }
    }

    private function buttonGroups(Request $request): array
    {
        $groups = [];
        if (is_array($request->input('buttons'))) {
            $groups['buttons'] = $request->input('buttons');
        }
        foreach (['cards', 'carousel_cards'] as $cardsKey) {
            foreach ($request->input($cardsKey, []) as $index => $card) {
                if (is_array($card['buttons'] ?? null)) {
                    $groups["{$cardsKey}.{$index}.buttons"] = $card['buttons'];
                }
            }
        }

        return $groups;
    }
}
