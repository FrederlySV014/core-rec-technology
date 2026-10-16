import { Form, Head } from '@inertiajs/react';
import { useRef, useState } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';

type BrowserSession = {
    id: string;
    ip_address: string;
    is_current_device: boolean;
    last_active: string;
    agent: string;
};

type Props = {
    passwordRules: string;
    sessions?: BrowserSession[];
} & ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const [logoutModalOpen, setLogoutModalOpen] = useState(false);

    return (
        <>
            <Head title="Security settings" />

            <h1 className="sr-only">Security settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Actualizar contraseña"
                    description="Asegúrate de que tu cuenta utilice una contraseña segura y compleja."
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">
                                    Contraseña actual
                                </Label>

                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    className="mt-1 block w-full"
                                    autoComplete="current-password"
                                    placeholder="Contraseña actual"
                                />

                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    Nueva contraseña
                                </Label>

                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="Nueva contraseña"
                                    passwordrules={props.passwordRules}
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirmar nueva contraseña
                                </Label>

                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="Confirmar contraseña"
                                    passwordrules={props.passwordRules}
                                />

                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    Guardar contraseña
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <div className="space-y-6 border-t pt-6">
                <Heading
                    variant="small"
                    title="Sesiones del navegador"
                    description="Consulta y administra las sesiones activas de tu cuenta en otros navegadores y dispositivos."
                />

                <div className="space-y-3">
                    {props.sessions && props.sessions.length > 0 ? (
                        props.sessions.map((sess) => (
                            <div
                                key={sess.id}
                                className="flex items-center justify-between rounded-lg border p-4 text-sm"
                            >
                                <div className="space-y-1">
                                    <p className="font-medium text-foreground">
                                        {sess.ip_address ||
                                            'Dirección IP desconocida'}
                                    </p>
                                    <p className="max-w-md truncate text-xs text-muted-foreground">
                                        {sess.agent || 'Navegador desconocido'}
                                    </p>
                                </div>
                                <div>
                                    {sess.is_current_device ? (
                                        <span className="inline-flex items-center rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                                            Este dispositivo
                                        </span>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">
                                            Última actividad: {sess.last_active}
                                        </span>
                                    )}
                                </div>
                            </div>
                        ))
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No se registran otras sesiones activas.
                        </p>
                    )}
                </div>

                <Dialog
                    open={logoutModalOpen}
                    onOpenChange={setLogoutModalOpen}
                >
                    <DialogTrigger asChild>
                        <Button variant="outline">Cerrar otras sesiones</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                Cerrar otras sesiones del navegador
                            </DialogTitle>
                            <DialogDescription>
                                Por favor ingresa tu contraseña para confirmar
                                el cierre de todas las demás sesiones en otros
                                dispositivos.
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            action="/settings/sessions"
                            method="delete"
                            onSuccess={() => setLogoutModalOpen(false)}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="session_password">
                                            Contraseña
                                        </Label>
                                        <PasswordInput
                                            id="session_password"
                                            name="password"
                                            placeholder="Ingresa tu contraseña"
                                            autoFocus
                                        />
                                        <InputError message={errors.password} />
                                    </div>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() =>
                                                setLogoutModalOpen(false)
                                            }
                                        >
                                            Cancelar
                                        </Button>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            Cerrar otras sesiones
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Security settings',
            href: edit(),
        },
    ],
};
