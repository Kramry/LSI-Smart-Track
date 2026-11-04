# Metabase Integration Guide for SmartTrack

This guide explains how to integrate Metabase with the SmartTrack system for advanced business intelligence and analytics.

## What is Metabase?

Metabase is an open-source business intelligence tool that allows you to:
- Create interactive dashboards and visualizations
- Build custom SQL queries
- Schedule automated reports
- Share insights with stakeholders
- Perform ad-hoc data analysis

## Prerequisites

- Docker installed (recommended) or Java JRE 11+
- Access to the SmartTrack MySQL database
- Administrative privileges on the server

## Installation Options

### Option 1: Docker (Recommended)

1. **Pull the Metabase Docker image:**
   ```bash
   docker pull metabase/metabase:latest
   ```

2. **Run Metabase:**
   ```bash
   docker run -d -p 3000:3000 \
     -v ~/metabase-data:/metabase-data \
     -e "MB_DB_FILE=/metabase-data/metabase.db" \
     --name metabase metabase/metabase
   ```

3. **Access Metabase:**
   Open your browser and navigate to `http://localhost:3000`

### Option 2: Java JAR

1. **Download the Metabase JAR:**
   ```bash
   wget https://downloads.metabase.com/latest/metabase.jar
   ```

2. **Run Metabase:**
   ```bash
   java -jar metabase.jar
   ```

3. **Access Metabase:**
   Open your browser and navigate to `http://localhost:3000`

## Database Connection Setup

### SmartTrack Database Connection Details

1. **Database Type:** MySQL
2. **Host:** `localhost` (or your database server IP)
3. **Port:** `3306` (default MySQL port)
4. **Database Name:** `smarttrack` (or your actual database name)
5. **Username:** Your MySQL username
6. **Password:** Your MySQL password

### Steps to Connect

