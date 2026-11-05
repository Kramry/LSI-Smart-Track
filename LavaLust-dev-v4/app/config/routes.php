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
$router->get('/manage/classes/{id}', 'Classes::detail');
$router->get('/manage/classes', 'Classes::index');
$router->post('/manage/classes/save', 'Classes::save');
$router->post('/manage/classes/delete/{id}', 'Classes::delete');
$router->get('/manage/analytics', 'Analytics::index');
$router->get('/manage/assessments', 'Assessments::assessments');
$router->get('/manage/payments', 'Payment::payments');
$router->get('/payments', 'Payment::payments');
$router->get('/my-payments', 'Payment::myPayments');
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

# PAYMENTS
$router->post('/payments/fees/save', 'Payment::saveFeeType');
$router->post('/payments/fees/{id}/deactivate', 'Payment::deactivateFeeType');
$router->post('/payments/record', 'Payment::recordPayment');
$router->post('/my-payments/proof', 'Payment::submitProof');
$router->post('/payments/proofs/{id}/review', 'Payment::reviewProof');
$router->get('/payments/proof/{id}', 'Payment::proofFile');
$router->get('/payments/receipt/{id}', 'Payment::receipt');
$router->post('/payments/bonds/{id}/action', 'Payment::bondAction');
$router->post('/payments/{id}/reverse', 'Payment::reversePayment');
$router->get('/payments/report/export', 'Payment::exportReport');

# REPORTS
$router->get('/manage/reports', 'Reports::index');
$router->post('/manage/reports/generate', 'Reports::generate');

# CERTIFICATES
$router->post('/certificates/recalculate', 'Certificates::recalculateEligibility');
$router->post('/certificates/review', 'Certificates::reviewEligibility');
$router->post('/certificates/eligibility/{id}/delete', 'Certificates::deleteEligibility');
$router->post('/certificates/issue', 'Certificates::issueCertificate');
$router->post('/certificates/revoke', 'Certificates::revokeCertificate');
$router->get('/certificates/to-sign', 'Certificates::certificatesToSign');
$router->get('/certificates/to-sign/{id}', 'Certificates::certificatesToSign');
$router->get('/certificates/{id}/sign-preview', 'Certificates::previewCertificateForSigning');
$router->post('/certificates/{id}/sign', 'Certificates::signCertificate');
$router->post('/certificates/{id}/reject-signature', 'Certificates::rejectCertificateSignature');
$router->post('/certificates/{id}/resubmit-signature', 'Certificates::resubmitCertificateForSigning');
$router->post('/certificate-signatories/save', 'Certificates::saveCertificateSignatory');
$router->post('/certificate-signatories/{id}/delete', 'Certificates::deleteCertificateSignatory');
$router->get('/certificates/{id}/history', 'Certificates::certificateHistory');
$router->get('/my-certificates', 'Certificates::myCertificates');
$router->get('/certificates/{id}/download', 'Certificates::downloadCertificate');
$router->get('/verify-certificate', 'Certificates::verifyCertificate');

