<?php


namespace Evolvoltd\LaravelOpenaiAssistants;

use Illuminate\Support\ServiceProvider;

class LaravelOpenAIAssistantsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/assistants.php' => \App\Providers\config_path('assistants.php'),
        ]);
    }
}
