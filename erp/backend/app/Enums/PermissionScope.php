<?php

namespace App\Enums;

/**
 * Over which records a role's permission applies, inside one organization.
 * Platform-wide ("global") access exists only for super admins.
 */
enum PermissionScope: string
{
    /** Every record of the organization. */
    case Organization = 'organization';
    /** Records of the institutions listed on the user's role assignment (and their departments). */
    case Institutions = 'institutions';
    /** Records of the departments listed on the user's role assignment. */
    case Departments = 'departments';
    /** Records the user created. */
    case Own = 'own';
    /** Records assigned to the user (module-specific, e.g. tasks). */
    case Assigned = 'assigned';

    /** Higher wins when a user holds several roles granting the same permission. */
    public function breadth(): int
    {
        return match ($this) {
            self::Organization => 4,
            self::Institutions => 3,
            self::Departments => 2,
            self::Own, self::Assigned => 1,
        };
    }
}