# API ENDPOINTS
$router->get('/api/session', 'SmartTrack_api::apiSession');
$router->get('/api/storage/status', 'SmartTrack_api::apiStorageStatus');
$router->get('/api/registration-options', 'SmartTrack_api::apiRegistrationOptions');
$router->post('/api/login', 'SmartTrack_api::apiLogin');
$router->post('/api/register', 'SmartTrack_api::apiRegister');
$router->get('/api/notifications/history', 'SmartTrack_api::apiNotificationHistory');
$router->post('/api/notifications/send', 'SmartTrack_api::apiSendAnnouncement');
$router->post('/api/notifications/{id}/retry', 'SmartTrack_api::apiRetryNotification');
$router->post('/api/notifications/{id}/delete', 'SmartTrack_api::apiDeleteNotification');
$router->post('/api/notifications/settings', 'SmartTrack_api::apiSaveNotificationSettings');
$router->post('/api/notifications/test', 'SmartTrack_api::apiTestNotification');
$router->post('/api/refresh', 'SmartTrack_api::apiRefresh');
$router->post('/api/logout', 'SmartTrack_api::apiLogout');
$router->get('/api/dashboard', 'SmartTrack_api::apiDashboard');
$router->get('/api/analytics', 'Analytics::data');
$router->get('/api/classes', 'Classes::apiIndex');
$router->get('/api/classes/{id}', 'Classes::apiDetail');
$router->post('/api/classes/save', 'Classes::apiSave');
$router->post('/api/classes/delete/{id}', 'Classes::apiDelete');
$router->get('/api/payments', 'Payment::apiData');
$router->post('/api/payments/xendit', 'Payment::apiCreateXenditPayment');
$router->post('/api/payments/xendit/webhook', 'Payment::apiXenditWebhook');
$router->get('/api/payments/proof/{id}', 'Payment::proofFile');
$router->get('/api/payments/receipt/{id}', 'Payment::receipt');
$router->get('/api/payments/report/export', 'Payment::apiExportReport');
$router->post('/api/payments/fees/save', 'Payment::saveFeeType');
$router->post('/api/payments/fees/{id}/deactivate', 'Payment::deactivateFeeType');
$router->post('/api/payments/record', 'Payment::recordPayment');
$router->post('/api/payments/proofs', 'Payment::submitProof');
$router->post('/api/payments/proofs/{id}/review', 'Payment::reviewProof');
$router->post('/api/payments/bonds/{id}/action', 'Payment::bondAction');
$router->post('/api/payments/{id}/reverse', 'Payment::reversePayment');
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
$router->get('/api/attendance/classes', 'SmartTrack_api::apiAttendanceClasses');
$router->get('/api/attendance/classes/{classId}/schedules', 'SmartTrack_api::apiAttendanceSchedules');
$router->get('/api/attendance/today', 'SmartTrack_api::apiTodayAttendance');
$router->get('/api/reports/export/{period}', 'SmartTrack_api::apiExportReport');
$router->get('/api/reports', 'SmartTrack_api::apiReports');
$router->post('/api/reports/generate', 'SmartTrack_api::apiGenerateReport');
$router->post('/api/certificates/review', 'SmartTrack_api::apiReviewEligibility');
$router->post('/api/certificates/recalculate', 'SmartTrack_api::apiRecalculateEligibility');
$router->post('/api/certificates/eligibility/{id}/delete', 'SmartTrack_api::apiDeleteEligibility');
$router->post('/api/certificates/issue', 'SmartTrack_api::apiIssueCertificate');
$router->post('/api/certificates/revoke', 'SmartTrack_api::apiRevokeCertificate');
$router->get('/api/certificates/{id}/download', 'Certificates::downloadCertificate');
$router->get('/api/certificate-templates/{id}/file', 'Certificates::certificateTemplateFile');
$router->get('/api/verify-certificate', 'Certificates::apiVerifyCertificate');
$router->get('/api/certificates/to-sign', 'SmartTrack_api::apiCertificatesToSign');
$router->get('/api/certificates/{id}/sign-preview', 'SmartTrack_api::apiCertificateSignPreview');
$router->post('/api/certificates/{id}/sign', 'SmartTrack_api::apiSignCertificate');
$router->post('/api/certificates/{id}/reject-signature', 'SmartTrack_api::apiRejectCertificateSignature');
$router->post('/api/certificate-config', 'SmartTrack_api::apiSaveCertificateConfig');
$router->post('/api/certificate-templates/{id}/activate', 'SmartTrack_api::apiActivateCertificateTemplate');
$router->post('/api/certificates/{id}/resubmit', 'SmartTrack_api::apiResubmitCertificateForSigning');
$router->post('/api/certificate-signatories/save', 'SmartTrack_api::apiSaveCertificateSignatory');
$router->post('/api/certificate-signatories/{id}/delete', 'SmartTrack_api::apiDeleteCertificateSignatory');
$router->post('/api/certificate-requirements/save', 'SmartTrack_api::apiSaveDocumentRequirement');
$router->post('/api/certificate-requirements/{id}/delete', 'SmartTrack_api::apiDeleteDocumentRequirement');