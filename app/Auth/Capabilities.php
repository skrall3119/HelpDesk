<?php

namespace App\Auth;

use App\Models\User;

final class Capabilities
{
    public const ALL = ['tickets.read', 'tickets.write', 'tickets.claim', 'tickets.assign', 'tickets.status', 'tickets.delete', 'tickets.restore', 'clients.write', 'clients.lifecycle', 'comments.write', 'comments.moderate', 'comments.edit_any', 'users.manage', 'settings.manage'];

    public static function for(User $user): array
    {
        return match ($user->role) {
            'administrator' => self::ALL,
            'manager' => array_values(array_diff(self::ALL, ['comments.edit_any', 'users.manage', 'settings.manage'])),
            'technician' => ['tickets.read', 'tickets.write', 'tickets.claim', 'tickets.status', 'clients.write', 'comments.write'],
            default => [],
        };
    }
}
