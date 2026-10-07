<?php

namespace App\Jobs;

use App\Models\LectureTicket;
use App\Services\LectureTicket\LectureTickets;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Lecture d'un ticket carburant en tâche de fond (RG-BC-20) : 60 secondes au plus, une seule tentative.
 * En cas d'échec, le panneau s'ouvre avec des champs vides à remplir à la main.
 */
class LireTicketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = LectureTickets::DELAI_MAX_SECONDES;

    public function __construct(public int $lectureId) {}

    public function handle(): void
    {
        if ($lecture = LectureTicket::find($this->lectureId)) {
            LectureTickets::executer($lecture);
        }
    }

    public function failed(\Throwable $erreur): void
    {
        LectureTicket::whereKey($this->lectureId)
            ->where('statut', LectureTicket::EN_COURS)
            ->update(['statut' => LectureTicket::INDISPONIBLE, 'terminee_le' => now()]);
    }
}
