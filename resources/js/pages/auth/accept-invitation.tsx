import { Head, useForm, setLayoutProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/input-error';
import { Building2, CheckCircle2 } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    requiresRegistration: boolean;
    companyName: string;
    email: string;
    token: string;
}

export default function AcceptInvitation({
    requiresRegistration,
    companyName,
    email,
    token,
}: Props) {
    const registerForm = useForm({
        name: '',
        password: '',
        password_confirmation: '',
    });

    const acceptForm = useForm({});

    const handleRegister: FormEventHandler = (e) => {
        e.preventDefault();
        registerForm.post(`/invitations/${token}/register`);
    };

    const handleAccept: FormEventHandler = (e) => {
        e.preventDefault();
        acceptForm.post(`/invitations/${token}/accept`);
    };

    setLayoutProps({
        title: `Invitación a ${companyName}`,
        description: requiresRegistration
            ? 'Cree su cuenta para unirse al equipo de la empresa.'
            : 'Confirme su incorporación al equipo de la empresa.',
    });

    return (
        <>
            <Head title={`Unirse a ${companyName}`} />

            <div className="space-y-6">
                <div className="flex items-center gap-3 rounded-lg border bg-muted/40 p-3">
                    <div className="rounded-md bg-primary/10 p-2 text-primary">
                        <Building2 className="h-5 w-5" />
                    </div>
                    <div>
                        <p className="text-sm font-medium">{companyName}</p>
                        <p className="text-xs text-muted-foreground">{email}</p>
                    </div>
                </div>

                {requiresRegistration ? (
                    <form onSubmit={handleRegister} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="email">Correo electrónico</Label>
                            <Input
                                id="email"
                                value={email}
                                disabled
                                className="bg-muted text-muted-foreground"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="name">Nombre completo</Label>
                            <Input
                                id="name"
                                type="text"
                                value={registerForm.data.name}
                                onChange={(e) =>
                                    registerForm.setData('name', e.target.value)
                                }
                                required
                                autoFocus
                                placeholder="Ej. Carlos Mendoza"
                            />
                            <InputError message={registerForm.errors.name} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password">Contraseña</Label>
                            <Input
                                id="password"
                                type="password"
                                value={registerForm.data.password}
                                onChange={(e) =>
                                    registerForm.setData(
                                        'password',
                                        e.target.value,
                                    )
                                }
                                required
                                placeholder="••••••••"
                            />
                            <InputError
                                message={registerForm.errors.password}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password_confirmation">
                                Confirmar contraseña
                            </Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                value={registerForm.data.password_confirmation}
                                onChange={(e) =>
                                    registerForm.setData(
                                        'password_confirmation',
                                        e.target.value,
                                    )
                                }
                                required
                                placeholder="••••••••"
                            />
                            <InputError
                                message={
                                    registerForm.errors.password_confirmation
                                }
                            />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={registerForm.processing}
                        >
                            Crear cuenta y unirse a la empresa
                        </Button>
                    </form>
                ) : (
                    <form onSubmit={handleAccept} className="space-y-4">
                        <p className="text-center text-sm text-muted-foreground">
                            Está autenticado como <strong>{email}</strong>. Al
                            aceptar, se activará su membresía y roles
                            correspondientes.
                        </p>

                        <Button
                            type="submit"
                            className="w-full gap-2"
                            disabled={acceptForm.processing}
                        >
                            <CheckCircle2 className="h-4 w-4" />
                            Aceptar invitación y entrar al ERP
                        </Button>
                    </form>
                )}
            </div>
        </>
    );
}
