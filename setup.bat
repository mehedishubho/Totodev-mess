@echo off
echo 🚀 Setting up Toto Mess Management System...

REM Check if .env exists, copy from example if not
if not exist .env (
    echo 📝 Creating .env file from .env.example...
    copy .env.example .env
    echo ✅ .env file created
) else (
    echo ✅ .env file already exists
)

REM Install dependencies
echo 📦 Installing PHP dependencies...
composer install --no-dev --optimize-autoloader

if %errorlevel% neq 0 (
    echo ❌ Failed to install dependencies
    pause
    exit /b 1
)

echo ✅ Dependencies installed successfully

REM Generate application key
echo 🔑 Generating application key...
php artisan key:generate

if %errorlevel% neq 0 (
    echo ❌ Failed to generate application key
    pause
    exit /b 1
)

echo ✅ Application key generated

REM Run migrations
echo 🗄️ Running database migrations...
php artisan migrate --force

if %errorlevel% neq 0 (
    echo ❌ Failed to run migrations
    pause
    exit /b 1
)

echo ✅ Migrations completed

REM Seed roles
echo 🌱 Seeding database roles...
php artisan db:seed --class=RoleSeeder

if %errorlevel% neq 0 (
    echo ❌ Failed to seed database
    pause
    exit /b 1
)

echo ✅ Database seeded with roles

REM Clear caches
echo 🧹 Clearing application caches...
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo ✅ Caches cleared

REM Create storage link
echo 🔗 Creating storage link...
php artisan storage:link

echo.
echo ✅ Setup completed successfully!
echo.
echo 🌐 Starting development server...
echo 📱 API: http://localhost:8000/api/
echo 🌍 Application: http://localhost:8000/
echo.
echo Press Ctrl+C to stop the server
echo.

REM Start the development server
php artisan serve