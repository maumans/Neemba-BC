/**
 * Composant NotificationBell - Cloche de notifications en temps réel
 * 
 * Affiche une cloche avec badge compteur dans la top bar.
 * Écoute les notifications push via Laravel Echo / Reverb.
 * Dropdown avec liste scrollable, marquage lu, et lien vers le bon.
 */
import { useState, useEffect, useCallback, useRef } from 'react';
import { usePage, router } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
import { toast } from 'sonner';
import {
    Bell,
    BellRing,
    Check,
    CheckCheck,
    FileText,
    CheckCircle2,
    XCircle,
    Banknote,
    MessageSquare,
    ClipboardCheck,
    AlertTriangle,
    Archive,
    ExternalLink,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Separator } from '@/Components/ui/separator';
import { Button } from '@/Components/ui/button';

/** Map des icônes par type de notification */
const iconeParType = {
    soumission: FileText,
    validation: CheckCircle2,
    approbation_finale: CheckCircle2,
    rejet: XCircle,
    demande_complement: MessageSquare,
    paiement: Banknote,
    regularisation: ClipboardCheck,
    relance_regularisation: AlertTriangle,
    archivage: Archive,
};

/** Couleurs par type */
const couleursParType = {
    soumission: { text: 'text-blue-600', bg: 'bg-blue-50', ring: 'ring-blue-200' },
    validation: { text: 'text-green-600', bg: 'bg-green-50', ring: 'ring-green-200' },
    approbation_finale: { text: 'text-emerald-600', bg: 'bg-emerald-50', ring: 'ring-emerald-200' },
    rejet: { text: 'text-red-600', bg: 'bg-red-50', ring: 'ring-red-200' },
    demande_complement: { text: 'text-amber-600', bg: 'bg-amber-50', ring: 'ring-amber-200' },
    paiement: { text: 'text-emerald-600', bg: 'bg-emerald-50', ring: 'ring-emerald-200' },
    regularisation: { text: 'text-purple-600', bg: 'bg-purple-50', ring: 'ring-purple-200' },
    relance_regularisation: { text: 'text-orange-600', bg: 'bg-orange-50', ring: 'ring-orange-200' },
    archivage: { text: 'text-gray-600', bg: 'bg-gray-100', ring: 'ring-gray-200' },
};

/** Couleurs par niveau d'urgence */
const couleursUrgence = {
    tres_urgente: { bg: 'bg-red-50', border: 'border-l-4 border-l-red-500', iconBg: 'bg-red-100', iconText: 'text-red-600', badge: 'bg-red-100 text-red-700', label: 'TRÈS URGENT' },
    urgente: { bg: 'bg-orange-50', border: 'border-l-4 border-l-orange-400', iconBg: 'bg-orange-100', iconText: 'text-orange-600', badge: 'bg-orange-100 text-orange-700', label: 'URGENT' },
};

/** Extraire le niveau d'urgence d'une notification */
function getNiveauUrgence(notif) {
    return notif.metadata?.niveau_urgence || notif.niveau_urgence || null;
}