1. **Initial Setup:**
   - On first launch, Metabase will ask you to create an admin account
   - Set your email and password
   - Choose "I'll add my data later" (we'll add it manually)

2. **Add Database:**
   - Go to Admin → Databases → Add database
   - Enter the connection details above
   - Click "Save"

3. **Test Connection:**
   - Metabase will automatically test the connection
   - If successful, you'll see all SmartTrack tables

## Recommended Metabase Dashboards

### 1. Training Overview Dashboard

**Questions to create:**
- Total trainees enrolled by month
- Training completion rate by program
- Active vs inactive trainees
- Trainees per training batch

**SQL Example:**
```sql
SELECT
    DATE_FORMAT(e.enrolled_at, '%Y-%m') AS month,
    COUNT(DISTINCT e.trainee_id) AS new_registrations
FROM class_enrollments e
JOIN training_classes c ON c.class_id = e.class_id
GROUP BY DATE_FORMAT(e.enrolled_at, '%Y-%m')
ORDER BY month;
```

### 2. Attendance Dashboard

**Questions to create:**
- Daily attendance trend
- Attendance by status (Present, Late, Absent)
- Attendance rate by class
- Absenteeism by trainee

**SQL Example:**
```sql
SELECT
    a.attendance_date,
    COUNT(*) AS total,
    SUM(a.status = 'Present') AS present,
    SUM(a.status = 'Late') AS late,
    SUM(a.status = 'Absent') AS absent
FROM attendance_records a
GROUP BY a.attendance_date
ORDER BY a.attendance_date;
```

### 3. Document Compliance Dashboard

**Questions to create:**
- Document submission status
- Document completion rate by class
- Missing documents by type
- Document verification backlog

**SQL Example:**
```sql
SELECT
    d.review_status,
    COUNT(*) AS count
FROM trainee_documents d
WHERE d.upload_date BETWEEN CURDATE() - INTERVAL 30 DAY AND CURDATE()
GROUP BY d.review_status;
```

### 4. Certificate Analytics Dashboard

**Questions to create:**
- Certificates issued per month
- Certificate eligibility status
- Certificates by training program
- Revoked certificates

**SQL Example:**
```sql
SELECT
    DATE_FORMAT(cert.issue_date, '%Y-%m') AS month,
    COUNT(*) AS certificates_issued
FROM certificates cert
WHERE cert.status = 'issued'
GROUP BY DATE_FORMAT(cert.issue_date, '%Y-%m')
ORDER BY month;
```

### 5. Financial Dashboard

**Questions to create:**
- Revenue trends
- Payment method distribution
- Outstanding balances
- Bond deposits and refunds

**SQL Example:**
```sql
SELECT
    p.payment_date,
    COALESCE(SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount
                     WHEN p.entry_type = 'reversal' THEN -p.amount ELSE 0 END), 0) AS net_collected
FROM payments p
WHERE p.payment_date BETWEEN CURDATE() - INTERVAL 30 DAY AND CURDATE()
GROUP BY p.payment_date
ORDER BY p.payment_date;
```

## Embedding Metabase in SmartTrack

### Option 1: Iframe Embedding

To embed Metabase dashboards directly in SmartTrack:

1. **Enable embedding in Metabase:**
   - Go to Admin → Settings → Embedding
   - Enable "Allow embedding"

2. **Get the embed code:**
   - Open a dashboard in Metabase
   - Click the share icon
   - Select "Embed"
   - Copy the iframe code

3. **Add to SmartTrack:**
   Create a new view in `app/views/metabase.php`:
   ```php
   <?php defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed'); ?>
   <!doctype html>
   <html>
   <head>
       <title>Metabase Dashboard</title>
       <style>
           iframe { width: 100%; height: 100vh; border: none; }
       </style>
   </head>
   <body>
       <iframe src="YOUR_METABASE_EMBED_URL"></iframe>
   </body>
   </html>
   ```

### Option 2: Public Sharing

1. **Set up public sharing:**
   - In Metabase, open a dashboard
   - Click the share icon
   - Select "Public sharing"
   - Enable and copy the public URL

2. **Link from SmartTrack:**
   Add a navigation link in the analytics view to the public dashboard URL

## Security Considerations

### Database Security
- Use a read-only MySQL user for Metabase
- Restrict Metabase access to necessary tables only
- Regularly rotate database credentials

### Metabase Security
- Enable SSL/TLS for Metabase
- Set up authentication (SSO recommended for production)
- Restrict dashboard access by user roles
- Enable audit logging

### Network Security
- Run Metabase behind a reverse proxy (nginx)
- Use firewall rules to restrict access
- Consider using a VPN for remote access

## Advanced Features

### Scheduled Reports

1. **Create a dashboard** with the desired questions
2. **Click the sharing icon** on the dashboard
3. **Select "Send email"**
4. **Configure schedule** (daily, weekly, monthly)
5. **Add recipients**

### SQL Parameters

Create dynamic questions with parameters:

```sql
SELECT *
FROM attendance_records
WHERE attendance_date BETWEEN {{start_date}} AND {{end_date}}
  AND class_id = {{class_id}}
```

### API Integration

Metabase provides a REST API for programmatic access:

```bash
# Get dashboard data
curl -X GET \
  http://localhost:3000/api/dashboard/:id \
  -H 'Authorization: Basic YOUR_API_KEY'
```

## Performance Optimization

### Database-Level
- Add indexes to frequently queried columns
- Use materialized views for complex aggregations
- Schedule heavy queries during off-peak hours

### Metabase-Level
- Enable query caching
- Set appropriate cache TTL
- Use sample data for large tables
- Limit dashboard refresh rates

## Troubleshooting

### Connection Issues
- Verify database credentials
- Check firewall rules
- Ensure MySQL allows remote connections
- Test with `mysql -h host -u user -p`

### Slow Queries
- Check MySQL slow query log
- Add missing indexes
- Optimize SQL queries
- Consider data partitioning

### Dashboard Performance
- Reduce number of questions per dashboard
- Use simpler visualizations
- Enable caching
- Schedule dashboard refreshes

## Maintenance

### Regular Tasks
- Monitor Metabase disk usage
- Backup Metabase database (`metabase.db`)
- Review and optimize slow queries
- Update Metabase to latest version
- Review user access permissions

### Backup Strategy
```bash
# Backup Metabase data
docker exec metabase cp /metabase-data/metabase.db ./backup/

# Schedule regular backups (cron)
0 2 * * * docker exec metabase cp /metabase-data/metabase.db /backup/metabase_$(date +\%Y\%m\%d).db
```

## Resources

- [Metabase Documentation](https://www.metabase.com/docs/latest/)
- [Metabase GitHub](https://github.com/metabase/metabase)
- [SmartTrack Database Schema](../lsi_smarttrack-upgrade.sql)
- [Metabase Community Forum](https://discourse.metabase.com/)

## Support

For issues specific to SmartTrack:
- Check the database schema in `lsi_smarttrack-upgrade.sql`
- Review the Analytics model in `app/models/Analytics_model.php`
- Refer to the existing analytics dashboard in `app/views/analytics.php`

For Metabase-specific issues:
- Visit the Metabase documentation
- Check the Metabase community forum
- Review Metabase logs for errors
