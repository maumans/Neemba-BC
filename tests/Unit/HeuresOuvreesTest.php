<?php

namespace Tests\Unit;

use App\Models\Parametre;
use App\Support\HeuresOuvrees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Heures ouvrées (spec v2.3, §6.7) : les délais de visa ne courent que du lundi au vendredi, de 8 h à 17 h,
 * hors jours fériés.
 */
class HeuresOuvreesTest extends TestCase
{
    use RefreshDatabase;

    /** 8 h – 17 h, soit 9 heures ouvrées par jour */
    public function test_les_bornes_par_defaut(): void
    {
        $this->assertSame([8 * 60, 17 * 60], HeuresOuvrees::bornes());
        $this->assertSame(540, HeuresOuvrees::minutesParJour());
    }

    public function test_un_delai_dans_la_journee_ne_deborde_pas(): void
    {
        /* Mardi 1er septembre 2026, 9 h + 4 h ouvrées = 13 h le même jour */
        $this->assertSame('2026-09-01 13:00', $this->echeance('2026-09-01 09:00', 4));
    }

    public function test_un_delai_reprend_le_lendemain_matin(): void
    {
        /* Mardi 16 h + 4 h : 1 h avant la fermeture, puis 3 h le mercredi dès 8 h */
        $this->assertSame('2026-09-02 11:00', $this->echeance('2026-09-01 16:00', 4));
    }

    public function test_le_week_end_ne_compte_pas(): void
    {
        /* Vendredi 4 septembre 16 h + 4 h : 1 h vendredi, puis 3 h lundi 7 dès 8 h */
        $this->assertSame('2026-09-07 11:00', $this->echeance('2026-09-04 16:00', 4));
    }

    public function test_un_depart_hors_bureau_part_de_la_prochaine_ouverture(): void
    {
        /* Soumis le samedi : le délai court du lundi 8 h */
        $this->assertSame('2026-09-07 12:00', $this->echeance('2026-09-05 15:30', 4));
        /* Soumis après la fermeture : le délai court du lendemain 8 h */
        $this->assertSame('2026-09-02 12:00', $this->echeance('2026-09-01 19:00', 4));
        /* Soumis avant l'ouverture : le délai court à 8 h le jour même */
        $this->assertSame('2026-09-01 12:00', $this->echeance('2026-09-01 06:00', 4));
    }

    public function test_un_jour_ferie_ne_compte_pas(): void
    {
        /* 2 octobre : fête de l'indépendance. Jeudi 1er 16 h + 4 h → vendredi 2 férié → lundi 5 à 11 h */
        Parametre::majValeur('jours_feries', '10-02');
        $this->assertSame('2026-10-05 11:00', $this->echeance('2026-10-01 16:00', 4));
    }

    public function test_un_delai_de_24_heures_couvre_presque_trois_jours(): void
    {
        /* 24 h ouvrées = 9 h + 9 h + 6 h : mardi 8 h → jeudi 14 h */
        $this->assertSame('2026-09-03 14:00', $this->echeance('2026-09-01 08:00', 24));
    }

    public function test_les_bornes_sont_parametrables(): void
    {
        Parametre::majValeur('heure_ouverture', '07:30');
        Parametre::majValeur('heure_fermeture', '16:00');
        $this->assertSame([450, 960], HeuresOuvrees::bornes());
        /* Mardi 15 h + 2 h : 1 h avant la fermeture, puis 1 h le mercredi dès 7 h 30 */
        $this->assertSame('2026-09-02 08:30', $this->echeance('2026-09-01 15:00', 2));
    }

    public function test_des_bornes_incoherentes_reviennent_au_defaut(): void
    {
        Parametre::majValeur('heure_ouverture', '18:00');
        Parametre::majValeur('heure_fermeture', '09:00');
        $this->assertSame([8 * 60, 17 * 60], HeuresOuvrees::bornes());
    }

    public function test_l_echeance_passee_est_signalee(): void
    {
        Carbon::setTestNow('2026-09-01 14:00');
        $this->assertTrue(HeuresOuvrees::echu(Carbon::parse('2026-09-01 09:00'), 4));
        $this->assertFalse(HeuresOuvrees::echu(Carbon::parse('2026-09-01 09:00'), 6));
        Carbon::setTestNow();
    }

    private function echeance(string $depart, float $heures): string
    {
        return HeuresOuvrees::ajouter(Carbon::parse($depart), $heures)->format('Y-m-d H:i');
    }
}
