# Docker Deployment Guide for aMember Pro

## Overview

This guide explains how to deploy aMember Pro using Docker on Railway or any Docker-compatible platform.

## Prerequisites

- Docker installed (for local testing)
- Railway account (for production deployment)
- MySQL database (provided by Railway or external)

## Local Development

### Using Docker Compose

1. **Start the services:**
   ```bash
   docker-compose up -d
   ```

2. **Access the application:**
   - Application: http://localhost:8080
   - MySQL: localhost:3306

3. **View logs:**
   ```bash
   docker-compose logs -f amember
   ```

4. **Stop the services:**
   ```bash
   docker-compose down
   ```

### Environment Variables

Edit `docker-compose.yml` to customize:
- Database connection details
- Port mappings
- Volume mounts

## Railway Deployment

### Step 1: Prepare Your Repository

Ensure your repository contains:
- `Dockerfile`
- `nginx.conf`
- `.dockerignore`
- All application files

### Step 2: Connect to Railway

1. Go to Railway Dashboard
2. Click "New Project"
3. Select "Deploy from GitHub repo"
4. Choose your repository

### Step 3: Configure Environment Variables

In Railway dashboard, go to your service → Variables tab and add:

**Database Configuration:**
```
DB_MYSQL_HOST=<your-mysql-host>
DB_MYSQL_PORT=3306
DB_MYSQL_DB=<your-database-name>
DB_MYSQL_USER=<your-database-user>
DB_MYSQL_PASS=<your-database-password>
DB_MYSQL_PREFIX=am_
```

**Application Configuration:**
```
AMEMBER_URL=https://your-app-name.up.railway.app
AM_APPLICATION_ENV=production
TZ=UTC
```

**PHP Configuration (Optional):**
```
PHP_MEMORY_LIMIT=256M
PHP_MAX_EXECUTION_TIME=300
PHP_UPLOAD_MAX_FILESIZE=20M
PHP_POST_MAX_SIZE=20M
```

### Step 4: Add MySQL Database

1. In Railway dashboard, click "New" → "Database" → "MySQL"
2. Railway will automatically create connection variables
3. Use those variables in your aMember service environment variables

### Step 5: Deploy

Railway will automatically:
1. Detect the `Dockerfile`
2. Build the Docker image
3. Deploy the container
4. Map the PORT environment variable to container port 8080

### Step 6: Access Your Application

1. Railway will provide a URL like: `https://your-app-name.up.railway.app`
2. Access the setup page: `https://your-app-name.up.railway.app/setup/`
3. Complete the installation wizard

## Docker Image Details

### Base Image
- PHP 8.1-FPM

### Installed Components
- Nginx web server
- PHP 8.1 with all required extensions:
  - pdo, pdo_mysql
  - gd, openssl, mbstring, iconv
  - xml, xmlwriter, xmlreader, ctype
  - curl, zip
- IonCube Loader for PHP 8.1

### Port Configuration
- Container listens on port 8080
- Railway maps this via PORT environment variable

### Writable Directories
The following directories are set to be writable:
- `/var/www/html/data/`
- `/var/www/html/data/cache/`
- `/var/www/html/data/new-rewrite/`
- `/var/www/html/data/public/`

## URL Rewriting

Nginx is configured to handle all URL rewriting rules from `.htaccess`:
- `/public/*` → `public.php`
- `/js.php` → `js.php`
- `/setup/*` → `setup/index.php`
- Static files → served directly
- All other requests → `index.php`

## Troubleshooting

### Container won't start
- Check logs: `docker logs amember-app` (local) or Railway logs
- Verify all environment variables are set
- Ensure database is accessible

### IonCube errors
- Verify IonCube Loader is installed: `docker exec amember-app php -m | grep ioncube`
- Check PHP version matches IonCube loader version

### Routing issues
- Verify nginx.conf is correctly copied to container
- Check Nginx error logs: `docker exec amember-app tail -f /var/log/nginx/error.log`

### Permission issues
- Ensure data directories are writable: `docker exec amember-app ls -la /var/www/html/data`

## Building the Image Manually

```bash
docker build -t amember:latest .
docker run -d -p 8080:8080 --name amember-app amember:latest
```

## Benefits of Docker Deployment

✅ **Consistent Environment**: Same setup across dev, staging, and production  
✅ **Proper URL Rewriting**: Nginx handles all routing correctly  
✅ **All Extensions Pre-installed**: No missing PHP extensions  
✅ **IonCube Ready**: IonCube Loader configured automatically  
✅ **Easy Scaling**: Deploy multiple instances easily  
✅ **Isolated**: No conflicts with other applications  

## Notes

- Railway automatically detects and builds from Dockerfile
- The container is stateless - use volumes for persistent data
- Consider using Railway's volume mounts for `data/` directory if needed
- Database should be external (Railway MySQL service or external provider)

