<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Policies;

use Zvizvi\Villament\Tests\Fixtures\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->status !== 'blocked';
    }

    public function view(User $user, User $model): bool
    {
        return true;
    }

    public function update(User $user, User $model): bool
    {
        return ! $model->is_admin;
    }

    public function delete(User $user, User $model): bool
    {
        return (bool) $user->is_admin;
    }
}
