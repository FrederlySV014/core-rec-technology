import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { AlertCircle } from 'lucide-react';

interface Props {
    message: string;
}

export default function InvitationInvalid({ message }: Props) {
    setLayoutProps({
        title: 'Invitación no disponible',
        description: 'El enlace al que intenta acceder no se encuentra activo.',
    });

    return (
        <>
            <Head title="Invitación no disponible" />

            <div className="flex flex-col items-center space-y-4 py-4 text-center">
                <div className="rounded-full bg-destructive/10 p-3 text-destructive">
                    <AlertCircle className="h-8 w-8" />
                </div>
                <p className="text-sm text-muted-foreground">{message}</p>
                <div className="w-full pt-2">
                    <Button asChild className="w-full">
                        <Link href="/login">Ir a Iniciar Sesión</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
