#!/bin/bash
set -e

echo "Installing IonCube Loader for PHP..."

# Detect PHP version and architecture
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
PHP_ARCH=$(uname -m)
PHP_ZTS=$(php -r "echo ZEND_THREAD_SAFE ? 'ts' : 'nts';")

echo "PHP Version: $PHP_VERSION"
echo "Architecture: $PHP_ARCH"
echo "ZTS: $PHP_ZTS"

# Get PHP extension directory
PHP_EXT_DIR=$(php -r "echo ini_get('extension_dir');")
echo "PHP Extension Directory: $PHP_EXT_DIR"

# Download IonCube Loader
cd /tmp
echo "Downloading IonCube Loader..."
wget -q https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz || curl -L -o ioncube_loaders_lin_x86-64.tar.gz https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz

# Extract
echo "Extracting IonCube Loader..."
tar -xzf ioncube_loaders_lin_x86-64.tar.gz

# Find the correct loader file
LOADER_DIR="ioncube"
LOADER_FILE=""

# Try different naming patterns
if [ -f "$LOADER_DIR/ioncube_loader_lin_${PHP_VERSION}_${PHP_ZTS}.so" ]; then
    LOADER_FILE="$LOADER_DIR/ioncube_loader_lin_${PHP_VERSION}_${PHP_ZTS}.so"
elif [ -f "$LOADER_DIR/ioncube_loader_lin_${PHP_VERSION}.so" ]; then
    LOADER_FILE="$LOADER_DIR/ioncube_loader_lin_${PHP_VERSION}.so"
else
    echo "Available loaders:"
    ls -la "$LOADER_DIR/" | grep "ioncube_loader"
    # Try to find any PHP 8.x loader
    LOADER_FILE=$(ls "$LOADER_DIR"/ioncube_loader_lin_8.*.so 2>/dev/null | head -1)
    if [ -z "$LOADER_FILE" ]; then
        echo "Error: Could not find IonCube loader for PHP $PHP_VERSION"
        exit 1
    fi
fi

echo "Using loader: $LOADER_FILE"

# Copy loader to PHP extensions directory
cp "$LOADER_FILE" "$PHP_EXT_DIR/ioncube_loader.so"
echo "Copied IonCube loader to $PHP_EXT_DIR/ioncube_loader.so"

# Create PHP configuration to load IonCube
# Try multiple locations for php.ini
PHP_INI_LOCATIONS=(
    "/etc/php/${PHP_VERSION}/cli/php.ini"
    "/etc/php/${PHP_VERSION}/fpm/php.ini"
    "/etc/php/php.ini"
    "$(php --ini | grep 'Loaded Configuration File' | awk '{print $4}')"
)

for PHP_INI in "${PHP_INI_LOCATIONS[@]}"; do
    if [ -f "$PHP_INI" ]; then
        echo "Found php.ini: $PHP_INI"
        # Check if already added
        if ! grep -q "ioncube_loader.so" "$PHP_INI"; then
            echo "" >> "$PHP_INI"
            echo "; IonCube Loader" >> "$PHP_INI"
            echo "zend_extension=$PHP_EXT_DIR/ioncube_loader.so" >> "$PHP_INI"
            echo "Added IonCube to $PHP_INI"
        fi
    fi
done

# Also try conf.d directories
CONF_D_DIRS=(
    "/etc/php/${PHP_VERSION}/cli/conf.d"
    "/etc/php/${PHP_VERSION}/fpm/conf.d"
    "/etc/php/conf.d"
)

for CONF_D in "${CONF_D_DIRS[@]}"; do
    if [ -d "$CONF_D" ]; then
        echo "zend_extension=$PHP_EXT_DIR/ioncube_loader.so" > "$CONF_D/00-ioncube.ini"
        echo "Created $CONF_D/00-ioncube.ini"
    fi
done

# Verify installation
echo "Verifying IonCube installation..."
if php -m 2>/dev/null | grep -qi ioncube; then
    echo "✓ IonCube Loader installed and loaded successfully!"
else
    echo "⚠ IonCube Loader installed but may need PHP restart"
    php -r "if (extension_loaded('ionCube Loader')) { echo 'IonCube is loaded\n'; } else { echo 'IonCube not loaded - check php.ini\n'; }"
fi

# Cleanup
rm -rf /tmp/ioncube_loaders_lin_x86-64.tar.gz /tmp/ioncube

echo "IonCube installation complete!"

