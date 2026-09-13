<?php

namespace App\Jobs;

use App\Models\ChatbotStudioDraft;
use App\Services\ChatbotStudio\BlueprintGenerator;
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

    public function __construct(public string $draftId, public int $version)
    {
        $this->onConnection('chatbot_studio')->onQueue('chatbot-studio');
    }

    public function handle(BlueprintGenerator $generator): void
    {
        $draft = ChatbotStudioDraft::find($this->draftId);
        if (!$draft || $draft->version !== $this->version || $draft->status !== 'generating') {
            return;
        }
        try {
            $conversation = $draft->conversation;
            $instruction = end($conversation)['content'];
            $blueprint = $generator->generate($draft->source, $instruction, $draft->blueprint);
            DB::transaction(function () use ($blueprint) {
                $draft = ChatbotStudioDraft::lockForUpdate()->find($this->draftId);
                if (!$draft || $draft->version !== $this->version || $draft->status !== 'generating') {
                    return;
                }
                $conversation = $draft->conversation;
                $conversation[] = ['role' => 'assistant', 'content' => $blueprint['name'],
                    'flows' => count($blueprint['screens']), 'issues' => count($blueprint['issues'])];
                $draft->update(['blueprint' => $blueprint, 'conversation' => $conversation, 'status' => 'draft', 'errors' => null]);
            });
        } catch (ValidationException $e) {
            $this->recordFailure($e->errors());
        }
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
            if ($draft && $draft->version === $this->version && $draft->status === 'generating') {
                $draft->update(['status' => 'failed', 'errors' => $errors]);
            }
        });
    }
}
