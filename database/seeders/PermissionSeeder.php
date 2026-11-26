<?php

namespace Database\Seeders;

use App\Enums\SchoolPermission;
use App\Enums\SchoolRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        setPermissionsTeamId(null);

        foreach (SchoolPermission::cases() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission->value,
                'guard_name' => 'web',
            ]);
        }

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            $role = Role::whereNull('school_id')
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->firstOrFail();

            $role->syncPermissions(array_map(
                static fn (SchoolPermission $permission): string => $permission->value,
                $permissions,
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Canonical permission mappings for each global school role.
     *
     * @return array<string, list<SchoolPermission>>
     */
    private function rolePermissions(): array
    {
        $all = SchoolPermission::cases();
        $viewOnly = [
            SchoolPermission::AcademicYearsView,
            SchoolPermission::GradesView,
            SchoolPermission::SectionsView,
            SchoolPermission::SubjectsView,
            SchoolPermission::StudentsView,
        ];

        return [
            SchoolRole::Owner->value => $all,
            SchoolRole::Admin->value => $all,
            SchoolRole::Accountant->value => $viewOnly,
            SchoolRole::Teacher->value => $viewOnly,
            SchoolRole::Parent->value => [],
            SchoolRole::Student->value => [],
        ];
    }
}
