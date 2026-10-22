# Cloud Storage Setup for LSI SmartTrack

This document storage system supports both local and cloud storage (AWS S3).

## Configuration

### 1. Update `.env` File

Add the following configuration to your `.env` file:

```env
# Cloud Storage Configuration (AWS S3)
CLOUD_STORAGE_ENABLED=true
CLOUD_STORAGE_PROVIDER=aws
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_REGION=us-east-1
AWS_BUCKET=your-bucket-name
AWS_PREFIX=smarttrack-documents/
```

### 2. Install AWS SDK (Recommended)

For full functionality, install the AWS SDK for PHP:

```bash
cd LavaLust-dev-v4
composer require aws/aws-sdk-php
```

### 3. AWS S3 Setup

1. **Create an S3 Bucket**
   - Go to AWS Console → S3
   - Create a new bucket
   - Note the bucket name and region

2. **Create IAM User**
   - Go to AWS Console → IAM
   - Create a new user with programmatic access
   - Attach the following policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:DeleteObject"
      ],
      "Resource": "arn:aws:s3:::your-bucket-name/*"
    }
  ]
}
```

3. **Get Credentials**
   - Save the Access Key ID and Secret Access Key
   - Add them to your `.env` file

## Features

### Automatic Cloud Upload
- When cloud storage is enabled, documents are automatically uploaded to S3
- File path in database is prefixed with `cloud://`
- Automatic fallback to local storage if cloud upload fails

### Secure Downloads
- Generates temporary presigned URLs (valid for 1 hour)
- No direct public access to S3 files
- Secure by default

### Cloud Deletion
- When deleting documents, files are removed from S3
- Automatic cleanup of cloud resources

## Benefits

- **Scalability**: Unlimited storage with S3
- **Performance**: Fast downloads from CloudFront or S3
- **Reliability**: 99.999999999% durability
- **Cost-Effective**: Pay only for what you use
- **Backup**: Automatic replication across availability zones

## Fallback Behavior

If cloud storage is disabled or upload fails:
- Documents are stored locally in `runtime/uploads/private/`
- All functionality continues to work normally
- Can switch between cloud and local at any time

## Testing

To test cloud storage without credentials, the system will use a basic cURL implementation (limited functionality). For production use, install the AWS SDK for full features.

## Migration

Existing local documents can be migrated to cloud storage by:
1. Enable cloud storage in `.env`
2. Use a migration script to upload existing files
3. Update database paths from local to cloud format

## Security Notes

- Never commit `.env` file with real credentials
- Use IAM roles instead of access keys when possible
- Enable bucket encryption (SSE-S3 or SSE-KMS)
- Enable bucket versioning for document recovery
- Set up bucket policies for additional security
