<?php

namespace App\Providers;

use App\Services\LocalData;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Security\LocalDataKey::class);
    }

    public function boot(): void
    {
        // Personal data is encrypted at rest with this computer's own key,
        // never APP_KEY (the same in every installer).
        \Illuminate\Database\Eloquent\Model::encryptUsing(new \App\Security\LazyLocalEncrypter);

        // The shell's "waiting to be sent" figure (top bar, logout warning).
        View::composer('layouts.app', function ($view) {
            $view->with('pendingTotal', Schema::hasTable('app_state') ? app(LocalData::class)->pendingCount() : 0);
            // Pages that don't pass the signed-in user (About) still get the shell.
            if (! isset($view->getData()['currentUser']) && Schema::hasTable('local_auth')) {
                $view->with('currentUser', \App\Models\LocalAuth::current());
            }
            // Transfer requests waiting for this account (the sidebar count).
            $auth = $view->getData()['currentUser'] ?? (Schema::hasTable('local_auth') ? \App\Models\LocalAuth::current() : null);
            $view->with('transfersWaiting', $auth?->hasOperationsAccess() && Schema::hasTable('server_snapshots')
                ? app(\App\Services\Transfers::class)->waitingCount($auth, \App\Models\EvacuationEvent::working())
                : 0);
        });
    }
}
