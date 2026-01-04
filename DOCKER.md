# Docker Setup for aMember PRO

This guide explains how to run aMember PRO using Docker and Docker Compose.

## Prerequisites

- Docker Engine 20.10 or higher
- Docker Compose 2.0 or higher

## Quick Start

1. **Clone or copy your aMember PRO files** to this directory (if not already done)

2. **Create environment file** (optional, defaults are provided):
   ```bash
   cp .env.example .env
   ```
   Edit `.env` if you need to change default values (ports, passwords, etc.)

3. **Build and start containers**:
   ```bash
   docker-compose up -d
   ```

4. **Wait for MySQL to be ready** (check with `docker-compose ps`)

5. **Access the setup wizard**:
   Open your browser and go to: `http://localhost:8080/setup/`
   (or use the port you configured in `.env`)

**Note:** The MySQL container automatically grants the necessary privileges (`SYSTEM_VARIABLES_ADMIN`, `SESSION_VARIABLES_ADMIN`) to the database user required for aMember setup. This fixes the MySQL 8.0 privilege error that would otherwise occur during installation.

## Configuration Steps

### Database Configuration

During the setup wizard, use the following database settings:

- **Host**: `mysql` (the service name from docker-compose.yml, not `localhost`)
- **Port**: `3306`
- **Database**: `amember` (or your `MYSQL_DATABASE` from `.env`)
- **Username**: `amember` (or your `MYSQL_USER` from `.env`)
- **Password**: `amember` (or your `MYSQL_PASSWORD` from `.env`)

### Manual Configuration File Creation

Alternatively, you can create the config file manually:

1. Copy the config template:
   ```bash
   docker-compose exec web cp /var/www/html/application/configs/config-dist.php /var/www/html/application/configs/config.php
   ```

2. Edit the config file:
   ```bash
   docker-compose exec web nano /var/www/html/application/configs/config.php
   ```

   Update with these values:
   ```php
   return [
       'db' => [
           'mysql' => [
               'db'    => 'amember',
               'user'  => 'amember',
               'pass'  => 'amember',
               'host'  => 'mysql',
               'prefix' => 'am_',
               'port'  => 3306,
               'pdo_options' => [],
           ],
       ],
   ];
   ```

## Directory Permissions

The Dockerfile automatically sets proper permissions for writable directories:
- `data/`
- `data/cache/`
- `data/new-rewrite/`
- `data/public/`

If you encounter permission issues, you can fix them manually:
```bash
docker-compose exec web chmod -R 777 /var/www/html/data
docker-compose exec web chown -R www-data:www-data /var/www/html/data
```

## Cron Job Setup

aMember requires a cron job to run `cron.php` periodically. You have several options:

### Option 1: Host Cron (Recommended)

Add this to your host's crontab (edit with `crontab -e`):
```bash
*/5 * * * * docker exec amember-web php /var/www/html/cron.php
```

### Option 2: Docker Exec Cron

Create a cron container in `docker-compose.yml`:
```yaml
cron:
  build:
    context: .
    dockerfile: Dockerfile
  container_name: amember-cron
  volumes:
    - ./:/var/www/html
  command: >
    bash -c "while true; do
      php /var/www/html/cron.php
      sleep 300
    done"
  depends_on:
    - web
    - mysql
  networks:
    - amember-network
  restart: unless-stopped
```

Then run:
```bash
docker-compose up -d cron
```

## Managing Containers

### View logs
```bash
docker-compose logs -f
```

### Stop containers
```bash
docker-compose down
```

### Stop and remove volumes (⚠️ deletes database)
```bash
docker-compose down -v
```

### Rebuild after changes
```bash
docker-compose build --no-cache
docker-compose up -d
```

### Access container shell
```bash
docker-compose exec web bash
```

### Access MySQL
```bash
docker-compose exec mysql mysql -u amember -p amember
```

## Backup and Restore

### Backup Database
```bash
docker-compose exec mysql mysqldump -u amember -p amember > backup.sql
```

### Restore Database
```bash
docker-compose exec -T mysql mysql -u amember -p amember < backup.sql
```

### Backup Application Data
```bash
docker cp amember-web:/var/www/html/data ./backup-data
```

## Troubleshooting

### Apache mod_rewrite not working

The Dockerfile enables mod_rewrite. If you still have issues:

1. Check if `.htaccess` is being read:
   ```bash
   docker-compose exec web cat /etc/apache2/sites-available/000-default.conf
   ```

2. Verify mod_rewrite is enabled:
   ```bash
   docker-compose exec web apache2ctl -M | grep rewrite
   ```

### Permission Denied Errors

Fix permissions:
```bash
docker-compose exec web chown -R www-data:www-data /var/www/html
docker-compose exec web chmod -R 755 /var/www/html
docker-compose exec web chmod -R 777 /var/www/html/data
```

### Database Connection Failed

1. Ensure MySQL is healthy:
   ```bash
   docker-compose ps
   ```

2. Check database credentials in config.php match `.env` values

3. Verify network connectivity:
   ```bash
   docker-compose exec web ping mysql
   ```

### Port Already in Use

Change the port in `.env`:
```env
WEB_PORT=8081
MYSQL_PORT=3307
```

Then restart:
```bash
docker-compose down
docker-compose up -d
```

## Production Considerations

For production deployment:

1. **Use strong passwords** in `.env`
2. **Use environment variables** instead of `.env` file (more secure)
3. **Enable HTTPS** using a reverse proxy (nginx, Traefik, etc.)
4. **Set up regular backups** for both database and data directory
5. **Use named volumes** (already configured) for data persistence
6. **Limit resource usage** with Docker resource constraints
7. **Use Docker secrets** for sensitive information
8. **Regularly update** base images and dependencies

## Support

For aMember PRO specific issues, refer to:
- [aMember Documentation](https://docs.amember.com)
- [aMember Support](https://www.amember.com/support/)

