# Local XAMPP Database Setup Guide

## Current Configuration

The `db.php` file is now configured for **local XAMPP development** with these default settings:

```php
$host = 'localhost';
$user = 'root';           // XAMPP default username
$pass = '';               // XAMPP default password (usually empty)
$dbname = 'watersync';    // Your local database name
```

## Step 1: Check Your Local Database Name

1. Open **phpMyAdmin** (http://localhost/phpmyadmin)
2. Look at the left sidebar - find your database name
3. Common names: `watersync`, `capstone`, `water_billing`, etc.

## Step 2: Update db.php if Needed

If your database name is different from `watersync`, edit `db.php`:

```php
$dbname = 'your_actual_database_name';  // Change this to match your database
```

## Step 3: Check MySQL Password

Most XAMPP installations use an **empty password** for the `root` user. If you set a password, update it:

```php
$pass = 'your_password';  // If you set a password, enter it here
```

## Step 4: Verify Database Exists

Make sure your database exists in phpMyAdmin. If it doesn't:

1. Open phpMyAdmin
2. Click "New" in the left sidebar
3. Enter database name (e.g., `watersync`)
4. Choose collation: `utf8mb4_general_ci`
5. Click "Create"

## Step 5: Import Database Schema (if needed)

If your database is empty, you may need to import your database structure:

1. In phpMyAdmin, select your database
2. Click "Import" tab
3. Choose your SQL file (if you have one)
4. Click "Go"

## Common XAMPP Credentials

| Setting | Default Value |
|---------|---------------|
| Host | `localhost` |
| Username | `root` |
| Password | `` (empty) or `root` |
| Port | `3306` |

## Troubleshooting

### Error: "Access denied for user 'root'@'localhost'"

**Solution 1:** Check if password is set:
- Try `$pass = 'root';` instead of `$pass = '';`

**Solution 2:** Reset MySQL password:
1. Open XAMPP Control Panel
2. Stop MySQL
3. Open MySQL config file (`my.ini`)
4. Add `skip-grant-tables` under `[mysqld]`
5. Start MySQL
6. Open phpMyAdmin and change root password
7. Remove `skip-grant-tables` and restart MySQL

### Error: "Unknown database 'watersync'"

**Solution:** Create the database or update `$dbname` in `db.php` to match your actual database name.

### Error: "Can't connect to MySQL server"

**Solution:**
1. Make sure MySQL is running in XAMPP Control Panel
2. Check if MySQL port (3306) is not blocked
3. Verify `$host = 'localhost';` is correct

## For Production (Verpex)

When deploying to Verpex, update `db.php` with your Verpex credentials:

```php
$host = 'localhost';
$user = 'brgymali_ardgarciano';  // Your Verpex database username
$pass = 'Lourince0923qwe23';     // Your Verpex database password
$dbname = 'brgymali_watersync';  // Your Verpex database name
```

**Note:** `db.php` is in `.gitignore`, so your local changes won't be pushed to GitHub. You'll need to update it manually on Verpex.

