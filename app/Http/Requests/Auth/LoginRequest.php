<?php

namespace App\Http\Requests\Auth;

use App\Services\IdentityAuthentication;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\User;
use GazaDelivery\Foundation\Support\PhoneNumber;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** Laravel Breeze 2.4.2 Blade LoginRequest, adapted to the existing phone and guard policies. */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:40'], 'password' => ['required', 'string', 'max:256']];
    }

    public function guardName(): string
    {
        return $this->routeIs('staff.login.store') ? 'staff' : 'merchant';
    }

    public function authenticate(): Staff|User
    {
        $guard = $this->guardName();
        $this->ensureIsNotRateLimited();
        $phone = PhoneNumber::normalize($this->input('phone'));
        $account = DB::transaction(function () use ($guard, $phone) {
            $auth = Auth::guard($guard);
            $allowed = $guard === 'staff' ? ['active'] : ['active', 'pending'];
            $credentials = ['phone' => $phone, 'password' => $this->input('password'), fn (Builder $q) => $q->whereIn('status', $allowed)->lockForUpdate()];
            if (! $auth->attempt($credentials, false)) {
                return null;
            }
            $account = $auth->user();
            app(IdentityAuthentication::class)->logout($this);
            $auth->login($account, false);
            $account->forceFill(['last_login_at' => now()])->save();
            $this->session()->put('delivery_auth.'.$guard.'.version', $account->session_version);
            Auth::shouldUse($guard);

            return $account;
        });
        if (! $account) {
            RateLimiter::hit($this->throttleKey(), 60);
            RateLimiter::hit($this->accountThrottleKey(), 900);
            throw ValidationException::withMessages(['phone' => 'تعذّر تسجيل الدخول. تحقق من البيانات أو حالة الحساب.']);
        }
        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->accountThrottleKey());

        return $account;
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5) && ! RateLimiter::tooManyAttempts($this->accountThrottleKey(), 20)) {
            return;
        }
        event(new Lockout($this));
        abort(429, 'محاولات كثيرة. انتظر قليلًا ثم حاول مجددًا.');
    }

    public function throttleKey(): string
    {
        return hash('sha256', 'login|'.$this->guardName().'|'.PhoneNumber::normalize($this->input('phone')).'|'.$this->ip());
    }

    private function accountThrottleKey(): string
    {
        return hash('sha256', 'account-login|'.$this->guardName().'|'.PhoneNumber::normalize($this->input('phone')));
    }
}
