<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\AccountLinks;
use App\Http\Controllers\Controller;
use App\Http\Requests\SecurityRequest;
use Illuminate\Http\JsonResponse;

class AccountLinkController extends Controller
{
    public function activate(SecurityRequest $request, AccountLinks $links): JsonResponse
    {
        return $this->consume($request, $links, 'activation');
    }

    public function resetPassword(SecurityRequest $request, AccountLinks $links): JsonResponse
    {
        return $this->consume($request, $links, 'reset');
    }

    private function consume(SecurityRequest $request, AccountLinks $links, string $kind): JsonResponse
    {
        $links->consume($kind, $request->validated('email'), $request->validated('token'), $request->validated('password'));
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => null, 'message' => 'Account security operation completed. Sign in with your password.']);
    }
}
