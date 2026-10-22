/**
 * Onglet « Sécurité » du paramétrage (spec v2.4, RG-M01-07) : l'administrateur active ou désactive la double
 * authentification (code de connexion par email) et en règle la durée et les essais. Le code SMS du visa urgent a été
 * retiré (Q104) ; l'encart SMS montre l'état des alertes des demandes urgentes.
 * Effet immédiat, sans second validateur (interrupteurs de secours) ; chaque changement est tracé et les autres
 * administrateurs sont prévenus. Un interrupteur s'applique dès qu'on le bascule (après confirmation pour le couper) ;
 * son état affiché est celui enregistré. Les durées et essais s'enregistrent avec leur bouton.
 */
import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, KeyRound, Mail, MessageSquareWarning, Save, ShieldCheck, ShieldOff } from 'lucide-react';
import { Switch } from '@/Components/ui/switch';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';

const AVERTISSEMENTS = {
    double_authentification_active: "Sans code de connexion, un mot de passe suffit pour entrer dans l'application. À réserver à une panne du serveur d'email.",
    changement_mot_de_passe_initial: "Les comptes au mot de passe initial (importés, créés ou réinitialisés par un administrateur) pourront s'en servir sans le changer. À réserver à la recette.",
};

function Etat({ actif }) {
    return actif
        ? <Badge className="bg-green-100 text-green-800 hover:bg-green-100">Activé</Badge>
        : <Badge className="bg-red-100 text-red-800 hover:bg-red-100">Désactivé</Badge>;
}

function Canal({ ok, libelleOk, libelleKo }) {
    return (
        <p className={`flex items-start gap-1.5 text-xs ${ok ? 'text-green-700' : 'text-amber-700'}`}>
            {ok ? <CheckCircle2 className="h-4 w-4 shrink-0" /> : <AlertTriangle className="h-4 w-4 shrink-0" />}
            <span>{ok ? libelleOk : libelleKo}</span>
        </p>
    );
}

function Nombre({ id, libelle, aide, valeur, bornes, onChange, erreur, desactive }) {
    return (
        <div>
            <Label htmlFor={id} className="text-xs">{libelle}</Label>
            <Input id={id} type="number" min={bornes.min} max={bornes.max} value={valeur} disabled={desactive}
                onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))} className="mt-1 w-28" />
            <p className="mt-0.5 text-[11px] text-gray-500">{aide} (de {bornes.min} à {bornes.max})</p>
            {erreur && <p className="text-xs text-red-600" role="alert">{erreur}</p>}
        </div>
    );
}

