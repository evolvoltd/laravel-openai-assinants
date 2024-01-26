<?php

return [
    /*
   |--------------------------------------------------------------------------
   | OpenAI Assistant Id
   |--------------------------------------------------------------------------
   |
   | Here you may specify your assistant id
   */

    'gpt_assistant_id' => env('GPT_ASSISTANT_ID'),

    /*
    |--------------------------------------------------------------------------
     | OpenAI Request Settings
    |--------------------------------------------------------------------------
     |
     | Here you may specify the maximum number of tries that the service is
     | allowed to perform
    */

    'max_retries' => env('MAX_RETRIES', 60),

    /*
   |--------------------------------------------------------------------------
    | Queue Settings
   |--------------------------------------------------------------------------
    |
    | Here you may specify the queue that will be used
   */

    'queue_name' => env('QUEUE_NAME', 'default'),
];
