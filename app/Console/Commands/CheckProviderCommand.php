<?php

namespace App\Console\Commands;

use App\Domain\AI\Actions\CheckProviderConnection;
use App\Models\Provider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ada:provider:check {slug : Provider slug}')]
#[Description('Validate a provider credential with a model listing call (no tokens are spent)')]
class CheckProviderCommand extends Command
{
    public function handle(CheckProviderConnection $check): int
    {
        $provider = Provider::query()->where('slug', (string) $this->argument('slug'))->first();

        if ($provider === null) {
            $this->components->error('Unknown provider.');

            return self::FAILURE;
        }

        $error = $check->handle($provider);

        if ($error !== null) {
            $this->components->error("Connection failed: {$error}");

            return self::FAILURE;
        }

        $this->components->info("{$provider->name}: connection OK.");

        return self::SUCCESS;
    }
}
