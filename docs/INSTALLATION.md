# AdoptAPet Installation Guide

This guide explains how to install AdoptAPet on a Windows PC using XAMPP.

**Important:** This is a local FYP demonstration setup. Keep the website private. Never use the shared Demo account on a public or production server.

## Stage 1: Install XAMPP and the Project

### Step 1: Install XAMPP

1. Download [XAMPP for Windows](https://www.apachefriends.org/download.html).
2. Install PHP 8.1 or later with Apache, MySQL/MariaDB, and phpMyAdmin.
3. Open the XAMPP Control Panel and start Apache and MySQL.

For this local demo, do not install XAMPP services as Windows services or change global Apache settings.

### Step 2: Extract the Project

1. Download and extract the project into a new folder under XAMPP's `htdocs`.
2. Example folder:

   `C:\xampp\htdocs\AdoptAPet`

3. Do not extract it over another installation.
4. If `vendor/autoload.php` is missing, run `composer install` from the project folder.

## Stage 2: Set Up the Database

### Step 3: Create a Database

1. Open [phpMyAdmin](http://localhost/phpmyadmin/).
2. Create a **new, empty database** named `adoptapet_demo`.
3. Select the database and click **Import**.
4. Choose `config/database-public.sql` from the project folder and import it once.

The SQL creates the application tables and a Demo Super Administrator account. It does not include sample pets, news, or other demo content.

**If the import fails, stop. Do not import the SQL again into a partially imported database. Use a different, empty database for a retry.**

### Step 4: Configure Database Access

1. In phpMyAdmin, create a dedicated database user for `adoptapet_demo` at host `127.0.0.1`.
2. Give the user `SELECT`, `INSERT`, `UPDATE`, and `DELETE` permissions on this database only. Do not use the database `root` account for the website.
3. Open PowerShell in the project folder and run:

   ```powershell
   Copy-Item .\config\database.example.php .\config\database.local.php
   notepad .\config\database.local.php
   ```

4. Enter your database settings:
   - Host: `127.0.0.1`
   - Port: `3306`, or your configured MySQL port
   - Database: `adoptapet_demo`
   - Username and password: the dedicated database account you created

5. Save the file and keep it private.

## Stage 3: Configure the Website URL

### Step 5: Set the Base URL (For Email Links)

The website can run locally without this setting. Configure it if you want generated email links to point to your local website.

For example, if your project folder is `AdoptAPet`, use:

`http://localhost/AdoptAPet/public/`

Replace the folder name if yours is different. Keep the trailing slash.

Set `ADOPTAPET_BASE_URL` to this URL and make it available to both **CLI PHP and Apache PHP**. Use the environment configuration method supported by your installation.

Do not create or copy an application URL configuration file unless the project provides one.

## Stage 4: Generate the MFA Key and Check Installation

### Step 6: Run Setup Helper

1. Open the project folder.
2. Double-click `setup-helper.bat`.
3. Select **1** to generate a unique MFA key for this installation.
4. Select **2** to check the installation.
5. When prompted, enter the exact database name: `adoptapet_demo`.
6. Continue only when the checker reports `READY`.
7. Select **3** to exit.

The MFA key is saved in `config/mfa.local.php`. Do not replace an existing key if it may already be used by an installation.

The checker checks the expected tables and Demo seed, but does not verify every part of the database schema. It also does not test Apache routing, email delivery, or hCaptcha.

**Do not run** `tools/first-super-admin.php prepare` or `finalize`. The imported Demo database is already marked as initialized.

## Stage 5: Sign In and Set Up MFA

### Step 7: Open the Website

Open this URL in your browser:

[http://localhost/AdoptAPet/public/](http://localhost/AdoptAPet/public/)

If you used a different project folder name, change the URL to match it.

### Step 8: Sign In

Use the Demo account:

| Email : demo.admin@example.invalid
| Password : DemoAdmin!2026

The account username is `demo_superadmin`, but sign-in uses the **email address and password**.

### Step 9: Enroll MFA

1. Follow the on-screen instructions.
2. Add the displayed TOTP account to an authenticator app.
3. Enter the current six-digit code.
4. Save the recovery codes somewhere private.

The Demo email address is a placeholder and cannot receive emails.

## Stage 6: Optional Email Setup

Complete this section only if you want to demonstrate email features, such as account verification or email notifications.

### Step 10: Configure SMTP

1. Open `config/smtp.example.php` to see the required settings.
2. Configure your email provider's SMTP host, port, username, password, and other required settings using the configuration method supported by the project.
3. Keep your SMTP credentials private.
4. Use a real email address for testing. The Demo administrator email cannot receive messages.
5. Test an email feature to confirm that messages can be sent.

SMTP is not required for the seeded Demo administrator to sign in or enroll MFA.

## Stage 7: Optional hCaptcha Setup

Complete this section only if you want to enable hCaptcha.

### Step 11: Configure hCaptcha

1. Register your site at [hCaptcha](https://www.hcaptcha.com/).
2. Obtain your site key and secret key.
3. Open `config/hcaptcha.example.php` to see the required settings.
4. Configure the keys using the method supported by the project.
5. Keep the secret key private.
6. Test the relevant verification flow after enabling hCaptcha.

hCaptcha is disabled by default. The setup checker does not contact hCaptcha or test its verification flow.

## Common Problems

- **Database connection fails:** Check that MySQL is running and that the database name, port, username, password, and `database.local.php` are correct.
- **The checker does not report `READY`:** Read the error, correct the reported problem, and run option 2 again.
- **MFA setup fails:** Check that `config/mfa.local.php` exists and contains a valid key. Never replace a key that may be used by an existing installation.
- **The website or assets do not load:** Check the project URL and local Apache configuration. Do not disable the project's access rules.
- **Email features fail:** Check your SMTP settings and `ADOPTAPET_BASE_URL`. The checker does not send test emails.
- **hCaptcha fails:** Check the site key, secret key, and whether hCaptcha is enabled correctly.

If the database import fails or the database contains unexpected data, stop and preserve it. For a new attempt, use a separate, confirmed-empty database.

## Additional Information

- [Local HTTPS Setup](https-local-setup.md)
- [Image Credits](IMAGE_CREDITS.md)

This guide describes the intended local installation process. A complete installation on a clean second PC, Apache routing and permissions, SMTP delivery, and hCaptcha still need to be tested separately.

**Security reminder:** Keep this Demo private. Never expose the shared Demo credentials or private configuration files to the public internet, and never use the shared Demo credentials in production.