<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmartTrackModuleCatalog
{
    public static function definition($section)
    {
        $definitions = [
            'users' => ['title' => 'User accounts', 'table' => 'users', 'key' => 'user_id', 'columns' => ['full_name', 'username', 'role', 'status']],
            'trainees' => ['title' => 'Trainees', 'table' => 'trainees', 'key' => 'trainee_id', 'columns' => ['first_name', 'last_name', 'course', 'program_name', 'status', 'qr_code']],
            'programs' => ['title' => 'Training programs', 'table' => 'training_programs', 'key' => 'program_id', 'columns' => ['program_code', 'program_name', 'status']],
            'classes' => ['title' => 'Classes', 'table' => 'training_classes', 'key' => 'class_id', 'columns' => ['class_name', 'program_name', 'instructor_name', 'start_date', 'end_date', 'status']],
            'schedules' => ['title' => 'Schedules', 'table' => 'training_schedules', 'key' => 'schedule_id', 'columns' => ['class_name', 'schedule_date', 'start_time', 'end_time']],
            'enrollments' => ['title' => 'Class enrollments', 'table' => 'class_enrollments', 'key' => 'enrollment_id', 'columns' => ['class_name', 'trainee_name', 'enrolled_at', 'registration_payment_status']],
            'attendance' => ['title' => 'Attendance', 'table' => 'attendance_records', 'key' => 'attendance_id', 'columns' => ['trainee_name', 'class_name', 'attendance_date', 'time_in', 'time_out', 'status', 'remarks']],
            'documents' => ['title' => 'Documents', 'table' => 'trainee_documents', 'key' => 'document_id', 'columns' => ['trainee_name', 'document_name', 'upload_date', 'review_status', 'status', 'access_level']],
            'certificates' => ['title' => 'Certificates', 'table' => 'certificates', 'key' => 'certificate_id', 'columns' => ['trainee_name', 'program_name', 'class_name', 'certificate_number', 'issue_date', 'status']],
            'eligibility' => ['title' => 'Certificate Eligibility', 'table' => 'certificate_eligibility', 'key' => 'eligibility_id', 'columns' => ['trainee_name', 'program_name', 'class_name', 'attendance_percentage', 'is_eligible', 'ineligibility_reasons']],
            'announcements' => ['title' => 'Announcements', 'table' => 'announcements', 'key' => 'announcement_id', 'columns' => ['title', 'status', 'author', 'created_at']],
            'inquiries' => ['title' => 'Inquiries', 'table' => 'inquiries', 'key' => 'inquiry_id', 'columns' => ['trainee_name', 'subject', 'message', 'reply', 'status', 'created_at']],
            'activity' => ['title' => 'Activity log', 'table' => 'activity_logs', 'key' => 'log_id', 'columns' => ['user_name', 'action', 'details', 'created_at']],
        ];

        return $definitions[$section] ?? null;
    }

    public static function fields($section, $role)
    {
        $fields = [
            'users' => [
                ['name' => 'full_name', 'label' => 'Full name', 'required' => true, 'max_length' => 150],
                ['name' => 'username', 'label' => 'Username', 'required' => true, 'max_length' => 50],
                ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'required' => true, 'values' => $role === 'administrator' ? ['trainee', 'instructor', 'staff', 'administrator'] : ['trainee', 'instructor', 'staff']],
                ['name' => 'status', 'label' => 'Account status', 'type' => 'select', 'required' => true, 'values' => ['active', 'inactive']],
                ['name' => 'password', 'label' => 'Temporary password (10+ characters)', 'type' => 'password', 'required' => false],
            ],
            'trainees' => [
                ['name' => 'first_name', 'label' => 'First name', 'required' => true, 'max_length' => 100],
                ['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'max_length' => 100],
                ['name' => 'sex', 'label' => 'Sex', 'type' => 'select', 'required' => true, 'values' => ['Female', 'Male', 'Other'], 'max_length' => 20],
                ['name' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'date'],
                ['name' => 'address', 'label' => 'Address', 'type' => 'textarea'],
                ['name' => 'email', 'label' => 'Email address', 'type' => 'email'],
                ['name' => 'contact_number', 'label' => 'Contact number', 'required' => true, 'max_length' => 20],
                ['name' => 'course', 'label' => 'Course / qualification', 'required' => true, 'max_length' => 100],
                ['name' => 'program_id', 'label' => 'Training program', 'type' => 'options', 'options' => 'programs'],
                ['name' => 'user_id', 'label' => 'Trainee login account', 'type' => 'options', 'options' => 'user_accounts'],
                ['name' => 'status', 'label' => 'Trainee status', 'type' => 'select', 'required' => true, 'values' => ['active', 'inactive']],
            ],
            'programs' => [
                ['name' => 'program_code', 'label' => 'LSI program code', 'required' => true, 'max_length' => 30],
                ['name' => 'program_name', 'label' => 'Program name', 'required' => true, 'max_length' => 150],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'values' => ['active', 'inactive']],
            ],
            'classes' => [
                ['name' => 'class_name', 'label' => 'Class name', 'required' => true, 'max_length' => 150],
                ['name' => 'program_id', 'label' => 'Program', 'type' => 'options', 'options' => 'programs', 'required' => true],
                ['name' => 'instructor_id', 'label' => 'Instructor', 'type' => 'options', 'options' => 'instructors'],
                ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date'],
                ['name' => 'end_date', 'label' => 'End date', 'type' => 'date'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'values' => ['active', 'inactive']],
            ],
            'schedules' => [
                ['name' => 'class_id', 'label' => 'Class', 'type' => 'options', 'options' => 'classes', 'required' => true],
                ['name' => 'program_id', 'label' => 'Program (legacy link)', 'type' => 'options', 'options' => 'programs'],
                ['name' => 'schedule_date', 'label' => 'Date', 'type' => 'date', 'required' => true],
                ['name' => 'start_time', 'label' => 'Start time', 'type' => 'time', 'required' => true],
                ['name' => 'end_time', 'label' => 'End time', 'type' => 'time', 'required' => true],
            ],
            'enrollments' => [
                ['name' => 'class_id', 'label' => 'Class', 'type' => 'options', 'options' => 'classes', 'required' => true],
                ['name' => 'trainee_id', 'label' => 'Trainee', 'type' => 'options', 'options' => 'trainees', 'required' => true],
            ],
            'attendance' => [
                ['name' => 'trainee_id', 'label' => 'Trainee', 'type' => 'options', 'options' => 'trainees', 'required' => true],
                ['name' => 'class_id', 'label' => 'Class', 'type' => 'options', 'options' => 'classes'],
                ['name' => 'schedule_id', 'label' => 'Schedule', 'type' => 'options', 'options' => 'schedules'],
                ['name' => 'attendance_date', 'label' => 'Attendance date', 'type' => 'date', 'required' => true],
                ['name' => 'time_in', 'label' => 'Time in', 'type' => 'time'],
                ['name' => 'time_out', 'label' => 'Time out', 'type' => 'time'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'values' => ['Present', 'Late', 'Absent']],
                ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
            ],
            'announcements' => [
                ['name' => 'title', 'label' => 'Title', 'required' => true, 'max_length' => 150],
                ['name' => 'content', 'label' => 'Announcement', 'type' => 'textarea', 'required' => true],
                ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'values' => ['General', 'Training Schedule', 'Training Updates', 'Requirements', 'Important Notice']],
                ['name' => 'attachment', 'label' => 'Attachment URL', 'type' => 'url'],
                ['name' => 'published_at', 'label' => 'Publication date and time', 'type' => 'datetime-local'],
                ['name' => 'status', 'label' => 'Visibility', 'type' => 'select', 'required' => true, 'values' => ['draft', 'published', 'archived']],
            ],
            'inquiries' => [
                ['name' => 'subject', 'label' => 'Subject', 'required' => true, 'max_length' => 150],
                ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
                ['name' => 'reply', 'label' => 'Reply', 'type' => 'textarea'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'values' => ['open', 'replied', 'closed']],
            ],
        ];

        if ($section === 'inquiries' && $role === 'trainee') {
            return array_slice($fields[$section], 0, 2);
        }
        if ($section === 'inquiries' && $role !== 'trainee') {
            return array_slice($fields[$section], 2);
        }
        return $fields[$section] ?? [];
    }
}
