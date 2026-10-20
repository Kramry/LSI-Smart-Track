#!/bin/bash
# Render deployment script for LSI SmartTrack

# Create runtime directories if they don't exist
mkdir -p runtime/cache
mkdir -p runtime/logs
mkdir -p runtime/session

# Set permissions
chmod -R 755 runtime

echo "Deployment setup complete"
