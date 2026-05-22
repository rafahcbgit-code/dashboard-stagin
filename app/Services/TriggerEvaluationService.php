<?php

namespace App\Services;

use App\Models\TriggerGroup;
use Illuminate\Support\Facades\Log;

class TriggerEvaluationService
{
    protected AiIntentClassifier $intentClassifier;
    protected RateLimitService $rateLimitService;
    protected WorkingHoursService $workingHoursService;
    protected AiSettingsService $aiSettingsService;

    public function __construct(
        AiIntentClassifier $intentClassifier,
        RateLimitService $rateLimitService,
        WorkingHoursService $workingHoursService,
        AiSettingsService $aiSettingsService
    ) {
        $this->intentClassifier = $intentClassifier;
        $this->rateLimitService = $rateLimitService;
        $this->workingHoursService = $workingHoursService;
        $this->aiSettingsService = $aiSettingsService;
    }

    /**
     * Evaluate all trigger groups for an organization against an incoming message.
     * Returns the first matching trigger group with its linked flow, or null.
     *
     * Evaluation order (intent beats circumstance):
     * 1. Keyword triggers (new_message) — customer explicitly typed a keyword
     * 2. Context triggers (new_contact, greeting, away) — circumstantial
     * 3. Fallback triggers — always last
     *
     * @param int $organizationId
     * @param mixed $contact The contact model
     * @param string $message The incoming message text
     * @param bool $isNewContact Whether this is a first-time contact
     * @return TriggerMatchResult|null
     */
    public function evaluate(int $organizationId, $contact, string $message, bool $isNewContact): ?TriggerMatchResult
    {
        $triggerGroups = TriggerGroup::forOrganization($organizationId)
            ->enabled()
            ->orderedByPriority()
            ->get();

        if ($triggerGroups->isEmpty()) {
            return null;
        }

        // Split into three evaluation groups: intent → context → fallback
        // Keyword triggers (new_message) are evaluated first because customer
        // intent should always beat circumstantial triggers.
        $keywordTriggers = $triggerGroups->filter(fn($tg) => $tg->type === 'new_message');
        $contextTriggers = $triggerGroups->filter(fn($tg) => in_array($tg->type, ['new_contact', 'greeting', 'away']));
        $fallbackTriggers = $triggerGroups->filter(fn($tg) => $tg->type === 'fallback');

        // 1. Evaluate keyword (intent) triggers first
        foreach ($keywordTriggers as $triggerGroup) {
            $match = $this->matchesTrigger($triggerGroup, $message, $isNewContact, $organizationId, $contact);

            if ($match !== null) {
                Log::info('TriggerEvaluationService: Trigger matched', [
                    'trigger_group_id' => $triggerGroup->id,
                    'trigger_name' => $triggerGroup->name,
                    'trigger_type' => $triggerGroup->type,
                    'match_mode' => $match->matchMode,
                    'contact_id' => $contact->id ?? null,
                ]);

                return new TriggerMatchResult(
                    triggerGroup: $triggerGroup,
                    flow: $triggerGroup->flow,
                    matchMode: $match->matchMode,
                    matchedKeyword: $match->matchedKeyword,
                    confidence: $match->confidence
                );
            }
        }

        // 2. Evaluate context triggers (new_contact, greeting, away)
        foreach ($contextTriggers as $triggerGroup) {
            $match = $this->matchesTrigger($triggerGroup, $message, $isNewContact, $organizationId, $contact);

            if ($match !== null) {
                Log::info('TriggerEvaluationService: Trigger matched', [
                    'trigger_group_id' => $triggerGroup->id,
                    'trigger_name' => $triggerGroup->name,
                    'trigger_type' => $triggerGroup->type,
                    'match_mode' => $match->matchMode,
                    'contact_id' => $contact->id ?? null,
                ]);

                return new TriggerMatchResult(
                    triggerGroup: $triggerGroup,
                    flow: $triggerGroup->flow,
                    matchMode: $match->matchMode,
                    matchedKeyword: $match->matchedKeyword,
                    confidence: $match->confidence
                );
            }
        }

        // 3. Evaluate fallback triggers last
        foreach ($fallbackTriggers as $triggerGroup) {
            $match = $this->matchesTrigger($triggerGroup, $message, $isNewContact, $organizationId, $contact);

            if ($match !== null) {
                Log::info('TriggerEvaluationService: Trigger matched', [
                    'trigger_group_id' => $triggerGroup->id,
                    'trigger_name' => $triggerGroup->name,
                    'trigger_type' => $triggerGroup->type,
                    'match_mode' => $match->matchMode,
                    'contact_id' => $contact->id ?? null,
                ]);

                return new TriggerMatchResult(
                    triggerGroup: $triggerGroup,
                    flow: $triggerGroup->flow,
                    matchMode: $match->matchMode,
                    matchedKeyword: $match->matchedKeyword,
                    confidence: $match->confidence
                );
            }
        }

        return null;
    }

