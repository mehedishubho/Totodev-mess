<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\Mess;
use App\Models\MessMember;
use App\Models\Meal;
use App\Models\Bazar;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use App\Policies\MessPolicy;
use App\Policies\MessMemberPolicy;
use App\Policies\MealPolicy;
use App\Policies\BazarPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\PaymentPolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Mess::class => MessPolicy::class,
        MessMember::class => MessMemberPolicy::class,
        Meal::class => MealPolicy::class,
        Bazar::class => BazarPolicy::class,
        Expense::class => ExpensePolicy::class,
        Payment::class => PaymentPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Implicitly grant "Super Admin" role all permissions
        Gate::before(function (User $user, string $ability) {
            if ($user->hasRole('super_admin')) {
                return true;
            }
        });
    }
}
