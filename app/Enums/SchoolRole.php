<?php

namespace App\Enums;

enum SchoolRole: string
{
    case Owner = 'Owner';
    case Admin = 'Admin';
    case Accountant = 'Accountant';
    case Teacher = 'Teacher';
    case Parent = 'Parent';
    case Student = 'Student';

    /**
     * Get all school staff roles (excludes Parent and Student).
     *
     * @return list<self>
     */
    public static function staffCases(): array
    {
        return [
            self::Owner,
            self::Admin,
            self::Accountant,
            self::Teacher,
        ];
    }

    /**
     * Determine whether this role is a school staff role.
     */
    public function isStaff(): bool
    {
        return in_array($this, self::staffCases(), true);
    }

    /**
     * Get the authority rank for this role (higher value means higher privilege).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 100,
            self::Admin => 80,
            self::Accountant => 50,
            self::Teacher => 40,
            self::Parent => 10,
            self::Student => 0,
        };
    }

    /**
     * Get the roles an actor holding the given role may invite or assign.
     *
     * @return list<self>
     */
    public static function assignableBy(self $actor): array
    {
        return match ($actor) {
            self::Owner => [self::Admin, self::Accountant, self::Teacher],
            self::Admin => [self::Accountant, self::Teacher],
            default => [],
        };
    }

    /**
     * Determine whether an actor holding this role may assign the target role.
     */
    public function canAssign(self $targetRole): bool
    {
        return in_array($targetRole, self::assignableBy($this), true);
    }

    /**
     * Determine whether an actor holding this role may manage a member holding the target role.
     */
    public function canManage(self $targetRole): bool
    {
        return $this->canAssign($targetRole);
    }
}
