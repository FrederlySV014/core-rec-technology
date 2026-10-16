import { Head, useForm, router, setLayoutProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/input-error';
import {
    UserPlus,
    UserX,
    UserCheck,
    Mail,
    RefreshCw,
    XCircle,
    Building2,
    Crown,
    AlertTriangle,
} from 'lucide-react';
import { useState, FormEventHandler } from 'react';

interface Role {
    id: number;
    name: string;
    slug: string;
    is_system: boolean;
    description?: string;
}

interface Member {
    id: number;
    is_owner: boolean;
    user: {
        id: number;
        name: string;
        email: string;
        email_verified?: boolean;
        two_factor_enabled?: boolean;
    };
    status: string;
    joined_at: string | null;
    roles: Role[];
}

interface Invitation {
    id: number;
    email: string;
    status: string;
    expires_at: string;
    role_ids: number[] | null;
    invited_by: string | null;
}

interface PendingTransfer {
    id: number;
    target_user: {
        id: number;
        name: string;
        email: string;
    };
    current_owner: {
        id: number;
        name: string;
    };
    expires_at: string;
}

interface Props {
    company: {
        name: string;
        slug: string;
    };
    members: Member[];
    invitations: Invitation[];
    availableRoles: Role[];
    pendingTransfer?: PendingTransfer | null;
    isCurrentUserOwner?: boolean;
}

export default function CompanyMembersIndex({
    company,
    members,
    invitations,
    availableRoles,
    pendingTransfer,
    isCurrentUserOwner = false,
}: Props) {
    const [inviteDialogOpen, setInviteDialogOpen] = useState(false);
    const [editRoleMember, setEditRoleMember] = useState<Member | null>(null);
    const [transferModalMember, setTransferModalMember] =
        useState<Member | null>(null);

    const inviteForm = useForm({
        email: '',
        role_ids: [] as number[],
    });

    const editRolesForm = useForm({
        role_ids: [] as number[],
    });

    const transferForm = useForm({
        target_user_id: 0,
    });

    const handleInvite: FormEventHandler = (e) => {
        e.preventDefault();
        inviteForm.post(`/app/${company.slug}/members/invite`, {
            onSuccess: () => {
                setInviteDialogOpen(false);
                inviteForm.reset();
            },
        });
    };

    const handleOpenEditRoles = (member: Member) => {
        setEditRoleMember(member);
        editRolesForm.setData(
            'role_ids',
            member.roles.map((r) => r.id),
        );
    };

    const handleUpdateRoles: FormEventHandler = (e) => {
        e.preventDefault();
        if (!editRoleMember) return;

        editRolesForm.put(
            `/app/${company.slug}/members/${editRoleMember.id}/roles`,
            {
                onSuccess: () => {
                    setEditRoleMember(null);
                },
            },
        );
    };

    const handleToggleSuspension = (member: Member) => {
        if (member.is_owner) {
            alert('El propietario titular no puede ser suspendido.');
            return;
        }
        const action =
            member.status === 'suspended' ? 'reactivar' : 'suspender';
        if (
            confirm(
                `¿Está seguro de ${action} el acceso de ${member.user.email} en ${company.name}?`,
            )
        ) {
            router.post(
                `/app/${company.slug}/members/${member.id}/toggle-suspension`,
            );
        }
    };

    const handleRevoke = (member: Member) => {
        if (member.is_owner) {
            alert(
                'El propietario titular no puede ser revocado. Debe transferir la titularidad primero.',
            );
            return;
        }
        if (
            confirm(
                `¿Desea revocar la membresía de ${member.user.email} en esta empresa?`,
            )
        ) {
            router.delete(`/app/${company.slug}/members/${member.id}`);
        }
    };

    const handleResendInvite = (invitation: Invitation) => {
        router.post(
            `/app/${company.slug}/members/invitations/${invitation.id}/resend`,
        );
    };

    const handleCancelInvite = (invitation: Invitation) => {
        if (confirm(`¿Cancelar la invitación enviada a ${invitation.email}?`)) {
            router.delete(
                `/app/${company.slug}/members/invitations/${invitation.id}`,
            );
        }
    };

    const handleOpenTransferModal = (member: Member) => {
        setTransferModalMember(member);
        transferForm.setData('target_user_id', member.user.id);
    };

    const handleInitiateTransfer: FormEventHandler = (e) => {
        e.preventDefault();
        if (!transferModalMember) return;

        transferForm.post(`/app/${company.slug}/ownership-transfers`, {
            onSuccess: () => {
                setTransferModalMember(null);
            },
        });
    };

    const handleCancelPendingTransfer = () => {
        if (
            confirm(
                '¿Desea cancelar la solicitud de transferencia de titularidad en curso?',
            )
        ) {
            router.post(`/app/${company.slug}/ownership-transfers/cancel`);
        }
    };

    setLayoutProps({
        breadcrumbs: [
            { title: company.name, href: `/app/${company.slug}` },
            {
                title: 'Miembros e Invitaciones',
                href: `/app/${company.slug}/members`,
            },
        ],
    });

    return (
        <>
            <Head title={`Miembros - ${company.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <div className="flex items-center gap-2">
                            <Building2 className="h-5 w-5 text-muted-foreground" />
                            <h1 className="text-2xl font-bold tracking-tight">
                                Miembros de la Empresa
                            </h1>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Gestione los accesos, roles comerciales e
                            invitaciones del equipo en {company.name}.
                        </p>
                    </div>

                    <Dialog
                        open={inviteDialogOpen}
                        onOpenChange={setInviteDialogOpen}
                    >
                        <DialogTrigger asChild>
                            <Button className="gap-2">
                                <UserPlus className="h-4 w-4" />
                                Invitar Miembro
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form onSubmit={handleInvite} className="space-y-4">
                                <DialogHeader>
                                    <DialogTitle>
                                        Invitar Miembro por Correo
                                    </DialogTitle>
                                    <DialogDescription>
                                        Se enviará un token seguro de invitación
                                        única con validez de 7 días.
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="space-y-3">
                                    <div className="space-y-1">
                                        <Label htmlFor="inv-email">
                                            Correo electrónico
                                        </Label>
                                        <Input
                                            id="inv-email"
                                            type="email"
                                            value={inviteForm.data.email}
                                            onChange={(e) =>
                                                inviteForm.setData(
                                                    'email',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                            placeholder="empleado@empresa.com"
                                        />
                                        <InputError
                                            message={inviteForm.errors.email}
                                        />
                                    </div>

                                    <div className="space-y-2 pt-2">
                                        <Label>
                                            Roles comerciales a asignar
                                        </Label>
                                        <div className="max-h-48 space-y-2 overflow-y-auto rounded-md border p-3">
                                            {availableRoles
                                                .filter(
                                                    (role) =>
                                                        role.slug !==
                                                        'company_owner',
                                                )
                                                .map((role) => {
                                                    const isSelected =
                                                        inviteForm.data.role_ids.includes(
                                                            role.id,
                                                        );
                                                    return (
                                                        <div
                                                            key={role.id}
                                                            className="flex cursor-pointer items-start space-x-2 rounded p-1.5 hover:bg-muted/50"
                                                            onClick={() => {
                                                                if (
                                                                    isSelected
                                                                ) {
                                                                    inviteForm.setData(
                                                                        'role_ids',
                                                                        inviteForm.data.role_ids.filter(
                                                                            (
                                                                                id,
                                                                            ) =>
                                                                                id !==
                                                                                role.id,
                                                                        ),
                                                                    );
                                                                } else {
                                                                    inviteForm.setData(
                                                                        'role_ids',
                                                                        [
                                                                            ...inviteForm
                                                                                .data
                                                                                .role_ids,
                                                                            role.id,
                                                                        ],
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            <Checkbox
                                                                id={`inv-role-${role.id}`}
                                                                checked={
                                                                    isSelected
                                                                }
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) => {
                                                                    if (
                                                                        checked
                                                                    ) {
                                                                        inviteForm.setData(
                                                                            'role_ids',
                                                                            [
                                                                                ...inviteForm
                                                                                    .data
                                                                                    .role_ids,
                                                                                role.id,
                                                                            ],
                                                                        );
                                                                    } else {
                                                                        inviteForm.setData(
                                                                            'role_ids',
                                                                            inviteForm.data.role_ids.filter(
                                                                                (
                                                                                    id,
                                                                                ) =>
                                                                                    id !==
                                                                                    role.id,
                                                                            ),
                                                                        );
                                                                    }
                                                                }}
                                                            />
                                                            <div className="grid gap-0.5 leading-none">
                                                                <span className="cursor-pointer text-xs font-medium">
                                                                    {role.name}
                                                                </span>
                                                                {role.description && (
                                                                    <p className="text-[11px] text-muted-foreground">
                                                                        {
                                                                            role.description
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                        </div>
                                        <InputError
                                            message={inviteForm.errors.role_ids}
                                        />
                                    </div>
                                </div>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setInviteDialogOpen(false)
                                        }
                                    >
                                        Cancelar
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={inviteForm.processing}
                                    >
                                        Emitir Invitación
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {/* Pending Ownership Transfer Banner */}
                {pendingTransfer && (
                    <div className="flex flex-col items-start justify-between gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-amber-900 sm:flex-row sm:items-center dark:text-amber-200">
                        <div className="flex items-start gap-3">
                            <Crown className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div>
                                <p className="text-sm font-semibold">
                                    Transferencia de Titularidad en Curso
                                </p>
                                <p className="mt-0.5 text-xs">
                                    Solicitud emitida para{' '}
                                    <strong>
                                        {pendingTransfer.target_user.name}
                                    </strong>{' '}
                                    ({pendingTransfer.target_user.email}). Vence
                                    el{' '}
                                    {new Date(
                                        pendingTransfer.expires_at,
                                    ).toLocaleString()}
                                    .
                                </p>
                            </div>
                        </div>
                        {isCurrentUserOwner && (
                            <Button
                                size="sm"
                                variant="outline"
                                className="border-amber-600 text-amber-700 hover:bg-amber-100 dark:border-amber-400 dark:text-amber-300 dark:hover:bg-amber-950"
                                onClick={handleCancelPendingTransfer}
                            >
                                Cancelar Solicitud
                            </Button>
                        )}
                    </div>
                )}

                {/* Active Members Section */}
                <div className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">
                        Miembros Activos ({members.length})
                    </h2>

                    <div className="grid gap-3">
                        {members.map((member) => (
                            <Card
                                key={member.id}
                                className={
                                    member.status === 'suspended'
                                        ? 'border-destructive/30 bg-destructive/5'
                                        : ''
                                }
                            >
                                <CardHeader className="flex flex-col items-start justify-between gap-3 space-y-0 pb-3 sm:flex-row sm:items-center">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <CardTitle className="text-base font-semibold">
                                                {member.user.name}
                                            </CardTitle>
                                            {member.is_owner && (
                                                <Badge className="gap-1 border-0 bg-amber-600 text-white hover:bg-amber-700">
                                                    <Crown className="h-3 w-3" />
                                                    Propietario Titular
                                                </Badge>
                                            )}
                                            {member.status === 'suspended' ? (
                                                <Badge variant="destructive">
                                                    Membresía Suspendida
                                                </Badge>
                                            ) : (
                                                <Badge
                                                    variant="secondary"
                                                    className="border-emerald-200 bg-emerald-50 text-emerald-700"
                                                >
                                                    Activo
                                                </Badge>
                                            )}
                                        </div>
                                        <CardDescription>
                                            {member.user.email}
                                        </CardDescription>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2">
                                        {/* Transfer button for owner */}
                                        {isCurrentUserOwner &&
                                            !member.is_owner &&
                                            member.status === 'active' &&
                                            !pendingTransfer && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="gap-1 border-amber-500/50 text-amber-700 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-950"
                                                    onClick={() =>
                                                        handleOpenTransferModal(
                                                            member,
                                                        )
                                                    }
                                                >
                                                    <Crown className="h-3.5 w-3.5" />
                                                    Transferir Titularidad
                                                </Button>
                                            )}

                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                handleOpenEditRoles(member)
                                            }
                                            disabled={member.is_owner}
                                            title={
                                                member.is_owner
                                                    ? 'Los roles del propietario no se modifican directamente'
                                                    : 'Editar roles'
                                            }
                                        >
                                            Roles
                                        </Button>

                                        {!member.is_owner && (
                                            <>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        handleToggleSuspension(
                                                            member,
                                                        )
                                                    }
                                                >
                                                    {member.status ===
                                                    'suspended' ? (
                                                        <>
                                                            <UserCheck className="mr-1 h-3.5 w-3.5 text-emerald-600" />
                                                            Reactivar
                                                        </>
                                                    ) : (
                                                        <>
                                                            <UserX className="mr-1 h-3.5 w-3.5 text-amber-600" />
                                                            Suspender
                                                        </>
                                                    )}
                                                </Button>

                                                <Button
                                                    variant="destructive"
                                                    size="sm"
                                                    onClick={() =>
                                                        handleRevoke(member)
                                                    }
                                                >
                                                    Revocar
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex flex-wrap gap-2">
                                        {member.roles.map((r) => (
                                            <Badge
                                                key={r.id}
                                                variant="outline"
                                                className="text-xs"
                                            >
                                                {r.name}
                                            </Badge>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </div>

                {/* Invitations Section */}
                {invitations.length > 0 && (
                    <div className="space-y-3 pt-4">
                        <h2 className="text-lg font-semibold tracking-tight">
                            Invitaciones ({invitations.length})
                        </h2>

                        <div className="grid gap-3">
                            {invitations.map((inv) => (
                                <Card key={inv.id} className="border-dashed">
                                    <CardHeader className="flex flex-row items-center justify-between space-y-0 py-3">
                                        <div className="flex items-center gap-3">
                                            <div className="rounded-full bg-muted p-2">
                                                <Mail className="h-4 w-4 text-muted-foreground" />
                                            </div>
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {inv.email}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    Expira:{' '}
                                                    {new Date(
                                                        inv.expires_at,
                                                    ).toLocaleDateString()}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant={
                                                    inv.status === 'pending'
                                                        ? 'secondary'
                                                        : inv.status ===
                                                            'accepted'
                                                          ? 'default'
                                                          : 'destructive'
                                                }
                                            >
                                                {inv.status === 'pending'
                                                    ? 'Pendiente'
                                                    : inv.status === 'accepted'
                                                      ? 'Aceptada'
                                                      : inv.status === 'expired'
                                                        ? 'Expirada'
                                                        : 'Cancelada'}
                                            </Badge>

                                            {inv.status === 'pending' && (
                                                <>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            handleResendInvite(
                                                                inv,
                                                            )
                                                        }
                                                        title="Reenviar invitación"
                                                    >
                                                        <RefreshCw className="h-3.5 w-3.5" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            handleCancelInvite(
                                                                inv,
                                                            )
                                                        }
                                                        className="text-destructive hover:text-destructive"
                                                        title="Cancelar invitación"
                                                    >
                                                        <XCircle className="h-3.5 w-3.5" />
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                    </CardHeader>
                                </Card>
                            ))}
                        </div>
                    </div>
                )}

                {/* Edit Roles Dialog */}
                {editRoleMember && (
                    <Dialog
                        open={true}
                        onOpenChange={(open) =>
                            !open && setEditRoleMember(null)
                        }
                    >
                        <DialogContent>
                            <form
                                onSubmit={handleUpdateRoles}
                                className="space-y-4"
                            >
                                <DialogHeader>
                                    <DialogTitle>
                                        Modificar Roles de Membresía
                                    </DialogTitle>
                                    <DialogDescription>
                                        Asigne los roles que desempeñará{' '}
                                        <strong>
                                            {editRoleMember.user.name}
                                        </strong>{' '}
                                        en {company.name}.
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="max-h-56 space-y-2 overflow-y-auto rounded-md border p-3">
                                    {availableRoles
                                        .filter(
                                            (role) =>
                                                role.slug !== 'company_owner',
                                        )
                                        .map((role) => (
                                            <div
                                                key={role.id}
                                                className="flex items-start space-x-2"
                                            >
                                                <Checkbox
                                                    id={`edit-role-${role.id}`}
                                                    checked={editRolesForm.data.role_ids.includes(
                                                        role.id,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) => {
                                                        if (checked) {
                                                            editRolesForm.setData(
                                                                'role_ids',
                                                                [
                                                                    ...editRolesForm
                                                                        .data
                                                                        .role_ids,
                                                                    role.id,
                                                                ],
                                                            );
                                                        } else {
                                                            editRolesForm.setData(
                                                                'role_ids',
                                                                editRolesForm.data.role_ids.filter(
                                                                    (id) =>
                                                                        id !==
                                                                        role.id,
                                                                ),
                                                            );
                                                        }
                                                    }}
                                                />
                                                <div className="grid gap-0.5 leading-none">
                                                    <label
                                                        htmlFor={`edit-role-${role.id}`}
                                                        className="cursor-pointer text-xs font-medium"
                                                    >
                                                        {role.name}
                                                    </label>
                                                    {role.description && (
                                                        <p className="text-[11px] text-muted-foreground">
                                                            {role.description}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                </div>
                                <InputError
                                    message={editRolesForm.errors.role_ids}
                                />

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setEditRoleMember(null)}
                                    >
                                        Cancelar
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={editRolesForm.processing}
                                    >
                                        Guardar Roles
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                )}

                {/* Transfer Ownership Dialog */}
                {transferModalMember && (
                    <Dialog
                        open={true}
                        onOpenChange={(open) =>
                            !open && setTransferModalMember(null)
                        }
                    >
                        <DialogContent>
                            <form
                                onSubmit={handleInitiateTransfer}
                                className="space-y-4"
                            >
                                <DialogHeader>
                                    <DialogTitle className="flex items-center gap-2">
                                        <Crown className="h-5 w-5 text-amber-600" />
                                        Transferir Titularidad Exclusiva
                                    </DialogTitle>
                                    <DialogDescription>
                                        Iniciará la solicitud formal para
                                        transferir la propiedad de{' '}
                                        <strong>{company.name}</strong> a{' '}
                                        <strong>
                                            {transferModalMember.user.name}
                                        </strong>{' '}
                                        ({transferModalMember.user.email}).
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="space-y-3 text-sm">
                                    <div className="space-y-1.5 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200">
                                        <p className="flex items-center gap-1.5 font-semibold">
                                            <AlertTriangle className="h-4 w-4 shrink-0" />
                                            Operación Crítica y Transaccional
                                        </p>
                                        <p>
                                            El destinatario debe tener correo
                                            verificado y 2FA confirmado. Al
                                            aceptar, asumirá la titularidad
                                            exclusiva y usted pasará a ser
                                            Administrador Comercial.
                                        </p>
                                        <p className="text-[11px] opacity-80">
                                            Esta solicitud vencerá en 48 horas o
                                            si decide cancelarla manualmente.
                                        </p>
                                    </div>
                                    <InputError
                                        message={
                                            transferForm.errors.target_user_id
                                        }
                                    />
                                </div>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setTransferModalMember(null)
                                        }
                                    >
                                        Cancelar
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={transferForm.processing}
                                        className="bg-amber-600 text-white hover:bg-amber-700"
                                    >
                                        {transferForm.processing
                                            ? 'Iniciando...'
                                            : 'Confirmar e Iniciar Transferencia'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                )}
            </div>
        </>
    );
}
