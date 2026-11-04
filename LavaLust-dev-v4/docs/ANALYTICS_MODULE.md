# SmartTrack Data Analytics Module

A comprehensive data analytics and reporting module for the SmartTrack training management system.

## Overview

The Analytics Module provides administrators, staff, and instructors with real-time insights into:
- Attendance trends and patterns
- Training enrollment and completion rates
- Document compliance and verification
- Certificate issuance and eligibility
- Payment collections and revenue
- Announcement engagement and notifications

## Features

### 1. Admin Analytics Dashboard

**Key Performance Indicators:**
- Total registered trainees
- Total completed training
- Ongoing training
- Pending training
- Total certificates issued
- Total submitted documents
- Missing requirements
- Total paid and unpaid transactions

### 2. Training Analytics

**Metrics Tracked:**
- Training enrollment statistics
- Training completion rates
- Trainees per training batch
- Monthly and yearly registration trends
- Training program popularity

**Visualizations:**
- Monthly enrollment trend line chart
- Program popularity bar chart
- Trainees per batch table

### 3. Attendance Analytics

**Metrics Tracked:**
- Overall attendance percentage
- Present and absent trainees
- Attendance trends over time
- Attendance per training batch

**Visualizations:**
- Daily attendance trend line chart
- Attendance status doughnut chart
- Class monitoring table
- Trainee attendance table

### 4. Document Analytics

**Metrics Tracked:**
- Total submitted documents
- Missing documents
- Verified documents
- Rejected documents
- Document completion rates

**Visualizations:**
- Document status distribution doughnut chart
- Document types bar chart
- Completion rate by class table

### 5. Certificate Analytics

**Metrics Tracked:**
- Total certificates issued
- Certificates pending generation
- Certificates per training batch
- Monthly certificate issuance
- Certificate eligibility statistics

**Visualizations:**
- Monthly certificate issuance line chart
- Certificate status doughnut chart
- Certificates per batch table

### 6. Payment Analytics

**Metrics Tracked:**
- Total collections
- Successful transactions
- Pending payments
- Failed payments
- Revenue trends
- Outstanding balances
- Bond deposits and refunds

**Visualizations:**
- Net payments by day bar chart
- Payments by fee type doughnut chart
- Payment method breakdown table

### 7. Announcement Analytics

**Metrics Tracked:**
- Total published announcements
- Email notifications sent
- SMS notifications sent
- Failed notifications
- Announcement read statistics

**Visualizations:**
- Notification types doughnut chart
- Read vs unread doughnut chart
- Announcement readership table

### 8. Interactive Features

- **Date Range Filters:** Select custom date ranges for all analytics
- **Training Batch Filters:** Filter by specific class/batch
- **Training Program Filters:** Filter by program type
- **Export to Excel:** Download analytics data as CSV
- **Print to PDF:** Print-friendly layout for reports
- **Responsive Design:** Works on desktop, tablet, and mobile
- **Real-time Data:** Data fetched on-demand with loading states

## Installation

The analytics module is already integrated into the SmartTrack system. No additional installation is required.

## Configuration

### Database Requirements

Ensure your database has the following tables (created by `lsi_smarttrack-upgrade.sql`):
- `users`
- `trainees`
- `training_programs`
- `training_classes`
- `training_schedules`
- `class_enrollments`
- `attendance_records`
- `trainee_documents`
- `certificates`
- `certificate_eligibility`
- `payments`
- `trainee_fees`
- `announcements`
- `notifications`

### Access Control

Analytics are available to users with the following roles:
- `administrator` - Full access to all analytics
- `staff` - Full access to all analytics
- `instructor` - Limited to their assigned classes

## Usage

### Accessing Analytics

1. Log in to SmartTrack with an admin, staff, or instructor account
2. Navigate to "Analytics" in the main menu
3. The dashboard will load with default filters (last 30 days)

### Using Filters

1. **Date Range:**
   - Click the "From" date picker to select start date
   - Click the "To" date picker to select end date
   - Click "Apply filters" to refresh data

2. **Class Filter:**
   - Select a specific class from the dropdown
   - Leave empty to view all available classes
   - Instructors see only their assigned classes

### Exporting Data

1. **Export to Excel:**
   - Click "Export Excel" button
   - CSV file will download with current analytics data
   - File includes all KPIs and detailed data

2. **Print to PDF:**
   - Click "Print PDF" button
   - Browser print dialog will open
   - Select "Save as PDF" as the destination
   - Print-friendly layout automatically applied

## API Endpoints

### Get Analytics Data

**Endpoint:** `GET /api/analytics`

