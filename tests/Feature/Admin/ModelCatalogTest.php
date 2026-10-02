<?php

use App\Domain\AI\Catalog\ModelCatalog;
use App\Domain\AI\Enums\ProviderDriver;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\Provider;
use App\Models\User;
use Carbon\CarbonImmutable;

function useCatalog(string $path): void
{
    config(['ada.catalog.path' => $path]);
    app()->forgetInstance(ModelCatalog::class);
}

test('the shipped catalog is complete and well formed', function () {
    $catalog = app(ModelCatalog::class);
    $entries = $catalog->all();

    expect($entries)->not->toBeEmpty();

    $keys = [];

    foreach ($entries as $entry) {
        $keys[] = $entry->driver->value.'/'.$entry->id;

        expect($entry->driver)->not->toBe(ProviderDriver::OpenAICompatible)
            ->and($entry->id)->toMatch('/^[A-Za-z0-9._:\/-]+$/')
            ->and($entry->contextWindow)->toBeGreaterThan(0)
            ->and($entry->maxOutputTokens)->toBeGreaterThan(0)->toBeLessThanOrEqual($entry->contextWindow)
            ->and($entry->source)->toStartWith('https://')
            ->and($entry->asOf)->toMatch('/^\d{4}-\d{2}-\d{2}$/');

        foreach ([$entry->inputPrice, $entry->outputPrice, $entry->cachedInputPrice, $entry->cacheWritePrice] as $price) {
            expect($price === null || preg_match('/^\d+(\.\d{1,6})?$/', $price) === 1)->toBeTrue();
        }
    }

    expect($keys)->toBe(array_values(array_unique($keys)));

    // Every provider with a catalog has entries; long-context surcharges are
    // avoided by the context window (resources/catalog/models.json "about").
    $drivers = array_unique(array_map(fn ($entry) => $entry->driver, $entries), SORT_REGULAR);
    expect($drivers)->toHaveCount(3);

    foreach ($catalog->forDriver(ProviderDriver::OpenAI) as $entry) {
        expect($entry->contextWindow)->toBeLessThanOrEqual(272000);
    }

    expect($catalog->find(ProviderDriver::Gemini, 'gemini-3.1-pro-preview')->contextWindow)->toBeLessThanOrEqual(200000);

    // Announced changes lie ahead and are well formed.
    $raw = json_decode(file_get_contents(resource_path('catalog/models.json')), true);

    foreach ($raw['models'] as $model) {
        foreach ($model['price_changes'] ?? [] as $change) {
            expect($change['from'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')->toBeGreaterThan($model['as_of'])
                ->and($change['prices'])->toHaveKeys(['input', 'output']);
        }
    }
});

test('announced price changes take effect on their day and are flagged', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-31 23:00', 'UTC'));
    $catalog = app(ModelCatalog::class);
    $flash = $catalog->find(ProviderDriver::Gemini, 'gemini-3.8-flash');

    expect($flash->inputPrice)->toBe('0.75')
        ->and($flash->nextChange)->toMatchArray(['from' => '2027-01-01', 'input' => '1.50', 'output' => '7.50']);

    $model = AiModel::factory()->for(Provider::factory()->create(['driver' => 'gemini']))->create([
        'provider_model_id' => 'gemini-3.8-flash',
        ...$flash->attributes(),
        'metadata' => ['pricing_source' => 'catalog', 'catalog_as_of' => $flash->asOf],
    ]);

    expect($catalog->newerPrices($model))->toBeNull();

    // The same (long-lived) catalog instance switches at midnight UTC.
    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30', 'UTC'));
    $flash = $catalog->find(ProviderDriver::Gemini, 'gemini-3.8-flash');

    expect($flash->inputPrice)->toBe('1.50')
        ->and($flash->cachedInputPrice)->toBe('0.15')
        ->and($flash->nextChange)->toBeNull()
        ->and($catalog->newerPrices($model)?->outputPrice)->toBe('7.50');
});

test('easy mode takes prices and limits from the catalog, not from the browser', function () {
    useCatalog(base_path('tests/Fixtures/catalog/models.json'));
    $provider = Provider::factory()->create(['driver' => 'anthropic']);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.models.store'), [
            'pricing' => 'catalog',
            'provider_id' => $provider->id,
            'provider_model_id' => 'claude-test-1',
            'display_name' => 'Claude',
            'description' => '',
            // Ignored in easy mode.
            'input_price_per_million' => '0',
            'output_price_per_million' => '0',
            'context_window' => 10,
            'supports_tools' => false,
            'enabled' => true,
        ])
        ->assertSessionHasNoErrors();

    $model = AiModel::query()->sole();

    expect($model->input_price_per_million)->toBe('1.000000')
        ->and($model->output_price_per_million)->toBe('5.000000')
        ->and($model->cached_input_price_per_million)->toBe('0.100000')
        ->and($model->cache_write_price_per_million)->toBe('1.250000')
        ->and($model->context_window)->toBe(200000)
        ->and($model->max_output_tokens)->toBe(64000)
        ->and($model->supports_vision)->toBeTrue()
        ->and($model->metadata)->toEqual(['pricing_source' => 'catalog', 'catalog_as_of' => '2026-10-01']);
});

