<?php

namespace App\Providers;

use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBlocker;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Policies\MeetingPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\TaskBlockerPolicy;
use App\Policies\TaskCommentPolicy;
use App\Policies\TaskPolicy;
use App\Policies\UserPolicy;
use App\Policies\WebhookDeliveryPolicy;
use App\Scopes\OrgScope;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;


class AppServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     */
    protected array $policies = [
        Task::class => TaskPolicy::class,
        Meeting::class => MeetingPolicy::class,
        Organization::class => OrganizationPolicy::class,
        User::class => UserPolicy::class,
        TaskComment::class => TaskCommentPolicy::class,
        TaskBlocker::class => TaskBlockerPolicy::class,
        WebhookDelivery::class => WebhookDeliveryPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.url') && str_starts_with(config('app.url'), 'https://')) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }

        Schema::defaultStringLength(191);


        // Register policies
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Section 18: Rate limit the X-API-Key routes at 300 requests/minute
        RateLimiter::for('n8n-api', function (Request $request) {
            $key = $request->header('X-API-Key') ?: $request->ip();
            return Limit::perMinute(300)->by($key);
        });

        // Flush OrgScope's cached org_id whenever the authenticated user changes
        // so the new user's org is applied correctly on the very next query.
        Event::listen(Login::class, fn () => OrgScope::flush());
        Event::listen(Logout::class, fn () => OrgScope::flush());
    }
}
