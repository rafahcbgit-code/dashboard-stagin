<?php

namespace App\Jobs;

use App\Models\ChatbotStudioDraft;
use App\Services\ChatbotStudio\BlueprintGenerator;
use App\Services\ChatbotStudio\BlueprintRecovery;
use App\Services\ChatbotStudio\IncompleteGenerationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerateChatbotStudioDraft implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 600;
    public $failOnTimeout = true;
    public int $step = 0;

    public function __construct(public string $draftId, public int $version, int $step = 0)
    {
        $this->step = $step;
        $this->onConnection('chatbot_studio')->onQueue('chatbot-studio');
    }

    public function handle(BlueprintGenerator $generator): void
    {
        $draft = ChatbotStudioDraft::find($this->draftId);
        if (!$this->isCurrent($draft)) {
            return;
        }
        try {
            $conversation = array_values(array_filter($draft->conversation, fn ($entry) => $entry['role'] === 'user'));
            $instruction = end($conversation)['content'];
            $state = $draft->generation_state;
            if (!$state && strlen($draft->source) > 60000) {
                $this->checkpoint($this->recoveryState());
                return;
            }
            if ($state && ($state['model'] ?? config('chatbot_studio.model')) !== config('chatbot_studio.model')) {
                throw ValidationException::withMessages(['generation' => 'The model changed during generation. Start a new revision to use the new model consistently.']);
            }
            if ($state && ($state['provider'] ?? 'gemini') !== config('chatbot_studio.provider', 'gemini')) {
                throw ValidationException::withMessages(['generation' => 'The provider changed during generation. Start a new revision to use the new provider consistently.']);
            }
            if (($state['stage'] ?? 'direct') === 'plan') {
                $plan = app(BlueprintRecovery::class)->plan($draft->source, $instruction, $draft->blueprint, $state['limit'], $draft->id);
                $state = array_merge($state, ['stage' => 'batch', 'plan' => $plan, 'completed' => [],
                    'pending' => array_chunk(array_column($plan['screens'], 'key'), 3), 'empty_retries' => 0]);
                $this->checkpoint($state);
                return;
            }
            if (($state['stage'] ?? 'direct') === 'batch') {
                $recovery = app(BlueprintRecovery::class);
                $batch = $recovery->batch($draft->source, $instruction, $draft->blueprint, $state['plan'],
                    $state['completed'], $state['pending'][0], $state['limit'], $draft->id);
                $state['completed'] = array_merge($state['completed'], $batch['screens']);
                $state['plan']['issues'] = array_values(array_unique(array_merge($state['plan']['issues'], $batch['issues'])));
                array_shift($state['pending']);
                $state['empty_retries'] = 0;
                if ($state['pending']) {
                    $this->checkpoint($state);
                    return;
                }
                $blueprint = $recovery->assemble($state['plan'], $state['completed'], true);
            } else {
                $blueprint = $generator->generate($draft->source, $instruction, $draft->blueprint, $draft->id);
            }
            DB::transaction(function () use ($blueprint) {
                $draft = ChatbotStudioDraft::lockForUpdate()->find($this->draftId);
                if (!$this->isCurrent($draft)) {
                    return;
                }
                $conversation = array_values(array_filter($draft->conversation, fn ($entry) => empty($entry['generation_progress'])));
                $conversation[] = ['role' => 'assistant', 'content' => $blueprint['name'],
                    'flows' => count($blueprint['screens']), 'issues' => count($blueprint['issues'])];
                $draft->update(['blueprint' => $blueprint, 'conversation' => $conversation, 'status' => 'draft', 'errors' => null, 'generation_state' => null]);
            });
        } catch (IncompleteGenerationException $e) {
            $state = $draft->generation_state ?? $this->recoveryState('direct');
            if ($e->reason === 'MAX_TOKENS') {
                if ($state['stage'] === 'direct') {
                    $this->checkpoint($this->recoveryState());
                    return;
                }
                if ($state['stage'] === 'batch' && count($state['pending'][0]) > 1) {
                    $keys = array_shift($state['pending']);
                    $state['pending'] = array_merge(array_chunk($keys, (int) ceil(count($keys) / 2)), $state['pending']);
                    $this->checkpoint($state);
                    return;
                }
                if ($state['limit'] < 65536) {
                    $state['limit'] = 65536;
                    $this->checkpoint($state);
                    return;
                }
            }
            if (in_array($e->reason, ['EMPTY_OUTPUT', 'NO_CANDIDATE', 'OTHER'], true) && ($state['empty_retries'] ?? 0) < 1) {
                $state['empty_retries'] = 1;
                $this->checkpoint($state);
                return;
            }
            $this->recordFailure($e->errors());
        } catch (ValidationException $e) {
            $this->recordFailure($e->errors());
        }
    }

    private function recoveryState(string $stage = 'plan'): array
    {
        return ['stage' => $stage, 'provider' => config('chatbot_studio.provider', 'gemini'), 'model' => config('chatbot_studio.model'), 'limit' => min(65536, max(256, (int) config('chatbot_studio.output_tokens'))), 'empty_retries' => 0];
    }

    private function isCurrent(?ChatbotStudioDraft $draft): bool
    {
        return $draft && $draft->version === $this->version && $draft->status === 'generating'
            && (int) ($draft->generation_state['step'] ?? 0) === $this->step;
    }

    private function checkpoint(array $state): void
    {
        DB::transaction(function () use ($state) {
            $draft = ChatbotStudioDraft::lockForUpdate()->find($this->draftId);
            if (!$this->isCurrent($draft)) {
                return;
            }
            $state['step'] = $this->step + 1;
            $history = $draft->conversation;
            $history = array_values(array_filter($history, fn ($entry) => empty($entry['generation_progress'])));
            $history[] = ['role' => 'assistant', 'generation_progress' => true,
                'content' => isset($state['plan'])
                    ? 'Generating screens: '.count($state['completed']).' / '.count($state['plan']['screens'])
                    : 'Preparing complete routing inventory for batch generation.'];
            $draft->update(['generation_state' => $state, 'conversation' => $history]);
            self::dispatch($this->draftId, $this->version, $state['step']);
        });
    }

    public function failed(?Throwable $error): void
    {
        Log::warning('Chatbot Studio generation failed', ['draft_id' => $this->draftId, 'exception' => $error ? get_class($error) : null]);
        $this->recordFailure(['generation' => ['Generation did not finish. Retry or check the AI service and queue worker.']]);
    }

    private function recordFailure(array $errors): void
    {
        DB::transaction(function () use ($errors) {
            $draft = ChatbotStudioDraft::lockForUpdate()->find($this->draftId);
            if ($this->isCurrent($draft)) {
                $draft->update(['status' => 'failed', 'errors' => $errors]);
            }
        });
    }
}