test('easy mode refuses a model the catalog does not know', function () {
    useCatalog(base_path('tests/Fixtures/catalog/models.json'));
    $provider = Provider::factory()->create(['driver' => 'openai']);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.models.store'), [
            'pricing' => 'catalog',
            'provider_id' => $provider->id,
            'provider_model_id' => 'claude-test-1',
            'display_name' => 'Wrong provider',
            'supports_tools' => false,
            'enabled' => true,
        ])
        ->assertSessionHasErrors(['provider_model_id' => __('admin.models.catalog_unknown')]);
});

test('a changed catalog price is flagged and can be taken over', function () {
    useCatalog(base_path('tests/Fixtures/catalog/models.json'));
    $provider = Provider::factory()->create(['driver' => 'anthropic']);
    $model = AiModel::factory()->for($provider)->create([
        'provider_model_id' => 'claude-test-1',
        'input_price_per_million' => '0.80',
        'output_price_per_million' => '5',
        'cached_input_price_per_million' => '0.10',
        'cache_write_price_per_million' => '1.25',
        'metadata' => ['pricing_source' => 'catalog', 'catalog_as_of' => '2026-01-01'],
    ]);
    $manual = AiModel::factory()->for($provider)->create(['provider_model_id' => 'claude-test-1-custom']);
    $admin = User::factory()->superAdmin()->create();

    $rows = collect($this->actingAs($admin)->get(route('admin.models.index'))->inertiaProps('models'))->keyBy('id');

    expect($rows[$model->id]['catalog_update']['input_price_per_million'])->toBe('1')
        ->and($rows[$manual->id]['catalog_update'])->toBeNull();

    $this->post(route('admin.models.catalog-sync', $model))->assertRedirect(route('admin.models.index'));

    expect($model->refresh()->input_price_per_million)->toBe('1.000000')
        ->and($model->metadata['catalog_as_of'])->toBe('2026-10-01')
        ->and(AuditLog::query()->where('action', 'ai_model.updated')->sole()->old_values)->toHaveKey('input_price_per_million');

    // Nothing left to take over.
    $this->post(route('admin.models.catalog-sync', $model))->assertStatus(409);
    $this->post(route('admin.models.catalog-sync', $manual))->assertStatus(409);
});

test('the model form lists the catalog for each provider', function () {
    useCatalog(base_path('tests/Fixtures/catalog/models.json'));
    $anthropic = Provider::factory()->create(['driver' => 'anthropic', 'name' => 'A']);
    $local = Provider::factory()->create(['driver' => 'openai_compatible', 'name' => 'B', 'base_url' => 'http://llm.test/v1']);

    $providers = collect($this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.models.create'))
        ->inertiaProps('providers'))->keyBy('id');

    expect($providers[$anthropic->id]['catalog'][0])->toMatchArray(['id' => 'claude-test-1', 'input_price_per_million' => '1', 'source' => 'https://example.test/pricing'])
        ->and($providers[$local->id]['catalog'])->toBe([]);
});

test('the doctor warns about models with newer catalog prices', function () {
    useCatalog(base_path('tests/Fixtures/catalog/models.json'));
    $model = AiModel::factory()->for(Provider::factory()->create(['driver' => 'anthropic']))->create([
        'provider_model_id' => 'claude-test-1',
        'display_name' => 'Test Claude',
        'input_price_per_million' => '0.80',
        'output_price_per_million' => '5',
        'metadata' => ['pricing_source' => 'catalog', 'catalog_as_of' => '2026-01-01'],
    ]);

    $this->artisan('ada:doctor')->expectsOutputToContain('New catalog prices for: Test Claude');

    $model->update(app(ModelCatalog::class)->find(ProviderDriver::Anthropic, 'claude-test-1')->attributes());

    $this->artisan('ada:doctor')->doesntExpectOutputToContain('New catalog prices for');
});
