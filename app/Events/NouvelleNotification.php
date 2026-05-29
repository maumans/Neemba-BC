<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event : Nouvelle Notification Push
 * 
 * Diffusé en temps réel via Laravel Reverb sur le canal privé
 * de l'utilisateur destinataire. Implémente ShouldBroadcastNow
 * pour un envoi immédiat sans passer par la queue.
 */
class NouvelleNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Notification $notification,
    ) {}

    /**
     * Canal privé de l'utilisateur destinataire
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('notifications.' . $this->notification->destinataire_id),
        ];
    }

    /**
     * Nom de l'événement côté frontend
     */
    public function broadcastAs(): string
    {
        return 'nouvelle-notification';
    }

    /**
     * Données envoyées au client WebSocket
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'titre' => $this->notification->titre,
            'message' => $this->notification->message,
            'bon_caisse_id' => $this->notification->bon_caisse_id,
            'expediteur' => $this->notification->expediteur
                ? [
                    'id' => $this->notification->expediteur->id,
                    'nom_complet' => $this->notification->expediteur->nom_complet,
                ]
                : null,
            'metadata' => $this->notification->metadata,
            'config' => Notification::TYPES_CONFIG[$this->notification->type] ?? null,
            'created_at' => $this->notification->created_at->toIso8601String(),
        ];
    }
}
