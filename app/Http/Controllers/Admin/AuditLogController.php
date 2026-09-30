<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only view of the audit log. Entries never contain secrets
 * (AuditLogger redacts them before they are written).
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'actor' => ['nullable', 'string', 'max:100'],
            'subject_type' => ['nullable', 'string', 'max:64'],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $actor = trim((string) ($filters['actor'] ?? ''));

        $entries = AuditLog::query()
            ->with('actor:id,name,email')
            ->when(filled($filters['action'] ?? null), fn (Builder $query) => $query->where('action', $filters['action']))
            ->when($actor !== '', fn (Builder $query) => $query->whereHas('actor', fn (Builder $query) => $query
                ->where('name', 'like', '%'.addcslashes($actor, '%_\\').'%')
                ->orWhere('email', 'like', '%'.addcslashes($actor, '%_\\').'%')))
            ->when(filled($filters['subject_type'] ?? null), fn (Builder $query) => $query->where('subject_type', $filters['subject_type']))
            ->when(filled($filters['subject_id'] ?? null), fn (Builder $query) => $query->where('subject_id', $filters['subject_id']))
            ->when(filled($filters['from'] ?? null), fn (Builder $query) => $query->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(filled($filters['to'] ?? null), fn (Builder $query) => $query->where('created_at', '<=', $filters['to'].' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Name users who are the subject of an entry (e.g. "user.role_changed").
        $userIds = $entries->getCollection()->where('subject_type', 'User')->pluck('subject_id')->unique()->all();
        $subjects = User::query()->whereKey($userIds)->get(['id', 'name', 'email'])->keyBy('id');

        return Inertia::render('admin/audit-log/index', [
            'entries' => $entries->through(fn (AuditLog $entry) => [
                'id' => $entry->id,
                'created_at' => $entry->created_at->toIso8601String(),
                'actor' => $entry->actor === null ? null : ['id' => $entry->actor->id, 'name' => $entry->actor->name, 'email' => $entry->actor->email],
                'actor_type' => $entry->actor_type,
                'action' => $entry->action,
                'subject_type' => $entry->subject_type,
                'subject_id' => $entry->subject_id,
                'subject_label' => $entry->subject_type === 'User' ? $subjects->get((int) $entry->subject_id)?->email : null,
                'old_values' => $entry->old_values,
                'new_values' => $entry->new_values,
                'ip_address' => $entry->ip_address,
            ]),
            'filters' => [
                'action' => $filters['action'] ?? null,
                'actor' => $actor,
                'subject_type' => $filters['subject_type'] ?? null,
                'subject_id' => $filters['subject_id'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'subjectTypes' => AuditLog::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }
}
