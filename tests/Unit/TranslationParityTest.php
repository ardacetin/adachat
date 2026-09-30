<?php

/**
 * Every available locale must define exactly the same translation keys,
 * both for backend (lang/) and frontend (resources/js/i18n/locales/) strings.
 */

/**
 * @param  array<mixed>  $array
 * @return list<string>
 */
function flattenTranslationKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        $keys = [...$keys, ...(is_array($value) ? flattenTranslationKeys($value, $path) : [$path])];
    }

    sort($keys);

    return $keys;
}

/**
 * @return array<string, list<string>>
 */
function translationKeysFor(string $directory, string $extension): array
{
    $result = [];

    foreach (glob("{$directory}/*.{$extension}") ?: [] as $file) {
        $data = $extension === 'php'
            ? require $file
            : json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        $result[basename($file, ".{$extension}")] = flattenTranslationKeys($data);
    }

    ksort($result);

    return $result;
}

dataset('translation sources', [
    'backend' => [fn () => lang_path(), 'php'],
    'frontend' => [fn () => resource_path('js/i18n/locales'), 'json'],
]);

test('all locales define the same keys', function (Closure $root, string $extension) {
    $locales = config('ada.locales.available');
    $reference = translationKeysFor($root()."/{$locales[0]}", $extension);

    expect($reference)->not->toBeEmpty();

    foreach (array_slice($locales, 1) as $locale) {
        expect(translationKeysFor($root()."/{$locale}", $extension))
            ->toBe($reference, "locale [{$locale}] differs from [{$locales[0]}]");
    }
})->with('translation sources');
