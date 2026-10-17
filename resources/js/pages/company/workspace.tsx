import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

type WorkspaceProps = {
    company: {
        legal_name: string;
        trade_name?: string | null;
        slug: string;
    };
    license: {
        status: string;
        is_read_only: boolean;
        plan_name?: string | null;
        enabled_modules: string[];
    };
    effective_permissions: string[];
};

export default function Workspace({
    company,
    license,
    effective_permissions,
}: WorkspaceProps) {
    return (
        <>
            <Head title={`Espacio: ${company.legal_name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between border-b pb-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            {company.legal_name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {company.trade_name
                                ? `${company.trade_name} • `
                                : ''}
                            Slug: {company.slug}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="default" asChild>
                            <Link href={`/app/${company.slug}/catalog`}>
                                Catálogo Comercial
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={dashboard()}>Cambiar de empresa</Link>
                        </Button>
                    </div>
                </div>

                {license.is_read_only && (
                    <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-amber-900 dark:text-amber-200">
                        <p className="font-medium">
                            Modo solo lectura (Período de Gracia)
                        </p>
                        <p className="text-sm">
                            La licencia de la empresa ha finalizado su cobertura
                            regular y se encuentra en período de gracia. Solo se
                            permiten consultas; las modificaciones comerciales
                            están suspendidas.
                        </p>
                    </div>
                )}

                <div className="grid gap-6 md:grid-cols-2">
                    <div className="rounded-xl border bg-card p-6 text-card-foreground shadow-xs">
                        <h2 className="mb-2 text-lg font-semibold">
                            Estado de Contratación
                        </h2>
                        <div className="space-y-2 text-sm">
                            <p>
                                <span className="text-muted-foreground">
                                    Plan:
                                </span>{' '}
                                <span className="font-medium">
                                    {license.plan_name || 'Estándar'}
                                </span>
                            </p>
                            <p>
                                <span className="text-muted-foreground">
                                    Estado de Licencia:
                                </span>{' '}
                                <span className="font-medium capitalize">
                                    {license.status}
                                </span>
                            </p>
                            <div>
                                <span className="text-muted-foreground">
                                    Módulos contratados:
                                </span>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {license.enabled_modules.length > 0 ? (
                                        license.enabled_modules.map((mod) => (
                                            <span
                                                key={mod}
                                                className="inline-flex items-center rounded-md bg-secondary px-2.5 py-0.5 text-xs font-medium text-secondary-foreground"
                                            >
                                                {mod}
                                            </span>
                                        ))
                                    ) : (
                                        <span className="text-muted-foreground">
                                            Ningún módulo habilitado
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="rounded-xl border bg-card p-6 text-card-foreground shadow-xs">
                        <h2 className="mb-2 text-lg font-semibold">
                            Permisos Efectivos
                        </h2>
                        <p className="mb-3 text-xs text-muted-foreground">
                            Permisos otorgados por sus roles vigentes dentro de
                            esta empresa y habilitados por la licencia activa.
                        </p>
                        <div className="max-h-60 space-y-1 overflow-y-auto text-xs">
                            {effective_permissions.length > 0 ? (
                                effective_permissions.map((perm) => (
                                    <div
                                        key={perm}
                                        className="rounded bg-muted/50 px-2 py-1 font-mono"
                                    >
                                        {perm}
                                    </div>
                                ))
                            ) : (
                                <p className="text-muted-foreground">
                                    No dispone de permisos activos en esta
                                    empresa.
                                </p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
