<?php

namespace Database\Seeders;

use App\Actions\AcademicYears\MakeAcademicYearCurrent;
use App\Actions\Grades\CreateStandardGrades;
use App\Actions\Students\AdmitStudent;
use App\Actions\Students\AttachGuardian;
use App\Enums\BloodGroup;
use App\Enums\EnrollmentStatus;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\IndianState;
use App\Enums\MembershipStatus;
use App\Enums\SchoolBoard;
use App\Enums\SchoolRole;
use App\Enums\SchoolStatus;
use App\Enums\SocialCategory;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Membership;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Activitylog\Support\ActivityLogStatus;

class DemoSeeder extends Seeder
{
    /**
     * Multi-regional Indian first names and surnames paired deterministically for 60 demo students.
     *
     * @var list<array{first: string, surname: string, father_first: string, mother_first: string, gender: Gender, city: string, state: IndianState, tongue: string, religion: string, category: SocialCategory}>
     */
    private const array FAMILY_CATALOG = [
        ['first' => 'Aarav', 'surname' => 'Patel', 'father_first' => 'Rajesh', 'mother_first' => 'Kiran', 'gender' => Gender::Male, 'city' => 'Ahmedabad', 'state' => IndianState::Gujarat, 'tongue' => 'Gujarati', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Kavya', 'surname' => 'Sharma', 'father_first' => 'Manoj', 'mother_first' => 'Priya', 'gender' => Gender::Female, 'city' => 'Jaipur', 'state' => IndianState::Rajasthan, 'tongue' => 'Hindi', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Ishaan', 'surname' => 'Verma', 'father_first' => 'Alok', 'mother_first' => 'Neha', 'gender' => Gender::Male, 'city' => 'Lucknow', 'state' => IndianState::UttarPradesh, 'tongue' => 'Hindi', 'religion' => 'Hindu', 'category' => SocialCategory::Obc],
        ['first' => 'Nandini', 'surname' => 'Reddy', 'father_first' => 'Srinivas', 'mother_first' => 'Padma', 'gender' => Gender::Female, 'city' => 'Hyderabad', 'state' => IndianState::Telangana, 'tongue' => 'Telugu', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Ananya', 'surname' => 'Iyer', 'father_first' => 'Subramanian', 'mother_first' => 'Lakshmi', 'gender' => Gender::Female, 'city' => 'Chennai', 'state' => IndianState::TamilNadu, 'tongue' => 'Tamil', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Harpreet', 'surname' => 'Singh', 'father_first' => 'Gurmeet', 'mother_first' => 'Simran', 'gender' => Gender::Male, 'city' => 'Chandigarh', 'state' => IndianState::Punjab, 'tongue' => 'Punjabi', 'religion' => 'Sikh', 'category' => SocialCategory::General],
        ['first' => 'Sneha', 'surname' => 'Kulkarni', 'father_first' => 'Prashant', 'mother_first' => 'Ashwini', 'gender' => Gender::Female, 'city' => 'Pune', 'state' => IndianState::Maharashtra, 'tongue' => 'Marathi', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Aaron', 'surname' => "D'Souza", 'father_first' => 'Francis', 'mother_first' => 'Maria', 'gender' => Gender::Male, 'city' => 'Panaji', 'state' => IndianState::Goa, 'tongue' => 'Konkani', 'religion' => 'Christian', 'category' => SocialCategory::General],
        ['first' => 'Arjun', 'surname' => 'Deshmukh', 'father_first' => 'Nitin', 'mother_first' => 'Swati', 'gender' => Gender::Male, 'city' => 'Nagpur', 'state' => IndianState::Maharashtra, 'tongue' => 'Marathi', 'religion' => 'Hindu', 'category' => SocialCategory::Obc],
        ['first' => 'Zoya', 'surname' => 'Khan', 'father_first' => 'Imran', 'mother_first' => 'Farhana', 'gender' => Gender::Female, 'city' => 'Bhopal', 'state' => IndianState::MadhyaPradesh, 'tongue' => 'Urdu', 'religion' => 'Muslim', 'category' => SocialCategory::Obc],
        ['first' => 'Ritvik', 'surname' => 'Banerjee', 'father_first' => 'Soumen', 'mother_first' => 'Anindita', 'gender' => Gender::Male, 'city' => 'Kolkata', 'state' => IndianState::WestBengal, 'tongue' => 'Bengali', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Tanvi', 'surname' => 'Joshi', 'father_first' => 'Hemant', 'mother_first' => 'Meera', 'gender' => Gender::Female, 'city' => 'Dehradun', 'state' => IndianState::Uttarakhand, 'tongue' => 'Hindi', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Pranav', 'surname' => 'Menon', 'father_first' => 'Gopal', 'mother_first' => 'Radha', 'gender' => Gender::Male, 'city' => 'Kochi', 'state' => IndianState::Kerala, 'tongue' => 'Malayalam', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Sana', 'surname' => 'Shaikh', 'father_first' => 'Tariq', 'mother_first' => 'Shabnam', 'gender' => Gender::Female, 'city' => 'Surat', 'state' => IndianState::Gujarat, 'tongue' => 'Gujarati', 'religion' => 'Muslim', 'category' => SocialCategory::Obc],
        ['first' => 'Meera', 'surname' => 'Nair', 'father_first' => 'Suresh', 'mother_first' => 'Geetha', 'gender' => Gender::Female, 'city' => 'Thiruvananthapuram', 'state' => IndianState::Kerala, 'tongue' => 'Malayalam', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Devansh', 'surname' => 'Gupta', 'father_first' => 'Pankaj', 'mother_first' => 'Ritu', 'gender' => Gender::Male, 'city' => 'Delhi', 'state' => IndianState::Delhi, 'tongue' => 'Hindi', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Rhea', 'surname' => "D'Souza", 'father_first' => 'Peter', 'mother_first' => 'Claire', 'gender' => Gender::Female, 'city' => 'Mumbai', 'state' => IndianState::Maharashtra, 'tongue' => 'English', 'religion' => 'Christian', 'category' => SocialCategory::General],
        ['first' => 'Karthik', 'surname' => 'Pillai', 'father_first' => 'Raghavan', 'mother_first' => 'Usha', 'gender' => Gender::Male, 'city' => 'Madurai', 'state' => IndianState::TamilNadu, 'tongue' => 'Tamil', 'religion' => 'Hindu', 'category' => SocialCategory::Sc],
        ['first' => 'Nisha', 'surname' => 'Rao', 'father_first' => 'Venkat', 'mother_first' => 'Sarita', 'gender' => Gender::Female, 'city' => 'Bengaluru', 'state' => IndianState::Karnataka, 'tongue' => 'Kannada', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Kabir', 'surname' => 'Mehta', 'father_first' => 'Sandeep', 'mother_first' => 'Pooja', 'gender' => Gender::Male, 'city' => 'Vadodara', 'state' => IndianState::Gujarat, 'tongue' => 'Gujarati', 'religion' => 'Jain', 'category' => SocialCategory::General],
        ['first' => 'Aditi', 'surname' => 'Singh', 'father_first' => 'Harish', 'mother_first' => 'Kavita', 'gender' => Gender::Female, 'city' => 'Patna', 'state' => IndianState::Bihar, 'tongue' => 'Hindi', 'religion' => 'Hindu', 'category' => SocialCategory::Obc],
        ['first' => 'Siddharth', 'surname' => 'Chatterjee', 'father_first' => 'Debashish', 'mother_first' => 'Mitali', 'gender' => Gender::Male, 'city' => 'Howrah', 'state' => IndianState::WestBengal, 'tongue' => 'Bengali', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Lakshmi', 'surname' => 'Reddy', 'father_first' => 'Rameshwar', 'mother_first' => 'Sujata', 'gender' => Gender::Female, 'city' => 'Vijayawada', 'state' => IndianState::AndhraPradesh, 'tongue' => 'Telugu', 'religion' => 'Hindu', 'category' => SocialCategory::General],
        ['first' => 'Farhan', 'surname' => 'Khan', 'father_first' => 'Salman', 'mother_first' => 'Nadia', 'gender' => Gender::Male, 'city' => 'Indore', 'state' => IndianState::MadhyaPradesh, 'tongue' => 'Hindi', 'religion' => 'Muslim', 'category' => SocialCategory::Obc],
        ['first' => 'Pallavi', 'surname' => 'Das', 'father_first' => 'Bimal', 'mother_first' => 'Smita', 'gender' => Gender::Female, 'city' => 'Bhubaneswar', 'state' => IndianState::Odisha, 'tongue' => 'Odia', 'religion' => 'Hindu', 'category' => SocialCategory::Sc],
        ['first' => 'Yash', 'surname' => 'Bhat', 'father_first' => 'Keshav', 'mother_first' => 'Sharda', 'gender' => Gender::Male, 'city' => 'Jammu', 'state' => IndianState::JammuAndKashmir, 'tongue' => 'Hindi', 'religion' => 'Hindu', 'category' => SocialCategory::St],
        ['first' => 'Nikhil', 'surname' => 'Parmar', 'father_first' => 'Dinesh', 'mother_first' => 'Bhavna', 'gender' => Gender::Male, 'city' => 'Rajkot', 'state' => IndianState::Gujarat, 'tongue' => 'Gujarati', 'religion' => 'Hindu', 'category' => SocialCategory::Sc],
        ['first' => 'Tanya', 'surname' => 'Gogoi', 'father_first' => 'Pradip', 'mother_first' => 'Rina', 'gender' => Gender::Female, 'city' => 'Guwahati', 'state' => IndianState::Assam, 'tongue' => 'Assamese', 'religion' => 'Hindu', 'category' => SocialCategory::St],
    ];

    /**
     * Sibling overrides pointing later demo students to earlier seeded father accounts.
     *
     * @var array<int, array{sibling_of_index: int, first: string, gender: Gender}>
     */
    private const array SIBLING_OVERRIDES = [
        16 => ['sibling_of_index' => 0, 'first' => 'Diya', 'gender' => Gender::Female],     // Patel siblings (Class 1-A & Class 3-B)
        22 => ['sibling_of_index' => 4, 'first' => 'Rohan', 'gender' => Gender::Male],     // Iyer siblings (Class 1-B & Class 4-B)
        31 => ['sibling_of_index' => 9, 'first' => 'Ayaan', 'gender' => Gender::Male],     // Khan siblings (Class 2-B & Class 6-A)
        43 => ['sibling_of_index' => 14, 'first' => 'Vikram', 'gender' => Gender::Male],   // Nair siblings (Class 3-A & Class 8-A)
    ];

    /**
     * Student indices (0-based out of 60) left without roll numbers to illustrate unassigned state.
     *
     * @var list<int>
     */
    private const array UNASSIGNED_ROLL_INDICES = [3, 19, 38, 57];

    /**
     * Student indices (0-based out of 60) transitioned to status Left.
     *
     * @var list<int>
     */
    private const array LEFT_STUDENT_INDICES = [11, 46];

    /**
     * Run the database seeds for local visual exploration of the school picker, switcher, and student views.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoSeeder can only run in local or testing environments.');
        }

        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
        ]);

        $multiSchoolUser = $this->ensureUser('Test User', 'test@example.com');
        $singleSchoolUser = $this->ensureUser('Single School User', 'single@example.com');
        $this->ensureUser('No School User', 'noschool@example.com');
        $otherUser = $this->ensureUser('Other School User', 'other@example.com');

        $sunrise = $this->ensureSchool(
            name: 'Sunrise Public School',
            status: SchoolStatus::Active,
            board: SchoolBoard::Cbse,
            city: 'Ahmedabad',
            state: IndianState::Gujarat,
            pincode: '380009',
        );

        $stXaviers = $this->ensureSchool(
            name: "St. Xavier's High School",
            status: SchoolStatus::Trial,
            board: SchoolBoard::Cisce,
            city: 'Pune',
            state: IndianState::Maharashtra,
            pincode: '411001',
            trialEndsAt: now()->addDays(30),
        );

        $greenValley = $this->ensureSchool(
            name: 'Green Valley Academy',
            status: SchoolStatus::Active,
            board: SchoolBoard::State,
            city: 'Bengaluru',
            state: IndianState::Karnataka,
            pincode: '560001',
        );

        $suspendedSchool = $this->ensureSchool(
            name: 'Archived Heritage School',
            status: SchoolStatus::Suspended,
            board: SchoolBoard::Cbse,
            city: 'Jaipur',
            state: IndianState::Rajasthan,
            pincode: '302001',
        );

        $disabledMembershipSchool = $this->ensureSchool(
            name: 'Former Oakridge School',
            status: SchoolStatus::Active,
            board: SchoolBoard::Other,
            city: 'Surat',
            state: IndianState::Gujarat,
            pincode: '395007',
        );

        $foreignSchool = $this->ensureSchool(
            name: 'Maple Leaf International School',
            status: SchoolStatus::Active,
            board: SchoolBoard::Cbse,
            city: 'Mumbai',
            state: IndianState::Maharashtra,
            pincode: '400001',
        );

        $this->ensureMembership($multiSchoolUser, $sunrise, MembershipStatus::Active, SchoolRole::Owner);
        $this->ensureMembership($multiSchoolUser, $stXaviers, MembershipStatus::Active, SchoolRole::Teacher);
        $this->ensureMembership($multiSchoolUser, $greenValley, MembershipStatus::Active, SchoolRole::Parent);
        $this->ensureMembership($multiSchoolUser, $suspendedSchool, MembershipStatus::Active, SchoolRole::Admin);
        $this->ensureMembership($multiSchoolUser, $disabledMembershipSchool, MembershipStatus::Disabled);

        $this->ensureMembership($singleSchoolUser, $sunrise, MembershipStatus::Active, SchoolRole::Teacher);
        $this->ensureMembership($otherUser, $foreignSchool, MembershipStatus::Active, SchoolRole::Owner);

        foreach ([$sunrise, $stXaviers, $greenValley, $suspendedSchool, $disabledMembershipSchool, $foreignSchool] as $school) {
            $this->ensureDemoSchoolSetup($school);
        }

        $this->seedSunriseStudents($sunrise, $multiSchoolUser);

        setPermissionsTeamId(null);
    }

    /**
     * Ensure each demo school has past/current academic years and standard grades.
     */
    private function ensureDemoSchoolSetup(School $school): void
    {
        CurrentSchool::set($school);

        try {
            AcademicYear::firstOrCreate(
                ['name' => '2025-26'],
                [
                    'starts_on' => '2025-04-01',
                    'ends_on' => '2026-03-31',
                ],
            );

            $currentYear = AcademicYear::firstOrCreate(
                ['name' => '2026-27'],
                [
                    'starts_on' => '2026-04-01',
                    'ends_on' => '2027-03-31',
                ],
            );

            if (! $currentYear->is_current && ! AcademicYear::current()->exists()) {
                app(MakeAcademicYearCurrent::class)->handle($currentYear);
            }

            app(CreateStandardGrades::class)->handle();
        } finally {
            CurrentSchool::forget();
        }
    }

    /**
     * Seed Class 1-10 sections (A & B) and 60 realistic students in Sunrise Public School for 2026-27 via real actions.
     */
    private function seedSunriseStudents(School $sunrise, User $actor): void
    {
        CurrentSchool::set($sunrise);
        setPermissionsTeamId($sunrise->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        $activityLogStatus = app(ActivityLogStatus::class);
        $wasDisabled = $activityLogStatus->disabled();

        try {
            $activityLogStatus->disable();

            $year = AcademicYear::query()->where('name', '2026-27')->firstOrFail();

            $gradeNames = array_map(
                static fn (int $classNum): string => "Class {$classNum}",
                range(1, 10),
            );

            $grades = Grade::query()
                ->whereIn('name', $gradeNames)
                ->ordered()
                ->get();

            if ($grades->count() !== count($gradeNames)) {
                throw new RuntimeException('Expected Class 1 through Class 10 grades to exist before seeding demo students.');
            }

            /** @var list<array{grade: Grade, class_num: int, section: Section}> $sectionSlots */
            $sectionSlots = [];

            foreach ($grades as $gradeIndex => $grade) {
                $classNum = $gradeIndex + 1;

                foreach (['A', 'B'] as $sectionName) {
                    $section = Section::firstOrCreate(
                        [
                            'academic_year_id' => $year->id,
                            'grade_id' => $grade->id,
                            'name' => $sectionName,
                        ],
                        [
                            'capacity' => 40,
                        ],
                    );

                    $sectionSlots[] = [
                        'grade' => $grade,
                        'class_num' => $classNum,
                        'section' => $section,
                    ];
                }
            }

            $admitStudent = app(AdmitStudent::class);
            $attachGuardian = app(AttachGuardian::class);

            // Spread 60 students cleanly across 20 sections (2-4 per section: 4, 2, 3, 3 repeated 5 times).
            $studentsPerSectionCycle = [4, 2, 3, 3];
            $bloodGroups = BloodGroup::cases();
            $familyMobilePrefixes = ['9', '8', '7'];

            $studentIndex = 0;

            foreach ($sectionSlots as $slotIndex => $slot) {
                $countForSection = $studentsPerSectionCycle[$slotIndex % count($studentsPerSectionCycle)];
                $nextRoll = 1;

                for ($position = 0; $position < $countForSection; $position++) {
                    $number = $studentIndex + 1;
                    $admissionNo = sprintf('SPS-2026-%03d', $number);
                    $isSibling = array_key_exists($studentIndex, self::SIBLING_OVERRIDES);
                    $sourceFamilyIndex = $isSibling
                        ? self::SIBLING_OVERRIDES[$studentIndex]['sibling_of_index']
                        : $studentIndex;

                    $family = self::FAMILY_CATALOG[$sourceFamilyIndex % count(self::FAMILY_CATALOG)];
                    $firstName = $isSibling
                        ? self::SIBLING_OVERRIDES[$studentIndex]['first']
                        : ($studentIndex < count(self::FAMILY_CATALOG) ? $family['first'] : $this->alternateFirstName($family['gender'], $number));
                    $gender = $isSibling ? self::SIBLING_OVERRIDES[$studentIndex]['gender'] : $family['gender'];
                    $fatherFirst = (! $isSibling && $studentIndex >= count(self::FAMILY_CATALOG))
                        ? $this->alternateParentFirstName(true, $number)
                        : $family['father_first'];
                    $motherFirst = (! $isSibling && $studentIndex >= count(self::FAMILY_CATALOG))
                        ? $this->alternateParentFirstName(false, $number)
                        : $family['mother_first'];
                    $fatherName = "{$fatherFirst} {$family['surname']}";
                    $motherName = "{$motherFirst} {$family['surname']}";

                    $mobilePrefix = $familyMobilePrefixes[$sourceFamilyIndex % count($familyMobilePrefixes)];
                    $fatherMobile = '+91'.$mobilePrefix.sprintf('00000%04d', 100 + $sourceFamilyIndex);
                    $motherMobile = '+91'.$mobilePrefix.sprintf('00000%04d', 500 + $sourceFamilyIndex);

                    $existingStudent = Student::query()->firstWhere('admission_no', $admissionNo);

                    if (! $existingStudent instanceof Student) {
                        $birthYear = 2020 - $slot['class_num'];
                        $birthMonth = (($number % 12) + 1);
                        $birthDay = (($number % 25) + 1);
                        $dob = sprintf('%04d-%02d-%02d', $birthYear, $birthMonth, $birthDay);

                        $admissionYear = 2026 - ($slot['class_num'] - 1);
                        $admissionDate = sprintf('%04d-04-%02d', $admissionYear, ($number % 15) + 1);
                        $enrolledOn = $year->resolveEnrollmentDate($admissionDate)->toDateString();

                        $includeSensitive = $studentIndex % 2 === 0;
                        $rollNumber = in_array($studentIndex, self::UNASSIGNED_ROLL_INDICES, true)
                            ? null
                            : $nextRoll++;

                        $studentData = [
                            'admission_no' => $admissionNo,
                            'admission_date' => $admissionDate,
                            'name' => "{$firstName} {$family['surname']}",
                            'date_of_birth' => $dob,
                            'gender' => $gender->value,
                            'nationality' => 'Indian',
                            'mother_tongue' => $family['tongue'],
                            'blood_group' => $bloodGroups[$studentIndex % count($bloodGroups)]->value,
                            'address_line' => sprintf('%d Shanti Nagar Society', 10 + $sourceFamilyIndex),
                            'city' => $family['city'],
                            'state' => $family['state']->value,
                            'pincode' => $sunrise->pincode ?? '380009',
                        ];

                        if ($includeSensitive) {
                            $studentData['social_category'] = $family['category']->value;
                            $studentData['religion'] = $family['religion'];
                            $studentData['apaar_id'] = sprintf('40000000%04d', $number);
                            $studentData['pen'] = sprintf('3000000%04d', $number);
                            $studentData['aadhaar_last4'] = sprintf('%04d', 1000 + $number);
                        }

                        $existingFather = Guardian::query()
                            ->where('mobile', $fatherMobile)
                            ->where('name', $fatherName)
                            ->first();

                        $primaryGuardianPayload = $existingFather instanceof Guardian
                            ? [
                                'guardian_id' => $existingFather->id,
                                'relationship' => GuardianRelationship::Father->value,
                                'is_primary' => true,
                            ]
                            : [
                                'name' => $fatherName,
                                'mobile' => $fatherMobile,
                                'occupation' => 'Service',
                                'relationship' => GuardianRelationship::Father->value,
                                'is_primary' => true,
                            ];

                        $admittedStudent = $admitStudent->handle(
                            actor: $actor,
                            studentData: $studentData,
                            guardiansData: [$primaryGuardianPayload],
                            enrollmentData: [
                                'academic_year_id' => $year->id,
                                'grade_id' => $slot['grade']->id,
                                'section_id' => $slot['section']->id,
                                'roll_number' => $rollNumber,
                                'enrolled_on' => $enrolledOn,
                            ],
                            consentConfirmed: true,
                        );

                        if ($studentIndex % 3 === 0) {
                            $existingMother = Guardian::query()
                                ->where('mobile', $motherMobile)
                                ->where('name', $motherName)
                                ->first();

                            $motherPayload = $existingMother instanceof Guardian
                                ? [
                                    'guardian_id' => $existingMother->id,
                                    'relationship' => GuardianRelationship::Mother->value,
                                    'is_primary' => false,
                                ]
                                : [
                                    'name' => $motherName,
                                    'mobile' => $motherMobile,
                                    'relationship' => GuardianRelationship::Mother->value,
                                    'is_primary' => false,
                                ];

                            $attachGuardian->handle($actor, $admittedStudent, $motherPayload);
                        }

                        if (in_array($studentIndex, self::LEFT_STUDENT_INDICES, true)) {
                            $admittedStudent->status = StudentStatus::Left;
                            $admittedStudent->save();

                            $enrollment = $admittedStudent->enrollments()
                                ->where('academic_year_id', $year->id)
                                ->firstOrFail();
                            $enrollment->update([
                                'status' => EnrollmentStatus::Left,
                                'left_on' => '2026-09-15',
                            ]);
                        }
                    }

                    $studentIndex++;
                }
            }
        } finally {
            if (! $wasDisabled) {
                $activityLogStatus->enable();
            }

            CurrentSchool::forget();
            setPermissionsTeamId(null);
        }
    }

    /**
     * Provide distinct secondary first names when reusing base family entries across 60 students.
     */
    private function alternateFirstName(Gender $gender, int $number): string
    {
        $maleNames = ['Vivaan', 'Aditya', 'Reyansh', 'Atharv', 'Shaurya', 'Kabir', 'Om', 'Rishabh', 'Parth', 'Naman', 'Kunal', 'Varun', 'Dev', 'Tejas', 'Tarun', 'Nikhil', 'Samar', 'Mihir'];
        $femaleNames = ['Aadhya', 'Siya', 'Pari', 'Navya', 'Ira', 'Myra', 'Nitya', 'Aarohi', 'Riya', 'Saanvi', 'Avni', 'Tisha', 'Jiya', 'Khushi', 'Shruti', 'Mehak', 'Priyal', 'Anvi'];

        $pool = $gender === Gender::Female ? $femaleNames : $maleNames;

        return $pool[$number % count($pool)];
    }

    /**
     * Provide distinct secondary parent first names when reusing surname templates across families.
     */
    private function alternateParentFirstName(bool $isFather, int $number): string
    {
        $fathers = ['Rakesh', 'Sanjay', 'Vijay', 'Sunil', 'Mahesh', 'Dinesh', 'Naveen', 'Ashok', 'Amit', 'Pradeep', 'Girish', 'Ravi', 'Mukesh', 'Tarun', 'Narendra', 'Harish', 'Kishore', 'Uday'];
        $mothers = ['Sunita', 'Anita', 'Rekha', 'Geeta', 'Seema', 'Deepa', 'Lata', 'Shobha', 'Vandana', 'Alka', 'Asha', 'Bharti', 'Nirmala', 'Suman', 'Archana', 'Kalpana', 'Jyoti', 'Urmila'];

        $pool = $isFather ? $fathers : $mothers;

        return $pool[$number % count($pool)];
    }

    /**
     * Find or create a verified demo user without overwriting existing passwords.
     */
    private function ensureUser(string $name, string $email): User
    {
        $user = User::where('email', $email)->first();

        if ($user instanceof User) {
            return $user;
        }

        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => 'test@123',
        ]);
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    /**
     * Find or create a school by name with explicit non-fillable status and trial bounds.
     */
    private function ensureSchool(
        string $name,
        SchoolStatus $status,
        SchoolBoard $board,
        string $city,
        IndianState $state,
        string $pincode,
        ?CarbonInterface $trialEndsAt = null,
    ): School {
        $school = School::withTrashed()->where('name', $name)->first();

        if ($school instanceof School) {
            if ($school->trashed()) {
                $school->restore();
            }

            return $school;
        }

        $school = new School([
            'name' => $name,
            'board' => $board,
            'city' => $city,
            'state' => $state,
            'pincode' => $pincode,
        ]);
        $school->status = $status;
        $school->trial_ends_at = $trialEndsAt;
        $school->save();

        return $school;
    }

    /**
     * Find or create a membership for a user and school, and optionally assign a school role.
     */
    private function ensureMembership(
        User $user,
        School $school,
        MembershipStatus $status,
        ?SchoolRole $role = null,
    ): Membership {
        $membership = Membership::where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $membership instanceof Membership) {
            $membership = new Membership([
                'joined_at' => now(),
            ]);
            $membership->school_id = $school->id;
            $membership->user_id = $user->id;
            $membership->save();
        }

        if ($status === MembershipStatus::Disabled && $membership->isActive()) {
            $membership->disable();
        } elseif ($status === MembershipStatus::Active && ! $membership->isActive()) {
            $membership->activate();
        }

        if ($role !== null) {
            setPermissionsTeamId($school->id);
            if (! $user->hasRole($role->value)) {
                $user->assignRole($role->value);
            }
        }

        return $membership;
    }
}
