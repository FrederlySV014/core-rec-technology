import { Head, useForm, router, setLayoutProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { ShieldCheck, Plus, Lock, Users, Pencil, Trash2 } from 'lucide-react';
import { useState, FormEventHandler } from 'react';

interface Permission {
    id: number;
    name: string;
    slug: string;
    description?: string;
    module_name?: string;
}

interface CompanyRole {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_system: boolean;
    members_count: number;
    permissions: Permission[];
}

interface Props {
    company: {
        name: string;
        slug: string;
    };
    roles: CompanyRole[];
    availablePermissions: Permission[];
}

export default function CompanyRolesIndex({
    company,
    roles,
    availablePermissions,
}: Props) {
    const [createDialogOpen, setCreateDialogOpen] = useState(false);
    const [editRole, setEditRole] = useState<CompanyRole | null>(null);

    const createForm = useForm({
        name: '',
        slug: '',
        description: '',
        permission_ids: [] as number[],
    });

    const editForm = useForm({
        name: '',
        description: '',
        permission_ids: [] as number[],
    });

    const handleCreate: FormEventHandler = (e) => {
        e.preventDefault();
        createForm.post(`/app/${company.slug}/roles`, {
            onSuccess: () => {
                setCreateDialogOpen(false);
                createForm.reset();
            },
        });
    };

    const handleOpenEdit = (role: CompanyRole) => {
        setEditRole(role);
        editForm.setData({
            name: role.name,
            description: role.description || '',
            permission_ids: role.permissions.map((p) => p.id),
        });
    };

    const handleUpdate: FormEventHandler = (e) => {
        e.preventDefault();
        if (!editRole) return;

        editForm.put(`/app/${company.slug}/roles/${editRole.id}`, {
            onSuccess: () => {
                setEditRole(null);
            },
        });
    };

    const handleDelete = (role: CompanyRole) => {
        if (confirm(`¿Está seguro de eliminar el rol "${role.name}"?`)) {
            router.delete(`/app/${company.slug}/roles/${role.id}`);
        }
    };

    // Group available permissions by module
    const permissionsByModule = availablePermissions.reduce(
        (acc, perm) => {
            const mod = perm.module_name || 'General';
            if (!acc[mod]) acc[mod] = [];
            acc[mod].push(perm);
            return acc;
        },
        {} as Record<string, Permission[]>,
    );

    setLayoutProps({
        breadcrumbs: [
            { title: company.name, href: `/app/${company.slug}` },
            { title: 'Roles y Permisos', href: `/app/${company.slug}/roles` },
        ],
    });

    return (
        <>
            <Head title={`Roles - ${company.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <div className="flex items-center gap-2">
                            <ShieldCheck className="h-5 w-5 text-muted-foreground" />
                            <h1 className="text-2xl font-bold tracking-tight">
                                Roles y Permisos Comerciales
                            </h1>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Configure perfiles de acceso y permisos dentro de{' '}
                            {company.name}.
                        </p>
                    </div>

                    <Dialog
                        open={createDialogOpen}
                        onOpenChange={setCreateDialogOpen}
                    >
                        <DialogTrigger asChild>
                            <Button className="gap-2">
                                <Plus className="h-4 w-4" />
                                Crear Rol Personalizado
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-w-2xl">
                            <form onSubmit={handleCreate} className="space-y-4">
                                <DialogHeader>
                                    <DialogTitle>
                                        Nuevo Rol Personalizado
                                    </DialogTitle>
                                    <DialogDescription>
                                        Defina los permisos específicos para las
                                        operaciones de su empresa.
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-1">
                                        <Label htmlFor="role-name">
                                            Nombre del rol
                                        </Label>
                                        <Input
                                            id="role-name"
                                            value={createForm.data.name}
                                            onChange={(e) =>
                                                createForm.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                            placeholder="Ej. Jefe de Almacén"
                                        />
                                        <InputError
                                            message={createForm.errors.name}
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="role-slug">
                                            Identificador (slug)
                                        </Label>
                                        <Input
                                            id="role-slug"
                                            value={createForm.data.slug}
                                            onChange={(e) =>
                                                createForm.setData(
                                                    'slug',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                            placeholder="ej. warehouse_chief"
                                        />
                                        <InputError
                                            message={createForm.errors.slug}
                                        />
                                    </div>
                                </div>

                                <div className="space-y-1">
                                    <Label htmlFor="role-desc">
                                        Descripción (opcional)
                                    </Label>
                                    <Input
                                        id="role-desc"
                                        value={createForm.data.description}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Descripción de responsabilidades"
                                    />
                                    <InputError
                                        message={createForm.errors.description}
                                    />
                                </div>

                                <div className="space-y-2 pt-2">
                                    <Label>Permisos asignados</Label>
                                    <div className="max-h-60 space-y-4 overflow-y-auto rounded-md border p-3">
                                        {Object.entries(
                                            permissionsByModule,
                                        ).map(([moduleName, perms]) => (
                                            <div
                                                key={moduleName}
                                                className="space-y-2"
                                            >
                                                <p className="text-xs font-semibold tracking-wider text-primary uppercase">
                                                    {moduleName}
                                                </p>
                                                <div className="grid grid-cols-1 gap-2 pl-2 sm:grid-cols-2">
                                                    {perms.map((p) => (
                                                        <div
                                                            key={p.id}
                                                            className="flex items-start space-x-2"
                                                        >
                                                            <Checkbox
                                                                id={`p-${p.id}`}
                                                                checked={createForm.data.permission_ids.includes(
                                                                    p.id,
                                                                )}
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) => {
                                                                    if (
                                                                        checked
                                                                    ) {
                                                                        createForm.setData(
                                                                            'permission_ids',
                                                                            [
                                                                                ...createForm
                                                                                    .data
                                                                                    .permission_ids,
                                                                                p.id,
                                                                            ],
                                                                        );
                                                                    } else {
                                                                        createForm.setData(
                                                                            'permission_ids',
                                                                            createForm.data.permission_ids.filter(
                                                                                (
                                                                                    id,
                                                                                ) =>
                                                                                    id !==
                                                                                    p.id,
                                                                            ),
                                                                        );
                                                                    }
                                                                }}
                                                            />
                                                            <div className="grid gap-0.5 leading-none">
                                                                <label
                                                                    htmlFor={`p-${p.id}`}
                                                                    className="cursor-pointer text-xs font-medium"
                                                                >
                                                                    {p.name}
                                                                </label>
                                                                <p className="text-[10px] text-muted-foreground">
                                                                    {p.slug}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    <InputError
                                        message={
                                            createForm.errors.permission_ids
                                        }
                                    />
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
                                        Guardar Rol
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    {roles.map((role) => (
                        <Card key={role.id}>
                            <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <CardTitle className="text-base font-semibold">
                                            {role.name}
                                        </CardTitle>
                                        {role.is_system ? (
                                            <Badge
                                                variant="secondary"
                                                className="gap-1 text-xs"
                                            >
                                                <Lock className="h-3 w-3" />
                                                Sistema
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="outline"
                                                className="text-xs"
                                            >
                                                Personalizado
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {role.slug}
                                    </p>
                                    {role.description && (
                                        <p className="pt-1 text-xs text-muted-foreground">
                                            {role.description}
                                        </p>
                                    )}
                                </div>

                                <div className="flex items-center gap-1">
                                    <Badge
                                        variant="outline"
                                        className="gap-1 text-xs"
                                    >
                                        <Users className="h-3 w-3" />
                                        {role.members_count}
                                    </Badge>

                                    {!role.is_system && (
                                        <>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="h-8 w-8"
                                                onClick={() =>
                                                    handleOpenEdit(role)
                                                }
                                            >
                                                <Pencil className="h-3.5 w-3.5" />
                                            </Button>

                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="h-8 w-8 text-destructive"
                                                disabled={
                                                    role.members_count > 0
                                                }
                                                onClick={() =>
                                                    handleDelete(role)
                                                }
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-1.5">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Permisos ({role.permissions.length}):
                                    </p>
                                    <div className="flex max-h-24 flex-wrap gap-1.5 overflow-y-auto">
                                        {role.permissions.map((p) => (
                                            <Badge
                                                key={p.id}
                                                variant="secondary"
                                                className="px-1.5 py-0 text-[11px]"
                                            >
                                                {p.name}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Edit Role Dialog */}
                {editRole && (
                    <Dialog
                        open={true}
                        onOpenChange={(open) => !open && setEditRole(null)}
                    >
                        <DialogContent className="max-w-2xl">
                            <form onSubmit={handleUpdate} className="space-y-4">
                                <DialogHeader>
                                    <DialogTitle>
                                        Editar Rol: {editRole.name}
                                    </DialogTitle>
                                    <DialogDescription>
                                        Modifique el nombre, descripción y
                                        permisos asignados.
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="space-y-3">
                                    <div className="space-y-1">
                                        <Label htmlFor="edit-role-name">
                                            Nombre
                                        </Label>
                                        <Input
                                            id="edit-role-name"
                                            value={editForm.data.name}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                        <InputError
                                            message={editForm.errors.name}
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="edit-role-desc">
                                            Descripción
                                        </Label>
                                        <Input
                                            id="edit-role-desc"
                                            value={editForm.data.description}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'description',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                editForm.errors.description
                                            }
                                        />
                                    </div>

                                    <div className="space-y-2 pt-2">
                                        <Label>Permisos asignados</Label>
                                        <div className="max-h-60 space-y-4 overflow-y-auto rounded-md border p-3">
                                            {Object.entries(
                                                permissionsByModule,
                                            ).map(([moduleName, perms]) => (
                                                <div
                                                    key={moduleName}
                                                    className="space-y-2"
                                                >
                                                    <p className="text-xs font-semibold tracking-wider text-primary uppercase">
                                                        {moduleName}
                                                    </p>
                                                    <div className="grid grid-cols-1 gap-2 pl-2 sm:grid-cols-2">
                                                        {perms.map((p) => (
                                                            <div
                                                                key={p.id}
                                                                className="flex items-start space-x-2"
                                                            >
                                                                <Checkbox
                                                                    id={`edit-p-${p.id}`}
                                                                    checked={editForm.data.permission_ids.includes(
                                                                        p.id,
                                                                    )}
                                                                    onCheckedChange={(
                                                                        checked,
                                                                    ) => {
                                                                        if (
                                                                            checked
                                                                        ) {
                                                                            editForm.setData(
                                                                                'permission_ids',
                                                                                [
                                                                                    ...editForm
                                                                                        .data
                                                                                        .permission_ids,
                                                                                    p.id,
                                                                                ],
                                                                            );
                                                                        } else {
                                                                            editForm.setData(
                                                                                'permission_ids',
                                                                                editForm.data.permission_ids.filter(
                                                                                    (
                                                                                        id,
                                                                                    ) =>
                                                                                        id !==
                                                                                        p.id,
                                                                                ),
                                                                            );
                                                                        }
                                                                    }}
                                                                />
                                                                <div className="grid gap-0.5 leading-none">
                                                                    <label
                                                                        htmlFor={`edit-p-${p.id}`}
                                                                        className="cursor-pointer text-xs font-medium"
                                                                    >
                                                                        {p.name}
                                                                    </label>
                                                                    <p className="text-[10px] text-muted-foreground">
                                                                        {p.slug}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                        <InputError
                                            message={
                                                editForm.errors.permission_ids
                                            }
                                        />
                                    </div>
                                </div>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setEditRole(null)}
                                    >
                                        Cancelar
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={editForm.processing}
                                    >
                                        Guardar Cambios
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
