import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';

type Props = {
    status?: string;
};

export default function VerifyEmail({ status }: Props) {
    return (
        <>
            <Head title="Verificación de Correo" />

            <div className="mb-4 text-sm text-muted-foreground">
                Gracias por formar parte de la plataforma. Antes de comenzar,
                por favor verifica tu dirección de correo electrónico haciendo
                clic en el enlace que acabamos de enviarte. Si no recibiste el
                correo, con gusto te enviaremos otro.
            </div>

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-sm font-medium text-green-600 dark:text-green-400">
                    Se ha enviado un nuevo enlace de verificación a la dirección
                    de correo electrónico proporcionada.
                </div>
            )}

            <div className="mt-4 flex items-center justify-between gap-4">
                <Form action="/email/verification-notification" method="post">
                    {({ processing }) => (
                        <Button disabled={processing}>
                            Reenviar correo de verificación
                        </Button>
                    )}
                </Form>

                <Form action={logout.url()} method="post">
                    <Button variant="ghost" type="submit">
                        Cerrar sesión
                    </Button>
                </Form>
            </div>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verificar correo electrónico',
    description: 'Confirma tu correo para acceder a todas las funciones',
};
