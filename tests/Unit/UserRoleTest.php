<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    #[DataProvider('roleLabels')]
    public function test_each_role_has_its_own_label(string $value, string $label): void
    {
        $this->assertSame($label, UserRole::from($value)->label());
    }

    public static function roleLabels(): array
    {
        return [
            'administrator' => ['admin', 'Administrator'],
            'manager' => ['manager', 'Manager / Executive'],
            'staff' => ['staff', 'Staff'],
            'intern' => ['intern', 'Intern'],
            'freelance' => ['freelance', 'Freelance'],
        ];
    }

    public function test_existing_role_values_remain_compatible_and_new_roles_are_distinct(): void
    {
        $this->assertSame(
            ['admin', 'manager', 'staff', 'intern', 'freelance'],
            array_map(fn (UserRole $role) => $role->value, UserRole::cases()),
        );
        $this->assertNull(UserRole::tryFrom('staff / intern'));
    }
}
