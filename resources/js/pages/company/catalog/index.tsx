import { Head, router, useForm } from '@inertiajs/react';
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
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/input-error';
import {
    Package,
    FolderTree,
    Scale,
    Plus,
    Search,
    Edit2,
    Building2,
    AlertTriangle,
    Info,
    ArrowLeft,
    Check,
    X,
} from 'lucide-react';
import { useState, useMemo, FormEventHandler } from 'react';

interface CategoryItem {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    products_count?: number;
}

interface UnitItem {
    id: number;
    code: string;
    name: string;
    allows_decimal: boolean;
    is_active: boolean;
    products_count?: number;
}

interface ProductItem {
    id: number;
    sku: string;
    name: string;
    description: string | null;
    barcode: string | null;
    category_id: number | null;
    category: {
        id: number;
        name: string;
        is_active: boolean;
    } | null;
    unit_of_measure_id: number;
    unit_of_measure: {
        id: number;
        code: string;
        name: string;
        allows_decimal: boolean;
        is_active: boolean;
    };
    is_active: boolean;
    created_at: string | null;
}

interface CatalogProps {
    company: {
        legal_name: string;
        trade_name?: string | null;
        slug: string;
    };
    products: ProductItem[];
    categories: CategoryItem[];
    units: UnitItem[];
    stats: {
        total_products: number;
        active_products: number;
        total_categories: number;
        active_categories: number;
        total_units: number;
        active_units: number;
    };
    filters: {
        search: string;
        status: string;
        category_id: number | null;
    };
    can: {
        manage_products: boolean;
        manage_categories: boolean;
        manage_units: boolean;
    };
    is_read_only: boolean;
}

