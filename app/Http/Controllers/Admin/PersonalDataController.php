<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\PersonalData\Detectors;
use App\Domain\PersonalData\Masker;
use App\Domain\PersonalData\PersonalDataLabels;
use App\Domain\PersonalData\PersonalDataScanner;
use App\Domain\PersonalData\PersonalDataSettings;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What happens to personal data in messages before they reach a provider
 * (docs/personal-data.md).
 */
class PersonalDataController extends Controller
{
    public function edit(PersonalDataSettings $settings): Response
    {
        return Inertia::render('admin/personal-data', [
            'rules' => array_merge(array_fill_keys(Detectors::KINDS, 'off'), $settings->rules),
            'patterns' => $settings->patterns,
        ]);
    }

    public function update(Request $request, PersonalDataSettings $settings, AuditLogger $audit): RedirectResponse
    {
        [$rules, $patterns] = $this->validated($request);
        $before = $settings->toArray();

        $settings->rules = $rules;
        $settings->patterns = $patterns;
        $settings->save();

        [$old, $new] = AuditLogger::diff($before, $settings->toArray());

        if ($new !== []) {
            $audit->record('personal_data.settings_updated', 'PersonalDataSettings', $old, $new);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.personal-data.edit');
    }

    /**
     * Shows what the rules on the form (saved or not) find in a sample
     * text and what would be sent for it.
     */
    public function test(Request $request): JsonResponse
    {
        $request->validate(['sample' => ['required', 'string', 'max:5000']]);
        [$rules, $patterns] = $this->validated($request);

        $scanner = new PersonalDataScanner($rules, $patterns);
        $sample = (string) $request->input('sample');

        return response()->json([
            'found' => array_map(fn ($detection) => [
                'kind' => PersonalDataLabels::label($detection->kind),
                'action' => $scanner->action($detection->kind),
                'value' => $detection->value,
            ], $scanner->scan($sample)),
            'sent' => (new Masker($scanner))->mask($sample),
        ]);
    }

    /**
     * @return array{0: array<string, string>, 1: list<array{name: string, pattern: string, action: string}>}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'rules' => ['required', 'array'],
            ...array_combine(
                array_map(fn (string $kind) => "rules.{$kind}", Detectors::KINDS),
                array_fill(0, count(Detectors::KINDS), ['required', Rule::in(PersonalDataScanner::ACTIONS)]),
            ),
            'patterns' => ['present', 'array', 'max:20'],
            'patterns.*.name' => ['required', 'string', 'max:40', 'distinct:ignore_case', Rule::notIn(Detectors::KINDS), 'regex:/^[\pL\pN][\pL\pN ._-]*$/u'],
            'patterns.*.pattern' => ['required', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! PersonalDataScanner::validPattern($value)) {
                    $fail(__('admin.personal_data_invalid_pattern'));
                } elseif (@preg_match("\x01".$value."\x01u", '') === 1) {
                    // A pattern that matches nothing at all would match everywhere.
                    $fail(__('admin.personal_data_empty_pattern'));
                }
            }],
            'patterns.*.action' => ['required', Rule::in(PersonalDataScanner::ACTIONS)],
        ]);

        $rules = [];

        foreach (Detectors::KINDS as $kind) {
            $rules[$kind] = (string) $validated['rules'][$kind];
        }

        $patterns = array_values(array_map(fn (array $pattern) => [
            'name' => trim((string) $pattern['name']),
            'pattern' => (string) $pattern['pattern'],
            'action' => (string) $pattern['action'],
        ], (array) $validated['patterns']));

        return [$rules, $patterns];
    }
}
