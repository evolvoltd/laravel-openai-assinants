<?php

namespace Evolvoltd\LaravelOpenaiAssistants\Jobs;

use Evolvoltd\LaravelOpenaiAssistants\Services\GPTService;
use Evolvoltd\LaravelOpenaiAssistants\Interfaces\ParseGPTResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class ProcessGPTQuery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $run;
    private string $threadId;
    private string $message;
    private $file;
    private ParseGPTResponse $parseGPTResponse;
    /**
     * The number of times the Jobs may be attempted.
     *
     * @var int
     */
    public $tries;

    /**
     * Create a new Jobs instance.
     */
    public function __construct($run, string $threadId, string $message, $file, ParseGPTResponse $parseGPTResponse)
    {
        $this->run = $run;
        $this->threadId = $threadId;
        $this->message = $message;
        $this->parseGPTResponse = $parseGPTResponse;
        $this->file = $file;
        $this->tries = config('assistants.max_retries');
    }

    /**
     * Execute the Jobs.
     */
    public function handle()
    {
        $GPTService = new GPTService();

        $this->run = $GPTService->waitOnRun($this->run, $this->message, $this->threadId, $this->parseGPTResponse);
        $this->run = $GPTService->processRunFunctions($this->run, $this->message, $this->parseGPTResponse);
        $GPTService->completeRun($this->run, $this->message, $this->parseGPTResponse);
        if (!is_null($this->file)) {
            OpenAI::files()->delete($this->file->id);
        }
    }
}
