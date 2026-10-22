<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Staff = 'staff';
    case Intern = 'intern';
    case Freelance = 'freelance';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Manager => 'Manager / Executive',
            self::Staff => 'Staff',
            self::Intern => 'Intern',
            self::Freelance => 'Freelance',
        };
    }
}
