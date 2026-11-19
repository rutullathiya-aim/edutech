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
}
