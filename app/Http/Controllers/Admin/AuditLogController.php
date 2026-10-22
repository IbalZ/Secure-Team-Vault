<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditChainVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, AuditChainVerifier $verifier): Renderable
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor' => ['nullable', 'integer', 'exists:users,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $displayTimezone = config('app.timezone', 'Asia/Kuala_Lumpur');
        $localDay = ! empty($filters['date'])
            ? CarbonImmutable::parse($filters['date'], $displayTimezone)->startOfDay()
            : null;

        $logs = AuditLog::query()
            ->with('actor')
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($filters['actor'] ?? null, fn ($query, $actor) => $query->where('actor_user_id', $actor))
            ->when($localDay, fn ($query) => $query
                ->where('occurred_at', '>=', $localDay->utc()->format('Y-m-d H:i:s.u'))
                ->where('occurred_at', '<', $localDay->addDay()->utc()->format('Y-m-d H:i:s.u')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'verification' => $verifier->verify(),
            'displayTimezone' => $displayTimezone,
            'displayUtcOffset' => now($displayTimezone)->format('P'),
        ]);
    }
}
