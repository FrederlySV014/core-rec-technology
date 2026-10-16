import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

type CompanyMembership = {
    id: number;
    company: {
        id: number;
        legal_name: string;
        trade_name?: string | null;
        slug: string;
        country_code: string;
    };
    roles: Array<{
        id: number;
        name: string;
        slug: string;
    }>;
    license: {
        status: string;
        is_read_only: boolean;
        enabled_modules: string[];
        plan_name?: string | null;
    };
};

type DashboardProps = {
    memberships: CompanyMembership[];
    canAccessPlatform: boolean;
};

export default function Dashboard({
    memberships,
    canAccessPlatform,
}: DashboardProps) {
    return (
        <>
            <Head title="Selección de Empresa" />
            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-bold tracking-tight">
                        Acceso Central
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Selecciona la empresa con la que deseas operar o
                        gestiona la plataforma si cuentas con privilegios
                        administrativos.
                    </p>
                </div>

                {canAccessPlatform && (
                    <div className="flex items-center justify-between rounded-xl border border-primary/20 bg-primary/5 p-4">
                        <div>
                            <p className="font-semibold text-primary">
                                Operador de Plataforma SaaS
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Tu usuario cuenta con roles administrativos
                                sobre el catálogo central, licencias y
                                auditoría.
                            </p>
                        </div>
                        <Button variant="outline" asChild>
                            <Link href="/platform/staff">
                                Administración SaaS
                            </Link>
                        </Button>
                    </div>
                )}

                <div>
                    <h2 className="mb-4 text-lg font-semibold">
                        Empresas Autorizadas
                    </h2>
                    {memberships.length === 0 ? (
                        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed p-8 text-center">
                            <p className="text-base font-medium">
                                Sin empresas disponibles
                            </p>
                            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                                No posees membresías activas en ninguna empresa.
                                Contacta al administrador comercial de tu
                                organización para recibir una invitación o
                                asignación.
                            </p>
                        </div>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            {memberships.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex flex-col justify-between rounded-xl border bg-card p-5 text-card-foreground shadow-xs transition-shadow hover:shadow-md"
                                >
                                    <div className="space-y-3">
                                        <div>
                                            <h3 className="leading-none font-semibold tracking-tight">
                                                {item.company.legal_name}
                                            </h3>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {item.company.trade_name
                                                    ? `${item.company.trade_name} • `
                                                    : ''}
                                                {item.company.slug} (
                                                {item.company.country_code})
                                            </p>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <span
                                                className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${
                                                    item.license.status ===
                                                    'active'
                                                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                        : item.license
                                                                .status ===
                                                            'grace_period'
                                                          ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                                          : 'bg-rose-500/10 text-rose-700 dark:text-rose-300'
                                                }`}
                                            >
                                                Licencia:{' '}
                                                {item.license.status.replace(
                                                    '_',
                                                    ' ',
                                                )}
                                            </span>

                                            {item.license.is_read_only && (
                                                <span className="inline-flex items-center rounded-full bg-amber-500/10 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-300">
                                                    Solo lectura
                                                </span>
                                            )}
                                        </div>

                                        <div className="text-xs text-muted-foreground">
                                            <span>Roles: </span>
                                            <span className="font-medium text-foreground">
                                                {item.roles
                                                    .map((r) => r.name)
                                                    .join(', ') ||
                                                    'Sin roles asignados'}
                                            </span>
                                        </div>
                                    </div>

                                    <div className="mt-5 border-t pt-3">
                                        <Button className="w-full" asChild>
                                            <Link
                                                href={`/app/${item.company.slug}`}
                                            >
                                                Ingresar a{' '}
                                                {item.company.trade_name ||
                                                    item.company.legal_name}
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
