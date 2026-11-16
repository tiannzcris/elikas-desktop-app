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
        //
    }

    public function boot(): void
    {
        // The shell's "waiting to be sent" figure (top bar, logout warning).
        View::composer('layouts.app', function ($view) {
            $view->with('pendingTotal', Schema::hasTable('app_state') ? app(LocalData::class)->pendingCount() : 0);
        });
    }
}
