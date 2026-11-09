<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Documents\KeyProvider;
use App\Services\Documents\LocalKeyProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(KeyProvider::class, LocalKeyProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The RBAC tables only accept model_type = 'user' (C26).
        Relation::enforceMorphMap(['user' => User::class]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(6)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // MY-3: UUIDs are stored as CHAR(36) with a binary ASCII collation.
        Blueprint::macro('pnUuid', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->char($column, 36)->charset('ascii')->collation('ascii_bin');
        });
    }
}
