#!/bin/bash

echo "🚀 Setting up Toto Mess Management System..."

# Check if .env exists, copy from example if not
if [ ! -f .env ]; then
    echo "📝 Creating .env file from .env.example..."
    cp .env.example .env
    echo "✅ .env file created"
else
    echo "✅ .env file already exists"
fi

# Install dependencies
echo "📦 Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader

if [ $? -eq 0 ]; then
    echo "✅ Dependencies installed successfully"
else
    echo "❌ Failed to install dependencies"
    exit 1
fi

# Generate application key
echo "🔑 Generating application key..."
php artisan key:generate

if [ $? -eq 0 ]; then
    echo "✅ Application key generated"
else
    echo "❌ Failed to generate application key"
    exit 1
fi

# Run migrations
echo "🗄️ Running database migrations..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    echo "✅ Migrations completed"
else
    echo "❌ Failed to run migrations"
    exit 1
fi

# Seed roles
echo "🌱 Seeding database roles..."
php artisan db:seed --class=RoleSeeder

if [ $? -eq 0 ]; then
    echo "✅ Database seeded with roles"
else
    echo "❌ Failed to seed database"
    exit 1
fi

# Clear caches
echo "🧹 Clearing application caches..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo "✅ Caches cleared"

# Create storage link
echo "🔗 Creating storage link..."
php artisan storage:link

echo "✅ Setup completed successfully!"
echo ""
echo "🌐 Starting development server..."
echo "📱 API: http://localhost:8000/api"
echo "🌍 Application: http://localhost:8000"
echo ""
echo "Press Ctrl+C to stop the server"
echo ""

# Start the development server
php artisan serve