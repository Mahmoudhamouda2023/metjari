<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterMerchantRequest;
use App\Services\IdentityAuthentication;
use GazaDelivery\Foundation\Services\MerchantRegistration;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Breeze registration scaffold, preserving merchant approval and store creation. */
class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterMerchantRequest $request, MerchantRegistration $registration, IdentityAuthentication $authentication): RedirectResponse
    {
        $user = $registration->register($request->validated());
        event(new Registered($user));
        $authentication->establishMerchantSession($request, $user);

        return redirect()->route('merchant.status')->with('status', 'وصلنا طلب تسجيل متجرك. سنراجع البيانات ونتواصل معك.');
    }
}
