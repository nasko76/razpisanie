<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;

class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view plans');
    }

    public function view(User $user, Plan $plan): bool
    {
        return $user->can('view plans');
    }

    public function create(User $user): bool
    {
        return $user->can('create plans');
    }

    public function update(User $user, Plan $plan): bool
    {
        return $user->can('edit plans');
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $user->can('delete plans');
    }
}