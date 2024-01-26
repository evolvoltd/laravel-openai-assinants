<?php

namespace Evolvoltd\LaravelOpenaiAssistants\Interfaces;

interface ParseGPTResponse
{
    public function parseResponse(string $response);

    public function executeFunctions(string $name, $arguments, $toolCall);

    public function statusUpdate(string $status, string $run_id, string $thread_id, string $message, string $file_path = null, string $file_name = null, string $response = null);
}
