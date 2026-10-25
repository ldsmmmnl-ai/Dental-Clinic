# Dental Clinic Management And Recording System

A web app for running a dental clinic — patients, appointments, billing, dental records, and staff accounts, all in one place. Built with PHP, MySQL, and Bootstrap.

---

## What it does

**Login and security.** Login locks for 5 minutes after 5 wrong tries, checked both by session and by IP address. There's a hidden field on the login form that only bots fill in — if it gets filled, the request just gets dropped silently. Forgot password sends a one-time code by SMS and email before a new password can be set. Sessions are locked down with an 8 hour timeout and a fresh session every time someone logs in. Every form that changes something checks a security token first, so a form can't be faked from another site. API requests are limited per address and need a token. Passwords are scrambled with one-way hashing before they're ever saved, so even with database access nobody can read the original password.

**Patients.** Full profile for each patient — name, birthday, gender, address, blood type, allergies, medical history, emergency contact. Each patient gets a unique code automatically. There's a column in the database meant for archiving a patient without deleting them for good, but there's no button anywhere in the app right now to actually use it, so every patient just shows as active. Patients can be searched by name, code, or phone number, with results split across pages.

**Appointments.** Booked by patient, service, doctor, date, and time. Walk-ins get the next open slot for today automatically. Status moves from pending to confirmed to completed, cancelled, or no-show. Can be filtered by status, date, or doctor. There's a calendar view color-coded per doctor, and an appointment slip that can be printed.

**Treatments and dental records.** Records what happened during a visit — notes, a tooth chart, a prescription, and the service given. Can print the dental record itself, a prescription, or a dental certificate.

**Billing.** Bills are linked back to an appointment. Partial payments are supported, tracking how much is owed against how much has actually been paid. Status is unpaid, partial, or paid. Paying by GCash shows a QR code on screen that updates live as the amount is typed in. The dashboard shows what's still owed overall. Bills can be filtered by status, date, or patient, and a receipt can be printed.

**Analytics, for admins only.** Cards showing total patients, today's appointments, this month's revenue, and pending bills, with the change from last month shown alongside. Charts for revenue, appointments, and patient growth. Can be filtered to the last 7 days, last 30 days, this month, or year to date.

**Reports, for admins only.** A monthly summary of appointments and revenue, a printable daily schedule, and the ability to export to PDF.

**Schedule, for admins only.** Clinic hours can be set per day, appointment slot length can be configured, and specific dates like holidays can be blocked off.

**Users, for admins only.** Two roles exist, admin and staff. Users can be added or edited, and accounts can be turned on or off. Admins can't turn off their own account.

**My account, for anyone logged in.** A profile photo can be uploaded or removed, and it shows up in the sidebar and the top bar. Name, email, and phone number can be updated for your own account.

**Notifications.** A bell icon shows how many alerts haven't been read yet, and they can be marked read one at a time or all at once.

**Audit log, for admins only.** Every important action gets logged — logins, billing changes, changes to patients, appointments, and treatments, and user changes. Can be filtered by keyword or by module, shown 50 at a time.

**Dark mode.** Toggled from the header and remembered for next time. It's applied before the page even finishes loading, so there's no flash of a white screen first.

**Accessibility.** There's a skip-to-content link for people navigating by keyboard, visible outlines when something is focused, respect for a reduced-motion setting, and labels in place for screen readers.

**Speed.** Pages load without a full refresh — only the content area changes, the sidebar and header stay exactly where they are. Files are cached aggressively so repeat visits load fast, and common lookups like the doctor list or service list are kept in memory for the session instead of being re-fetched on every click.

---

## What's in the database

There are tables for users, patients, services, doctors, weekly clinic hours, one-off blocked dates, appointments, dental records, bills, notifications, the audit log, and rate limiting for abuse prevention, plus a table for API login tokens. Two more tables exist but aren't used by anything yet — one was set up for a future stock-tracking feature, the other for future system settings.

---

## Built with

PHP on the backend, MySQL or MariaDB for the database, Bootstrap for the frontend, Caddy as the web server, and it's set up to run in Docker as a container.

---

## Setting it up locally, with Laragon or XAMPP

Copy the project folder into Laragon's www folder, or htdocs if using XAMPP. Start Apache and MySQL. Open phpMyAdmin or HeidiSQL, create a database, and import the cap.sql file — that one file builds every table needed. Copy the example environment file to a new file simply named .env — the defaults already match a normal Laragon or XAMPP setup, so nothing usually needs to change. Then open a browser and go to the local address for the project folder.

If this came from a fresh zip rather than from version control, note that the version history and the environment file won't be included — those need to be set up again separately.

To run it in Docker instead, there are two commands: one to build the image, one to run it with the environment file passed in. After that it's available on the container's local address.

---

## Environment variables, in plain terms

The database connection needs a host, port, database name, username, and password — the defaults already work for a standard local setup. The clinic's name and the app's base address are also configured here.

Login codes (OTP) can go out two ways. The main way is an email service called Resend, which works on cloud hosts like Railway because it uses the normal web port. The backup way is a Gmail account, which is meant for your own computer (Laragon or XAMPP) because many cloud hosts block Gmail. Both are set in the environment file only — no email address or password is typed into any code file. There is also an optional key for sending the code by SMS (Semaphore). If no SMS or email key is set, the code just shows on screen so the flow can still be tested.

The environment file is never saved to version control (it is listed in .gitignore). A starter copy named `.env.example` is included — copy it to a file named `.env` and fill in your own values. Never share the real `.env` file or put it in a zip you send to other people.

---

## Default login

After importing the database file, the username is admin and the password is password. This should be changed before the system sees any real use.

---

## Documentation

There's a set of guides that walk through how the system actually works, meant for anyone — a client, a panel, or a new teammate. One covers getting set up and handing the project off. One covers how the code is organized and how a page actually loads. One covers how the database tables connect to each other. One covers security, checked line by line against the real code rather than just described from memory. Each guide ends with a small exercise, so it's not just reading.

---

## How the project is organized

The login page, forgot password flow, and OTP verification live in one file at the root of the project, alongside the main dashboard, a router that handles fast page navigation, and the logout page. Background JSON requests live in their own folder. Each feature — patients, appointments, treatments, billing, doctors, services, schedule, users, analytics, reports, the audit log, walk-ins, and printable pages — has its own folder under modules, each following roughly the same shape of a list page, an add page, and a view page. Shared code that every page depends on, like the database connection and login checks, lives in one shared folder. Styling, scripts, and images live under assets. The database file that builds everything lives under database. The documentation guides live under docs. And the files needed to run the project in Docker sit at the root alongside everything else.
