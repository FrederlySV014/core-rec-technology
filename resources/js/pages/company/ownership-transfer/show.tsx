import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import AuthCardLayout from '@/layouts/auth/auth-card-layout';
import { ShieldCheck, ArrowRight, AlertTriangle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    company: {
        legal_name: string;
        trade_name?: string | null;
        slug: string;
    };
    token: string;
    currentOwner: {
        name: string;
        email: string;
    };
    user: {
        name: string;
        email: string;
        two_factor_enabled: boolean;
        two_factor_cleared: boolean;
    };
    expiresAt: string;
}

export default function OwnershipTransferShow({
    company,
    token,
    currentOwner,
    user,
    expiresAt,
}: Props) {
    const { post, processing } = useForm();

    const handleAccept: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/app/${company.slug}/ownership-transfers/${token}/accept`);
    };

    const is2faReady = user.two_factor_enabled;

    return (
        <AuthCardLayout
            title="Transferencia de Titularidad"
            description={`Solicitud formal para asumir la propiedad exclusiva de ${company.legal_name}`}
        >
            <Head title={`Aceptar Titularidad - ${company.legal_name}`} />

            <div className="space-y-5">
                <div className="space-y-2 rounded-lg border bg-muted/40 p-4 text-sm">
                    <div className="flex justify-between">
                        <span className="text-muted-foreground">Empresa:</span>
                        <span className="font-semibold">
                            {company.legal_name}
                        </span>
                    </div>
                    <div className="flex justify-between">
                        <span className="text-muted-foreground">
                            Propietario Actual:
                        </span>
                        <span className="font-medium">
                            {currentOwner.name} ({currentOwner.email})
                        </span>
                    </div>
                    <div className="flex justify-between">
                        <span className="text-muted-foreground">
                            Nuevo Titular Designado:
                        </span>
                        <span className="font-medium">
                            {user.name} ({user.email})
                        </span>
                    </div>
                    <div className="flex justify-between">
                        <span className="text-muted-foreground">Vence:</span>
                        <span className="font-mono text-xs">
                            {new Date(expiresAt).toLocaleString()}
                        </span>
                    </div>
                </div>

                <div className="space-y-2 rounded-lg border p-4">
                    <h3 className="flex items-center gap-1.5 text-sm font-semibold">
                        <ShieldCheck className="h-4 w-4 text-primary" />
                        Requisitos de Seguridad
                    </h3>
                    <div className="space-y-1.5 text-xs">
                        <div className="flex items-center justify-between">
                            <span>Membresía activa e identidad verificada</span>
                            <Badge
                                variant="secondary"
                                className="border-emerald-200 bg-emerald-50 text-emerald-700"
                            >
                                Cumplido
                            </Badge>
                        </div>
                        <div className="flex items-center justify-between">
                            <span>
                                Autenticación de Dos Factores (2FA) configurada
                            </span>
                            {user.two_factor_enabled ? (
                                <Badge
                                    variant="secondary"
                                    className="border-emerald-200 bg-emerald-50 text-emerald-700"
                                >
                                    Configurado
                                </Badge>
                            ) : (
                                <Badge variant="destructive">Pendiente</Badge>
                            )}
                        </div>
                    </div>
                </div>

                {!is2faReady && (
                    <div className="flex gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <p className="font-medium">
                                2FA Requerido para Asumir Titularidad
                            </p>
                            <p className="mt-1">
                                Para aceptar la titularidad exclusiva, primero
                                debe activar y confirmar su autenticación de dos
                                factores en su cuenta.
                            </p>
                            <Button
                                size="sm"
                                variant="outline"
                                className="mt-2 text-xs"
                                asChild
                            >
                                <Link href="/settings/security">
                                    Configurar 2FA ahora
                                </Link>
                            </Button>
                        </div>
                    </div>
                )}

                <div className="border-t pt-3 text-xs text-muted-foreground">
                    <p>
                        Al hacer clic en{' '}
                        <strong>Aceptar y Asumir Titularidad</strong>, se
                        ejecutará una transferencia atómica. El propietario
                        actual pasará a ser Administrador Comercial y usted
                        asumirá la propiedad total y exclusiva de la empresa.
                    </p>
                </div>

                <form onSubmit={handleAccept}>
                    <Button
                        type="submit"
                        className="w-full gap-2"
                        disabled={processing || !is2faReady}
                    >
                        {processing
                            ? 'Procesando...'
                            : 'Aceptar y Asumir Titularidad'}
                        <ArrowRight className="h-4 w-4" />
                    </Button>
                </form>

                <div className="text-center">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={`/app/${company.slug}`}>
                            Volver al espacio de trabajo
                        </Link>
                    </Button>
                </div>
            </div>
        </AuthCardLayout>
    );
}
