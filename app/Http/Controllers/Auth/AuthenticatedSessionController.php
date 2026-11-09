<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\IdentityAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Laravel Breeze Blade session controller, preserving the two existing login portals. */
class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.login', ['guard' => $request->routeIs('staff.login') ? 'staff' : 'merchant']);
    }

    public function store(LoginRequest $request, IdentityAuthentication $authentication): RedirectResponse
    {
        $account = $request->authenticate();
        $request->session()->regenerate();

        return redirect($request->guardName() === 'staff' ? $authentication->staffHome($account) : route('merchant.dashboard'));
    }

    public function destroy(Request $request, IdentityAuthentication $authentication): RedirectResponse
    {
        $staff = $request->routeIs('staff.logout');
        $authentication->logout($request);

        return redirect()->route($staff ? 'staff.login' : 'login');
    }
}
