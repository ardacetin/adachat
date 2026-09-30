<?php

use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\User;

function modelPayload(Provider $provider, array $overrides = []): array
{
    return [
        'provider_id' => $provider->id,
        'provider_model_id' => 'gpt-test-1',
        'display_name' => 'GPT Test',
        'description' => '',
        'input_price_per_million' => '1.25',
        'output_price_per_million' => '10',
        'cached_input_price_per_million' => '0.125',
        'cache_write_price_per_million' => '',
        'context_window' => 400000,
        'max_output_tokens' => 128000,
        'supports_vision' => true,
        'supports_files' => false,
        'supports_tools' => false,
        'supports_reasoning' => true,
        'enabled' => true,
        ...$overrides,
    ];
}

function aliasPayload(AiModel $model, array $overrides = []): array
{
    return [
        'slug' => 'ada-smart',
        'name' => ['en' => 'Smart', 'tr' => 'Akıllı'],
        'description' => ['en' => '', 'tr' => ''],
        'ai_model_id' => $model->id,
        'max_output_tokens' => 4096,
        'temperature' => '',
        'system_prompt' => '',
        'show_model_details' => false,
        'sort_order' => 0,
        'enabled' => true,
        ...$overrides,
    ];
}

test('only super admins can manage models and aliases', function (string $route) {
    $this->actingAs(User::factory()->admin()->create())->get(route($route))->assertForbidden();
    $this->actingAs(User::factory()->superAdmin()->create())->get(route($route))->assertOk();
})->with(['admin.models.index', 'admin.models.create', 'admin.aliases.index', 'admin.aliases.create']);

test('a model is stored with exact decimal prices', function () {
    $provider = Provider::factory()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.models.store'), modelPayload($provider))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.models.index'));

    $model = AiModel::query()->sole();
    expect($model->input_price_per_million)->toBe('1.250000')
        ->and($model->cached_input_price_per_million)->toBe('0.125000')
        ->and($model->cache_write_price_per_million)->toBeNull()
        ->and(AuditLog::query()->sole()->action)->toBe('ai_model.created');
});

test('model validation', function (array $overrides, string $error) {
    $provider = Provider::factory()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.models.store'), modelPayload($provider, $overrides))
        ->assertSessionHasErrors($error);
})->with([
    'too many decimals' => [['input_price_per_million' => '0.0000001'], 'input_price_per_million'],
    'negative price' => [['output_price_per_million' => '-1'], 'output_price_per_million'],
    'output above context' => [['max_output_tokens' => 500000], 'max_output_tokens'],
    'bad model id' => [['provider_model_id' => 'has space'], 'provider_model_id'],
]);

test('price changes are audited with old and new values', function () {
    $model = AiModel::factory()->create(['provider_model_id' => 'gpt-test-1']);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.models.update', $model), modelPayload($model->provider, [
            'output_price_per_million' => '12.5',
            'context_window' => $model->context_window,
            'max_output_tokens' => $model->max_output_tokens,
            'display_name' => $model->display_name,
            'input_price_per_million' => $model->input_price_per_million,
            'cached_input_price_per_million' => '',
            'supports_vision' => false,
            'supports_reasoning' => false,
        ]))
        ->assertSessionHasNoErrors();

    $log = AuditLog::query()->where('action', 'ai_model.updated')->sole();
    expect($log->old_values)->toEqual(['output_price_per_million' => '10.000000'])
        ->and($log->new_values)->toEqual(['output_price_per_million' => '12.500000']);
});

test('an alias is stored with localized names', function () {
    $model = AiModel::factory()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.aliases.store'), aliasPayload($model))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.aliases.index'));

    $alias = ModelAlias::query()->sole();
    expect($alias->name)->toEqual(['en' => 'Smart', 'tr' => 'Akıllı'])
        ->and($alias->description)->toBeNull()
        ->and($alias->localizedName('tr'))->toBe('Akıllı')
        ->and(AuditLog::query()->sole()->action)->toBe('model_alias.created');
});

test('an alias cannot allow more output than its model', function () {
    $model = AiModel::factory()->create(['max_output_tokens' => 8192]);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.aliases.store'), aliasPayload($model, ['max_output_tokens' => 9000]))
        ->assertSessionHasErrors('max_output_tokens');
});

test('every locale needs an alias name', function () {
    $model = AiModel::factory()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.aliases.store'), aliasPayload($model, ['name' => ['en' => 'Smart']]))
        ->assertSessionHasErrors('name.tr');
});

test('changing the backing model is audited', function () {
    $alias = ModelAlias::factory()->create(['slug' => 'ada-smart']);
    $other = AiModel::factory()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.aliases.update', $alias), aliasPayload($other, [
            'name' => $alias->name,
            'max_output_tokens' => '',
        ]))
        ->assertSessionHasNoErrors();

    $log = AuditLog::query()->where('action', 'model_alias.updated')->sole();
    expect($log->old_values['ai_model_id'])->toBe($alias->ai_model_id)
        ->and($log->new_values['ai_model_id'])->toBe($other->id);
});

test('new aliases are available to the default group', function () {
    $model = AiModel::factory()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.aliases.store'), aliasPayload($model))
        ->assertSessionHasNoErrors();

    expect(ModelAlias::query()->sole()->groups->pluck('id')->all())->toBe([Group::default()->id])
        ->and(AuditLog::query()->sole()->new_values['group_ids'])->toBe([Group::default()->id]);
});

test('changing an alias\'s groups is audited', function () {
    $alias = ModelAlias::factory()->create(['slug' => 'ada-smart']);
    $alias->groups()->attach(Group::default());
    $staff = Group::factory()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.aliases.update', $alias), aliasPayload($alias->aiModel, [
            'name' => $alias->name,
            'max_output_tokens' => '',
            'group_ids' => [$staff->id],
        ]))
        ->assertSessionHasNoErrors();

    expect($alias->groups()->pluck('groups.id')->all())->toBe([$staff->id]);

    $log = AuditLog::query()->where('action', 'model_alias.updated')->sole();
    expect($log->old_values['group_ids'])->toBe([Group::default()->id])
        ->and($log->new_values['group_ids'])->toBe([$staff->id]);
});

test('alias groups must exist', function () {
    $model = AiModel::factory()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.aliases.store'), aliasPayload($model, ['group_ids' => [999999]]))
        ->assertSessionHasErrors('group_ids.0');
});
