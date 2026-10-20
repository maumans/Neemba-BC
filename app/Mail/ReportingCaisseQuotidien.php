<?php

namespace App\Mail;

use App\Models\ClotureCaisse;
use App\Services\Caisse\RapportsCaisse;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Spec v2.4, RG-M09-04 : rapport « REPORTING CAISSE » d'une journée close, en PDF et en Excel, envoyé avant 8 h aux
 * destinataires de la caisse.
 */
class ReportingCaisseQuotidien extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ClotureCaisse $cloture)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'REPORTING CAISSE — ' . $this->cloture->caisse->libelle . ' — ' . $this->cloture->date_journee->format('d/m/Y'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reporting-caisse', with: [
            'caisse' => $this->cloture->caisse->libelle,
            'date' => $this->cloture->date_journee->format('d/m/Y'),
            'cloture' => $this->cloture,
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $caisse = $this->cloture->caisse;
        $jour = $this->cloture->date_journee;
        $nom = RapportsCaisse::nomFichier($caisse, $jour, $jour);

        return [
            Attachment::fromData(fn () => RapportsCaisse::pdf($caisse, $jour, $jour)->output(), "{$nom}.pdf")->withMime('application/pdf'),
            Attachment::fromData(fn () => RapportsCaisse::excel($caisse, $jour, $jour), "{$nom}.xlsx")
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