/** Formatage relatif du temps */
function tempsRelatif(dateStr) {
    const now = new Date();
    const date = new Date(dateStr);
    const diff = Math.floor((now - date) / 1000);

    if (diff < 60) return 'À l\'instant';
    if (diff < 3600) return `Il y a ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `Il y a ${Math.floor(diff / 3600)}h`;
    if (diff < 172800) return 'Hier';
    return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}

export default function NotificationBell() {
    const { auth, notificationsNonLues: initialNonLues } = usePage().props;
    const userId = auth.user?.id;

    const [ouvert, setOuvert] = useState(false);
    const [notifications, setNotifications] = useState([]);
    const [nonLues, setNonLues] = useState(initialNonLues || 0);
    const [chargement, setChargement] = useState(false);
    const [animate, setAnimate] = useState(false);
    const [dejaCharge, setDejaCharge] = useState(false);
    const dropdownRef = useRef(null);
    const fetchEnCours = useRef(false);

    /** Charger les notifications depuis l'API */
    const chargerNotifications = useCallback(async () => {
        if (fetchEnCours.current) return;
        fetchEnCours.current = true;
        setChargement(true);
        try {
            const notifRes = await window.axios.get('/api/notifications?par_page=20');
            const data = notifRes.data?.data || notifRes.data || [];
            setNotifications(Array.isArray(data) ? data : []);
            setDejaCharge(true);
        } catch (err) {
            console.error('Erreur chargement notifications:', err);
        } finally {
            setChargement(false);
            fetchEnCours.current = false;
        }
    }, []);

    /** Synchroniser le compteur avec la prop serveur à chaque navigation Inertia */
    useEffect(() => {
        if (typeof initialNonLues === 'number') {
            setNonLues(initialNonLues);
            /* Si le compteur change (nouvelle notif côté serveur), forcer le rafraîchissement au prochain ouverture */
            if (initialNonLues > 0 && dejaCharge) {
                setDejaCharge(false);
            }
        }
    }, [initialNonLues]);

    /** Écouter les notifications push en temps réel via Echo */
    useEffect(() => {
        if (!userId || !window.Echo) return;

        const channel = window.Echo.private(`notifications.${userId}`);

        channel.listen('.nouvelle-notification', (data) => {
            /* Ajouter en tête de liste */
            setNotifications((prev) => {
                const updated = [data, ...prev].slice(0, 20);
                return updated;
            });
            setNonLues((prev) => prev + 1);

            /* Animation de la cloche */
            setAnimate(true);
            setTimeout(() => setAnimate(false), 1000);

            /* Toast de notification */
            const couleurs = couleursParType[data.type] || couleursParType.soumission;
            toast(data.titre, {
                description: data.message,
                action: data.bon_caisse_id
                    ? {
                        label: 'Voir',
                        onClick: () => router.visit(route('bons-caisse.show', data.bon_caisse_id)),
                    }
                    : undefined,
            });
        });

        return () => {
            channel.stopListening('.nouvelle-notification');
            window.Echo.leave(`notifications.${userId}`);
        };
    }, [userId]);

    /** Fermer le dropdown au clic extérieur */
    useEffect(() => {
        const handleClickOutside = (e) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
                setOuvert(false);
            }
        };
        if (ouvert) {
            document.addEventListener('mousedown', handleClickOutside);
        }
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [ouvert]);

    /** Marquer une notification comme lue */
    const marquerLue = async (notification) => {
        if (notification.lue_le) return;
        try {
            await window.axios.post(`/api/notifications/${notification.id}/lue`);
            setNotifications((prev) =>
                prev.map((n) => (n.id === notification.id ? { ...n, lue_le: new Date().toISOString() } : n))
            );
            setNonLues((prev) => Math.max(0, prev - 1));
        } catch (err) {
            console.error('Erreur marquage notification:', err);
        }
    };

    /** Marquer toutes comme lues */
    const marquerToutesLues = async () => {
        try {
            await window.axios.post('/api/notifications/tout-lire');
            setNotifications((prev) =>
                prev.map((n) => ({ ...n, lue_le: n.lue_le || new Date().toISOString() }))
            );
            setNonLues(0);
        } catch (err) {
            console.error('Erreur tout-lire:', err);
        }
    };

    /** Cliquer sur une notification → marquer lue + naviguer */
    const handleClick = (notification) => {
        marquerLue(notification);
        setOuvert(false);
        if (notification.bon_caisse_id) {
            router.visit(route('bons-caisse.show', notification.bon_caisse_id));
        } else if (notification.type === 'delegation') {
            router.visit(route('delegations.index'));
        }
    };

    /** Ouvrir/fermer le dropdown */
    const toggleDropdown = () => {
        if (!ouvert) {
            setOuvert(true);
            /* Toujours charger les notifications à l'ouverture */
            chargerNotifications();
        } else {
            setOuvert(false);
        }
    };

    return (
        <div className="relative" ref={dropdownRef}>
            {/* Bouton cloche */}
            <button
                onClick={toggleDropdown}
                className={cn(
                    'relative p-2 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 transition-colors',
                    ouvert && 'bg-gray-100 text-gray-700'
                )}
                aria-label={`Notifications${nonLues > 0 ? ` (${nonLues} non lues)` : ''}`}
            >
                <motion.div
                    animate={animate ? { rotate: [0, -15, 15, -10, 10, 0] } : {}}
                    transition={{ duration: 0.5 }}
                >
                    {nonLues > 0 ? (
                        <BellRing className="h-5 w-5" />
                    ) : (
                        <Bell className="h-5 w-5" />
                    )}
                </motion.div>

                {/* Badge compteur */}
                <AnimatePresence>
                    {nonLues > 0 && (
                        <motion.span
                            initial={{ scale: 0 }}
                            animate={{ scale: 1 }}
                            exit={{ scale: 0 }}
                            className="absolute -top-0.5 -right-0.5 flex items-center justify-center min-w-[18px] h-[18px] rounded-full bg-red-500 text-white text-[10px] font-bold px-1 ring-2 ring-white"
                        >
                            {nonLues > 99 ? '99+' : nonLues}
                        </motion.span>
                    )}
                </AnimatePresence>
            </button>

            {/* Dropdown */}
            <AnimatePresence>
                {ouvert && (
                    <motion.div
                        initial={{ opacity: 0, y: -8, scale: 0.95 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: -8, scale: 0.95 }}
                        transition={{ duration: 0.15 }}
                        className="absolute right-0 mt-2 w-96 max-w-[calc(100vw-2rem)] z-50 rounded-xl bg-white border border-gray-200 shadow-xl overflow-hidden"
                    >
                        {/* Header */}
                        <div className="flex items-center justify-between px-4 py-3 bg-gray-50 border-b">
                            <h3 className="text-sm font-semibold text-gray-900">
                                Notifications
                                {nonLues > 0 && (
                                    <span className="ml-2 inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                        {nonLues} nouvelle{nonLues > 1 ? 's' : ''}
                                    </span>
                                )}
                            </h3>
                            {nonLues > 0 && (
                                <button
                                    onClick={marquerToutesLues}
                                    className="flex items-center gap-1 text-xs text-neemba-600 hover:text-neemba-700 font-medium"
                                >
                                    <CheckCheck className="h-3.5 w-3.5" />
                                    Tout lire
                                </button>
                            )}
                        </div>

                        {/* Liste */}
                        <ScrollArea className="max-h-[400px]">
                            {chargement ? (
                                <div className="flex flex-col items-center justify-center py-12 px-4 text-center">
                                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-neemba-500 mb-3"></div>
                                    <p className="text-sm text-gray-500">Chargement...</p>
                                </div>
                            ) : notifications.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-12 px-4 text-center">
                                    <Bell className="h-10 w-10 text-gray-300 mb-3" />
                                    <p className="text-sm text-gray-500">Aucune notification</p>
                                    <p className="text-xs text-gray-400 mt-1">
                                        Vous serez notifié des actions sur vos bons de caisse
                                    </p>
                                </div>
                            ) : (
                                <div>
                                    {notifications.map((notif, index) => {
                                        const Icon = iconeParType[notif.type] || Bell;
                                        const couleurs = couleursParType[notif.type] || couleursParType.soumission;
                                        const estNonLue = !notif.lue_le;
                                        const urgence = getNiveauUrgence(notif);
                                        const urgenceCouleurs = urgence ? couleursUrgence[urgence] : null;
                                        const estUrgent = urgence === 'urgente' || urgence === 'tres_urgente';

                                        return (
                                            <div key={notif.id || index}>
                                                <button
                                                    onClick={() => handleClick(notif)}
                                                    className={cn(
                                                        'w-full text-left px-4 py-3 hover:bg-gray-50 transition-colors flex gap-3',
                                                        estNonLue && !urgenceCouleurs && 'bg-neemba-50/30',
                                                        urgenceCouleurs?.bg,
                                                        urgenceCouleurs?.border
                                                    )}
                                                >
                                                    {/* Icône */}
                                                    <div className={cn(
                                                        'flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center',
                                                        urgenceCouleurs ? urgenceCouleurs.iconBg : couleurs.bg,
                                                        estUrgent && estNonLue && 'animate-urgence-blink'
                                                    )}>
                                                        <Icon className={cn('h-4 w-4', urgenceCouleurs ? urgenceCouleurs.iconText : couleurs.text)} />
                                                    </div>

                                                    {/* Contenu */}
                                                    <div className="flex-1 min-w-0">
                                                        <div className="flex items-start justify-between gap-2">
                                                            <div className="flex items-center gap-1.5 flex-wrap">
                                                                {urgenceCouleurs && estNonLue && (
                                                                    <span className={cn(
                                                                        'inline-flex items-center rounded px-1 py-0.5 text-[9px] font-bold tracking-wide leading-none',
                                                                        urgenceCouleurs.badge,
                                                                        urgence === 'tres_urgente' && 'animate-urgence-blink'
                                                                    )}>
                                                                        {urgenceCouleurs.label}
                                                                    </span>
                                                                )}
                                                                <p className={cn(
                                                                    'text-sm leading-tight',
                                                                    estNonLue ? 'font-semibold text-gray-900' : 'font-medium text-gray-700'
                                                                )}>
                                                                    {notif.titre}
                                                                </p>
                                                            </div>
                                                            {estNonLue && (
                                                                <span className={cn(
                                                                    'flex-shrink-0 w-2 h-2 mt-1.5 rounded-full',
                                                                    urgence === 'tres_urgente' ? 'bg-red-500 animate-pulse' :
                                                                    urgence === 'urgente' ? 'bg-orange-500' : 'bg-neemba-500'
                                                                )} />
                                                            )}
                                                        </div>
                                                        <p className="text-xs text-gray-500 mt-0.5 line-clamp-2">
                                                            {notif.message}
                                                        </p>
                                                        <div className="flex items-center gap-2 mt-1">
                                                            <span className="text-[11px] text-gray-400">
                                                                {tempsRelatif(notif.created_at)}
                                                            </span>
                                                            {notif.expediteur && (
                                                                <span className="text-[11px] text-gray-400">
                                                                    • {notif.expediteur.nom_complet || `${notif.expediteur.prenom || ''} ${notif.expediteur.name || ''}`.trim()}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </button>
                                                {index < notifications.length - 1 && <Separator />}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </ScrollArea>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}
