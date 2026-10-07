<?php

namespace Tests\Unit;

use App\Support\Format;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Conventions d'affichage (SFD §1.4), mêmes cas que tests/js/format.test.js.
 */
class FormatTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    public function test_montants(): void
    {
        $this->assertSame('1' . self::NBSP . '500' . self::NBSP . '000' . self::NBSP . 'GNF', Format::montant(1500000));
        $this->assertSame('0' . self::NBSP . 'GNF', Format::montant(null));
        $this->assertSame('-150' . self::NBSP . '000', Format::nombre(-150000));
        $this->assertSame('2' . self::NBSP . '377', Format::nombre(2376.6));
    }

    public function test_dates(): void
    {
        $this->assertSame('05/10/2026', Format::date('2026-10-05'));
        $this->assertSame('05/10/2026 14:30', Format::dateHeure(Carbon::parse('2026-10-05 14:30:59')));
        $this->assertSame('—', Format::date(null));
    }

    public function test_durees(): void
    {
        $this->assertSame('45' . self::NBSP . 'min', Format::duree(45));
        $this->assertSame('2' . self::NBSP . 'h 15' . self::NBSP . 'min', Format::duree(135));
        $this->assertSame('2' . self::NBSP . 'h', Format::duree(120));
        $this->assertSame('3' . self::NBSP . 'j 4' . self::NBSP . 'h', Format::duree(3 * 1440 + 4 * 60 + 59));
        $this->assertSame('35' . self::NBSP . 'j', Format::duree(35 * 1440));
        $this->assertSame('3' . self::NBSP . 'j 4' . self::NBSP . 'h', Format::dureeEntre('2026-08-01 08:00', '2026-08-04 12:00'));
    }

    public function test_les_sms_n_ont_pas_d_espace_insecable(): void
    {
        $this->assertSame('1 500 000 GNF', Format::pourSms(Format::montant(1500000)));
    }
}
