FROM php:8.3-apache
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# --- Apache ------------------------------------------------------------------
RUN a2enmod headers && cat > /etc/apache2/conf-available/app.conf <<'EOF'
ServerTokens Prod
ServerSignature Off
TraceEnable Off

<Directory /var/www/html>
    DirectoryIndex api.php
    Options -Indexes
</Directory>

# Security headers (ported from the former nginx configuration)
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set Referrer-Policy "no-referrer"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
Header always set Content-Security-Policy "default-src 'self';"
EOF
RUN a2enconf app

# --- Application -----------------------------------------------------------
COPY src/ /var/www/html/

EXPOSE 80
