<?php

namespace App\Providers;

use App\Services\LectureTicket\LecteurLocal;
use App\Services\LectureTicket\LecteurManuel;
use App\Services\LectureTicket\LecteurTicket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /* Lecteur des tickets carburant (US-BC-09) : manuel par défaut tant qu'OP-BC-5 n'est pas tranché (décision Q8) */
        $this->app->bind(LecteurTicket::class, fn ($app) => match (config('services.lecture_tickets.lecteur')) {
            'local' => $app->make(LecteurLocal::class),
            default => new LecteurManuel(),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        /* Forcer Carbon en français pour translatedFormat() */
        Carbon::setLocale('fr');
        setlocale(LC_TIME, 'fr_FR.UTF-8', 'fr_FR', 'fr');
    }
}
