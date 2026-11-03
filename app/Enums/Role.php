<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Coordinator = 'coordinator';
    case Accountant = 'accountant';
    case HeadEngineer = 'head_engineer';
    case HrExecutive = 'hr_executive';
    case StoreKeeper = 'store_keeper';
    case ServiceTracker = 'service_tracker';
    case Technician = 'technician';
    case Customer = 'customer';

    /** Roles a Company Admin can create inside a tenant. */
    public static function staffRoles(): array
    {
        return [...self::officeRoles(), self::Technician->value];
    }

    /** Office (web / manager app) roles – everyone in a company except technicians and customers. */
    public static function officeRoles(): array
    {
        return [
            self::Admin->value, self::Coordinator->value, self::Accountant->value, self::HeadEngineer->value,
            self::HrExecutive->value, self::StoreKeeper->value, self::ServiceTracker->value,
        ];
    }

    public function isOffice(): bool
    {
        return in_array($this->value, self::officeRoles(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Company Admin',
            self::Coordinator => 'Coordinator',
            self::Accountant => 'Accountant',
            self::HeadEngineer => 'Head Engineer',
            self::HrExecutive => 'HR Executive',
            self::StoreKeeper => 'Store Keeper',
            self::ServiceTracker => 'Service Tracker',
            self::Technician => 'Technician',
            self::Customer => 'Customer',
        };
    }
}
