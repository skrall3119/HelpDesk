<?php

namespace App\Http\Controllers;

use App\Auth\BearerTokens;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthenticationController extends Controller
{
    public function login(Request $request, BearerTokens $tokens): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $user = User::where('tenant_id', $request->attributes->get('tenant_id'))
            ->whereRaw('lower(email) = ?', [strtolower($data['email'])])
            ->where('active', true)->whereNull('deleted_at')->first();

        // Pay password hashing cost for unknown accounts as well.
        $valid = false;
        if ($user) {
            $valid = Hash::check($data['password'], $user->password);
        } else {
            Hash::make(bin2hex(random_bytes(32)));
        }
        if (! $user || ! $valid) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        return response()->json([...$tokens->issue($user), 'user' => $this->userData($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userData($request->user('api'))]);
    }

    public function logout(Request $request): Response
    {
        DB::table('access_tokens')->where('tenant_id', $request->attributes->get('tenant_id'))
            ->where('id', $request->attributes->get('access_token_id'))->delete();

        return response()->noContent();
    }

    /** @return array<string, string> */
    private function userData(User $user): array
    {
        return [
            'id' => (string) $user->id, 'tenant_id' => (string) $user->tenant_id,
            'name' => $user->name, 'email' => $user->email, 'role' => $user->role,
        ];
    }
}
