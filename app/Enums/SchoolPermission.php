<?php

namespace App\Enums;

enum SchoolPermission: string
{
    case AcademicYearsView = 'academic_years.view';
    case AcademicYearsManage = 'academic_years.manage';
    case GradesView = 'grades.view';
    case GradesManage = 'grades.manage';
}
