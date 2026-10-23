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

$router->get('/', 'SmartTrack::index');
$router->get('/login', 'SmartTrack::showLogin');
$router->get('/setup', 'SmartTrack::setup');
$router->get('/register', 'SmartTrack::register');
$router->post('/login', 'SmartTrack::login');
$router->post('/setup', 'SmartTrack::setup');
$router->post('/register', 'SmartTrack::register');
$router->post('/logout', 'SmartTrack::logout');
$router->get('/dashboard', 'SmartTrack::dashboard');
$router->get('/manage/certificates', 'SmartTrack::certificates');
$router->get('/manage/eligibility', 'SmartTrack::eligibility');
$router->get('/manage/assessments', 'SmartTrack::assessments');
$router->get('/manage/certificate-config', 'SmartTrack::certificateConfig');
$router->post('/manage/certificate-config', 'SmartTrack::certificateConfig');
$router->post('/certificate-requirements/save', 'SmartTrack::saveDocumentRequirement');
$router->post('/certificate-requirements/{id}/delete', 'SmartTrack::deleteDocumentRequirement');
$router->get('/manage/{section}', 'SmartTrack::module');
$router->post('/manage/{section}/save', 'SmartTrack::save');
$router->post('/manage/{section}/delete/{id}', 'SmartTrack::delete');
$router->get('/profile', 'SmartTrack::profile');
$router->post('/profile/save', 'SmartTrack::saveProfile');
$router->post('/profile/password', 'SmartTrack::savePassword');
$router->post('/attendance/scan', 'SmartTrack::scanAttendance');
$router->get('/reports/export/{period}', 'SmartTrack::exportReport');
$router->post('/documents/upload', 'SmartTrack::uploadDocument');
$router->get('/documents/{id}', 'SmartTrack::downloadDocument');
$router->post('/documents/{id}/delete', 'SmartTrack::deleteDocument');
$router->post('/documents/{id}/status', 'SmartTrack::updateDocumentStatus');
$router->post('/certificates/recalculate', 'SmartTrack::recalculateEligibility');
$router->post('/certificates/review', 'SmartTrack::reviewEligibility');
$router->post('/certificates/eligibility/{id}/delete', 'SmartTrack::deleteEligibility');
$router->post('/assessments/save', 'SmartTrack::saveAssessment');
$router->post('/assessments/{id}/delete', 'SmartTrack::deleteAssessment');
$router->post('/certificates/issue', 'SmartTrack::issueCertificate');
$router->post('/certificates/revoke', 'SmartTrack::revokeCertificate');
$router->get('/certificates/{id}/history', 'SmartTrack::certificateHistory');
$router->get('/my-certificates', 'SmartTrack::myCertificates');
$router->get('/certificates/{id}/download', 'SmartTrack::downloadCertificate');
$router->get('/verify-certificate', 'SmartTrack::verifyCertificate');