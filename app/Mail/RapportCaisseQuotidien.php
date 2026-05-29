<?php

namespace App\Mail;

use App\Exports\RapportCaisseExport;
use App\Models\BonCaisse;
use App\Models\RapportCaisse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;

/**
 * E-mail du Rapport Journalier de Caisse
 * 
 * Envoyé automatiquement chaque jour (J+0 avant 8h) aux destinataires :
 * DAF, Chef Comptable, Trésorière.
 * 
 * Contient le rapport en pièces jointes Excel + PDF.
 */
class RapportCaisseQuotidien extends Mailable
{
    use Queueable, SerializesModels;

    public RapportCaisse $rapport;
    public $bonsPaye;

    public function __construct(RapportCaisse $rapport, $bonsPaye)
    {
        $this->rapport = $rapport;
        $this->bonsPaye = $bonsPaye;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Rapport Journalier de Caisse — ' . $this->rapport->site . ' — ' . $this->rapport->date_rapport->format('d/m/Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rapport-caisse-quotidien',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $baseName = 'rapport-caisse-' . $this->rapport->site . '-' . $this->rapport->date_rapport->format('Y-m-d');

        /* Générer le PDF en mémoire */
        $pdf = Pdf::loadView('exports.rapport-caisse-pdf', [
            'rapport' => $this->rapport,
            'bonsPaye' => $this->bonsPaye,
        ])->setPaper('a4', 'portrait');

        /* Générer l'Excel en mémoire */
        $excelContent = Excel::raw(
            new RapportCaisseExport($this->rapport, $this->bonsPaye),
            \Maatwebsite\Excel\Excel::XLSX
        );

        return [
            Attachment::fromData(fn () => $pdf->output(), $baseName . '.pdf')
                ->withMime('application/pdf'),
            Attachment::fromData(fn () => $excelContent, $baseName . '.xlsx')
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
