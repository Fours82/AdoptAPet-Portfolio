# AdoptAPet

AdoptAPet is a web-based animal rescue and adoption platform developed as a Final Year Project using PHP and MariaDB.

The platform connects users with adoptable pets and provides tools for reporting stray animals, community interaction, and administration.

## Features

- Pet browsing and adoption applications
- Stray animal reporting and management
- Simulated donations
- Community posts and news
- Notifications and live chat
- Administrator management and analytics
- Role-based access control
- Multi-factor authentication (MFA)

## Requirements

- Windows with XAMPP
- PHP 8.1 or later
- MariaDB / MySQL
- Composer, if dependencies need to be installed
- Node.js, only for JavaScript tests

## Installation

For a new local installation, follow the [Installation Guide](docs/INSTALLATION.md).

The installation guide covers:

- XAMPP and project setup
- Database creation and configuration
- MFA setup and installation checks
- Demo administrator sign-in
- Optional email and hCaptcha configuration

**Demo security:** The public SQL includes a shared demonstration administrator account. Keep the installation local and never use the Demo credentials in production.

## Configuration

Configuration files are located in `config/`.

Depending on the features you use, you may need to configure:

- **Database:** Required for application data access
- **Administrator MFA:** Requires an installation-specific key
- **Email (SMTP):** Required for email delivery
- **hCaptcha:** Optional; disabled by default

Use the example configuration files as references. Keep passwords, secret keys, and private configuration files out of public repositories.

## Running Tests

The project does not provide a single Composer or npm test command. Run individual tests from the project root.

Example commands:

```powershell
php tests/first-super-admin-bootstrap.test.php
php tests/installation-check.test.php
node tests/security-ux.test.js
```

JavaScript tests require Node.js. Some tests use static source checks or in-memory data and do not connect to a live database. Passing these tests does not replace testing the application in a browser or performing a clean installation.

## Project Structure

| Path | Description |
|---|---|
| `public/` | Application entry point and public assets |
| `config/` | Application and local configuration |
| `tests/` | Automated regression tests |
| `docs/` | Installation, HTTPS, and other documentation |
| `tools/` | Installation checks and administrator utilities |
| `setup-helper.bat` | Windows helper for MFA key generation and installation checks |

Image sources and attribution are documented in [Image Credits](docs/IMAGE_CREDITS.md).

## Notes

- Donations use simulated payment transactions.
- Live chat uses periodic HTTP polling.
- Email features require valid SMTP settings.
- Maps require internet access for Leaflet and OpenStreetMap resources.
- Local HTTPS requires additional configuration. See the [HTTPS Setup Guide](docs/https-local-setup.md).