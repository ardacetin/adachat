<?php

/*
 * Prepares the database for the Playwright tests: a provider pointing at
 * tests/e2e/mock-provider.mjs, a model and an alias available to the
 * default group. Run after `php artisan migrate:fresh --seed`.
 */

use App\Domain\AI\Services\CredentialVault;
use App\Models\AiModel;
use App\Models\Group;
use App\Models\ModelAlias;
use App\Models\Provider;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$port = getenv('MOCK_PROVIDER_PORT') ?: '8765';

$provider = new Provider;
$provider->forceFill([
    'slug' => 'mock-openai',
    'driver' => 'openai',
    'name' => 'Mock OpenAI',
    'base_url' => "http://127.0.0.1:{$port}/v1",
    'enabled' => true,
])->save();

$app->make(CredentialVault::class)->rotate($provider, 'sk-e2e-mock-0000');

$model = new AiModel;
$model->forceFill([
    'provider_id' => $provider->id,
    'provider_model_id' => 'gpt-mock',
    'display_name' => 'GPT Mock',
    'input_price_per_million' => '1',
    'output_price_per_million' => '10',
    'context_window' => 128000,
    'max_output_tokens' => 4096,
    'supports_vision' => true,
])->save();

$alias = new ModelAlias;
$alias->forceFill([
    'slug' => 'smart',
    'name' => ['en' => 'Smart', 'tr' => 'Akıllı'],
    'description' => ['en' => 'Everyday questions', 'tr' => 'Günlük sorular'],
    'ai_model_id' => $model->id,
])->save();
$alias->groups()->attach(Group::default());

echo "e2e data ready\n";
