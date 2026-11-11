<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmartTrackModelRegistry
{
    public function loadForApiModule($db, string $section): array
    {
        foreach (['Base_model', 'User_model', 'Record_model'] as $model) {
            require_once APP_DIR . 'models/' . $model . '.php';
        }

        $configModel = null;
        $eligibilityModel = null;
        $certificateModel = null;
        $documentModel = null;
        $assessmentModel = null;
        $attendanceModel = null;
        $dashboardModel = null;
        $timedAssessmentModel = null;

        if (in_array($section, ['certificates', 'eligibility', 'my_certificates', 'certificate_config'], true)) {
            require_once APP_DIR . 'models/Config_model.php';
            require_once APP_DIR . 'models/Eligibility_model.php';
            require_once APP_DIR . 'models/Certificate_model.php';
            $configModel = new Config_model($db);
            $eligibilityModel = new Eligibility_model($db, $configModel);
            $certificateModel = new Certificate_model($db, $eligibilityModel, $configModel);
        }
        if (in_array($section, ['documents', 'classes', 'certificate_config'], true)) {
            require_once APP_DIR . 'models/Document_model.php';
            $documentModel = new Document_model($db);
        }
        if ($section === 'assessments') {
            require_once APP_DIR . 'models/Assessment_model.php';
            require_once APP_DIR . 'models/TimedAssessment_model.php';
            $assessmentModel = new Assessment_model($db);
            $timedAssessmentModel = new TimedAssessment_model($db);
        }
        // Load timed assessment model for assessments/timed routes
        if ($section === 'assessments/timed') {
            require_once APP_DIR . 'models/Assessment_model.php';
            require_once APP_DIR . 'models/TimedAssessment_model.php';
            $assessmentModel = new Assessment_model($db);
            $timedAssessmentModel = new TimedAssessment_model($db);
        }
        if ($section === 'attendance') {
            require_once APP_DIR . 'models/Attendance_model.php';
            require_once APP_DIR . 'models/Dashboard_model.php';
            $attendanceModel = new Attendance_model($db);
            $dashboardModel = new Dashboard_model($db);
        }

        $recordModel = new Record_model($db);
        return [
            'configModel' => $configModel,
            'eligibilityModel' => $eligibilityModel,
            'certificateModel' => $certificateModel,
            'paymentModel' => null,
            'userModel' => new User_model($db),
            'recordModel' => $recordModel,
            'attendanceModel' => $attendanceModel,
            'documentModel' => $documentModel,
            'assessmentModel' => $assessmentModel,
            'timedAssessmentModel' => $timedAssessmentModel,
            'dashboardModel' => $dashboardModel,
            'model' => $recordModel,
        ];
    }

    public function load($db)
    {
        foreach ([
            'Base_model',
            'User_model',
            'Record_model',
            'Attendance_model',
            'Document_model',
            'Assessment_model',
            'TimedAssessment_model',
            'Config_model',
            'Eligibility_model',
            'Certificate_model',
            'Payment_model',
            'Dashboard_model',
        ] as $model) {
            require_once APP_DIR . 'models/' . $model . '.php';
        }

        $configModel = new Config_model($db);
        $eligibilityModel = new Eligibility_model($db, $configModel);
        $recordModel = new Record_model($db);

        return [
            'configModel' => $configModel,
            'eligibilityModel' => $eligibilityModel,
            'certificateModel' => new Certificate_model($db, $eligibilityModel, $configModel),
            'paymentModel' => new Payment_model($db),
            'userModel' => new User_model($db),
            'recordModel' => $recordModel,
            'attendanceModel' => new Attendance_model($db),
            'documentModel' => new Document_model($db),
            'assessmentModel' => new Assessment_model($db),
            'timedAssessmentModel' => new TimedAssessment_model($db),
            'dashboardModel' => new Dashboard_model($db),
            'model' => $recordModel,
        ];
    }

    public function loadOptional($db, $model)
    {
        $allowedModels = ['Analytics_model', 'Classes_model', 'Reports_model'];
        if (!in_array($model, $allowedModels, true)) {
            throw new InvalidArgumentException('Unsupported SmartTrack model: ' . $model);
        }

        require_once APP_DIR . 'models/' . $model . '.php';
        return new $model($db);
    }
}
