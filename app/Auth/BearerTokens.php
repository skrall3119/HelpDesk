<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BearerTokens
{
    public function authenticate(Request $request): ?User
    {
        $token = $request->bearerToken();
        $tenantId = $request->attributes->get('tenant_id');
        if (! $tenantId || ! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }

        $record = DB::table('access_tokens')->where('tenant_id', $tenantId)
            ->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now()->toIso8601String())->first();
        if (! $record) {
            return null;
        }

        $user = User::where('tenant_id', $tenantId)->whereKey($record->user_id)
            ->where('active', true)->whereNull('deleted_at')->first();
        if (! $user || ! hash_equals($record->password_fingerprint, hash('sha256', $user->password))) {
            return null;
        }

        $request->attributes->set('access_token_id', $record->id);
        DB::selectOne("SELECT set_config('app.user_id', ?, true)", [(string) $user->id]);

        return $user;
    }

    /** @return array{access_token: string, token_type: string, expires_at: string} */
    public function issue(User $user): array
    {
        $plain = bin2hex(random_bytes(32));
        $created = now()->startOfSecond();
        $expires = $created->copy()->addHours(48);
        DB::table('access_tokens')->insert([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'password_fingerprint' => hash('sha256', $user->password),
            'created_at' => $created->toIso8601String(),
            'expires_at' => $expires->toIso8601String(),
        ]);

        return ['access_token' => $plain, 'token_type' => 'Bearer', 'expires_at' => $expires->toISOString()];
    }
}
