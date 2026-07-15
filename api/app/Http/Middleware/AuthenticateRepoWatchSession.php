<?php

namespace App\Http\Middleware;

use App\Http\Resources\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateRepoWatchSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->session()->get('repo_watch_identity');

        if (! is_array($identity) || ! isset($identity['id'], $identity['name'])) {
            return ApiResponse::unauthorized('请先通过 DogeOW 统一登录');
        }

        $user = new User($identity);
        $request->setUserResolver(static fn (): User => $user);
        Auth::setUser($user);

        return $next($request);
    }
}