export default function ReglagesSecurite({ securite }) {
    const { valeurs, bornes, email, sms, valideursSansTelephone = [], historique = [] } = securite;
    const NOMBRES = ['otp_connexion_duree', 'otp_connexion_tentatives'];
    const form = useForm(Object.fromEntries(NOMBRES.map((cle) => [cle, valeurs[cle]])));
    const [confirmation, setConfirmation] = useState(null);
    const [enCours, setEnCours] = useState(null);

    /* Interrupteur : appliqué tout de suite avec les valeurs enregistrées (les durées en cours de saisie ne partent pas) */
    const appliquer = (cle, actif) => router.put(route('parametrage.securite.update'), { ...valeurs, [cle]: actif }, {
        preserveScroll: true,
        onStart: () => setEnCours(cle),
        onFinish: () => setEnCours(null),
    });
    /* Couper un interrupteur demande une confirmation ; le rallumer, non */
    const basculer = (cle, actif) => (actif ? appliquer(cle, true) : setConfirmation(cle));
    const modifie = NOMBRES.some((cle) => form.data[cle] !== valeurs[cle]);
    /* Durées et essais, avec les interrupteurs tels qu'enregistrés (transform ne renvoie pas le formulaire : pas de chaînage) */
    form.transform((data) => ({ ...valeurs, ...data }));
    const enregistrer = () => form.put(route('parametrage.securite.update'), { preserveScroll: true });

    return (
        <div className="space-y-4">
            <div className="rounded-lg border border-neemba-200 bg-neemba-50/50 p-4 text-sm text-gray-700">
                La double authentification protège la connexion (code envoyé par email). Les demandes urgentes se visent sans
                code : leurs valideurs sont prévenus par SMS. Vos changements s'appliquent <strong>immédiatement</strong>, sans
                second validateur ; ils sont tracés ci-dessous et les autres administrateurs sont prévenus.
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {/* Connexion */}
                <section className="space-y-3 rounded-lg border bg-white p-4" aria-labelledby="titre-connexion">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h4 id="titre-connexion" className="flex items-center gap-1.5 text-sm font-semibold text-gray-900">
                                <Mail className="h-4 w-4 text-neemba-600" /> Code de connexion par email
                            </h4>
                            <p className="mt-0.5 text-xs text-gray-500">Après le mot de passe, un code à 6 chiffres est demandé à chaque connexion.</p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Etat actif={valeurs.double_authentification_active} />
                            <Switch aria-label="Code de connexion par email" checked={valeurs.double_authentification_active} disabled={enCours !== null}
                                onCheckedChange={(v) => basculer('double_authentification_active', v)} />
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-4">
                        <Nombre id="otp_connexion_duree" libelle="Validité du code (minutes)" aide="Durée du code" bornes={bornes.otp_connexion_duree}
                            valeur={form.data.otp_connexion_duree} onChange={(v) => form.setData('otp_connexion_duree', v)}
                            erreur={form.errors.otp_connexion_duree} desactive={!valeurs.double_authentification_active} />
                        <Nombre id="otp_connexion_tentatives" libelle="Essais par code" aide="Code de connexion" bornes={bornes.otp_connexion_tentatives}
                            valeur={form.data.otp_connexion_tentatives} onChange={(v) => form.setData('otp_connexion_tentatives', v)}
                            erreur={form.errors.otp_connexion_tentatives} />
                    </div>
                    <Canal ok={email.operationnel} libelleOk={`Serveur d'email configuré (${email.mailer}).`}
                        libelleKo={`Aucun serveur d'email (mode « ${email.mailer} ») : les codes sont écrits dans le journal du serveur, pas envoyés.`} />
                </section>

                {/* Q108 : mot de passe initial à changer à la première connexion */}
                <section className="space-y-3 rounded-lg border bg-white p-4" aria-labelledby="titre-mot-de-passe-initial">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h4 id="titre-mot-de-passe-initial" className="flex items-center gap-1.5 text-sm font-semibold text-gray-900">
                                <KeyRound className="h-4 w-4 text-neemba-600" /> Mot de passe initial à changer
                            </h4>
                            <p className="mt-0.5 text-xs text-gray-500">
                                Compte importé, créé ou dont un administrateur a saisi le mot de passe : à sa première connexion, son titulaire choisit le sien.
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Etat actif={valeurs.changement_mot_de_passe_initial} />
                            <Switch aria-label="Mot de passe initial à changer" checked={valeurs.changement_mot_de_passe_initial} disabled={enCours !== null}
                                onCheckedChange={(v) => basculer('changement_mot_de_passe_initial', v)} />
                        </div>
                    </div>
                </section>

                {/* Alertes SMS des demandes urgentes (plus de code de visa, Q104) */}
                <section className="space-y-3 rounded-lg border bg-white p-4" aria-labelledby="titre-alertes-sms">
                    <div>
                        <h4 id="titre-alertes-sms" className="flex items-center gap-1.5 text-sm font-semibold text-gray-900">
                            <MessageSquareWarning className="h-4 w-4 text-neemba-600" /> Alertes SMS des demandes urgentes
                        </h4>
                        <p className="mt-0.5 text-xs text-gray-500">
                            Ordre de mission urgent ou très urgent, bon très urgent : le valideur est prévenu par SMS à chaque étape. Aucun code n'est demandé pour viser.
                        </p>
                    </div>
                    <Canal ok={sms.operationnel} libelleOk="Fournisseur SMS configuré."
                        libelleKo="Aucun fournisseur SMS configuré : les alertes SMS ne partent pas (la notification dans l'application reste)." />
                    {valideursSansTelephone.length > 0 && (
                        <div className="rounded-md bg-amber-50 p-2 text-xs text-amber-800">
                            <p className="font-medium">Valideurs sans numéro de téléphone ({valideursSansTelephone.length}) : ils ne reçoivent pas l'alerte SMS.</p>
                            <p className="mt-0.5">{valideursSansTelephone.join(', ')}</p>
                        </div>
                    )}
                </section>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs text-gray-500">
                    Plus personne ne peut se connecter ? Sur le serveur : <code className="rounded bg-gray-100 px-1">php artisan securite:double-authentification desactiver</code>
                </p>
                <Button onClick={enregistrer} disabled={!modifie || form.processing}>
                    <Save className="mr-2 h-4 w-4" /> Enregistrer les durées et essais
                </Button>
            </div>

            {/* Derniers changements */}
            <div>
                <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400">Derniers changements</h4>
                {historique.length === 0 ? (
                    <p className="text-sm text-gray-400">Aucun changement depuis la mise en place.</p>
                ) : (
                    <div className="overflow-x-auto rounded-md border bg-white">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                                    <th className="px-3 py-2">Date</th>
                                    <th className="px-3 py-2">Réglage</th>
                                    <th className="px-3 py-2">Changement</th>
                                    <th className="px-3 py-2">Par</th>
                                    <th className="px-3 py-2">État</th>
                                </tr>
                            </thead>
                            <tbody>
                                {historique.map((h) => (
                                    <tr key={h.id} className="border-b last:border-0">
                                        <td className="whitespace-nowrap px-3 py-2 text-xs text-gray-500">{h.date}</td>
                                        <td className="px-3 py-2">{h.reglage}</td>
                                        <td className="whitespace-nowrap px-3 py-2">{h.avant} → {h.apres}</td>
                                        <td className="px-3 py-2">{h.auteur}</td>
                                        <td className="px-3 py-2 text-xs">{h.statut}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Dialog open={confirmation !== null} onOpenChange={(ouvert) => !ouvert && setConfirmation(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2"><ShieldOff className="h-5 w-5 text-red-600" /> Désactiver ce contrôle ?</DialogTitle>
                        <DialogDescription>{confirmation && AVERTISSEMENTS[confirmation]}</DialogDescription>
                    </DialogHeader>
                    <p className="text-sm text-gray-600">Le changement prend effet immédiatement, pour tous les utilisateurs.</p>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmation(null)}>
                            <ShieldCheck className="mr-2 h-4 w-4" /> Garder activé
                        </Button>
                        <Button variant="destructive" onClick={() => { appliquer(confirmation, false); setConfirmation(null); }}>
                            Désactiver maintenant
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
