<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Coordinator = 'coordinator';
    case Accountant = 'accountant';
    case Technician = 'technician';
    case Customer = 'customer';

    /** Roles a Company Admin can create inside a tenant. */
    public static function staffRoles(): array
    {
        return [self::Admin->value, self::Coordinator->value, self::Accountant->value, self::Technician->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Company Admin',
            self::Coordinator => 'Coordinator',
            self::Accountant => 'Accountant',
            self::Technician => 'Technician',
            self::Customer => 'Customer',
        };
    }
}
