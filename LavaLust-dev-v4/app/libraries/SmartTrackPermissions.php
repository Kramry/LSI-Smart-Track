<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmartTrackPermissions
{
    private const ROLE_GROUPS = [
        'administrative_staff' => ['administrator', 'staff'],
        'teaching_staff' => ['administrator', 'staff', 'instructor'],
        'all_accounts' => ['administrator', 'staff', 'instructor', 'trainee'],
        'certificate_viewers' => ['administrator', 'staff', 'trainee'],
    ];

    private const ADMIN_STAFF_ACCESS = ['users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'payments', 'analytics', 'certificates', 'eligibility', 'assessments', 'certificate_config', 'announcements', 'inquiries', 'activity'];
    private const ADMIN_STAFF_DEFAULT_NAVIGATION = ['dashboard', 'users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'payments', 'analytics', 'certificates', 'certificates_to_sign', 'eligibility', 'assessments', 'certificate_config', 'announcements', 'inquiries', 'activity'];
    private const ADMIN_STAFF_STANDALONE_NAVIGATION = ['dashboard', 'users', 'trainees', 'programs', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'payments', 'analytics', 'certificates', 'certificates_to_sign', 'eligibility', 'assessments', 'certificate_config', 'announcements', 'inquiries', 'activity', 'profile'];

    private const ACCESS = [
        'administrator' => self::ADMIN_STAFF_ACCESS,
        'staff' => self::ADMIN_STAFF_ACCESS,
        'instructor' => ['trainees', 'classes', 'schedules', 'enrollments', 'attendance', 'documents', 'analytics', 'assessments', 'announcements', 'inquiries'],
        'trainee' => ['attendance', 'documents', 'my_payments', 'assessments', 'announcements', 'inquiries'],
    ];

    private const MANAGEMENT = [
        'administrator' => ['*'],
        'staff' => ['*'],
        'instructor' => ['attendance', 'assessments', 'announcements', 'inquiries', 'documents'],
        'trainee' => ['inquiries'],
    ];

    private const NAVIGATION = [
        'default' => [
            'administrator' => self::ADMIN_STAFF_DEFAULT_NAVIGATION,
            'staff' => self::ADMIN_STAFF_DEFAULT_NAVIGATION,
            'instructor' => ['dashboard', 'classes', 'trainees', 'schedules', 'enrollments', 'attendance', 'documents', 'analytics', 'assessments', 'announcements', 'inquiries'],
            'trainee' => ['dashboard', 'attendance', 'documents', 'my_payments', 'assessments', 'my_certificates', 'announcements', 'inquiries'],
        ],
        'standalone' => [
            'administrator' => self::ADMIN_STAFF_STANDALONE_NAVIGATION,
            'staff' => self::ADMIN_STAFF_STANDALONE_NAVIGATION,
            'instructor' => ['dashboard', 'classes', 'trainees', 'schedules', 'enrollments', 'attendance', 'documents', 'analytics', 'announcements', 'inquiries', 'profile'],
            'trainee' => ['dashboard', 'attendance', 'documents', 'my_payments', 'my_certificates', 'announcements', 'inquiries', 'profile'],
        ],
    ];

    public function canAccess($role, $section)
    {
        return in_array($section, self::ACCESS[$role] ?? [], true);
    }

    public function canManage($role, $section)
    {
        $manageable = self::MANAGEMENT[$role] ?? [];
        return in_array('*', $manageable, true) || in_array($section, $manageable, true);
    }

    public function inRoleGroup($role, $group)
    {
        return in_array($role, self::ROLE_GROUPS[$group] ?? [], true);
    }

    public function navigation($role, $context = 'default')
    {
        return self::NAVIGATION[$context][$role] ?? [];
    }
}
