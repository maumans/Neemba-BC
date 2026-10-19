<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Code de connexion de la double authentification (spec v2.4, RG-M01-07). Envoyé tout de suite (pas de file) : la
 * personne attend le code devant l'écran.
 */
class CodeConnexion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $utilisateur, public string $code, public int $minutes)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "NEEMBA — Votre code de connexion : {$this->code}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.code-connexion');
    }
}
