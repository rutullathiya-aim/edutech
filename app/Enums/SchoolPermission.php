<?php

namespace App\Enums;

enum SchoolPermission: string
{
    case AcademicYearsView = 'academic_years.view';
    case AcademicYearsManage = 'academic_years.manage';
    case GradesView = 'grades.view';
    case GradesManage = 'grades.manage';
    case SectionsView = 'sections.view';
    case SectionsManage = 'sections.manage';
    case SubjectsView = 'subjects.view';
    case SubjectsManage = 'subjects.manage';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
}
