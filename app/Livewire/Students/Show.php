<?php

namespace App\Livewire\Students;

use App\Livewire\Students\Concerns\InteractsWithStudentScreens;
use App\Models\School;
use App\Models\Student;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read School $school
 * @property-read Student $student
 * @property-read bool $canManage
 * @property-read bool $canViewSensitive
 */
#[Title('Student profile')]
class Show extends Component
{
    use InteractsWithStudentScreens;

    #[Locked]
    public string $studentId;

    public string $activeTab = 'profile';

    /**
     * Authorize viewing the student record and store only the locked primary key on component state.
     */
    public function mount(Student $student): void
    {
        $this->authorize('view', $student);

        $this->studentId = $student->id;
    }

    /**
     * Switch the active profile tab between Profile, Guardians, and Enrollment history.
     */
    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['profile', 'guardians', 'history'], true) ? $tab : 'profile';
    }

    /**
     * Determine whether the signed-in user may edit this student record.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('update', $this->student) ?? false;
    }

    /**
     * Determine whether the signed-in user may view sensitive fields on this student.
     */
    #[Computed]
    public function canViewSensitive(): bool
    {
        return auth()->user()?->can('viewSensitive', $this->student) ?? false;
    }

    /**
     * Load the student profile selecting sensitive columns only when permitted by policy.
     */
    #[Computed]
    public function student(): Student
    {
        $selectColumns = [
            'id',
            'school_id',
            'admission_no',
            'admission_date',
            'name',
            'date_of_birth',
            'gender',
            'nationality',
            'mother_tongue',
            'blood_group',
            'address_line',
            'city',
            'state',
            'pincode',
            'previous_school',
            'previous_tc_no',
            'status',
            'consent_recorded_at',
            'consent_recorded_by',
            'created_at',
            'updated_at',
        ];

        if (auth()->user()?->can('viewSensitive', Student::class) ?? false) {
            $selectColumns = array_merge($selectColumns, Student::VIEW_SENSITIVE_FIELDS);
        }

        return Student::query()
            ->select($selectColumns)
            ->with([
                'consentRecordedBy:id,name',
                'enrollments' => static function ($query): void {
                    $query->with([
                        'academicYear:id,school_id,name,starts_on,ends_on,is_current',
                        'section:id,school_id,grade_id,academic_year_id,name',
                        'section.grade:id,school_id,name,sort_order',
                    ]);
                },
                'studentGuardians' => static function ($query): void {
                    $query->orderByDesc('is_primary')
                        ->with([
                            'guardian:id,school_id,name,mobile,email,occupation',
                            'guardian.studentGuardians' => static function ($siblingLinkQuery): void {
                                $siblingLinkQuery->with([
                                    'student:id,school_id,admission_no,name',
                                    'student.enrollments' => static function ($siblingEnrollmentQuery): void {
                                        $siblingEnrollmentQuery->with([
                                            'academicYear:id,school_id,is_current,starts_on',
                                            'section:id,school_id,grade_id,academic_year_id,name',
                                            'section.grade:id,school_id,name',
                                        ]);
                                    },
                                ]);
                            },
                        ]);
                },
            ])
            ->findOrFail($this->studentId);
    }

    /**
     * Render the student profile view.
     */
    public function render(): View
    {
        return view('livewire.students.show');
    }
}
