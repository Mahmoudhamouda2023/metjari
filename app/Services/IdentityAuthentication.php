<?php

declare(strict_types=1);

namespace App\Services;

use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class IdentityAuthentication
{
    public function establishMerchantSession(Request $request, User $user): void
    {
        $user = $user->fresh();
        $this->logout($request);
        Auth::guard('merchant')->login($user, false);
        $request->session()->put('delivery_auth.merchant.version', $user->session_version);
    }

    public function logout(Request $request): void
    {
        Auth::guard('staff')->logout();
        Auth::guard('merchant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function staffHome(Staff $staff): string
    {
        if ($staff->must_change_password) {
            return route('staff.password.edit');
        }
        if ($staff->hasAnyRole(['super_admin', 'operations', 'finance'])) {
            return route('admin.dashboard');
        }

        return route('captain.dashboard');
    }
}
