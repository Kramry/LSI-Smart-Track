<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

# AUTHENTICATION
$router->get('/', 'Auth::index');
$router->get('/login', 'Auth::showLogin');
$router->get('/setup', 'Auth::setup');
$router->get('/register', 'Auth::register');
$router->post('/login', 'Auth::login');
$router->post('/setup', 'Auth::setup');
$router->post('/register', 'Auth::register');
$router->post('/logout', 'Auth::logout');

# DASHBOARD & MODULES
$router->get('/dashboard', 'Dashboard::dashboard');
$router->get('/manage/assessments', 'Assessments::assessments');
$router->get('/manage/certificates', 'Certificates::certificates');
$router->get('/manage/eligibility', 'Certificates::eligibility');
$router->get('/manage/certificate-config', 'Certificates::certificateConfig');
$router->get('/manage/certificate_config', 'Certificates::certificateConfig');
$router->get('/certificate-templates/{id}/file', 'Certificates::certificateTemplateFile');
$router->post('/manage/certificate-config/activate/{id}', 'Certificates::activateCertificateTemplate');
$router->post('/manage/certificate-config/delete/{id}', 'Certificates::deleteCertificateTemplate');
$router->post('/manage/certificate-config', 'Certificates::certificateConfig');
$router->get('/manage/{section}', 'Dashboard::module');
$router->post('/manage/{section}/save', 'Dashboard::save');
$router->post('/manage/{section}/delete/{id}', 'Dashboard::delete');

# PROFILE
$router->get('/profile', 'Profile::profile');
$router->post('/profile/save', 'Profile::saveProfile');
$router->post('/profile/password', 'Profile::savePassword');

# ATTENDANCE
$router->post('/attendance/scan', 'Attendance::scanAttendance');
$router->get('/reports/export/{period}', 'Attendance::exportReport');

# DOCUMENTS
$router->post('/documents/upload', 'Documents::uploadDocument');
$router->get('/documents/{id}', 'Documents::downloadDocument');
$router->post('/documents/{id}/delete', 'Documents::deleteDocument');
$router->post('/documents/{id}/status', 'Documents::updateDocumentStatus');
$router->post('/documents/{id}/review', 'Documents::reviewDocument');

# CERTIFICATE CONFIG
$router->post('/certificate-requirements/save', 'Documents::saveDocumentRequirement');
$router->post('/certificate-requirements/{id}/delete', 'Documents::deleteDocumentRequirement');

# ASSESSMENTS
$router->post('/assessments/save', 'Assessments::saveAssessment');
$router->post('/assessments/{id}/delete', 'Assessments::deleteAssessment');

# CERTIFICATES
$router->post('/certificates/recalculate', 'Certificates::recalculateEligibility');
$router->post('/certificates/review', 'Certificates::reviewEligibility');
$router->post('/certificates/eligibility/{id}/delete', 'Certificates::deleteEligibility');
$router->post('/certificates/issue', 'Certificates::issueCertificate');
$router->post('/certificates/revoke', 'Certificates::revokeCertificate');
$router->get('/certificates/{id}/history', 'Certificates::certificateHistory');
$router->get('/my-certificates', 'Certificates::myCertificates');
$router->get('/certificates/{id}/download', 'Certificates::downloadCertificate');
$router->get('/verify-certificate', 'Certificates::verifyCertificate');

# API ENDPOINTS
$router->get('/api/session', 'SmartTrack_api::apiSession');
$router->post('/api/login', 'SmartTrack_api::apiLogin');
$router->post('/api/refresh', 'SmartTrack_api::apiRefresh');
$router->post('/api/logout', 'SmartTrack_api::apiLogout');
$router->get('/api/dashboard', 'SmartTrack_api::apiDashboard');
$router->get('/api/modules/{section}', 'SmartTrack_api::apiModule');
$router->get('/api/trainees', 'SmartTrack_api::getTrainees');
$router->post('/api/modules/{section}/save', 'SmartTrack_api::apiSaveModule');
$router->post('/api/modules/{section}/delete/{id}', 'SmartTrack_api::apiDeleteModule');
$router->post('/api/profile/save', 'SmartTrack_api::apiSaveProfile');
$router->post('/api/profile/password', 'SmartTrack_api::apiSavePassword');
$router->post('/api/documents/upload', 'SmartTrack_api::apiUploadDocument');
$router->get('/api/documents/{id}', 'SmartTrack_api::apiDownloadDocument');
$router->post('/api/documents/{id}/status', 'SmartTrack_api::apiUpdateDocumentStatus');
$router->post('/api/documents/{id}/review', 'SmartTrack_api::apiReviewDocument');
$router->post('/api/documents/{id}/delete', 'SmartTrack_api::apiDeleteDocument');
$router->post('/api/assessments/save', 'SmartTrack_api::apiSaveAssessment');
$router->post('/api/assessments/{id}/delete', 'SmartTrack_api::apiDeleteAssessment');
$router->post('/api/attendance/scan', 'SmartTrack_api::apiScanAttendance');
$router->get('/api/reports/export/{period}', 'SmartTrack_api::apiExportReport');
$router->post('/api/certificates/review', 'SmartTrack_api::apiReviewEligibility');
$router->post('/api/certificates/recalculate', 'SmartTrack_api::apiRecalculateEligibility');
$router->post('/api/certificates/eligibility/{id}/delete', 'SmartTrack_api::apiDeleteEligibility');
$router->post('/api/certificates/issue', 'SmartTrack_api::apiIssueCertificate');
$router->post('/api/certificates/revoke', 'SmartTrack_api::apiRevokeCertificate');
$router->get('/api/certificates/{id}/download', 'SmartTrack_api::downloadCertificate');
$router->post('/api/certificate-config', 'SmartTrack_api::apiSaveCertificateConfig');
$router->post('/api/certificate-requirements/save', 'SmartTrack_api::apiSaveDocumentRequirement');
$router->post('/api/certificate-requirements/{id}/delete', 'SmartTrack_api::apiDeleteDocumentRequirement');