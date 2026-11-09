<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Services\IdentityAuthentication;
use Illuminate\Http\Request;

/** @deprecated Compatibility aliases; routes/auth.php uses the Breeze controller. */
final class SessionController extends AuthenticatedSessionController
{
    public function merchantCreate(Request $r)
    {
        return $this->create($r);
    }

    public function staffCreate(Request $r)
    {
        return $this->create($r);
    }

    public function merchantStore(LoginRequest $r, IdentityAuthentication $a)
    {
        return $this->store($r, $a);
    }

    public function staffStore(LoginRequest $r, IdentityAuthentication $a)
    {
        return $this->store($r, $a);
    }

    public function merchantDestroy(Request $r, IdentityAuthentication $a)
    {
        return $this->destroy($r, $a);
    }

    public function staffDestroy(Request $r, IdentityAuthentication $a)
    {
        return $this->destroy($r, $a);
    }
}
