# Railway Deployment Guide for aMember Pro

## Deployment Method

This application now uses **Docker** for deployment. Railway will automatically detect the `Dockerfile` and build a container with:
- Nginx web server
- PHP 8.1-FPM
- All required PHP extensions
- IonCube Loader
- Proper URL rewriting configuration

**Note:** For Docker deployment details, see [DOCKER_DEPLOYMENT.md](DOCKER_DEPLOYMENT.md)

## Environment Variables

Set these environment variables in your Railway project dashboard:

### Required Database Configuration

```
DB_MYSQL_HOST=<your-mysql-host>
DB_MYSQL_PORT=3306
DB_MYSQL_DB=<your-database-name>
DB_MYSQL_USER=<your-database-user>
DB_MYSQL_PASS=<your-database-password>
DB_MYSQL_PREFIX=am_
```

**Note:** Railway provides MySQL via a service. You can either:
- Use Railway's MySQL service and reference it via `$MYSQL_URL` (format: `mysql://user:pass@host:port/db`)
- Set the individual variables above

### Application Configuration

```
AMEMBER_URL=https://your-app-name.up.railway.app
AM_APPLICATION_ENV=production
TZ=UTC
```

### PHP Configuration (Optional)

```
PHP_MEMORY_LIMIT=256M
PHP_MAX_EXECUTION_TIME=300
PHP_UPLOAD_MAX_FILESIZE=20M
PHP_POST_MAX_SIZE=20M
```

### Security

Generate a secure random string for:
```
AM_SECRET_KEY=<generate-a-random-string>
```

## Setup Steps

1. **Connect GitHub Repository**
   - In Railway dashboard, click "New Project"
   - Select "Deploy from GitHub repo"
   - Choose your `cipher-x-sudo/aMember` repository

2. **Add MySQL Database**
   - In Railway dashboard, click "New" → "Database" → "MySQL"
   - Railway will automatically create connection variables

3. **Configure Environment Variables**
   - Go to your service → Variables tab
   - Add all required environment variables listed above

4. **Set Writable Directories**
   - Railway's filesystem is ephemeral, but you can use:
   - `data/` directory for cache and uploads
   - Consider using external storage (S3) for uploads in production

5. **Deploy**
   - Railway will automatically deploy when you push to the `main` branch
   - Or trigger a manual deploy from the Railway dashboard

## Post-Deployment

1. Access your application at the Railway-provided URL
2. Complete the aMember setup wizard
3. Configure your database connection using the environment variables
4. Set up cron jobs (Railway supports scheduled tasks)

## Notes

- Railway automatically detects PHP applications
- The `nixpacks.toml` file configures PHP 8.0 with required extensions
- Nginx is configured via `nginx.conf` for proper URL rewriting
- Static assets are served from `data/public/`
- Make sure `data/`, `data/cache`, `data/new-rewrite/`, and `data/public/` are writable


