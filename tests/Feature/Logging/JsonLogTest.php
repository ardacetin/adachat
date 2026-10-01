<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

test('the json channel writes one JSON object per line into a daily file', function () {
    $directory = storage_path('framework/testing/logs-'.uniqid());
    config(['logging.channels.json.path' => $directory.'/ada.log']);

    Log::channel('json')->warning('AI provider request failed', ['provider' => 'openai', 'status' => 429]);

    $files = File::files($directory);
    expect($files)->toHaveCount(1)
        ->and($files[0]->getFilename())->toBe('ada-'.now()->format('Y-m-d').'.log');

    $entry = json_decode(trim($files[0]->getContents()), true, flags: JSON_THROW_ON_ERROR);
    expect($entry)->toMatchArray([
        'message' => 'AI provider request failed',
        'level_name' => 'WARNING',
        'context' => ['provider' => 'openai', 'status' => 429],
    ]);

    File::deleteDirectory($directory);
});
