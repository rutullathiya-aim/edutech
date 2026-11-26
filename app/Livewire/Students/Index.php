<?php

namespace App\Livewire\Students;

use App\Enums\StudentStatus;
use App\Livewire\Students\Concerns\InteractsWithStudentScreens;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Support\LikePattern;
use App\Support\MobileNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read School $school
 * @property-read Collection<int, AcademicYear> $academicYears
 * @property-read Collection<int, Grade> $grades
 * @property-read Collection<int, Section> $sections
 * @property-read LengthAwarePaginator<int, Student> $students
 */
#[Title('Students')]
class Index extends Component
{
    use InteractsWithStudentScreens, WithPagination;

    public string $academic_year_id = '';

    public string $grade_id = '';

    public string $section_id = '';

    public string $status = '';

    public string $search = '';

    /**
     * Authorize directory access and default filters to the current academic year.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Student::class);

        $years = $this->academicYears;
        $defaultYear = $years->firstWhere('is_current', true) ?? $years->first();

        $this->academic_year_id = $defaultYear !== null ? $defaultYear->id : '';
    }

    /**
     * Reset dependent section filter and pagination when the academic year changes.
     */
    public function updatedAcademicYearId(): void
    {
        $this->section_id = '';
        $this->resetPage();
        unset($this->sections, $this->students);
    }

    /**
     * Reset dependent section filter and pagination when the grade changes.
     */
    public function updatedGradeId(): void
    {
        $this->section_id = '';
        $this->resetPage();
        unset($this->sections, $this->students);
    }

    /**
     * Reset pagination when section filter changes.
     */
    public function updatedSectionId(): void
    {
        $this->resetPage();
        unset($this->students);
    }

    /**
     * Reset pagination when status filter changes.
     */
    public function updatedStatus(): void
    {
        $this->resetPage();
        unset($this->students);
    }

    /**
     * Reset pagination when search query changes.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
        unset($this->students);
    }

    /**
     * Determine whether the signed-in user may admit or edit students.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('create', Student::class) ?? false;
    }

    /**
     * Get all grades in this school for the filter dropdown.
     *
     * @return Collection<int, Grade>
     */
    #[Computed]
    public function grades(): Collection
    {
        return Grade::query()
            ->ordered()
            ->get();
    }

    /**
     * Get sections filtered by selected academic year and optional grade.
     *
     * @return Collection<int, Section>
     */
    #[Computed]
    public function sections(): Collection
    {
        if ($this->academic_year_id === '') {
            return new Collection;
        }

        return Section::query()
            ->where('academic_year_id', $this->academic_year_id)
            ->when($this->grade_id !== '', fn (Builder $query) => $query->where('grade_id', $this->grade_id))
            ->with('grade:id,school_id,name,sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Load paginated non-sensitive student rows with constant query count regardless of student volume.
     *
     * @return LengthAwarePaginator<int, Student>
     */
    #[Computed]
    public function students(): LengthAwarePaginator
    {
        $like = LikePattern::escaped($this->search);
        $mobileLike = $this->resolveMobileLikePattern();

        return Student::query()
            ->select([
                'students.id',
                'students.school_id',
                'students.admission_no',
                'students.admission_date',
                'students.name',
                'students.gender',
                'students.status',
            ])
            ->when(
                $this->academic_year_id !== '' || $this->grade_id !== '' || $this->section_id !== '',
                function (Builder $query): void {
                    $query->whereHas('enrollments', function (Builder $enrollmentQuery): void {
                        if ($this->academic_year_id !== '') {
                            $enrollmentQuery->where('academic_year_id', $this->academic_year_id);
                        }

                        if ($this->section_id !== '') {
                            $enrollmentQuery->where('section_id', $this->section_id);
                        } elseif ($this->grade_id !== '') {
                            $enrollmentQuery->whereHas('section', function (Builder $sectionQuery): void {
                                $sectionQuery->where('grade_id', $this->grade_id);
                            });
                        }
                    });
                },
            )
            ->when(
                $this->status !== '' && StudentStatus::tryFrom($this->status) !== null,
                fn (Builder $query) => $query->where('students.status', $this->status),
            )
            ->when($like !== null || $mobileLike !== null, function (Builder $query) use ($like, $mobileLike): void {
                $query->where(static function (Builder $searchQuery) use ($like, $mobileLike): void {
                    if ($like !== null) {
                        $searchQuery->whereRaw("students.name LIKE ? ESCAPE '!'", [$like])
                            ->orWhereRaw("students.admission_no LIKE ? ESCAPE '!'", [$like]);
                    }

                    if ($mobileLike !== null) {
                        $method = $like !== null ? 'orWhereHas' : 'whereHas';
                        $searchQuery->{$method}('guardians', static function (Builder $guardianQuery) use ($mobileLike): void {
                            $guardianQuery->whereRaw("guardians.mobile LIKE ? ESCAPE '!'", [$mobileLike]);
                        });
                    }
                });
            })
            ->with([
                'enrollments' => function ($query): void {
                    if ($this->academic_year_id !== '') {
                        $query->where('academic_year_id', $this->academic_year_id);
                    }

                    $query->with([
                        'academicYear:id,school_id,is_current,starts_on',
                        'section:id,school_id,grade_id,academic_year_id,name',
                        'section.grade:id,school_id,name,sort_order',
                    ]);
                },
                'studentGuardians' => static function ($query): void {
                    $query->where('is_primary', true)
                        ->with('guardian:id,school_id,name,mobile');
                },
            ])
            ->orderBy('students.name')
            ->orderBy('students.id')
            ->paginate(25);
    }

    /**
     * Normalize user mobile search input before escaping for LIKE lookup on guardians.mobile.
     */
    private function resolveMobileLikePattern(): ?string
    {
        $trimmed = trim($this->search);

        if ($trimmed === '') {
            return null;
        }

        $normalized = MobileNumber::normalize($trimmed);

        if ($normalized !== null && MobileNumber::isValid($normalized)) {
            return LikePattern::escaped($normalized);
        }

        $digits = preg_replace('/\D+/', '', $trimmed);

        if (is_string($digits) && strlen($digits) >= 4) {
            return LikePattern::escaped($digits);
        }

        return null;
    }

    /**
     * Render the students list view.
     */
    public function render(): View
    {
        return view('livewire.students.index');
    }
}