**Query Parameters:**
- `start_date` (required): Start date in YYYY-MM-DD format
- `end_date` (required): End date in YYYY-MM-DD format
- `class_id` (optional): Filter by specific class ID

**Example Request:**
```bash
curl -X GET "http://localhost/lavalust/api/analytics?start_date=2024-01-01&end_date=2024-12-31&class_id=1" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Response Structure:**
```json
{
  "filters": {
    "start_date": "2024-01-01",
    "end_date": "2024-12-31",
    "class_id": 1
  },
  "class_options": [
    {"class_id": 1, "class_name": "Class A"},
    {"class_id": 2, "class_name": "Class B"}
  ],
  "summary": {
    "active_trainees": 150,
    "active_classes": 5,
    "attendance_records": 5000,
    "attendance_rate": 85.5,
    "absent_records": 725
  },
  "trend": [
    {"date": "2024-01-01", "label": "Jan 1", "present": 45, "late": 5, "absent": 10}
  ],
  "status": {"Present": 4000, "Late": 275, "Absent": 725},
  "classes": [
    {
      "class_id": 1,
      "class_name": "Class A",
      "program_name": "Program X",
      "active_trainees": 30,
      "attendance_records": 1000,
      "absent_records": 150,
      "attendance_rate": 85.0
    }
  ],
  "trainees": [
    {
      "trainee_id": 1,
      "trainee_name": "John Doe",
      "classes": "Class A, Class B",
      "present_records": 45,
      "late_records": 5,
      "absent_records": 10,
      "attendance_rate": 83.3,
      "last_attendance": "2024-12-31"
    }
  ],
  "payments": {
    "summary": {
      "net_collected": 50000.00,
      "gross_collected": 52000.00,
      "reversed_amount": 2000.00,
      "outstanding_balance": 15000.00,
      "pending_proof_count": 5,
      "pending_proof_amount": 2500.00,
      "bond_held": 10000.00,
      "refunded": 1000.00,
      "forfeited": 500.00
    },
    "collections": [
      {"date": "2024-01-01", "label": "Jan 1", "amount": 1000.00}
    ],
    "by_fee_kind": [
      {"fee_kind": "registration", "net_collected": 40000.00},
      {"fee_kind": "bond", "net_collected": 10000.00}
    ],
    "by_method": [
      {"method": "cash", "net_collected": 30000.00, "payment_count": 150},
      {"method": "bank_transfer", "net_collected": 20000.00, "payment_count": 50}
    ]
  },
  "training": {
    "summary": {
      "total_enrolled": 200,
      "active_trainees": 150,
      "inactive_trainees": 50,
      "completed_trainings": 120,
      "completion_rate": 60.0
    },
    "trainees_per_batch": [
      {
        "class_id": 1,
        "class_name": "Class A",
        "program_name": "Program X",
        "trainee_count": 30,
        "start_date": "2024-01-01",
        "end_date": "2024-06-30"
      }
    ],
    "monthly_trends": [
      {"month": "2024-01", "new_registrations": 25}
    ],
    "program_popularity": [
      {
        "program_id": 1,
        "program_name": "Program X",
        "program_code": "PRG-X",
        "trainee_count": 100,
        "class_count": 3
      }
    ]
  },
  "documents": {
    "summary": {
      "total_submitted": 500,
      "verified": 450,
      "rejected": 20,
      "pending": 30,
      "missing": 50,
      "completion_rate": 90.0
    },
    "completion_by_class": [
      {
        "class_id": 1,
        "class_name": "Class A",
        "required_count": 5,
        "submitted_count": 4,
        "completion_rate": 80.0
      }
    ],
    "document_types": [
      {
        "document_name": "ID Card",
        "count": 200,
        "approved": 180,
        "rejected": 10,
        "pending": 10
      }
    ]
  },
  "certificates": {
    "summary": {
      "total_issued": 120,
      "issued": 100,
      "pending": 15,
      "revoked": 5
    },
    "certificates_per_batch": [
      {
        "class_id": 1,
        "class_name": "Class A",
        "program_name": "Program X",
        "certificate_count": 25,
        "trainee_count": 30
      }
    ],
    "monthly_issuance": [
      {"month": "2024-01", "certificates_issued": 20}
    ],
    "eligibility": {
      "total_eligible": 150,
      "eligible": 120,
      "not_eligible": 30,
      "avg_attendance": 85.5
    }
  },
  "announcements": {
    "summary": {
      "total_published": 25,
      "published": 20,
      "draft": 5,
      "email_notifications": 100,
      "sms_notifications": 50,
      "read_notifications": 120,
      "unread_notifications": 30,
      "failed_notifications": 5
    },
    "read_statistics": [
      {
        "announcement_id": 1,
        "title": "Class Schedule Update",
        "created_at": "2024-01-01 10:00:00",
        "unique_readers": 145
      }
    ]
  }
}
```

## Customization

### Adding New Analytics

1. **Add method to Analytics_model.php:**
   ```php
   private function customAnalytics(array $filters)
   {
       // Your query logic here
       return $data;
   }
   ```

2. **Call from analytics() method:**
   ```php
   if (in_array($user['role'] ?? '', ['administrator', 'staff'], true)) {
       $result['custom'] = $this->customAnalytics($filters);
   }
   ```

3. **Add UI in analytics.php:**
   - Add KPI cards in the HTML
   - Add chart canvases
   - Add update function in JavaScript
   - Call update function in loadAnalytics()

### Modifying Queries

All analytics queries are in `app/models/Analytics_model.php`. To modify:

1. Locate the relevant method (e.g., `trainingAnalytics`)
2. Update the SQL query
3. Update the data processing logic
4. Update the corresponding view if needed

### Customizing Charts

Charts use Chart.js. To customize:

1. **Chart colors:** Update the `backgroundColor` and `borderColor` values
2. **Chart types:** Change `type` property (line, bar, doughnut, pie, etc.)
3. **Chart options:** Modify the `options` object for scales, tooltips, legends

## Performance Optimization

### Database Indexes

Ensure the following indexes exist for optimal performance:
```sql
CREATE INDEX idx_attendance_date ON attendance_records(attendance_date);
CREATE INDEX idx_enrollment_date ON class_enrollments(enrolled_at);
CREATE INDEX idx_payment_date ON payments(payment_date);
CREATE INDEX idx_document_upload_date ON trainee_documents(upload_date);
CREATE INDEX idx_certificate_issue_date ON certificates(issue_date);
```

### Query Optimization

- Use date range filters to limit data
- Add LIMIT clauses for large result sets
- Use aggregate functions instead of row-by-row processing
- Cache frequently accessed data

### Caching

Consider implementing Redis or Memcached for:
- KPI summaries (cache for 5-15 minutes)
- Trend data (cache for 1-5 minutes)
- Class options (cache for 1 hour)

## Troubleshooting

### Analytics Not Loading

**Issue:** Analytics dashboard shows "Loading analytics…" indefinitely

**Solutions:**
1. Check browser console for JavaScript errors
2. Verify API endpoint is accessible
3. Check database connection
4. Ensure user has appropriate role

### Incorrect Data

**Issue:** Analytics show incorrect or missing data

**Solutions:**
1. Verify database tables have data
2. Check date range filters
3. Review SQL queries in Analytics_model.php
4. Check for NULL values in data

### Slow Performance

**Issue:** Analytics take too long to load

**Solutions:**
1. Add database indexes
2. Reduce date range
3. Implement caching
4. Optimize SQL queries
5. Use LIMIT clauses

### Export Not Working

**Issue:** Export Excel button doesn't download file

**Solutions:**
1. Check browser popup blocker
2. Verify JavaScript is enabled
3. Check for JavaScript errors in console
4. Ensure data has loaded before clicking export

## Security

### Role-Based Access

Analytics automatically filter data based on user role:
- **Administrator:** Full access to all data
- **Staff:** Full access to all data
- **Instructor:** Limited to their assigned classes only

### Data Validation

All inputs are validated:
- Date format validation (YYYY-MM-DD)
- Date range validation (start ≤ end, max 366 days)
- Class ID validation (numeric, positive)
- SQL injection prevention via prepared statements

### Audit Trail

All analytics views are logged in the activity log:
- User who accessed analytics
- Filters applied
- Timestamp of access

## Integration with Metabase

For advanced BI and reporting, see the [Metabase Integration Guide](./METABASE_INTEGRATION.md).

Metabase provides:
- More advanced visualizations
- Custom SQL queries
- Scheduled reports
- Shared dashboards
- Embedding capabilities

## Future Enhancements

Potential future features:
- Real-time WebSocket updates
- Advanced drill-down capabilities
- Custom dashboard builder
- Predictive analytics
- Anomaly detection
- Mobile app
- Email/SMS report scheduling
- Advanced export formats (PDF generation)
- Multi-language support

## Support

For issues or questions:
1. Check this documentation
2. Review the code in `app/models/Analytics_model.php`
3. Review the view in `app/views/analytics.php`
4. Check the controller in `app/controllers/Analytics.php`
5. Consult the Metabase integration guide for BI-specific questions

## License

This module is part of the SmartTrack system and follows the same license terms.
