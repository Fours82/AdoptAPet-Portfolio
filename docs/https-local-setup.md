# Local HTTPS Setup

This optional guide enables HTTPS for AdoptAPet on one Windows PC. Complete [basic installation](INSTALLATION.md) and confirm that the website works over HTTP first.

## 1. Install mkcert

Install [mkcert](https://github.com/FiloSottile/mkcert#installation) using its official Windows instructions.

Open PowerShell and run:

```powershell
mkcert -install
```

If Windows reports a permission error, reopen PowerShell as Administrator and try again.

## 2. Create a Certificate

Open PowerShell and set your XAMPP installation path. Change `C:\xampp` if necessary.

```powershell
$XamppRoot = 'C:\xampp'
$CertDir = Join-Path $XamppRoot 'apache\conf\adoptapet-tls'

New-Item -ItemType Directory -Force -Path $CertDir

mkcert -cert-file "$CertDir\adoptapet.test.pem" `
       -key-file "$CertDir\adoptapet.test-key.pem" `
       adoptapet.test
```

Keep the certificate and private key on this PC. Do not share the private key.

## 3. Configure the Windows Hosts File

Open Notepad as Administrator and edit:

`C:\Windows\System32\drivers\etc\hosts`

Add this line if it does not already exist:

```text
127.0.0.1 adoptapet.test
```

Save the file, then check the hostname in PowerShell:

```powershell
ping -n 1 adoptapet.test
```

The resolved address should be `127.0.0.1`.

## 4. Configure Apache

Use [the supplied Apache HTTPS template](apache-adoptapet-https.conf). Back up the Apache configuration files before editing them.

1. Open `apache/conf/httpd.conf` and confirm that the required SSL module and virtual-host configuration are enabled. Standard XAMPP installations usually already have these settings.
2. Open `apache/conf/extra/httpd-vhosts.conf`.
3. Keep the existing virtual hosts. If an `adoptapet.test` HTTPS virtual host already exists, update it instead of adding a duplicate.
4. Add the template's HTTPS virtual-host configuration and change `ADOPTAPET_PROJECT_ROOT` to your actual project directory. Its `DocumentRoot` must point to the project's `public` folder.
5. Ensure that Apache listens on port `443` only once.

Do not replace your existing Apache configuration files with the template.

## 5. Check Apache and Open the Website

Set the same XAMPP path used in Step 2, then run:

```powershell
$XamppRoot = 'C:\xampp'
& "$XamppRoot\apache\bin\httpd.exe" -t -f "$XamppRoot\apache\conf\httpd.conf"
```

Continue only if Apache reports:

```text
Syntax OK
```

Restart Apache from the XAMPP Control Panel, then open:

[https://adoptapet.test/](https://adoptapet.test/)

Check that the browser shows a trusted certificate for `adoptapet.test`.

## 6. Troubleshooting and Notes

- **Certificate warning:** Confirm that `mkcert -install` succeeded and that Apache uses the certificate and key generated in Step 2. Restart the browser.
- **Apache will not start:** Run the syntax check again. Check port `443`, duplicate virtual hosts, required modules, and Apache error logs.
- **Wrong page appears:** Check that `ADOPTAPET_PROJECT_ROOT` points to the correct project folder and that `DocumentRoot` ends in `/public`.
- **Hostname does not resolve:** Check the hosts file and confirm that `adoptapet.test` resolves to `127.0.0.1`.

The existing HTTP URL remains available:

`http://localhost/<project-folder>/public/`

Replace `<project-folder>` with your actual project folder name.

This HTTPS setup is local to your PC. Other computers must configure and trust their own local certificates. HSTS is intentionally disabled for local development.

If you configure `ADOPTAPET_BASE_URL` for email links, use the HTTPS URL and ensure the setting is available to both CLI PHP and Apache PHP.