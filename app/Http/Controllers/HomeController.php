<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\VaultCredential;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function index(Request $request): Renderable
    {
        $user = $request->user();
        $visibleCredentials = VaultCredential::query()->visibleTo($user);

        return view('home', [
            'stats' => [
                'credentials' => (clone $visibleCredentials)->count(),
                'owned' => VaultCredential::query()->where('owner_id', $user->id)->count(),
                'active_users' => $user->isAdmin()
                    ? User::query()->where('is_active', true)->count()
                    : null,
                'audit_events' => $user->isAdmin() ? AuditLog::query()->count() : null,
            ],
            'recentCredentials' => (clone $visibleCredentials)
                ->with('owner')
                ->latest('updated_at')
                ->limit(5)
                ->get(),
            'recentActivity' => AuditLog::query()
                ->with('actor')
                ->when(! $user->isAdmin(), fn ($query) => $query->where('actor_user_id', $user->id))
                ->latest('id')
                ->limit(6)
                ->get(),
        ]);
    }
}
