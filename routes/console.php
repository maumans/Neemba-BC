<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/* Relance quotidienne des BP non régularisés dans les délais */
Schedule::command('bons:relancer-regularisation')->dailyAt('08:00');

/* Génération et envoi automatique du rapport journalier de caisse (J+0 avant 8h) */
Schedule::command('rapports:envoyer-quotidien')->dailyAt('07:30');

/* Vérification SLA validations : relances et escalades automatiques (toutes les heures) */
Schedule::command('validations:relancer-sla')->hourly();

/* Alertes d'expiration des archives légales (J-30, J-7, J-1) */
Schedule::command('archives:alerter-expiration')->dailyAt('08:30');

/* Vérification proactive des seuils de caisse : alertes SMS + push aux caissiers (2× par jour) */
Schedule::command('caisse:verifier-seuils')->dailyAt('08:00');
Schedule::command('caisse:verifier-seuils')->dailyAt('14:00');
