import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AuthCardLayout from '@/layouts/auth/auth-card-layout';
import { ShieldAlert, ArrowLeft } from 'lucide-react';

interface Props {
    company: {
        legal_name: string;
        slug: string;
    };
    message?: string;
}

export default function OwnershipTransferInvalid({
    company,
    message = 'La solicitud de transferencia de titularidad no es válida, ha vencido o fue cancelada por el propietario actual.',
}: Props) {
    return (
        <AuthCardLayout
            title="Solicitud no disponible"
            description={company.legal_name}
        >
            <Head title={`Solicitud Inválida - ${company.legal_name}`} />

            <div className="space-y-6 text-center">
                <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
                    <ShieldAlert className="h-6 w-6" />
                </div>

                <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">{message}</p>
                </div>

                <div className="pt-2">
                    <Button variant="outline" className="w-full gap-2" asChild>
                        <Link href={`/app/${company.slug}`}>
                            <ArrowLeft className="h-4 w-4" />
                            Volver a la empresa
                        </Link>
                    </Button>
                </div>
            </div>
        </AuthCardLayout>
    );
}
