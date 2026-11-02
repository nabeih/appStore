<?php

namespace App\Support\Access;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

class ActivePasswordUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        $user = parent::retrieveByCredentials($credentials);

        return $user instanceof User && $user->is_active && ! $user->trashed()
            && (! $user->role_id || $user->role?->is_active) ? $user : null;
    }
}
