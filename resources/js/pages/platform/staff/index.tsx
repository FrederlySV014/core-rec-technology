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
    Shield,
    UserPlus,
    UserX,
    UserCheck,
    ShieldAlert,
    KeyRound,
} from 'lucide-react';
import { useState, FormEventHandler } from 'react';

interface Permission {
    id: number;
    name: string;
    slug: string;
    description?: string;
}

interface PlatformRole {
    id: number;
    name: string;
    slug: string;
    description: string;
    permissions: Permission[];
}

interface OperatorRole {
    id: number;
    name: string;
    slug: string;
    description: string;
    assigned_at: string;
    suspended_at: string | null;
    permissions: Permission[];
}

interface Operator {
    id: number;
    name: string;
    email: string;
    global_status: string;
    is_platform_suspended: boolean;
    roles: OperatorRole[];
}

interface Props {
    operators: Operator[];
    platformRoles: PlatformRole[];
    canManage: boolean;
}

export default function PlatformStaffIndex({
    operators,
    platformRoles,
    canManage,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Plataforma', href: '/platform/staff' },
            { title: 'Personal SaaS', href: '/platform/staff' },
        ],
    });

    const [createDialogOpen, setCreateDialogOpen] = useState(false);
    const [editRoleUser, setEditRoleUser] = useState<Operator | null>(null);

    const createForm = useForm({
        email: '',
        name: '',
        role_ids: [] as number[],
    });

    const editRolesForm = useForm({
        role_ids: [] as number[],
    });

    const handleCreate: FormEventHandler = (e) => {
        e.preventDefault();
        createForm.post('/platform/staff', {
            onSuccess: () => {
                setCreateDialogOpen(false);
                createForm.reset();
            },
        });
    };

    const handleOpenEditRoles = (operator: Operator) => {
        setEditRoleUser(operator);
        editRolesForm.setData(
            'role_ids',
            operator.roles.map((r) => r.id),
        );
    };

    const handleUpdateRoles: FormEventHandler = (e) => {
        e.preventDefault();
        if (!editRoleUser) return;

        editRolesForm.put(`/platform/staff/${editRoleUser.id}/roles`, {
            onSuccess: () => {
                setEditRoleUser(null);
            },
        });
    };

    const handleToggleSuspension = (operator: Operator) => {
        const action = operator.is_platform_suspended
            ? 'reactivar'
            : 'suspender';
        if (
            confirm(
                `¿Está seguro de ${action} el acceso de plataforma para ${operator.email}?`,
            )
        ) {
            router.post(`/platform/staff/${operator.id}/toggle-suspension`);
        }
    };

    const handleRevokeAccess = (operator: Operator) => {
        if (
            confirm(
                `¿Desea retirar completamente los roles de plataforma de ${operator.email}? Sus membresías empresariales y cuenta global se conservarán intactas.`,
            )
        ) {
            router.delete(`/platform/staff/${operator.id}`);
        }
    };

    return (
        <>
            <Head title="Personal de Plataforma SaaS" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Personal de Plataforma SaaS
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Administración de operadores, asignación de roles de
                            plataforma y gobernanza de accesos centrales.
                        </p>
                    </div>

                    {canManage && (
                        <Dialog
                            open={createDialogOpen}
                            onOpenChange={setCreateDialogOpen}
                        >
                            <DialogTrigger asChild>
                                <Button className="gap-2">
                                    <UserPlus className="h-4 w-4" />
                                    Incorporar Operador
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <form
                                    onSubmit={handleCreate}
                                    className="space-y-4"
                                >
                                    <DialogHeader>
                                        <DialogTitle>
                                            Incorporar Operador de Plataforma
                                        </DialogTitle>
                                        <DialogDescription>
                                            Asigne roles administrativos a un
                                            usuario nuevo o existente para
                                            operar el SaaS.
                                        </DialogDescription>
                                    </DialogHeader>

                                    <div className="space-y-3">
                                        <div className="space-y-1">
                                            <Label htmlFor="op-email">
                                                Correo electrónico
                                            </Label>
                                            <Input
                                                id="op-email"
                                                type="email"
                                                value={createForm.data.email}
                                                onChange={(e) =>
                                                    createForm.setData(
                                                        'email',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                                placeholder="operador@plataforma.com"
                                            />
                                            <InputError
                                                message={
                                                    createForm.errors.email
                                                }
                                            />
                                        </div>

                                        <div className="space-y-1">
                                            <Label htmlFor="op-name">
                                                Nombre completo (opcional si ya
                                                existe)
                                            </Label>
                                            <Input
                                                id="op-name"
                                                value={createForm.data.name}
                                                onChange={(e) =>
                                                    createForm.setData(
                                                        'name',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Nombre del operador"
                                            />
                                            <InputError
                                                message={createForm.errors.name}
                                            />
                                        </div>

                                        <div className="space-y-2 pt-2">
                                            <Label>Roles de plataforma</Label>
                                            <div className="max-h-48 space-y-2 overflow-y-auto rounded-md border p-3">
                                                {platformRoles.map((role) => (
                                                    <div
                                                        key={role.id}
                                                        className="flex items-start space-x-2"
                                                    >
                                                        <Checkbox
                                                            id={`role-${role.id}`}
                                                            checked={createForm.data.role_ids.includes(
                                                                role.id,
                                                            )}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) => {
                                                                if (checked) {
                                                                    createForm.setData(
                                                                        'role_ids',
                                                                        [
                                                                            ...createForm
                                                                                .data
                                                                                .role_ids,
                                                                            role.id,
                                                                        ],
                                                                    );
                                                                } else {
                                                                    createForm.setData(
                                                                        'role_ids',
                                                                        createForm.data.role_ids.filter(
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
                                                            <label
                                                                htmlFor={`role-${role.id}`}
                                                                className="cursor-pointer text-xs font-medium"
                                                            >
                                                                {role.name}
                                                            </label>
                                                            <p className="text-[11px] text-muted-foreground">
                                                                {
                                                                    role.description
                                                                }
                                                            </p>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                            <InputError
                                                message={
                                                    createForm.errors.role_ids
                                                }
                                            />
                                        </div>
                                    </div>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                setCreateDialogOpen(false)
                                            }
                                        >
                                            Cancelar
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={createForm.processing}
                                        >
                                            Guardar Operador
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    )}
                </div>

                <div className="grid gap-4">
                    {operators.map((op) => (
                        <Card
                            key={op.id}
                            className={
                                op.is_platform_suspended
                                    ? 'border-destructive/30 bg-destructive/5'
                                    : ''
                            }
                        >
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <CardTitle className="text-base font-semibold">
                                            {op.name}
                                        </CardTitle>
                                        {op.is_platform_suspended ? (
                                            <Badge
                                                variant="destructive"
                                                className="gap-1"
                                            >
                                                <ShieldAlert className="h-3 w-3" />
                                                Acceso SaaS Suspendido
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="secondary"
                                                className="gap-1"
                                            >
                                                <Shield className="h-3 w-3 text-emerald-600" />
                                                Operador Activo
                                            </Badge>
                                        )}
                                    </div>
                                    <CardDescription>
                                        {op.email}
                                    </CardDescription>
                                </div>

                                {canManage && (
                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="gap-1"
                                            onClick={() =>
                                                handleOpenEditRoles(op)
                                            }
                                        >
                                            <KeyRound className="h-3.5 w-3.5" />
                                            Roles
                                        </Button>

                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="gap-1"
                                            onClick={() =>
                                                handleToggleSuspension(op)
                                            }
                                        >
                                            {op.is_platform_suspended ? (
                                                <>
                                                    <UserCheck className="h-3.5 w-3.5 text-emerald-600" />
                                                    Reactivar
                                                </>
                                            ) : (
                                                <>
                                                    <UserX className="h-3.5 w-3.5 text-amber-600" />
                                                    Suspender
                                                </>
                                            )}
                                        </Button>

                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            onClick={() =>
                                                handleRevokeAccess(op)
                                            }
                                        >
                                            Retirar Acceso
                                        </Button>
                                    </div>
                                )}
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Roles asignados:
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {op.roles.map((r) => (
                                            <Badge
                                                key={r.id}
                                                variant="outline"
                                                className="px-2 py-0.5"
                                            >
                                                {r.name}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Edit Roles Dialog */}
                {editRoleUser && (
                    <Dialog
                        open={true}
                        onOpenChange={(open) => !open && setEditRoleUser(null)}
                    >
                        <DialogContent>
                            <form
                                onSubmit={handleUpdateRoles}
                                className="space-y-4"
                            >
                                <DialogHeader>
                                    <DialogTitle>
                                        Editar Roles de Plataforma
                                    </DialogTitle>
                                    <DialogDescription>
                                        Modifique los roles asignados a{' '}
                                        <strong>{editRoleUser.email}</strong>.
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="space-y-2">
                                    <Label>Seleccionar roles</Label>
                                    <div className="max-h-56 space-y-2 overflow-y-auto rounded-md border p-3">
                                        {platformRoles.map((role) => (
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
                                                    <p className="text-[11px] text-muted-foreground">
                                                        {role.description}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    <InputError
                                        message={editRolesForm.errors.role_ids}
                                    />
                                </div>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setEditRoleUser(null)}
                                    >
                                        Cancelar
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={editRolesForm.processing}
                                    >
                                        Actualizar Roles
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