    /**
     * Check if a single trigger group matches the incoming message/context.
     *
     * @return TriggerTypeMatchResult|null
     */
    protected function matchesTrigger(
        TriggerGroup $triggerGroup,
        string $message,
        bool $isNewContact,
        int $organizationId,
        $contact
    ): ?TriggerTypeMatchResult {
        switch ($triggerGroup->type) {
            case 'new_contact':
                return $this->matchNewContact($triggerGroup, $isNewContact, $organizationId, $contact);

            case 'greeting':
                return $this->matchGreeting($triggerGroup, $organizationId, $contact);

            case 'away':
                return $this->matchAway($triggerGroup, $organizationId, $contact);

            case 'fallback':
                return $this->matchFallback($triggerGroup);

            case 'new_message':
                return $this->matchNewMessage($triggerGroup, $message, $organizationId, $contact);

            default:
                return null;
        }
    }

    /**
     * Match a new_contact trigger.
     * Only fires if the contact is new and rate limit allows.
     */
    protected function matchNewContact(TriggerGroup $triggerGroup, bool $isNewContact, int $organizationId, $contact): ?TriggerTypeMatchResult
    {
        if (!$isNewContact) {
            return null;
        }

        if (!$this->checkRateLimit($triggerGroup, $organizationId, $contact)) {
            return null;
        }

        if (!$triggerGroup->hasFlowLinked()) {
            return null;
        }

        return new TriggerTypeMatchResult(
            matchMode: 'new_contact',
            matchedKeyword: null,
            confidence: 1.0
        );
    }

    /**
     * Match a greeting trigger.
     * Only fires during working hours and if rate limit allows.
     */
    protected function matchGreeting(TriggerGroup $triggerGroup, int $organizationId, $contact): ?TriggerTypeMatchResult
    {
        $isWithinHours = $this->workingHoursService->isWithinWorkingHours($organizationId);
        if (!$isWithinHours) {
            return null;
        }

        if (!$this->checkRateLimit($triggerGroup, $organizationId, $contact)) {
            return null;
        }

        if (!$triggerGroup->hasFlowLinked()) {
            return null;
        }

        return new TriggerTypeMatchResult(
            matchMode: 'greeting',
            matchedKeyword: null,
            confidence: 1.0
        );
    }

    /**
     * Match an away trigger.
     * Only fires outside working hours and if rate limit allows.
     */
    protected function matchAway(TriggerGroup $triggerGroup, int $organizationId, $contact): ?TriggerTypeMatchResult
    {
        $isWithinHours = $this->workingHoursService->isWithinWorkingHours($organizationId);
        if ($isWithinHours) {
            return null;
        }

        if (!$this->checkRateLimit($triggerGroup, $organizationId, $contact)) {
            return null;
        }

        if (!$triggerGroup->hasFlowLinked()) {
            return null;
        }

        return new TriggerTypeMatchResult(
            matchMode: 'away',
            matchedKeyword: null,
            confidence: 1.0
        );
    }

    /**
     * Match a fallback trigger.
     * Always matches (catches everything as last resort).
     */
    protected function matchFallback(TriggerGroup $triggerGroup): ?TriggerTypeMatchResult
    {
        return new TriggerTypeMatchResult(
            matchMode: 'fallback',
            matchedKeyword: null,
            confidence: 1.0
        );
    }

    /**
     * Match a new_message trigger using keyword evaluation.
     * Evaluates keywords using their respective match modes (exact, contains, ai).
     */
    protected function matchNewMessage(TriggerGroup $triggerGroup, string $message, int $organizationId, $contact): ?TriggerTypeMatchResult
    {
        if (!$triggerGroup->hasKeywords()) {
            return null;
        }

        // AI mode triggers require AI to be enabled org-wide
        if ($triggerGroup->match_mode === 'ai' && !$this->aiSettingsService->isEnabled($organizationId)) {
            return null;
        }

        if (!$this->checkRateLimit($triggerGroup, $organizationId, $contact)) {
            return null;
        }

        if (!$triggerGroup->hasFlowLinked()) {
            return null;
        }

        $result = $this->intentClassifier->classify(
            $message,
            $triggerGroup->keywords,
            $triggerGroup->match_mode,
            $this->aiSettingsService->getDefaultConfidence($organizationId)
        );

        if ($result === null) {
            return null;
        }

        return new TriggerTypeMatchResult(
            matchMode: $result->matchMode,
            matchedKeyword: $result->matchedKeyword,
            confidence: $result->confidence
        );
    }

    /**
     * Check rate limit for a trigger group and contact.
     */
    protected function checkRateLimit(TriggerGroup $triggerGroup, int $organizationId, $contact): bool
    {
        if ($triggerGroup->rate_limit_type === 'none') {
            return true;
        }

        $contactId = $contact->id ?? null;
        if (!$contactId) {
            return true;
        }

        return $this->rateLimitService->canTrigger($organizationId, $contactId, $triggerGroup->id);
    }
}

/**
 * Result of a full trigger evaluation.
 */
class TriggerMatchResult
{
    public function __construct(
        public TriggerGroup $triggerGroup,
        public $flow, // nullable Flow model
        public string $matchMode,
        public ?string $matchedKeyword,
        public float $confidence,
    ) {}
}

/**
 * Internal result for trigger type matching.
 */
class TriggerTypeMatchResult
{
    public function __construct(
        public string $matchMode,
        public ?string $matchedKeyword,
        public float $confidence,
    ) {}
}