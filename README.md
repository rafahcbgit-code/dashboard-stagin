# Chatberry Dashboard - Developer Documentation

Welcome to the Chatberry Dashboard development guide! This document provides all the necessary instructions for setting up your local development environment, understanding the tech stack, and contributing to the project.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Local Development Setup](#local-development-setup)
- [Running the Application](#running-the-application)
- [Architecture Overview](#architecture-overview)
- [Technology Stack](#technology-stack)
- [Coding Standards](#coding-standards)
- [Testing](#testing)
- [Database](#database)
- [Key Third-Party Services](#key-third-party-services)
- [Contributing](#contributing)

## Prerequisites

Before you begin, ensure you have the following installed on your local machine:

- **PHP**: `^8.2` (as specified in `composer.json`)
- **Composer**: For managing PHP dependencies.
- **Node.js**: LTS version recommended.
- **NPM** or **Yarn**: For managing JavaScript dependencies.
- **A local database server**: e.g., MySQL, PostgreSQL, or MariaDB.

## Local Development Setup

Follow these steps to get the project running on your local machine.

1.  **Clone the repository:**
    ```bash
    git clone <repository-url>
    cd chatberry-dashbaord
    ```

2.  **Install PHP Dependencies:**
    Use Composer to install the required PHP packages.
    ```bash
    composer install
    ```

3.  **Install JavaScript Dependencies:**
    Use NPM or Yarn to install the required Node.js packages.
    ```bash
    # Using NPM
    npm install

    # Or using Yarn
    yarn install
    ```

4.  **Create Environment File:**
    The project uses a `.env` file for environment-specific configuration. Copy the example file to create your own local configuration.
    ```bash
    cp .env.example .env
    ```

5.  **Generate Application Key:**
    Laravel requires an application key for securing session data and encryption.
    ```bash
    php artisan key:generate
    ```

6.  **Configure Environment Variables:**
    Open the `.env` file and configure your local settings. Pay close attention to:
    - `DB_*` variables for your local database connection.
    - `APP_URL` to match your local development URL (e.g., `http://localhost:8000`).
    - Keys for any external services you need to test (e.g., `PUSHER_*`, `STRIPE_*`, `RESEND_API_KEY`).

7.  **Run Database Migrations:**
    Set up your database schema by running the Laravel migrations.
    ```bash
    php artisan migrate
    ```

8.  **Seed the Database (Optional):**
    If database seeders are available, you can populate your database with initial data.
    ```bash
    php artisan db:seed
    ```

## Running the Application

The application consists of a PHP backend and a JavaScript frontend, which need to be run concurrently in two separate terminal windows during development.

### Backend Server (Laravel)

Start the PHP development server using Artisan:
```bash
php artisan serve
```
By default, this will serve the application at `http://localhost:8000`.

### Frontend Development Server (Vite)

The frontend assets are managed by Vite. Run the following command in a **separate terminal** to compile assets and enable Hot Module Replacement (HMR) for a smooth development experience.

```bash
# Run Vite's development server
npm run dev
```

Now you can access the application in your browser at the URL provided by `php artisan serve`.

## Technology Stack

### Backend

- **Framework**: Laravel 10
- **Language**: PHP 8.2
- **API/UI Layer**: Inertia.js (`inertiajs/inertia-laravel`)
- **Authentication**: Laravel Sanctum
- **Real-time Events**: Pusher (`pusher/pusher-php-server`)
- **Database ORM**: Eloquent

### Frontend

- **Framework**: Vue.js 3
- **Language**: JavaScript / TypeScript
- **Bundler**: Vite (`laravel-vite-plugin`)
- **Styling**: Tailwind CSS
- **UI Components**: Headless UI, Heroicons, Radix Vue
- **Client-side Routing**: Inertia.js (`@inertiajs/vue3`)
- **Internationalization**: `vue-i18n`

## Coding Standards

### PHP

We use **Laravel Pint** for enforcing a consistent code style. Before committing your changes, please run Pint to format your code.
```bash
./vendor/bin/pint
```

### JavaScript/Vue

Please adhere to the existing code style. Consider using a code formatter like Prettier with the recommended settings for the project.


```