export default function CatalogIndex({
    company,
    products,
    categories,
    units,
    stats,
    filters,
    can,
    is_read_only,
}: CatalogProps) {
    const [activeTab, setActiveTab] = useState<
        'products' | 'categories' | 'units'
    >('products');
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const [categoryFilter, setCategoryFilter] = useState<string>(
        filters.category_id ? String(filters.category_id) : 'all',
    );

    // Dialog States
    const [productModalOpen, setProductModalOpen] = useState(false);
    const [editingProduct, setEditingProduct] = useState<ProductItem | null>(
        null,
    );

    const [categoryModalOpen, setCategoryModalOpen] = useState(false);
    const [editingCategory, setEditingCategory] = useState<CategoryItem | null>(
        null,
    );

    const [unitModalOpen, setUnitModalOpen] = useState(false);
    const [editingUnit, setEditingUnit] = useState<UnitItem | null>(null);

    // Forms
    const productForm = useForm({
        sku: '',
        name: '',
        description: '',
        barcode: '',
        category_id: '',
        unit_of_measure_id: '',
        is_active: true,
    });

    const categoryForm = useForm({
        name: '',
        description: '',
        is_active: true,
    });

    const unitForm = useForm({
        code: '',
        name: '',
        allows_decimal: false,
        is_active: true,
    });

    // Filtering handler
    const applyFilters = (
        newSearch?: string,
        newStatus?: string,
        newCategory?: string,
    ) => {
        const s = newSearch !== undefined ? newSearch : searchQuery;
        const st = newStatus !== undefined ? newStatus : statusFilter;
        const cat = newCategory !== undefined ? newCategory : categoryFilter;

        router.get(
            `/app/${company.slug}/catalog`,
            {
                search: s || undefined,
                status: st !== 'all' ? st : undefined,
                category_id: cat !== 'all' ? cat : undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    // Open Product Modal
    const openCreateProduct = () => {
        setEditingProduct(null);
        productForm.setData({
            sku: '',
            name: '',
            description: '',
            barcode: '',
            category_id: '',
            unit_of_measure_id: units.find((u) => u.is_active)?.id
                ? String(units.find((u) => u.is_active)!.id)
                : '',
            is_active: true,
        });
        productForm.clearErrors();
        setProductModalOpen(true);
    };

    const openEditProduct = (prod: ProductItem) => {
        setEditingProduct(prod);
        productForm.setData({
            sku: prod.sku,
            name: prod.name,
            description: prod.description || '',
            barcode: prod.barcode || '',
            category_id: prod.category_id ? String(prod.category_id) : '',
            unit_of_measure_id: String(prod.unit_of_measure_id),
            is_active: prod.is_active,
        });
        productForm.clearErrors();
        setProductModalOpen(true);
    };

    // Submit Product
    const submitProduct: FormEventHandler = (e) => {
        e.preventDefault();
        const payload = {
            sku: productForm.data.sku,
            name: productForm.data.name,
            description: productForm.data.description || null,
            barcode: productForm.data.barcode || null,
            category_id: productForm.data.category_id
                ? Number(productForm.data.category_id)
                : null,
            unit_of_measure_id: Number(productForm.data.unit_of_measure_id),
            is_active: productForm.data.is_active,
        };

        productForm.transform(() => payload);

        if (editingProduct) {
            productForm.put(
                `/app/${company.slug}/catalog/products/${editingProduct.id}`,
                {
                    onSuccess: () => setProductModalOpen(false),
                },
            );
        } else {
            productForm.post(`/app/${company.slug}/catalog/products`, {
                onSuccess: () => setProductModalOpen(false),
            });
        }
    };

    // Explicit Status Updates (Idempotent)
    const setProductStatus = (prod: ProductItem, targetStatus: boolean) => {
        router.patch(
            `/app/${company.slug}/catalog/products/${prod.id}/status`,
            { is_active: targetStatus },
            { preserveScroll: true },
        );
    };

    // Category Modal Handlers
    const openCreateCategory = () => {
        setEditingCategory(null);
        categoryForm.setData({ name: '', description: '', is_active: true });
        categoryForm.clearErrors();
        setCategoryModalOpen(true);
    };

    const openEditCategory = (cat: CategoryItem) => {
        setEditingCategory(cat);
        categoryForm.setData({
            name: cat.name,
            description: cat.description || '',
            is_active: cat.is_active,
        });
        categoryForm.clearErrors();
        setCategoryModalOpen(true);
    };

    const submitCategory: FormEventHandler = (e) => {
        e.preventDefault();
        if (editingCategory) {
            categoryForm.put(
                `/app/${company.slug}/catalog/categories/${editingCategory.id}`,
                {
                    onSuccess: () => setCategoryModalOpen(false),
                },
            );
        } else {
            categoryForm.post(`/app/${company.slug}/catalog/categories`, {
                onSuccess: () => setCategoryModalOpen(false),
            });
        }
    };

    const setCategoryStatus = (cat: CategoryItem, targetStatus: boolean) => {
        router.patch(
            `/app/${company.slug}/catalog/categories/${cat.id}/status`,
            { is_active: targetStatus },
            { preserveScroll: true },
        );
    };

    // Unit Modal Handlers
    const openCreateUnit = () => {
        setEditingUnit(null);
        unitForm.setData({
            code: '',
            name: '',
            allows_decimal: false,
            is_active: true,
        });
        unitForm.clearErrors();
        setUnitModalOpen(true);
    };

    const openEditUnit = (u: UnitItem) => {
        setEditingUnit(u);
        unitForm.setData({
            code: u.code,
            name: u.name,
            allows_decimal: u.allows_decimal,
            is_active: u.is_active,
        });
        unitForm.clearErrors();
        setUnitModalOpen(true);
    };

    const submitUnit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editingUnit) {
            unitForm.put(
                `/app/${company.slug}/catalog/units/${editingUnit.id}`,
                {
                    onSuccess: () => setUnitModalOpen(false),
                },
            );
        } else {
            unitForm.post(`/app/${company.slug}/catalog/units`, {
                onSuccess: () => setUnitModalOpen(false),
            });
        }
    };

    const setUnitStatus = (u: UnitItem, targetStatus: boolean) => {
        router.patch(
            `/app/${company.slug}/catalog/units/${u.id}/status`,
            { is_active: targetStatus },
            { preserveScroll: true },
        );
    };

    // Inactive references computation for Product Edit modal (Requirement 4)
    const selectableCategories = useMemo(() => {
        return categories.filter((c) => {
            if (c.is_active) return true;
            // Allow retaining current product's category even if inactive
            return editingProduct && editingProduct.category_id === c.id;
        });
    }, [categories, editingProduct]);

    const selectableUnits = useMemo(() => {
        return units.filter((u) => {
            if (u.is_active) return true;
            // Allow retaining current product's unit even if inactive
            return editingProduct && editingProduct.unit_of_measure_id === u.id;
        });
    }, [units, editingProduct]);

    const hasCurrentInactiveCategory =
        editingProduct &&
        editingProduct.category &&
        !editingProduct.category.is_active &&
        Number(productForm.data.category_id) === editingProduct.category_id;

    const hasCurrentInactiveUnit =
        editingProduct &&
        editingProduct.unit_of_measure &&
        !editingProduct.unit_of_measure.is_active &&
        Number(productForm.data.unit_of_measure_id) ===
            editingProduct.unit_of_measure_id;

    return (
        <>
            <Head title={`Catálogo de Productos - ${company.legal_name}`} />

            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex flex-col gap-4 border-b pb-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => router.visit(`/app/${company.slug}`)}
                        >
                            <ArrowLeft className="h-5 w-5" />
                        </Button>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-2xl font-bold tracking-tight">
                                    Catálogo Comercial
                                </h1>
                                <Badge
                                    variant="outline"
                                    className="font-mono text-xs"
                                >
                                    ERP
                                </Badge>
                                {is_read_only && (
                                    <Badge
                                        variant="destructive"
                                        className="text-xs"
                                    >
                                        Solo Lectura
                                    </Badge>
                                )}
                            </div>
                            <p className="mt-0.5 flex items-center gap-1.5 text-sm text-muted-foreground">
                                <Building2 className="h-3.5 w-3.5" />
                                {company.legal_name} • {company.slug}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {activeTab === 'products' &&
                            can.manage_products &&
                            !is_read_only && (
                                <Button
                                    onClick={openCreateProduct}
                                    className="gap-1.5"
                                >
                                    <Plus className="h-4 w-4" />
                                    Nuevo Producto
                                </Button>
                            )}
                        {activeTab === 'categories' &&
                            can.manage_categories &&
                            !is_read_only && (
                                <Button
                                    onClick={openCreateCategory}
                                    className="gap-1.5"
                                >
                                    <Plus className="h-4 w-4" />
                                    Nueva Categoría
                                </Button>
                            )}
                        {activeTab === 'units' &&
                            can.manage_units &&
                            !is_read_only && (
                                <Button
                                    onClick={openCreateUnit}
                                    className="gap-1.5"
                                >
                                    <Plus className="h-4 w-4" />
                                    Nueva Unidad
                                </Button>
                            )}
                    </div>
                </div>

                {/* Read Only Notice Banner */}
                {is_read_only && (
                    <div className="flex items-start gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-amber-900 dark:text-amber-200">
                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                        <div className="text-sm">
                            <p className="font-semibold">
                                Modo de Solo Lectura Activo
                            </p>
                            <p>
                                La empresa se encuentra en período de gracia o
                                con licencia restringida. Las operaciones de
                                creación, edición o cambio de estado de catálogo
                                están deshabilitadas.
                            </p>
                        </div>
                    </div>
                )}

                {/* KPI Stat Cards */}
                <div className="grid gap-4 md:grid-cols-3">
                    <Card
                        className={`cursor-pointer transition-all ${
                            activeTab === 'products'
                                ? 'border-primary ring-2 ring-primary'
                                : 'hover:border-primary/50'
                        }`}
                        onClick={() => setActiveTab('products')}
                    >
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">
                                Productos
                            </CardTitle>
                            <Package className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.total_products}
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                <span className="font-medium text-emerald-600">
                                    {stats.active_products} activos
                                </span>{' '}
                                • {stats.total_products - stats.active_products}{' '}
                                inactivos
                            </p>
                        </CardContent>
                    </Card>

                    <Card
                        className={`cursor-pointer transition-all ${
                            activeTab === 'categories'
                                ? 'border-primary ring-2 ring-primary'
                                : 'hover:border-primary/50'
                        }`}
                        onClick={() => setActiveTab('categories')}
                    >
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">
                                Categorías
                            </CardTitle>
                            <FolderTree className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.total_categories}
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                <span className="font-medium text-emerald-600">
                                    {stats.active_categories} activas
                                </span>{' '}
                                •{' '}
                                {stats.total_categories -
                                    stats.active_categories}{' '}
                                inactivas
                            </p>
                        </CardContent>
                    </Card>

                    <Card
                        className={`cursor-pointer transition-all ${
                            activeTab === 'units'
                                ? 'border-primary ring-2 ring-primary'
                                : 'hover:border-primary/50'
                        }`}
                        onClick={() => setActiveTab('units')}
                    >
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">
                                Unidades de Medida
                            </CardTitle>
                            <Scale className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.total_units}
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                <span className="font-medium text-emerald-600">
                                    {stats.active_units} activas
                                </span>{' '}
                                • {stats.total_units - stats.active_units}{' '}
                                inactivas
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Tab Navigation */}
                <div className="flex border-b border-border">
                    <button
                        onClick={() => setActiveTab('products')}
                        className={`flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
                            activeTab === 'products'
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <Package className="h-4 w-4" />
                        Productos ({products.length})
                    </button>
                    <button
                        onClick={() => setActiveTab('categories')}
                        className={`flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
                            activeTab === 'categories'
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <FolderTree className="h-4 w-4" />
                        Categorías ({categories.length})
                    </button>
                    <button
                        onClick={() => setActiveTab('units')}
                        className={`flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
                            activeTab === 'units'
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <Scale className="h-4 w-4" />
                        Unidades de Medida ({units.length})
                    </button>
                </div>

                {/* TAB CONTENT: PRODUCTS */}
                {activeTab === 'products' && (
                    <div className="space-y-4">
                        {/* Filters Bar */}
                        <div className="flex flex-col gap-3 sm:flex-row">
                            <div className="relative flex-1">
                                <Search className="absolute top-2.5 left-3 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Buscar por SKU, nombre o código de barras..."
                                    value={searchQuery}
                                    onChange={(e) =>
                                        setSearchQuery(e.target.value)
                                    }
                                    onKeyDown={(e) =>
                                        e.key === 'Enter' &&
                                        applyFilters(searchQuery)
                                    }
                                    className="pl-9"
                                />
                            </div>
                            <div className="flex gap-2">
                                <select
                                    value={statusFilter}
                                    onChange={(e) => {
                                        setStatusFilter(e.target.value);
                                        applyFilters(undefined, e.target.value);
                                    }}
                                    className="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:ring-1 focus:ring-ring focus:outline-hidden"
                                >
                                    <option value="all">
                                        Todos los estados
                                    </option>
                                    <option value="active">Solo activos</option>
                                    <option value="inactive">
                                        Solo inactivos
                                    </option>
                                </select>

                                <select
                                    value={categoryFilter}
                                    onChange={(e) => {
                                        setCategoryFilter(e.target.value);
                                        applyFilters(
                                            undefined,
                                            undefined,
                                            e.target.value,
                                        );
                                    }}
                                    className="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:ring-1 focus:ring-ring focus:outline-hidden"
                                >
                                    <option value="all">
                                        Todas las categorías
                                    </option>
                                    {categories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}{' '}
                                            {!c.is_active ? '(Inactiva)' : ''}
                                        </option>
                                    ))}
                                </select>

                                <Button
                                    variant="secondary"
                                    onClick={() => applyFilters()}
                                >
                                    Filtrar
                                </Button>
                            </div>
                        </div>

                        {/* Products Table */}
                        <div className="rounded-md border bg-card">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b bg-muted/50 text-xs text-muted-foreground">
                                        <tr>
                                            <th className="p-3 font-semibold">
                                                SKU
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Nombre & Descripción
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Código de Barras
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Categoría
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Unidad
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Estado
                                            </th>
                                            <th className="p-3 text-right font-semibold">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {products.length === 0 ? (
                                            <tr>
                                                <td
                                                    colSpan={7}
                                                    className="p-8 text-center text-muted-foreground"
                                                >
                                                    No se encontraron productos
                                                    en el catálogo con los
                                                    filtros aplicados.
                                                </td>
                                            </tr>
                                        ) : (
                                            products.map((p) => (
                                                <tr
                                                    key={p.id}
                                                    className="transition-colors hover:bg-muted/30"
                                                >
                                                    <td className="p-3 font-mono font-medium">
                                                        {p.sku}
                                                    </td>
                                                    <td className="p-3">
                                                        <div className="font-medium text-foreground">
                                                            {p.name}
                                                        </div>
                                                        {p.description && (
                                                            <div className="line-clamp-1 text-xs text-muted-foreground">
                                                                {p.description}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="p-3 font-mono text-xs">
                                                        {p.barcode ? (
                                                            <span>
                                                                {p.barcode}
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground italic">
                                                                Sin código
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="p-3">
                                                        {p.category ? (
                                                            <span className="inline-flex items-center gap-1.5">
                                                                <span>
                                                                    {
                                                                        p
                                                                            .category
                                                                            .name
                                                                    }
                                                                </span>
                                                                {!p.category
                                                                    .is_active && (
                                                                    <Badge
                                                                        variant="secondary"
                                                                        className="border-amber-500/30 bg-amber-500/10 text-[10px] text-amber-600"
                                                                    >
                                                                        Cat.
                                                                        Inactiva
                                                                    </Badge>
                                                                )}
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground italic">
                                                                Sin categoría
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="p-3">
                                                        <span className="inline-flex items-center gap-1">
                                                            <span className="font-mono font-semibold">
                                                                {
                                                                    p
                                                                        .unit_of_measure
                                                                        .code
                                                                }
                                                            </span>
                                                            <span className="text-xs text-muted-foreground">
                                                                (
                                                                {
                                                                    p
                                                                        .unit_of_measure
                                                                        .name
                                                                }
                                                                )
                                                            </span>
                                                            {!p.unit_of_measure
                                                                .is_active && (
                                                                <Badge
                                                                    variant="secondary"
                                                                    className="border-amber-500/30 bg-amber-500/10 text-[10px] text-amber-600"
                                                                >
                                                                    Und.
                                                                    Inactiva
                                                                </Badge>
                                                            )}
                                                        </span>
                                                    </td>
                                                    <td className="p-3">
                                                        {p.is_active ? (
                                                            <Badge className="border-emerald-500/30 bg-emerald-500/15 text-emerald-700 hover:bg-emerald-500/20">
                                                                Activo
                                                            </Badge>
                                                        ) : (
                                                            <Badge
                                                                variant="secondary"
                                                                className="text-muted-foreground"
                                                            >
                                                                Inactivo
                                                            </Badge>
                                                        )}
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        <div className="flex items-center justify-end gap-1">
                                                            {can.manage_products &&
                                                                !is_read_only && (
                                                                    <>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="sm"
                                                                            onClick={() =>
                                                                                openEditProduct(
                                                                                    p,
                                                                                )
                                                                            }
                                                                            className="h-8 px-2"
                                                                        >
                                                                            <Edit2 className="mr-1 h-3.5 w-3.5" />
                                                                            Editar
                                                                        </Button>

                                                                        {p.is_active ? (
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                onClick={() =>
                                                                                    setProductStatus(
                                                                                        p,
                                                                                        false,
                                                                                    )
                                                                                }
                                                                                className="h-8 px-2 text-rose-600 hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-950/30"
                                                                                title="Desactivar producto explícitamente"
                                                                            >
                                                                                <X className="mr-1 h-3.5 w-3.5" />
                                                                                Desactivar
                                                                            </Button>
                                                                        ) : (
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                onClick={() =>
                                                                                    setProductStatus(
                                                                                        p,
                                                                                        true,
                                                                                    )
                                                                                }
                                                                                className="h-8 px-2 text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700 dark:hover:bg-emerald-950/30"
                                                                                title="Activar producto explícitamente"
                                                                            >
                                                                                <Check className="mr-1 h-3.5 w-3.5" />
                                                                                Activar
                                                                            </Button>
                                                                        )}
                                                                    </>
                                                                )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}

                {/* TAB CONTENT: CATEGORIES */}
                {activeTab === 'categories' && (
                    <div className="space-y-4">
                        <div className="rounded-md border bg-card">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b bg-muted/50 text-xs text-muted-foreground">
                                        <tr>
                                            <th className="p-3 font-semibold">
                                                Nombre de Categoría
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Descripción
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Productos Asociados
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Estado
                                            </th>
                                            <th className="p-3 text-right font-semibold">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {categories.length === 0 ? (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="p-8 text-center text-muted-foreground"
                                                >
                                                    No hay categorías
                                                    registradas en esta empresa.
                                                </td>
                                            </tr>
                                        ) : (
                                            categories.map((c) => (
                                                <tr
                                                    key={c.id}
                                                    className="transition-colors hover:bg-muted/30"
                                                >
                                                    <td className="p-3 font-medium text-foreground">
                                                        {c.name}
                                                    </td>
                                                    <td className="p-3 text-xs text-muted-foreground">
                                                        {c.description || (
                                                            <span className="italic">
                                                                Sin descripción
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="p-3 font-mono text-xs">
                                                        {c.products_count ?? 0}{' '}
                                                        producto(s)
                                                    </td>
                                                    <td className="p-3">
                                                        {c.is_active ? (
                                                            <Badge className="border-emerald-500/30 bg-emerald-500/15 text-emerald-700 hover:bg-emerald-500/20">
                                                                Activa
                                                            </Badge>
                                                        ) : (
                                                            <Badge
                                                                variant="secondary"
                                                                className="text-muted-foreground"
                                                            >
                                                                Inactiva
                                                            </Badge>
                                                        )}
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        <div className="flex items-center justify-end gap-1">
                                                            {can.manage_categories &&
                                                                !is_read_only && (
                                                                    <>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="sm"
                                                                            onClick={() =>
                                                                                openEditCategory(
                                                                                    c,
                                                                                )
                                                                            }
                                                                            className="h-8 px-2"
                                                                        >
                                                                            <Edit2 className="mr-1 h-3.5 w-3.5" />
                                                                            Editar
                                                                        </Button>

                                                                        {c.is_active ? (
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                onClick={() =>
                                                                                    setCategoryStatus(
                                                                                        c,
                                                                                        false,
                                                                                    )
                                                                                }
                                                                                className="h-8 px-2 text-rose-600 hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-950/30"
                                                                                title="Desactivar categoría explícitamente"
                                                                            >
                                                                                <X className="mr-1 h-3.5 w-3.5" />
                                                                                Desactivar
                                                                            </Button>
                                                                        ) : (
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                onClick={() =>
                                                                                    setCategoryStatus(
                                                                                        c,
                                                                                        true,
                                                                                    )
                                                                                }
                                                                                className="h-8 px-2 text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700 dark:hover:bg-emerald-950/30"
                                                                                title="Activar categoría explícitamente"
                                                                            >
                                                                                <Check className="mr-1 h-3.5 w-3.5" />
                                                                                Activar
                                                                            </Button>
                                                                        )}
                                                                    </>
                                                                )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}

                {/* TAB CONTENT: UNITS OF MEASURE */}
                {activeTab === 'units' && (
                    <div className="space-y-4">
                        {/* Requirement 6 Callout */}
                        <div className="flex items-start gap-3 rounded-lg border border-sky-500/30 bg-sky-500/10 p-4 text-sky-950 dark:text-sky-200">
                            <Info className="mt-0.5 h-5 w-5 shrink-0 text-sky-600" />
                            <div className="text-sm">
                                <p className="font-semibold">
                                    Control Interno de Unidades de Medida
                                </p>
                                <p>
                                    Los códigos de esta entrega son de uso y
                                    administración interna dentro del ERP
                                    empresarial. No se asumen equivalencias
                                    fiscales automáticas por usar ejemplos como
                                    NIU o KGM. La correspondencia tributaria y
                                    normativa (ej. catálogos SUNAT Anexo 6 /
                                    UBL) se definirá posteriormente en la fase
                                    respectiva.
                                </p>
                            </div>
                        </div>

                        <div className="rounded-md border bg-card">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b bg-muted/50 text-xs text-muted-foreground">
                                        <tr>
                                            <th className="p-3 font-semibold">
                                                Código Interno
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Nombre
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Permite Decimales
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Productos Asociados
                                            </th>
                                            <th className="p-3 font-semibold">
                                                Estado
                                            </th>
                                            <th className="p-3 text-right font-semibold">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {units.length === 0 ? (
                                            <tr>
                                                <td
                                                    colSpan={6}
                                                    className="p-8 text-center text-muted-foreground"
                                                >
                                                    No hay unidades de medida
                                                    registradas en esta empresa.
                                                </td>
                                            </tr>
                                        ) : (
                                            units.map((u) => (
                                                <tr
                                                    key={u.id}
                                                    className="transition-colors hover:bg-muted/30"
                                                >
                                                    <td className="p-3 font-mono font-bold text-foreground">
                                                        {u.code}
                                                    </td>
                                                    <td className="p-3">
                                                        {u.name}
                                                    </td>
                                                    <td className="p-3">
                                                        {u.allows_decimal ? (
                                                            <Badge
                                                                variant="outline"
                                                                className="text-xs"
                                                            >
                                                                Sí
                                                                (fraccionario)
                                                            </Badge>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">
                                                                No (entero)
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="p-3 font-mono text-xs">
                                                        {u.products_count ?? 0}{' '}
                                                        producto(s)
                                                    </td>
                                                    <td className="p-3">
                                                        {u.is_active ? (
                                                            <Badge className="border-emerald-500/30 bg-emerald-500/15 text-emerald-700 hover:bg-emerald-500/20">
                                                                Activa
                                                            </Badge>
                                                        ) : (
                                                            <Badge
                                                                variant="secondary"
                                                                className="text-muted-foreground"
                                                            >
                                                                Inactiva
                                                            </Badge>
                                                        )}
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        <div className="flex items-center justify-end gap-1">
                                                            {can.manage_units &&
                                                                !is_read_only && (
                                                                    <>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="sm"
                                                                            onClick={() =>
                                                                                openEditUnit(
                                                                                    u,
                                                                                )
                                                                            }
                                                                            className="h-8 px-2"
                                                                        >
                                                                            <Edit2 className="mr-1 h-3.5 w-3.5" />
                                                                            Editar
                                                                        </Button>

                                                                        {u.is_active ? (
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                onClick={() =>
                                                                                    setUnitStatus(
                                                                                        u,
                                                                                        false,
                                                                                    )
                                                                                }
                                                                                className="h-8 px-2 text-rose-600 hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-950/30"
                                                                                title="Desactivar unidad explícitamente"
                                                                            >
                                                                                <X className="mr-1 h-3.5 w-3.5" />
                                                                                Desactivar
                                                                            </Button>
                                                                        ) : (
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                onClick={() =>
                                                                                    setUnitStatus(
                                                                                        u,
                                                                                        true,
                                                                                    )
                                                                                }
                                                                                className="h-8 px-2 text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700 dark:hover:bg-emerald-950/30"
                                                                                title="Activar unidad explícitamente"
                                                                            >
                                                                                <Check className="mr-1 h-3.5 w-3.5" />
                                                                                Activar
                                                                            </Button>
                                                                        )}
                                                                    </>
                                                                )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* PRODUCT MODAL (Create / Edit) with Inactive Reference Handling (Requirement 4) */}
            <Dialog open={productModalOpen} onOpenChange={setProductModalOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editingProduct
                                ? `Editar Producto: ${editingProduct.sku}`
                                : 'Nuevo Producto Simple'}
                        </DialogTitle>
                        <DialogDescription>
                            {editingProduct
                                ? 'Modifique los datos del producto en el catálogo comercial.'
                                : 'Ingrese los datos obligatorios para registrar un producto en esta empresa.'}
                        </DialogDescription>
                    </DialogHeader>

                    {/* Explanatory Alerts for Inactive References (Requirement 4) */}
                    {hasCurrentInactiveCategory && (
                        <div className="flex items-start gap-2 rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                            <div>
                                <span className="font-semibold">
                                    Referencia a Categoría Inactiva:
                                </span>{' '}
                                Este producto conserva su categoría actual (
                                {editingProduct?.category?.name}), la cual se
                                encuentra inactiva. Puede mantenerla o
                                seleccionar una categoría activa diferente. No
                                se permite asignar una categoría inactiva
                                distinta.
                            </div>
                        </div>
                    )}

                    {hasCurrentInactiveUnit && (
                        <div className="flex items-start gap-2 rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                            <div>
                                <span className="font-semibold">
                                    Referencia a Unidad Inactiva:
                                </span>{' '}
                                Este producto conserva su unidad de medida
                                actual ({editingProduct?.unit_of_measure?.name}
                                ), la cual se encuentra inactiva. Puede
                                mantenerla o seleccionar una unidad activa
                                diferente.
                            </div>
                        </div>
                    )}

                    <form onSubmit={submitProduct} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label htmlFor="sku">
                                    SKU (Código único) *
                                </Label>
                                <Input
                                    id="sku"
                                    value={productForm.data.sku}
                                    onChange={(e) =>
                                        productForm.setData(
                                            'sku',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="PROD-001"
                                    className="font-mono uppercase"
                                    required
                                />
                                <InputError
                                    message={productForm.errors.sku}
                                    className="mt-1"
                                />
                            </div>

                            <div>
                                <Label htmlFor="barcode">
                                    Código de Barras (Opcional)
                                </Label>
                                <Input
                                    id="barcode"
                                    value={productForm.data.barcode}
                                    onChange={(e) =>
                                        productForm.setData(
                                            'barcode',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="7751234567890"
                                    className="font-mono"
                                />
                                <InputError
                                    message={productForm.errors.barcode}
                                    className="mt-1"
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="product-name">
                                Nombre del Producto *
                            </Label>
                            <Input
                                id="product-name"
                                value={productForm.data.name}
                                onChange={(e) =>
                                    productForm.setData('name', e.target.value)
                                }
                                placeholder="Ej. Cuaderno cuadriculado 100 hojas"
                                required
                            />
                            <InputError
                                message={productForm.errors.name}
                                className="mt-1"
                            />
                        </div>

                        <div>
                            <Label htmlFor="product-description">
                                Descripción (Opcional)
                            </Label>
                            <Input
                                id="product-description"
                                value={productForm.data.description}
                                onChange={(e) =>
                                    productForm.setData(
                                        'description',
                                        e.target.value,
                                    )
                                }
                                placeholder="Especificaciones técnicas o detalles..."
                            />
                            <InputError
                                message={productForm.errors.description}
                                className="mt-1"
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label htmlFor="product-category">
                                    Categoría (Opcional)
                                </Label>
                                <select
                                    id="product-category"
                                    value={productForm.data.category_id}
                                    onChange={(e) =>
                                        productForm.setData(
                                            'category_id',
                                            e.target.value,
                                        )
                                    }
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:ring-1 focus:ring-ring focus:outline-hidden"
                                >
                                    <option value="">(Ninguna)</option>
                                    {selectableCategories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}{' '}
                                            {!c.is_active
                                                ? '(Inactiva - Referencia actual)'
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={productForm.errors.category_id}
                                    className="mt-1"
                                />
                            </div>

                            <div>
                                <Label htmlFor="product-unit">
                                    Unidad de Medida *
                                </Label>
                                <select
                                    id="product-unit"
                                    value={productForm.data.unit_of_measure_id}
                                    onChange={(e) =>
                                        productForm.setData(
                                            'unit_of_measure_id',
                                            e.target.value,
                                        )
                                    }
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:ring-1 focus:ring-ring focus:outline-hidden"
                                    required
                                >
                                    <option value="" disabled>
                                        Seleccione una unidad
                                    </option>
                                    {selectableUnits.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.code} - {u.name}{' '}
                                            {!u.is_active
                                                ? '(Inactiva - Referencia actual)'
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={
                                        productForm.errors.unit_of_measure_id
                                    }
                                    className="mt-1"
                                />
                            </div>
                        </div>

                        <div className="flex items-center space-x-2 pt-2">
                            <Checkbox
                                id="product-active"
                                checked={productForm.data.is_active}
                                onCheckedChange={(val) =>
                                    productForm.setData(
                                        'is_active',
                                        Boolean(val),
                                    )
                                }
                            />
                            <Label
                                htmlFor="product-active"
                                className="cursor-pointer text-sm font-medium"
                            >
                                Producto activo para operaciones comerciales
                            </Label>
                        </div>

                        <DialogFooter className="pt-4">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setProductModalOpen(false)}
                            >
                                Cancelar
                            </Button>
                            <Button
                                type="submit"
                                disabled={productForm.processing}
                            >
                                {productForm.processing
                                    ? 'Guardando...'
                                    : editingProduct
                                      ? 'Actualizar Producto'
                                      : 'Crear Producto'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* CATEGORY MODAL */}
            <Dialog
                open={categoryModalOpen}
                onOpenChange={setCategoryModalOpen}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {editingCategory
                                ? `Editar Categoría: ${editingCategory.name}`
                                : 'Nueva Categoría'}
                        </DialogTitle>
                        <DialogDescription>
                            Organice sus productos en categorías comerciales.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitCategory} className="space-y-4">
                        <div>
                            <Label htmlFor="cat-name">
                                Nombre de la Categoría *
                            </Label>
                            <Input
                                id="cat-name"
                                value={categoryForm.data.name}
                                onChange={(e) =>
                                    categoryForm.setData('name', e.target.value)
                                }
                                placeholder="Ej. Abarrotes, Papelería..."
                                required
                            />
                            <InputError
                                message={categoryForm.errors.name}
                                className="mt-1"
                            />
                        </div>

                        <div>
                            <Label htmlFor="cat-desc">
                                Descripción (Opcional)
                            </Label>
                            <Input
                                id="cat-desc"
                                value={categoryForm.data.description}
                                onChange={(e) =>
                                    categoryForm.setData(
                                        'description',
                                        e.target.value,
                                    )
                                }
                                placeholder="Detalles de la categoría..."
                            />
                            <InputError
                                message={categoryForm.errors.description}
                                className="mt-1"
                            />
                        </div>

                        <div className="flex items-center space-x-2 pt-2">
                            <Checkbox
                                id="cat-active"
                                checked={categoryForm.data.is_active}
                                onCheckedChange={(val) =>
                                    categoryForm.setData(
                                        'is_active',
                                        Boolean(val),
                                    )
                                }
                            />
                            <Label
                                htmlFor="cat-active"
                                className="cursor-pointer text-sm font-medium"
                            >
                                Categoría activa
                            </Label>
                        </div>

                        <DialogFooter className="pt-4">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCategoryModalOpen(false)}
                            >
                                Cancelar
                            </Button>
                            <Button
                                type="submit"
                                disabled={categoryForm.processing}
                            >
                                {categoryForm.processing
                                    ? 'Guardando...'
                                    : editingCategory
                                      ? 'Actualizar Categoría'
                                      : 'Crear Categoría'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* UNIT OF MEASURE MODAL */}
            <Dialog open={unitModalOpen} onOpenChange={setUnitModalOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {editingUnit
                                ? `Editar Unidad: ${editingUnit.code}`
                                : 'Nueva Unidad de Medida'}
                        </DialogTitle>
                        <DialogDescription>
                            Código y configuración de unidad de uso
                            administrativo interno.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitUnit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label htmlFor="unit-code">
                                    Código Interno *
                                </Label>
                                <Input
                                    id="unit-code"
                                    value={unitForm.data.code}
                                    onChange={(e) =>
                                        unitForm.setData('code', e.target.value)
                                    }
                                    placeholder="NIU, KGM, UND..."
                                    className="font-mono uppercase"
                                    required
                                />
                                <InputError
                                    message={unitForm.errors.code}
                                    className="mt-1"
                                />
                            </div>

                            <div>
                                <Label htmlFor="unit-name">Nombre *</Label>
                                <Input
                                    id="unit-name"
                                    value={unitForm.data.name}
                                    onChange={(e) =>
                                        unitForm.setData('name', e.target.value)
                                    }
                                    placeholder="Unidad, Kilogramo..."
                                    required
                                />
                                <InputError
                                    message={unitForm.errors.name}
                                    className="mt-1"
                                />
                            </div>
                        </div>

                        <div className="flex items-center space-x-2 pt-2">
                            <Checkbox
                                id="unit-decimal"
                                checked={unitForm.data.allows_decimal}
                                onCheckedChange={(val) =>
                                    unitForm.setData(
                                        'allows_decimal',
                                        Boolean(val),
                                    )
                                }
                            />
                            <Label
                                htmlFor="unit-decimal"
                                className="cursor-pointer text-sm font-medium"
                            >
                                Permite valores fraccionarios / decimales (ej.
                                1.250 KGM)
                            </Label>
                        </div>

                        <div className="flex items-center space-x-2">
                            <Checkbox
                                id="unit-active"
                                checked={unitForm.data.is_active}
                                onCheckedChange={(val) =>
                                    unitForm.setData('is_active', Boolean(val))
                                }
                            />
                            <Label
                                htmlFor="unit-active"
                                className="cursor-pointer text-sm font-medium"
                            >
                                Unidad de medida activa
                            </Label>
                        </div>

                        <DialogFooter className="pt-4">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setUnitModalOpen(false)}
                            >
                                Cancelar
                            </Button>
                            <Button
                                type="submit"
                                disabled={unitForm.processing}
                            >
                                {unitForm.processing
                                    ? 'Guardando...'
                                    : editingUnit
                                      ? 'Actualizar Unidad'
                                      : 'Crear Unidad'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
