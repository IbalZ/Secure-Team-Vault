<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    /**
     * Complete a successful password check only for an active, unexpired account.
     *
     * @throws ValidationException
     */
    protected function authenticated(Request $request, User $user): ?RedirectResponse
    {
        if (! $user->canAccessSystem()) {
            $this->recordAuditSafely($request, $user, 'auth.login_rejected');
            $this->guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                $this->username() => [trans('auth.failed')],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->recordAuditSafely($request, $user, 'auth.login_succeeded');

        return null;
    }

    /**
     * Record unsuccessful password checks without retaining the submitted email.
     *
     * @throws ValidationException
     */
    protected function sendFailedLoginResponse(Request $request): never
    {
        $this->recordAuditSafely($request, null, 'auth.login_failed');

        throw ValidationException::withMessages([
            $this->username() => [trans('auth.failed')],
        ]);
    }

    /**
     * Log the user out and make the old session unusable.
     */
    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();

        try {
            if ($user !== null) {
                app(AuditLogger::class)->record(
                    $user,
                    'auth.logout',
                    'user',
                    $user->getKey(),
                    [],
                    $request,
                );
            }
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            // Logging must never prevent termination of the authenticated session.
            $this->guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect('/');
    }

    private function recordAuditSafely(Request $request, ?User $user, string $action): void
    {
        try {
            app(AuditLogger::class)->record(
                $user,
                $action,
                $user === null ? null : 'user',
                $user?->getKey(),
                [],
                $request,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
