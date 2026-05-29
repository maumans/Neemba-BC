/**
 * GuestLayout - Layout pour les pages non authentifiées (Login, Forgot Password, etc.)
 *
 * Design split-screen NEEMBA :
 * - Gauche : panneau décoratif avec branding NEEMBA (or #fdc911)
 * - Droite : formulaire centré
 */
import { motion } from "framer-motion";
import { Wallet } from "lucide-react";

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen">
            {/* Panneau gauche - Branding NEEMBA */}
            <div className="hidden lg:flex lg:w-1/2 relative overflow-hidden bg-marine-950">
                {/* Gradient décoratif */}
                <div className="absolute inset-0 bg-gradient-to-br from-marine-950 via-marine-900 to-marine-800" />

                {/* Motif géométrique subtil */}
                <div
                    className="absolute inset-0 opacity-[0.04]"
                    style={{
                        backgroundImage: `url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23fdc911' fill-opacity='1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")`,
                    }}
                />

                {/* Cercle décoratif doré */}
                <div className="absolute -bottom-32 -left-32 w-96 h-96 rounded-full bg-neemba-400/10 blur-3xl" />
                <div className="absolute -top-20 -right-20 w-72 h-72 rounded-full bg-neemba-400/5 blur-2xl" />

                {/* Contenu du panneau */}
                <div className="relative z-10 flex flex-col justify-center px-12 xl:px-20 w-full">
                    <motion.div
                        initial={{ opacity: 0, x: -30 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ duration: 0.6 }}
                    >
                        {/* Logo / Marque */}
                        <div className="flex items-center gap-3 mb-10">
                            <img
                                src="/logo.png"
                                alt="NEEMBA CAT Logo"
                                className="h-14 w-auto object-contain"
                            />
                            <div>
                                <h1 className="text-2xl font-bold text-white tracking-tight">
                                    NEEMBA
                                </h1>
                                <p className="text-xs text-neemba-400 font-medium tracking-widest uppercase">
                                    Cash Management
                                </p>
                            </div>
                        </div>

                        {/* Titre accrocheur */}
                        <h2 className="text-4xl xl:text-5xl font-bold text-white leading-tight mb-6">
                            Gestion de caisse
                            <span className="block text-neemba-400">
                                simplifiée.
                            </span>
                        </h2>

                        <p className="text-marine-300 text-lg leading-relaxed max-w-md">
                            Créez, validez et suivez vos bons de caisse en toute
                            simplicité. Un workflow hiérarchique fiable pour
                            maîtriser vos décaissements.
                        </p>

                        {/* Points clés */}
                        <div className="mt-10 space-y-4">
                            {[
                                "Validation hiérarchique multi-niveaux",
                                "Suivi en temps réel des demandes",
                                "Rapports de caisse automatisés",
                            ].map((item, i) => (
                                <motion.div
                                    key={i}
                                    initial={{ opacity: 0, x: -20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ delay: 0.4 + i * 0.15 }}
                                    className="flex items-center gap-3"
                                >
                                    <div className="w-2 h-2 rounded-full bg-neemba-400 flex-shrink-0" />
                                    <span className="text-marine-200 text-sm">
                                        {item}
                                    </span>
                                </motion.div>
                            ))}
                        </div>
                    </motion.div>
                </div>
            </div>

            {/* Panneau droit - Formulaire */}
            <div className="flex-1 flex flex-col items-center justify-center px-6 py-12 bg-gray-50 min-h-screen">
                {/* Logo mobile (visible uniquement sur petits écrans) */}
                <div className="lg:hidden flex items-center gap-2 mb-8">
                    <img
                        src="/logo.png"
                        alt="NEEMBA CAT Logo"
                        className="h-10 w-auto object-contain"
                    />
                    <div>
                        <h1 className="text-xl font-bold text-gray-900">
                            NEEMBA
                        </h1>
                        <p className="text-[10px] text-neemba-600 font-medium tracking-widest uppercase">
                            Cash Management
                        </p>
                    </div>
                </div>

                <motion.div
                    initial={{ opacity: 0, y: 15 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="w-full max-w-md"
                >
                    <div className="bg-white rounded-2xl shadow-xl shadow-gray-200/50 border border-gray-100 px-8 py-10">
                        {children}
                    </div>

                    <p className="text-center text-xs text-gray-400 mt-6">
                        &copy; {new Date().getFullYear()} NEEMBA — Tous droits
                        réservés
                    </p>
                </motion.div>
            </div>
        </div>
    );
}
