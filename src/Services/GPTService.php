<?php

namespace Evolvoltd\LaravelOpenaiAssistants\Services;

use Evolvoltd\LaravelOpenaiAssistants\Interfaces\ParseGPTResponse;
use Evolvoltd\LaravelOpenaiAssistants\Jobs\ProcessGPTQuery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;

class GPTService
{
    public function ask(string $message, ParseGPTResponse $parseGPTResponse, $fileContents = null, $gptAssistantId = null, $queueName = null)
    {
        $assistantId = $gptAssistantId ?? config('assistants.gpt_assistant_id');

        // create new thread and run it
        [$file, $threadId, $run] = $this->createThreadAndRun($assistantId, $message, $parseGPTResponse, $fileContents);

        ProcessGPTQuery::dispatch($run, $threadId, $message, $file, $parseGPTResponse)->onQueue($queueName ?? config('assistants.queue_name'));

        return $threadId;
    }

    private function createThreadAndRun($assistantId, string $message, ParseGPTResponse $parseGPTResponse, $fileContents = null)
    {
        $response = null;
        if (!is_null($fileContents)) {
            $response = OpenAI::files()->upload([
                'purpose' => 'assistants',
                'file' => $fileContents,
            ]);
        }

        $thread = OpenAI::threads()->create([]);
        if (!is_null($response) && !is_null($response->id)) {
            $run = $this->submitMessage($assistantId, $thread->id, $message, $response->id);
        } else {
            $run = $this->submitMessage($assistantId, $thread->id, $message, null);
        }

        $run = OpenAI::threads()->runs()->retrieve(
            threadId: $thread->id,
            runId: $run->id,
        );

        $parseGPTResponse->statusUpdate(
            status: 'new',
            run_id: $run->id,
            thread_id: $thread->id,
            message: $message,
        );

        return [
            $response,
            $thread->id,
            $run,
        ];
    }

    private function submitMessage($assistantId, $threadId, $message, $file_id)
    {
        if (is_null($file_id)){
            OpenAI::threads()->messages()->create($threadId, [
                'role' => 'user',
                'content' => $message,
            ]);
        } else {
            OpenAI::threads()->messages()->create($threadId, [
                'role' => 'user',
                'content' => $message,
                'file_ids' => [$file_id]
            ]);
        }
        return OpenAI::threads()->runs()->create(
            threadId: $threadId,
            parameters: [
                'assistant_id' => $assistantId
            ],
        );
    }

    public function waitOnRun($run, $message, $threadId, ParseGPTResponse $parseGPTResponse)
    {
        $parseGPTResponse->statusUpdate(
            status: 'waiting',
            run_id: $run->id,
            thread_id: $threadId,
            message: $message,
        );
        while ($run->status == "queued" || $run->status == "in_progress") {
            $run = OpenAI::threads()->runs()->retrieve(
                threadId: $threadId,
                runId: $run->id,
            );

            throw new \Exception();
        }

        return $run;
    }

    private function getMessages($threadId, $order = 'asc', $messageId = null)
    {
        $params = [
            'order' => $order,
            'limit' => 10
        ];

        if ($messageId) {
            $params['after'] = $messageId;
        }

        return OpenAI::threads()->messages()->list($threadId, $params);
    }

    public function processRunFunctions($run, $message, ParseGPTResponse $parseGPTResponse)
    {
        // check if the run requires any action
        while ($run->status == 'requires_action' && $run->requiredAction->type == 'submit_tool_outputs') {
            // Extract tool calls
            // multiple calls possible
            $toolCalls = $run->requiredAction->submitToolOutputs->toolCalls;
            $toolOutputs = [];

            foreach ($toolCalls as $toolCall) {
                $name = $toolCall->function->name;
                $arguments = json_decode($toolCall->function->arguments);
                $toolOutput = $parseGPTResponse->executeFunctions($name, $arguments, $toolCall);
                if ($toolOutput != []) {
                    $toolOutputs[] = $toolOutput;
                }
            }

            $run = OpenAI::threads()->runs()->submitToolOutputs(
                threadId: $run->threadId,
                runId: $run->id,
                parameters: [
                    'tool_outputs' => $toolOutputs,
                ]
            );

            $run = $this->waitOnRun($run, $message, $run->threadId, $parseGPTResponse);
        }

        return $run;
    }

    public function completeRun($run, string $originalMessage, ParseGPTResponse $parseGPTResponse)
    {
        if ($run->status == 'completed') {
            // get the latest messages after user's message
            $messages = OpenAI::threads()->messages()->list(
                threadId: $run->threadId,
            );

            $messagesData = $messages->data;

            if (!empty($messagesData)) {
                $messagesCount = count($messagesData);
                $assistantResponseMessage = '';

                // check if assistant sent more than 1 message
                if ($messagesCount > 1) {
                    foreach ($messagesData as $message) {
                        if ($message->content[0]->text->value != $originalMessage)
                            // concatenate multiple messages
                            $assistantResponseMessage .= $message->content[0]->text->value . "\n\n";
                    }

                    // remove the last new line
                    $assistantResponseMessage = rtrim($assistantResponseMessage);
                } else {
                    // take the first message
                    $assistantResponseMessage = $messagesData[0]->content[0]->text->value;
                }
                $parsedResponseMessage = $parseGPTResponse->parseResponse($assistantResponseMessage);
                $parseGPTResponse->statusUpdate(
                    status: 'completed',
                    run_id: $run->id,
                    thread_id: $run->threadId,
                    message: $originalMessage,
                    response: $parsedResponseMessage,
                );
            } else {
                $parseGPTResponse->statusUpdate(
                    status: 'error',
                    run_id: $run->id,
                    thread_id: $run->threadId,
                    message: $originalMessage,
                    response: 'Something went wrong; assistant didn\'t respond',
                );
            }
        } else {
            $parseGPTResponse->statusUpdate(
                status: 'error',
                run_id: $run->id,
                thread_id: $run->threadId,
                message: $originalMessage,
                response: 'Something went wrong; assistant run wasn\'t completed successfully',
            );
        }
        $response = OpenAI::threads()->delete($run->threadId);
    }
}
