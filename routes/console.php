<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/* RG-M07-04 : relance des BP non régularisés chaque vendredi, au nom du caissier, avec les copies prévues */
Schedule::command('bons:relancer-regularisation')->fridays()->at('08:00');

/* RG-M07-05 : BP dont l'échéance est dépassée transmis aux RH */
Schedule::command('bons:transmettre-bp-rh')->dailyAt('08:15');

/* RG-M07-07 : rapport mensuel des BP non régularisés au Directeur Pays */
Schedule::command('bons:rapport-bp-mensuel')->monthlyOn(1, '08:00');

/* Spec v2.4, RG-M09-03, RG-M09-04 : clôture des journées de caisse restées ouvertes à 23 h 45, puis envoi du rapport
 * « REPORTING CAISSE » de la veille, par caisse, avant 8 h */
Schedule::command('caisse:cloturer-journees')->dailyAt('23:45');
Schedule::command('rapports:envoyer-quotidien')->dailyAt('07:00');

/* Spec v2.4, RG-M10-01 : inventaires de fin de mois et contrôles inopinés (rappels et alertes au Chef comptable) */
Schedule::command('caisse:verifier-inventaires')->dailyAt('08:15');

/* Vérification SLA validations : relances et escalades automatiques (toutes les heures) */
Schedule::command('validations:relancer-sla')->hourly();

/* RG-M04-09 : bons dont l'étape en cours n'a plus aucun valideur possible → niveau supérieur */
Schedule::command('bons:sauter-etapes')->hourly();

/* M12 : visas d'ordres de mission en retard, relance à l'échéance puis escalade au double du délai (§6.7) */
Schedule::command('odm:relancer-visas')->hourly();

/* RG-M12-28 : rappel au demandeur 2 jours ouvrés avant la fin d'un segment de mission */
Schedule::command('odm:rappeler-fin-segment')->dailyAt('07:00');

/* Spec v2.4, RG-M12-40 : OR à régulariser (relance J+3, échéance, retard) */
Schedule::command('odm:relancer-or')->dailyAt('07:15');

/* M16, RG-M16-04 : bons payés non imputés à J+1 ouvré, alerte au Chef comptable */
Schedule::command('comptabilite:alerter-non-imputes')->dailyAt('08:00');

/* Alertes d'expiration des archives légales (J-30, J-7, J-1) */
Schedule::command('archives:alerter-expiration')->dailyAt('08:30');

/* Spec v2.4, RG-M08-03 : rappel quotidien des caisses sous leur seuil (l'alerte part au franchissement) */
Schedule::command('caisse:verifier-seuils')->dailyAt('08:00');

/* Spec v2.4, RG-M08-13 : chaque lundi, rappel de la réconciliation Orange Money de la semaine écoulée */
Schedule::command('caisse:rappeler-reconciliation-om')->weeklyOn(1, '09:00');

/* RG-BC-26 : annulation des brouillons non modifiés depuis 30 jours, avec notification in-app */
Schedule::command('bons:annuler-brouillons-abandonnes')->dailyAt('02:00');
