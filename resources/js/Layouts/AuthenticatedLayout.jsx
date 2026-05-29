/**
 * Layout Principal Authentifié - NEEMBA
 *
 * Layout avec sidebar fixe à gauche pour la navigation principale.
 * Utilise les couleurs NEEMBA (#fdc911) et les animations Framer Motion.
 */
import { Link, usePage } from "@inertiajs/react";
import { useState, useEffect } from "react";
import { toast } from "sonner";
import { Toaster } from "@/Components/ui/sonner";
import { motion, AnimatePresence } from "framer-motion";
import {
    LayoutDashboard,
    FileText,
    CheckSquare,
    BarChart3,
    Archive,
    Users,
    User,
    LogOut,
    Menu,
    X,
    ChevronDown,
    Settings,
    Handshake,
    Wallet,
    ClipboardList,
    ShieldAlert,
} from "lucide-react";
import { cn } from "@/lib/utils";
import NotificationBell from "@/Components/NotificationBell";
import { Button } from "@/Components/ui/button";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { Separator } from "@/Components/ui/separator";
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from "@/Components/ui/tooltip";

/**
 * Éléments de navigation de la sidebar
 * Chaque élément est filtré selon le rôle de l'utilisateur
 */
const navigationItems = [
    {
        label: "Tableau de bord",
        href: "dashboard",
        icon: LayoutDashboard,
        roles: [
            "demandeur",
            "responsable_service",
            "controle_gestion",
            "daf",
            "directeur_pays",
            "caissier",
            "administrateur",
        ],
    },
    {
        label: "Bons de Caisse",
        href: "bons-caisse.index",
        icon: FileText,
        roles: [
            "demandeur",
            "responsable_service",
            "controle_gestion",
            "daf",
            "directeur_pays",
            "caissier",
            "administrateur",
        ],
    },
    {
        label: "Validations",
        href: "validations.index",
        icon: CheckSquare,
        roles: [
            "responsable_service",
            "controle_gestion",
            "daf",
            "directeur_pays",
        ],
    },
    {
        label: "Rapports Caisse",
        href: "rapports.index",
        icon: BarChart3,
        roles: ["caissier", "daf", "directeur_pays", "administrateur"],
    },
    {
        label: "Mouvements Caisse",
        href: "mouvements-caisse.index",
        icon: Wallet,
        roles: ["caissier", "daf", "directeur_pays", "administrateur"],
    },
    {
        label: "Délégations",
        href: "delegations.index",
        icon: Handshake,
        roles: [
            "demandeur",
            "caissier",
            "responsable_service",
            "controle_gestion",
            "daf",
            "directeur_pays",
            "administrateur",
        ],
    },
    {
        label: "Archivage",
        href: "archivage.index",
        icon: Archive,
        roles: ["daf", "controle_gestion", "administrateur"],
    },
    {
        label: "BP en Retard",
        href: "bons-caisse.bp-en-retard",
        icon: ClipboardList,
        roles: ["daf", "directeur_pays", "administrateur"],
    },
    {
        label: "Modifications Admin",
        href: "admin.modifications-en-attente.index",
        icon: ShieldAlert,
        roles: ["administrateur"],
    },
    {
        label: "Utilisateurs",
        href: "utilisateurs.index",
        icon: Users,
        roles: ["administrateur"],
    },
    {
        label: "Paramétrage",
        href: "parametrage.index",
        icon: Settings,
        roles: ["administrateur"],
    },
];

/** Labels lisibles pour les rôles */
const rolesLabels = {
    demandeur: "Demandeur",
    responsable_service: "Chef de Service",
    controle_gestion: "Contrôle de Gestion",
    daf: "DAF",
    directeur_pays: "Directeur Pays",
    caissier: "Caissier",
    administrateur: "Administrateur",
};

