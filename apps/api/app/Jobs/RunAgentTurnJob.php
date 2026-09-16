<?php

namespace App\Jobs;

use App\Models\AiSession;
use App\Models\User;
use App\Services\AgentRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunAgentTurnJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(
        public string $sessionId,
        public int $userId,
    ) {
        $this->onQueue('agent');
    }

    public function handle(AgentRunner $agents): void
    {
        $session = AiSession::query()->find($this->sessionId);
        $user = User::query()->find($this->userId);
        if (! $session || ! $user || $session->status !== 'running') {
            return;
        }

        $agents->finishTurn($session, $user);
    }

    public function failed(?Throwable $e): void
    {
        $session = AiSession::query()->find($this->sessionId);
        if (! $session || $session->status !== 'running') {
            return;
        }

        $session->update(['status' => 'error']);
        $session->messages()->create([
            'role' => 'assistant',
            'content' => 'Agent failed: '.($e?->getMessage() ?: 'queue worker error'),
        ]);
    }
}
