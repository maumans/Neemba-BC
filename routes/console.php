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

/* RG-M04-09 : bons dont l'étape en cours n'a plus aucun valideur possible → niveau supérieur */
Schedule::command('bons:sauter-etapes')->hourly();

/* M12 : visas d'ordres de mission en retard, relance à l'échéance puis escalade au double du délai (§6.7) */
Schedule::command('odm:relancer-visas')->hourly();

/* RG-M12-28 : rappel au demandeur 2 jours ouvrés avant la fin d'un segment de mission */
Schedule::command('odm:rappeler-fin-segment')->dailyAt('07:00');

/* Alertes d'expiration des archives légales (J-30, J-7, J-1) */
Schedule::command('archives:alerter-expiration')->dailyAt('08:30');

/* Vérification proactive des seuils de caisse : alertes SMS + push aux caissiers (2× par jour) */
Schedule::command('caisse:verifier-seuils')->dailyAt('08:00');
Schedule::command('caisse:verifier-seuils')->dailyAt('14:00');

/* RG-BC-26 : annulation des brouillons non modifiés depuis 30 jours, avec notification in-app */
Schedule::command('bons:annuler-brouillons-abandonnes')->dailyAt('02:00');