export default function AuthenticatedLayout({ header, children }) {
    const { auth } = usePage().props;
    const user = auth.user;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);

    /* Filtrer les éléments de navigation selon les rôles effectifs de l'utilisateur */
    const rolesUtilisateur = user.roles_effectifs || [user.role || "demandeur"];
    const menuItems = navigationItems.filter((item) =>
        item.roles.some((r) => rolesUtilisateur.includes(r)),
    );

    /**
     * Vérifie si une route est active (pour le highlighting de la sidebar)
     */
    const isActive = (routeName) => {
        try {
            /* Extraire le préfixe de ressource (ex: bons-caisse.index → bons-caisse) */
            const prefix = routeName.replace(/\.\w+$/, "");
            return route().current(routeName) || route().current(prefix + ".*");
        } catch {
            return false;
        }
    };

    /* Flash messages → toast */
    const flash = usePage().props.flash || {};
    useEffect(() => {
        if (flash.success) toast.success(flash.success);
        if (flash.error) toast.error(flash.error);
        if (flash.warning) toast.warning(flash.warning);
        if (flash.info) toast.info(flash.info);
    }, [flash.success, flash.error, flash.warning, flash.info]);

    return (
        <TooltipProvider>
            <div className="flex h-screen bg-gray-50">
                {/* ============================================================
                    SIDEBAR DESKTOP - fixe à gauche
                   ============================================================ */}
                <aside className="hidden lg:flex lg:flex-col lg:w-64 lg:fixed lg:inset-y-0 bg-marine-950 text-white">
                    {/* Logo NEEMBA */}
                    <div className="flex items-center h-16 px-6 border-b border-marine-800 bg-yellow-400">
                        <Link
                            href={route("dashboard")}
                            className="flex items-center space-x-3"
                        >
                            <img
                                src="/logo.png"
                                alt="NEEMBA CAT Logo"
                                className="h-full w-auto object-contain px-1"
                            />
                        </Link>
                    </div>

                    {/* Navigation */}
                    <ScrollArea className="flex-1 py-4">
                        <nav className="px-3 space-y-1">
                            {menuItems.map((item) => {
                                const Icon = item.icon;
                                const active = isActive(item.href);
                                return (
                                    <Link
                                        key={item.href}
                                        href={route(item.href)}
                                        className={cn(
                                            "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all duration-200",
                                            active
                                                ? "bg-neemba-400 text-marine-950 shadow-md"
                                                : "text-gray-300 hover:bg-marine-800 hover:text-white",
                                        )}
                                    >
                                        <Icon className="h-5 w-5 flex-shrink-0" />
                                        {item.label}
                                    </Link>
                                );
                            })}
                        </nav>
                    </ScrollArea>

                    {/* Profil utilisateur en bas de la sidebar */}
                    <div className="border-t border-marine-800 p-4">
                        <div className="flex items-center gap-3">
                            <div className="w-9 h-9 rounded-full bg-neemba-400 flex items-center justify-center flex-shrink-0">
                                <span className="text-marine-950 font-semibold text-sm">
                                    {(
                                        user.prenom?.[0] ||
                                        user.name?.[0] ||
                                        "U"
                                    ).toUpperCase()}
                                </span>
                            </div>
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-medium text-white truncate">
                                    {user.prenom
                                        ? `${user.prenom} ${user.name}`
                                        : user.name}
                                </p>
                                <p className="text-xs text-gray-400 truncate">
                                    {rolesLabels[user.role] ||
                                        user.role ||
                                        "Utilisateur"}
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 flex gap-2">
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Link
                                        href={route("profile.edit")}
                                        className="flex-1 flex items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs text-gray-300 hover:bg-marine-800 hover:text-white transition-colors"
                                    >
                                        <Settings className="h-3.5 w-3.5" />
                                        Profil
                                    </Link>
                                </TooltipTrigger>
                                <TooltipContent side="top">
                                    Modifier le profil
                                </TooltipContent>
                            </Tooltip>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Link
                                        href={route("logout")}
                                        method="post"
                                        as="button"
                                        className="flex-1 flex items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs text-gray-300 hover:bg-red-600/20 hover:text-red-400 transition-colors"
                                    >
                                        <LogOut className="h-3.5 w-3.5" />
                                        Déconnexion
                                    </Link>
                                </TooltipTrigger>
                                <TooltipContent side="top">
                                    Se déconnecter
                                </TooltipContent>
                            </Tooltip>
                        </div>
                    </div>
                </aside>

                {/* ============================================================
                    SIDEBAR MOBILE - overlay
                   ============================================================ */}
                <AnimatePresence>
                    {sidebarOpen && (
                        <>
                            {/* Overlay sombre */}
                            <motion.div
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                className="fixed inset-0 z-40 bg-black/50 lg:hidden"
                                onClick={() => setSidebarOpen(false)}
                            />
                            {/* Sidebar mobile */}
                            <motion.aside
                                initial={{ x: -280 }}
                                animate={{ x: 0 }}
                                exit={{ x: -280 }}
                                transition={{
                                    type: "spring",
                                    damping: 25,
                                    stiffness: 200,
                                }}
                                className="fixed inset-y-0 left-0 z-50 w-64 bg-marine-950 text-white lg:hidden"
                            >
                                {/* Header mobile */}
                                <div className="flex items-center justify-between h-16 px-6 border-b border-marine-800">
                                    <Link
                                        href={route("dashboard")}
                                        className="flex items-center space-x-3"
                                    >
                                        <img
                                            src="/logo.png"
                                            alt="NEEMBA CAT Logo"
                                            className="h-8 w-auto object-contain"
                                        />
                                    </Link>
                                    <button
                                        onClick={() => setSidebarOpen(false)}
                                        className="text-gray-400 hover:text-white"
                                    >
                                        <X className="h-5 w-5" />
                                    </button>
                                </div>

                                {/* Navigation mobile */}
                                <nav className="px-3 py-4 space-y-1">
                                    {menuItems.map((item) => {
                                        const Icon = item.icon;
                                        const active = isActive(item.href);
                                        return (
                                            <Link
                                                key={item.href}
                                                href={route(item.href)}
                                                onClick={() =>
                                                    setSidebarOpen(false)
                                                }
                                                className={cn(
                                                    "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all",
                                                    active
                                                        ? "bg-neemba-400 text-marine-950"
                                                        : "text-gray-300 hover:bg-marine-800 hover:text-white",
                                                )}
                                            >
                                                <Icon className="h-5 w-5" />
                                                {item.label}
                                            </Link>
                                        );
                                    })}
                                </nav>

                                {/* Profil mobile */}
                                <div className="absolute bottom-0 left-0 right-0 border-t border-marine-800 p-4">
                                    <div className="flex items-center gap-3 mb-3">
                                        <div className="w-9 h-9 rounded-full bg-neemba-400 flex items-center justify-center">
                                            <span className="text-marine-950 font-semibold text-sm">
                                                {(
                                                    user.prenom?.[0] ||
                                                    user.name?.[0] ||
                                                    "U"
                                                ).toUpperCase()}
                                            </span>
                                        </div>
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium truncate">
                                                {user.prenom
                                                    ? `${user.prenom} ${user.name}`
                                                    : user.name}
                                            </p>
                                            <p className="text-xs text-gray-400">
                                                {rolesLabels[user.role] ||
                                                    "Utilisateur"}
                                            </p>
                                        </div>
                                    </div>
                                    <Link
                                        href={route("logout")}
                                        method="post"
                                        as="button"
                                        className="w-full flex items-center justify-center gap-2 rounded-md py-2 text-sm text-gray-300 hover:bg-red-600/20 hover:text-red-400 transition-colors"
                                    >
                                        <LogOut className="h-4 w-4" />
                                        Déconnexion
                                    </Link>
                                </div>
                            </motion.aside>
                        </>
                    )}
                </AnimatePresence>

                {/* ============================================================
                    CONTENU PRINCIPAL
                   ============================================================ */}
                <div className="flex-1 flex flex-col lg:pl-64">
                    {/* Top bar */}
                    <header className="sticky top-0 z-30 flex items-center h-16 bg-white border-b border-gray-200 px-4 sm:px-6 lg:px-8">
                        {/* Bouton menu mobile */}
                        <button
                            onClick={() => setSidebarOpen(true)}
                            className="lg:hidden mr-4 p-1.5 rounded-md text-gray-500 hover:bg-gray-100"
                        >
                            <Menu className="h-6 w-6" />
                        </button>

                        {/* Titre de la page */}
                        <div className="flex-1">
                            {header && (
                                <div className="text-lg font-semibold text-gray-900">
                                    {header}
                                </div>
                            )}
                        </div>

                        {/* Notifications + Menu utilisateur desktop */}
                        <div className="hidden sm:flex items-center gap-3">
                            <NotificationBell />
                            <div className="text-right mr-2">
                                <p className="text-sm font-medium text-gray-700">
                                    {user.prenom
                                        ? `${user.prenom} ${user.name}`
                                        : user.name}
                                </p>
                                <p className="text-xs text-gray-500">
                                    {rolesLabels[user.role] || "Utilisateur"}
                                </p>
                            </div>
                            <div className="relative">
                                <button
                                    onClick={() =>
                                        setUserMenuOpen(!userMenuOpen)
                                    }
                                    className="w-9 h-9 rounded-full bg-neemba-400 flex items-center justify-center hover:ring-2 hover:ring-neemba-300 transition-all"
                                >
                                    <span className="text-marine-950 font-semibold text-sm">
                                        {(
                                            user.prenom?.[0] ||
                                            user.name?.[0] ||
                                            "U"
                                        ).toUpperCase()}
                                    </span>
                                </button>

                                {/* Dropdown menu */}
                                <AnimatePresence>
                                    {userMenuOpen && (
                                        <>
                                            <div
                                                className="fixed inset-0 z-40"
                                                onClick={() =>
                                                    setUserMenuOpen(false)
                                                }
                                            />
                                            <motion.div
                                                initial={{ opacity: 0, y: -10 }}
                                                animate={{ opacity: 1, y: 0 }}
                                                exit={{ opacity: 0, y: -10 }}
                                                className="absolute right-0 mt-2 w-48 z-50 rounded-lg bg-white border shadow-lg py-1"
                                            >
                                                <Link
                                                    href={route("profile.edit")}
                                                    className="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                                                    onClick={() =>
                                                        setUserMenuOpen(false)
                                                    }
                                                >
                                                    <User className="h-4 w-4" />
                                                    Mon Profil
                                                </Link>
                                                <Separator />
                                                <Link
                                                    href={route("logout")}
                                                    method="post"
                                                    as="button"
                                                    className="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50"
                                                >
                                                    <LogOut className="h-4 w-4" />
                                                    Déconnexion
                                                </Link>
                                            </motion.div>
                                        </>
                                    )}
                                </AnimatePresence>
                            </div>
                        </div>
                    </header>

                    {/* Contenu de la page avec animation */}
                    <main className="flex-1 overflow-y-auto overflow-x-hidden">
                        <motion.div
                            initial={{ opacity: 0, y: 8 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.3, ease: "easeOut" }}
                            className="p-4 sm:p-6 lg:p-8"
                        >
                            {children}
                        </motion.div>
                    </main>
                </div>
            </div>
            <Toaster />
        </TooltipProvider>
    );
}
