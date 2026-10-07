<?php

namespace Tests\Unit;

use App\Support\MontantEnLettres;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RG-BC-08, ANO-01, TC-BC-007 — cas communs avec le test JS (tests/fixtures/montants_lettres.json).
 */
class MontantEnLettresTest extends TestCase
{
    public static function cas(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../fixtures/montants_lettres.json'), true);

        return collect($fixture['cas'])
            ->mapWithKeys(fn ($cas) => [(string) $cas[0] => $cas])
            ->all();
    }

    #[DataProvider('cas')]
    public function test_montant_en_lettres(int $montant, string $attendu): void
    {
        $this->assertSame($attendu, MontantEnLettres::convertir($montant));
    }
}
