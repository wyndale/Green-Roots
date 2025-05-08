# Green Roots

Green Roots is a web application designed to encourage tree planting and promote environmental protection. This app allows users to participate in tree planting activities, earn points, connect with community events, compete with others through rankings, and redeem rewards, all contributing to a greener planet. It is built with a user-friendly interface to make it accessible to everyone, even those who may not be familiar with technology.

## Table of Contents

- [Features](#features)
- [How Features Work](#how-features-work)
  - [User Features](#user-features)
  - [Validator Features](#validator-features)
  - [Admin Features](#admin-features)
- [Folder Structure](#folder-structure)
- [Database Setup](#database-setup)
- [Tools Used](#tools-used)
- [How to Install](#how-to-install)
- [How to Use](#how-to-use)
- [Architecture](#architecture)
- [File Functionalities](#file-functionalities)
  - [User Functionalities](#user-functionalities)
  - [Validator Functionalities](#validator-functionalities)
  - [Admin Functionalities](#admin-functionalities)
- [Security Features](#security-features)

## Features

- **Secure Login and Sign-Up**: Create an account or log in safely. Validator and admin roles require a separate application with the website owner.
- **Tree Planting Records**: Submit details and photos of tree planting efforts for review to ensure authenticity.
- **Designated Planting Site Information**: View specific locations for planting trees in your community.
- **Community Events**: Browse, filter, and join group tree planting events in your region, with QR code tickets for attendance verification and downloadable PDF passes.
- **Rewards**: Earn points for planting trees and redeem them for vouchers or cash via PayPal.
- **Rankings**: Compare your community's tree planting efforts through a leaderboard, fostering competition.
- **Activity Tracker**: Log actions like submissions, event participation, reward redemptions, and feedback submissions to track progress.
- **Feedback**: Submit feedback (bug reports, feature requests, general comments) with ratings and anonymity options, and view their feedback history with admin responses.
- **Account Settings**: Update personal details (username, email, phone, name) securely, with read-only location fields set during registration.
- **Profile**: Upload or remove a profile picture to personalize your account, with support for JPEG, PNG, and GIF formats.
- **Password & Security**: Change your password securely with validation for strength and confirmation.
- **Payment Methods (Prototype)**: Add or remove a PayPal email for reward withdrawals, with validation for email format.
- **Logout**: Securely end your session and return to the landing page.
- **Validator Features**: Allow validators to review tree planting submissions, update statuses, and manage points (note: this feature may contain bugs due to the nearing deadline and limited time for testing).
- **Admin Features**: Provide admins with full access to manage users, events, rankings, and other aspects of the platform (note: this feature is only partially implemented, with other functions not completed due to time constraints).

## How Features Work

Detailed explanations of each feature, written for all audiences, describing the behind-the-scenes mechanics.

### User Features

#### Secure Login and Sign-Up

**What It Does**: Allows users to create or access accounts securely, protecting personal information. Validator and admin roles require direct application to the website owner.

**How It Works**:
- **Login**: Verifies username and password against a secure database with encrypted passwords. Limits login attempts (5 within 5 minutes) and uses CSRF tokens.
- **Sign-Up**: Users provide name, email, password, and location (barangay, city, province, region, country), which are encrypted and stored. Unique usernames/emails are enforced. Validator and admin applications are handled separately.

#### Tree Planting Records

**What It Does**: Enables users to submit proof of tree planting, including photos and details, for verification.

**How It Works**:
- Users submit forms with tree counts (up to 100), photos, and notes. Statuses include “pending,” “approved,” or “rejected.”
- Photos are validated for format (JPG/PNG), size (<10MB), resolution (≥800x600 pixels), and location data. A hash prevents duplicates.
- Validators review submissions, updating points, tree counts, and rankings upon approval.

#### Designated Planting Site Information

**What It Does**: Displays community-specific planting locations to aid planning.

**How It Works**:
- Retrieves planting site coordinates for the user’s barangay from the database, displayed with an Open Street Map link.
- Prompts users to contact admins if no site is assigned, ensuring designated planting areas.

#### Community Events

**What It Does**: Facilitates participation in group tree planting events, allowing users to browse, filter, join, and verify attendance with QR code tickets and downloadable PDF passes.

**How It Works**:
- **Browsing Events**: The “Events” page displays two tabs: “Upcoming Events” (filtered by region, province, city, barangay, or date) and “My Events” (events the user joined). Pagination shows 10 events per page, with details like title, date, location, barangay, capacity, and spots left.
- **Filtering Events**: Users filter upcoming events by date or location (province, city, barangay) within their region, using cascading dropdowns populated via database queries. Filters persist across page navigation.
- **Joining Events**: Users join events with available spots via an AJAX request, preventing duplicate joins. The system checks capacity and generates a unique QR code (SHA-256 hash of user ID, event ID, and timestamp) stored in the `event_participants` table. Activities are logged in the `activities` table with event details.
- **QR Code Tickets**: Upon joining or viewing “My Events,” users access a modal displaying their QR code, username, email, and event details. The QR code is generated using QRCode.js and used for attendance verification at events.
- **PDF Tickets**: Users can download a PDF ticket via jsPDF, including event details, user info, and the QR code. The PDF is styled with Green Roots branding and saved as `Event_[Title]_Ticket.pdf`.
- **Attendance Verification**: Event organizers scan QR codes to confirm attendance, updating the `confirmed_at` timestamp in `event_participants`. Confirmed events appear in the user’s history with a “Confirmed” status.
- **Event Status**: Events are marked as “Upcoming,” “Ongoing,” or “Past” based on the event date compared to the current date, with visual indicators (e.g., colored badges).
- **Database Integration**: Events are stored in the `events` table, with participation tracked in `event_participants`. Activities are logged in `activities`, and history is displayed via `history.php`.

#### Rewards

**What It Does**: Users earn points for tree planting and redeem them for vouchers or cash via PayPal.

**How It Works**:
- **Earning Points**: Awarded for approved submissions or event participation, stored in the user’s account.
- **Redeeming Vouchers**: Users browse vouchers, redeem with sufficient points, and receive a unique code, QR code, and 30-day expiration. Duplicate redemptions are prevented.
- **Cash Withdrawal**: Points convert to cash (1 point = ₱0.50, minimum 100 points) via PayPal, limited to 5 withdrawals per hour. Requires a PayPal email set in Payment Methods (see [Payment Methods](#payment-methods-prototype)).
- **Voucher Management**: Expired vouchers are removed, and users view redeemed vouchers with QR codes and PDF download options.
- **PDF Generation**: Users download an “EcoVoucher Pass” PDF with voucher details and QR code.

#### Rankings

**What It Does**: Displays community and individual rankings based on trees planted.

**How It Works**:
- **Community Rankings**: Aggregates trees planted per barangay, updating the `rankings` table in real-time using SQL `RANK()`.
- **Individual Rankings**: Users are ranked within their barangay, with badges for top positions.
- **Broader Rankings**: Province and region rankings are calculated and displayed in modals with search functionality.
- **Leaderboard Page**: Shows user, barangay, province, and region ranks with real-time updates.

#### Activity Tracker

**What It Does**: Logs user actions (submissions, events, rewards, feedback) for progress tracking.

**How It Works**:
- Actions are logged in the `activities` table with details (e.g., date, type, event title, feedback submission).
- The “History” page displays activities in tabs (Planting, Events, Rewards) with date filters and pagination, showing statuses like “Confirmed” for events or “Submitted” for feedback.

#### Feedback

**What It Does**: Allows users to submit feedback (bug reports, feature requests, general comments) with ratings and anonymity options, and view their feedback history with admin responses.

**How It Works**:
- **Submitting Feedback**: The “Feedback” page has two tabs: “Submit Feedback” and “Feedback History.” Users select a category (bug, feature, general), provide a 1-5 star rating, write comments (up to 1000 characters), and choose anonymity. A character counter updates in real-time, warning at 900+ characters.
- **Validation**: Feedback is validated for category, rating (1-5), and non-empty comments (<1000 characters). Users are limited to one submission every 24 hours, checked via the `feedback` table’s `submitted_at` timestamp.
- **Database Storage**: Valid feedback is stored in the `feedback` table with `user_id`, `category`, `rating`, `comments`, `is_anonymous`, `submitted_at`, `status` (Submitted, Under Review, Resolved), and optional `response`. Feedback submissions are logged in the `activities` table as “Submitted feedback.”
- **Feedback History**: The “Feedback History” tab shows feedback entries with pagination (10 per page), displaying category, rating (as stars), comments, anonymity status, submission date, status, and admin responses (if any). Users can toggle “Show More/Less” to expand/collapse long comments.
- **UI Elements**: Features a responsive form with tooltips (e.g., for rating and anonymity), a modal for success/error messages (e.g., “Feedback submitted!” or “Submit only once every 24 hours”), and status badges (e.g., yellow for Submitted, blue for Under Review, green for Resolved). Includes sidebar, search bar, and profile dropdown.
- **Error Handling**: Handles invalid inputs (e.g., missing comments), database errors, and session-based error messages. Form inputs are preserved on errors for user convenience.

#### Account Settings

**What It Does**: Enables users to update personal details (username, email, phone number, first/last name) securely, with read-only location fields set during registration.

**How It Works**:
- **Updating Details**: The “Account Settings” page allows users to modify username, email, phone number (optional), and first/last name. Location fields (barangay, city, province, region, country) are read-only, set during registration, with tooltips explaining this. Navigation tabs link to Profile, Password & Security, and Payment Methods.
- **Validation**: Inputs are validated for non-empty username, valid email format, optional phone number (matching international formats), and non-empty names. Checks ensure username/email are unique (excluding the user’s own record).
- **Database Updates**: Valid updates are saved to the `users` table, updating `username`, `email`, `phone_number`, `first_name`, and `last_name`. The session’s `username` is updated to reflect changes.
- **UI Elements**: Features a responsive form with field-specific error messages, a success message (e.g., “Account settings updated!”), and a cancel button to reset inputs. Includes sidebar, search bar, profile dropdown, and navigation tabs.
- **Error Handling**: Displays field-specific errors (e.g., “Invalid email format”) and general errors (e.g., “Username already taken”). Form inputs are preserved on errors via session storage. Handles database errors gracefully.
- **Security**: Uses PDO prepared statements to prevent SQL injection and sanitizes inputs (e.g., email via `FILTER_SANITIZE_EMAIL`).

#### Profile

**What It Does**: Allows users to upload a new profile picture or remove the existing one to personalize their account.

**How It Works**:
- **Uploading Profile Picture**: The “Profile” page displays the current profile picture (custom or default) and offers a form to upload a new image (JPEG, PNG, GIF, <20MB). A live preview updates upon file selection using JavaScript’s FileReader API.
- **Validation**: Validates file type (JPEG, PNG, GIF) and size (<20MB). Invalid files trigger session-based error messages (e.g., “Only JPEG, PNG, and GIF files are allowed”).
- **Database Storage**: Valid images are stored as binary data in the `users` table’s `profile_picture` column (blob) using PDO’s `PARAM_LOB`. If no custom picture exists, a default image is fetched from the `assets` table via `default_profile_asset_id` or a static fallback (`default_profile.jpg`).
- **Removing Profile Picture**: Users can remove their custom picture, setting `profile_picture` to NULL and reverting to the default image.
- **UI Elements**: Features a responsive form with a file input, live preview, and buttons for uploading, canceling, or removing the picture. Includes sidebar, search bar, profile dropdown, and navigation tabs (Account Settings, Profile, Password & Security, Payment Methods). Success/error messages (e.g., “Profile picture updated successfully!”) are displayed.
- **Error Handling**: Manages file reading errors, database errors, and session-based messages. Preserves form state on errors.
- **Security**: Uses PDO prepared statements for database updates and validates file types to prevent malicious uploads.

#### Password & Security

**What It Does**: Allows users to change their password securely with validation for strength and confirmation.

**How It Works**:
- **Password Change**: The “Password & Security” page provides a form to input the current password, new password, and confirmation. Navigation tabs link to Account Settings, Profile, and Payment Methods.
- **Validation**: Ensures the current password matches the stored hash, the new password meets strength requirements (minimum 8 characters, at least one uppercase, lowercase, number, and special character), and the new password matches the confirmation. Errors are displayed field-specifically (e.g., “Current password is incorrect”).
- **Database Updates**: On successful validation, the new password is hashed using PHP’s `password_hash()` with the default algorithm (currently bcrypt) and updated in the `users` table using PDO prepared statements.
- **UI Elements**: Features a responsive form with password visibility toggles, a real-time password strength indicator (Weak/Medium/Strong), field-specific error messages, and a success message (e.g., “Password updated successfully!”). Includes sidebar, search bar, profile dropdown, and navigation tabs.
- **Error Handling**: Displays errors for incorrect current password, weak new password, or mismatch between new and confirm passwords. Preserves form inputs on errors via session storage. Handles database errors gracefully.
- **Security**: Uses PDO prepared statements to prevent SQL injection, hashes passwords with `password_hash()`, and verifies current password with `password_verify()`.

#### Payment Methods (Prototype)

**What It Does**: Allows users to add or remove a PayPal email for reward withdrawals (currently a prototype with limited functionality).

**How It Works**:
- **Updating PayPal Email**: The “Payment Methods” page displays the current PayPal email (if set) and offers a form to update it. Users enter a new email, which is required for cash withdrawals in the Rewards feature (see [Rewards](#rewards)).
- **Validation**: Ensures the email is non-empty and valid (using `FILTER_VALIDATE_EMAIL`). Invalid inputs trigger field-specific error messages (e.g., “Please enter a valid email address for PayPal”).
- **Database Updates**: Valid emails are stored in the `users` table’s `paypal_email` column (varchar, nullable) using PDO prepared statements.
- **Removing PayPal Email**: Users can remove the email, setting `paypal_email` to NULL, disabling cash withdrawals until a new email is set.
- **UI Elements**: Features a responsive form with an email input, a display of the current email (with a “Not set” indicator if unset), and buttons for updating, canceling, or removing. Includes sidebar, search bar, profile dropdown, and navigation tabs (Account Settings, Profile, Password & Security, Payment Methods). Success/error messages (e.g., “Payment method updated successfully!”) are displayed.
- **Error Handling**: Manages database errors and session-based error messages. Preserves form inputs on errors via session storage.
- **Security**: Uses PDO prepared statements to prevent SQL injection and sanitizes email inputs.
- **Prototype Note**: This feature is under development, currently supporting only PayPal email management. Future enhancements may include additional payment methods or integration with PayPal’s API.

#### Logout

**What It Does**: Securely ends the user’s session and redirects to the landing page.

**How It Works**:
- **Session Termination**: The “Logout” action clears all session variables (`$_SESSION = []`) and destroys the session using `session_destroy()`.
- **Redirection**: Redirects the user to the landing page (`index.php`) to ensure no access to authenticated pages.
- **UI Elements**: Accessible via the profile dropdown menu on all authenticated pages (e.g., Dashboard, Profile, Payment Methods).
- **Security**: Ensures no residual session data remains, preventing unauthorized access post-logout.

### Validator Features

#### Validator Dashboard

**What It Does**: Provides validators with an overview of their assigned barangay, user statistics, submission statuses, and a snapshot of the most recent submission.

**How It Works**:
- **Authentication and Authorization**: Restricts access to users with the `eco_validator` role, redirecting unauthorized users to `login.php` or `access_denied.php` if they have a different role (e.g., user or admin).
- **Data Fetching**: Retrieves validator details (username, email, profile picture, barangay) from the `users` and `barangays` tables, and stats (user count, pending/approved/flagged submissions) for the assigned barangay.
- **Profile Picture Handling**: Displays the validator’s profile picture, either from the `profile_picture` blob in the `users` table, a default asset from the `assets` table, or a static fallback (`default_profile.jpg`).
- **Statistics Display**: Shows the number of users in the barangay, pending submissions (status: "pending"), approved submissions (status: "approved"), and flagged submissions (flagged: 1).
- **Recent Submission**: Displays the most recent submission in the barangay, including submission ID, user’s first name, trees planted, photo (if available), latitude/longitude, submission date, and status.
- **Navigation**: Provides sidebar links to the dashboard (`validator_dashboard.php`), pending reviews (`pending_reviews.php`), reviewed submissions (`reviewed_submissions.php`), and barangay planting site (`barangay_designated_site.php`).
- **Search Functionality**: Allows searching for pages (e.g., Dashboard, Pending Reviews) via a search bar with autocomplete suggestions, redirecting to the selected page.
- **UI Elements**: Features a responsive layout with a sidebar, header (with search bar and profile dropdown), stat boxes for submissions, and a table for the recent submission. Includes hover effects, tooltips, and mobile responsiveness.
- **Error Handling**: Displays database errors in a styled error message box if queries fail.

#### Pending Reviews

**What It Does**: Allows validators to review pending tree planting submissions, approve or reject them, and optionally flag them for further review.

**How It Works**:
- **Authentication and Authorization**: Accessible only to users with the `eco_validator` role. Unauthorized users are redirected to `login.php` or `access_denied.php` if they have a different role.
- **Fetching Submissions**: Retrieves pending submissions (`status='pending'`) for the validator’s assigned barangay from the `submissions` table, joined with `users` for submitter details (username, email). Supports pagination (11 items per page) and ordering by submission date (newest first).
- **Search Functionality**: Allows searching submissions by username or email, persisting the search query across pages using session storage (`$_SESSION['validator_search']`) and URL parameters. Uses the PRG (Post-Redirect-Get) pattern to handle form submissions.
- **Eco Points Calculation**: Calculates eco points for each submission based on trees planted: base points (50 per tree), a 20% fairness buffer (1.2 multiplier), and a 10% reward multiplier (1.1), rounded to the nearest integer.
- **Submission Review**:
  - Displays submissions in a table with columns: Submission ID, Submitter (username), Submitted At (formatted date), Location (latitude/longitude with OpenStreetMap link), Photo (clickable to enlarge), Trees Planted, Eco Points, Notes, Status, Flag (icon if flagged), and Actions (button to approve/reject).
  - Validators can click an "Actions" button to open a modal showing submission details (ID, trees planted, eco points) with options to approve or reject.
  - **Approve**: Updates the submission status to "approved" in the `submissions` table, adds eco points and trees planted to the user’s record in the `users` table, and disables further actions on the submission in the UI.
  - **Reject**: Opens a second modal to input a rejection reason (required), then updates the submission status to "rejected" with the reason in the `submissions` table.
  - Both actions record the validator’s ID (`validated_by`) and timestamp (`validated_at`) in the `submissions` table.
- **Flagging**: Displays a flag icon if a submission is flagged (`flagged=1`), but flagging functionality is not implemented in this page (handled elsewhere).
- **UI Elements**: Features a responsive table with hover effects, clickable photos to enlarge in a modal, and pagination links. Includes a sidebar with navigation (Dashboard, Pending Reviews, Reviewed Submissions, Barangay Map), a header with a search bar (for page navigation), and a profile dropdown (Account, Logout).
- **Error Handling**: Catches PDO and general exceptions, displaying errors in a styled error message box. Handles empty results with a “No pending submissions available” message.
- **Database Updates**: Uses the `update_submission.php` service via a fetch API call to update submission status and user data within a transaction, ensuring data consistency.

#### Reviewed Submissions

**What It Does**: Displays a history of reviewed tree planting submissions (approved or rejected) for the validator’s assigned barangay, allowing validators to view past decisions and monitor flagged submissions.

**How It Works**:
- **Authentication and Authorization**: Restricts access to users with the `eco_validator` role, redirecting unauthorized users to `login.php` or `access_denied.php` if they have a different role.
- **Data Fetching**: Retrieves approved (`status='approved'`) and rejected (`status='rejected'`) submissions for the validator’s barangay from the `submissions` table, joined with `users` for submitter details (username, email). Supports pagination (10 items per page) and ordering by submission date (newest first).
- **Search and Filter Functionality**:
  - Allows searching submissions by username or email, persisting the query across pages using session storage (`$_SESSION['search_query_reviewed']`) and URL parameters. Uses the PRG pattern for form submissions.
  - Provides a status filter (`all`, `approved`, `rejected`) via a dropdown, updating the page URL with the selected value.
- **Eco Points Calculation**: Calculates eco points for each submission: base points (50 per tree), a 20% fairness buffer (1.2 multiplier), and a 10% reward multiplier (1.1), rounded to the nearest integer.
- **Real-Time Updates**: Implements AJAX polling every 2 seconds to fetch updated submission data from the `fetch_reviewed.php` service, refreshing the table dynamically without page reloads.
- **Display**:
  - Shows submissions in a table with columns: Submission ID, Submitter (username), Submitted At (formatted date), Location (latitude/longitude with OpenStreetMap link), Photo (clickable to enlarge), Trees Planted, Eco Points, Notes, Status, Rejection Reason (if applicable), and Flag (icon if flagged).
  - Photos are displayed as thumbnails, clickable to enlarge in a modal. Missing photos show "No Photo".
  - Location links open in OpenStreetMap in a new tab.
- **Pagination**: Supports navigation through multiple pages with "Previous" and "Next" links, dynamically adjusting based on total items and current page.
- **UI Elements**: Features a responsive table with hover effects, a custom search bar and status filter, a sidebar with navigation (Dashboard, Pending Reviews, Reviewed Submissions, Barangay Map), a header with a page search bar and profile dropdown (Account, Logout), and an image modal for photo enlargement. Includes accessibility focus states and mobile responsiveness.
- **Error Handling**: Displays PDO or general exceptions in a styled error message box. Handles empty results with a “No reviewed submissions available” message.
- **Dependencies**: Relies on the `fetch_reviewed.php` service for real-time data and uses Font Awesome for icons.

#### Barangay Designated Site

**What It Does**: Displays the designated planting site for the validator’s assigned barangay, providing a map view and real-time updates.

**How It Works**:
- **Authentication and Authorization**: Restricts access to users with the `eco_validator` role, redirecting unauthorized users to `login.php` or `access_denied.php` if they have a different role. Includes an additional check to redirect non-validator users to their respective dashboards (e.g., `dashboard.php` for users, `admin_dashboard.php` for admins).
- **Data Fetching**: Retrieves the validator’s barangay ID and details from the `users` and `barangays` tables. Fetches the latest planting site coordinates (latitude, longitude) and update timestamp from the `planting_sites` table, joined with `barangays` for the barangay name, using a function (`getPlantingSite`) that orders by `updated_at` DESC and limits to 1 record.
- **Profile Picture Handling**: Displays the validator’s profile picture, either from the `profile_picture` blob in the `users` table, a default asset from the `assets` table (with MIME type detection for PNG or JPEG), or a static fallback (`default_profile.jpg`).
- **Asset Fetching**: Loads favicon and logo data from the `assets` table (type `favicon` and `logo`) as base64-encoded images, with fallbacks to static files (`favicon.png` and `logo.png`) if no data is found.
- **Display**:
  - Shows a card with details: barangay name, latitude, longitude, and last updated timestamp. If no planting site exists, displays an error message prompting the validator to contact an admin.
  - Includes a link to view the site on OpenStreetMap in a new tab.
  - Embeds an interactive Leaflet map with a custom marker (icon from a CDN) at the planting site coordinates, with a popup displaying the barangay name.
- **Real-Time Updates**: Uses AJAX polling every 5 seconds to fetch updated planting site data from the `fetch_designated_site.php` service, refreshing the card details and map view dynamically. Updates the map marker and view center based on new coordinates.
- **UI Elements**: Features a responsive layout with a sidebar (links to Dashboard, Pending Reviews, Reviewed Submissions, Barangay Map), a header with a search bar (for page navigation) and profile dropdown (Account, Logout), a styled planting site card with animations, and a map container. Includes hover effects, accessibility focus states, and mobile responsiveness (e.g., sidebar moves to bottom, map height adjusts).
- **Error Handling**: Catches PDO exceptions, logging them to the error log and displaying a styled error message. Handles cases where no planting site data is available with a user-friendly message.
- **Dependencies**: Relies on the `fetch_designated_site.php` service for real-time data and uses Leaflet.js for mapping, with a custom icon from a CDN.

#### Barangay Designated Site Service

**What It Does**: Provides real-time planting site data for the `barangay_designated_site.php` page via AJAX requests.

**How It Works**:
- **Authentication and Authorization**: Checks for a valid session with a `user_id`, returning a 403 Forbidden response with a JSON error if unauthorized.
- **Input Handling**: Accepts a `barangay_id` GET parameter; returns a JSON error if missing.
- **Data Fetching**: Queries the `planting_sites` table, joined with `barangays`, to retrieve the latest planting site data (latitude, longitude, updated_at, barangay name) for the specified barangay, ordered by `updated_at` DESC and limited to 1 record.
- **Response**: Returns a JSON object with the planting site data or an error message if no data is found or the query fails.
- **Error Handling**: Catches PDO and general exceptions, returning a 500 Internal Server Error status with a JSON error message including the exception details.
- **Security**: Ensures session validation to prevent unauthorized access to site data.

### Admin Features

**What It Does**: Admins should have full access to the system to manage users, events, rankings, and other aspects of the platform.

**How It Works**:
- **Note**: Admin functionalities are still not fully implemented. Currently, only partial features exist, such as basic user and event management. Full implementation is pending due to time constraints.

## Folder Structure

- `index.php`: Homepage with login/signup links.
- `views/`: Page templates (e.g., `login.php`, `events.php`, `feedback.php`, `account_settings.php`, `profile.php`, `password_security.php`, `payment_methods.php`, `logout.php`).
- `services/`: Backend logic (e.g., `get_locations.php`, `update_submission.php`, `fetch_reviewed.php`, `fetch_designated_site.php`).
- `assets/`: Styles, scripts, images.
- `includes/config.php`: Database connection configuration.
- `database/`: Contains exported SQL files for setting up the database tables.
- `validator/`: Validator-specific pages (e.g., `barangay_designated_site.php`, `pending_reviews.php`, `reviewed_submissions.php`, `validator_dashboard.php`).
- `admin/`: Admin-specific pages (e.g., `admin_dashboard.php`, `manage_planting_sites.php`, `manage_validators.php`, `upload_assets.php`).

## Database Setup

The app uses a MySQL database to store data. Each table serves a specific purpose, and they link together for smooth operation. To set up the database, navigate to the `database` folder where the exported SQL files are located. Import these files into a database named `greenroots_db` using phpMyAdmin or a similar tool to create the necessary tables.

## Tools Used

- **Frontend**: HTML, CSS (Font Awesome), JavaScript.
- **Backend**: PHP with PDO for secure database access.
- **Database**: MySQL.
- **External Services**:
  - Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
  - Chart.js (CDN: `https://cdn.jsdelivr.net/npm/chart.js`).
  - QRCode.js (CDN: `https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js`).
  - jsPDF (CDN: `https://cdnjs.cloudflare.com/ajax/libs/jspdf/3.0.1/jspdf.umd.min.js` or `2.5.1` for events).
  - Leaflet.js (CDN: `https://unpkg.com/leaflet@1.9.4/dist/leaflet.js` and `leaflet.css`).
- **Utilities**: XAMPP, VS Code.

## How to Install

1. **Clone Repository**:
   ```bash
   git clone https://github.com/wyndale/Green-Roots.git
   ```
   Or download and unzip from GitHub.
2. **Set Up Server**: Install XAMPP, start Apache and MySQL.
3. **Create Database**: In phpMyAdmin, create `greenroots_db` and import the SQL files from the `database` folder.
4. **Configure**: Edit `includes/config.php` with database credentials (default: username “root”, no password).
5. **Access**: Open `http://localhost/green-roots/index.php`.

## How to Use

- **Sign Up/Log In**: Register or log in via the homepage.
- **Submit Planting**: Upload tree planting details on “Submit Planting.”
- **Check Planting Site**: View coordinates on “Planting Site.”
- **Join Events**: Browse and join events on “Events,” download QR code tickets.
- **Earn Rewards**: Redeem points on “Rewards” (requires PayPal email in Payment Methods).
- **Check Rankings**: View rankings on “Leaderboard.”
- **Track Activities**: Review actions on “History.”
- **Provide Feedback**: Submit feedback or view history on “Feedback.”
- **Manage Account**: Update details on “Account Settings.”
- **Update Profile**: Upload or remove profile picture on “Profile.”
- **Change Password**: Update your password on “Password & Security.”
- **Set Payment Methods**: Add/remove PayPal email on “Payment Methods” (prototype).
- **Logout**: End session via the profile dropdown.
- **Validator Actions**: Review submissions on the validator dashboard (use with caution due to potential bugs).
- **Admin Actions**: Manage users, events, and rankings via the partial admin dashboard (limited functionality).

## Architecture

The application employs a monolithic procedural programming approach, where PHP backend logic, HTML templates, CSS styles, and JavaScript functionality are tightly integrated within single files (e.g., `events.php`, `feedback.php`, `account_settings.php`). This structure combines server-side processing (PHP with PDO for database interactions) and client-side rendering (HTML, CSS, JavaScript) to deliver a cohesive user experience. Configuration is managed via `includes/config.php`, and backend services (e.g., `get_locations.php`) handle specific data operations.

This represents an initial, simplified structure for prototyping purposes and is not the final architecture. As a prototype, it prioritizes rapid development and testing over modularity. Future iterations may transition to a more robust framework, such as a full MVC architecture, or adopt a modular, API-driven design to enhance scalability and maintainability.

## File Functionalities

### User Functionalities

#### index.php (Landing Page)

**Purpose**: Welcomes users to Green Roots.

**Functionalities**:
- Displays title, “Get Started” button, navigation bar, and feature highlights.
- Uses Font Awesome icons and custom CSS.

**External Dependencies**:
- Font Awesome (CDN).
- CSS (`assets/css/index.css`).

#### login.php (Login Page)

**Purpose**: Authenticates users securely.

**Functionalities**:
- Limits login attempts, uses CSRF tokens, validates credentials with encrypted passwords.
- Redirects to `validate.php` (validators) or `dashboard.php` (users).
- Animated form with placeholders for third-party logins.

**External Dependencies**:
- Font Awesome (CDN).
- Inline CSS.

#### register.php (Registration Page)

**Purpose**: Handles new user sign-ups.

**Functionalities**:
- Validates inputs, checks unique usernames/emails, encrypts passwords.
- Uses AJAX for dynamic location selection (barangay, city, province, region, country).
- Animated form with redirects to `login.php`.

**External Dependencies**:
- Font Awesome (CDN).
- `services/get_locations.php`.

#### dashboard.php (Dashboard Page)

**Purpose**: Provides a personalized overview.

**Functionalities**:
- Checks login status, displays user stats (trees, points, CO2 offset, rank, events) with Chart.js graphs.
- Includes sidebar, search bar, notifications, profile dropdown with links to Account Settings, Profile, Password & Security, Payment Methods, and Logout.

**External Dependencies**:
- Font Awesome, Chart.js (CDN).
- CSS (`assets/css/dashboard.css`).

#### submit.php (Submit Tree Planting Page)

**Purpose**: Manages tree planting submissions.

**Functionalities**:
- Validates submissions (trees: 1-100, photo: JPG/PNG, <10MB, ≥800x600 pixels) with CSRF tokens.
- Checks photo EXIF data, saves to database, logs activities.
- Supports offline submissions with drag-and-drop upload.

**External Dependencies**:
- Font Awesome (CDN).
- Inline CSS.

#### planting_site.php (Designated Planting Site Page)

**Purpose**: Displays planting locations.

**Functionalities**:
- Shows barangay coordinates with Open Street Map link.
- Redirects unauthorized users, handles missing sites.
- Responsive design with sidebar.

**External Dependencies**:
- Font Awesome (CDN).
- Inline CSS.

#### leaderboard.php (Leaderboard Page)

**Purpose**: Displays user and community rankings.

**Functionalities**:
- Updates rankings in real-time using SQL `RANK()`.
- Shows user, barangay, province, region ranks with badges.
- Features modals with search for top rankings.
- Includes sidebar, search bar, profile dropdown.

**External Dependencies**:
- Font Awesome (CDN).
- CSS (`assets/css/leaderboard.css`).

#### rewards.php (Rewards Page)

**Purpose**: Manages point redemption.

**Functionalities**:
- Displays eco points, vouchers, redeemed vouchers, and cash withdrawal options.
- Supports voucher redemption with QR codes, PDF downloads, and cash withdrawals (1 point = ₱0.50, 5/hour limit, requires PayPal email from Payment Methods).
- Uses CSRF tokens, logs activities, removes expired vouchers.

**External Dependencies**:
- Font Awesome, QRCode.js, jsPDF (CDN).
- CSS (`assets/css/rewards.css`).

#### events.php (Events Page)

**Purpose**: Enables users to browse, filter, join, and manage community tree planting events with QR code tickets.

**Functionalities**:
- **Authentication**: Checks login status, redirects to `login.php` if needed, and fetches user data (username, email, barangay, profile picture).
- **Tabs**: Features “Upcoming Events” (all regional events) and “My Events” (user-joined events), toggled via query parameters.
- **Event Browsing**: Displays events with details (title, date, location, barangay, capacity, spots left, status: Upcoming/Ongoing/Past). Pagination shows 10 events per page.
- **Filtering**: Allows filtering by date, province, city, or barangay within the user’s region, using cascading dropdowns populated via database queries. Filters are applied via URL parameters and persist across navigation.
- **Joining Events**: Users join events with available spots via AJAX, preventing duplicates and capacity overflows. A unique QR code (SHA-256 hash) is generated and stored in `event_participants`. Activities are logged in `activities` with event details (title, date, location).
- **QR Code Tickets**: Displays a modal with QR code, user info, and event details upon joining or viewing “My Events.” QRCode.js generates the QR code for attendance verification.
- **PDF Tickets**: Users download a styled PDF ticket via jsPDF, including QR code, user info, and event details, saved as `Event_[Title]_Ticket.pdf`. Handles errors if QR code fails to render.
- **Attendance Verification**: QR codes are scanned at events, updating `confirmed_at` in `event_participants`. Confirmed events show in “My Events” and history.
- **UI Elements**: Includes sidebar, search bar, profile dropdown, notifications, and responsive event cards with status badges (e.g., green for Ongoing). A loading spinner appears during AJAX requests.
- **Error Handling**: Displays errors for database issues, invalid joins (e.g., full capacity), or QR code generation failures. Session messages show success/error notifications (e.g., “Successfully joined!”).

**External Dependencies**:
- Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
- QRCode.js (CDN: `https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js`).
- jsPDF (CDN: `https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jsPDF.umd.min.js`).
- CSS (`assets/css/events.css`).
- JavaScript (`assets/js/jsPDF-3.0.1/dist/jspdf.umd.min.js` for PDF generation).

#### history.php (History Page)

**Purpose**: Displays a log of user activities (planting, events, rewards, feedback) with filtering and pagination.

**Functionalities**:
- **Authentication**: Verifies login status, fetches user data (username, email, profile picture).
- **Tabs**: Offers three tabs: “Planting History” (submission logs), “Event History” (event participation), “Reward History” (redeemed rewards), toggled via query parameters.
- **Activity Display**: Shows activities with details:
  - **Planting**: Trees planted, location, status (Pending/Approved/Rejected), date.
  - **Events**: Event title, location, event date, status (Pending Confirmation/Confirmed), join date.
  - **Rewards**: Reward type (voucher/cash), value, eco points used, date.
  - **Feedback**: Feedback submission with category (bug, feature, general) and date, logged as “Submitted feedback.”
- **Filtering**: Allows date range filtering (start/end date) for all tabs, applied via URL parameters. Filters limit results to the specified period.
- **Pagination**: Displays 10 entries per page, with navigation links for each tab based on total entries.
- **Event Tracking**: Event history includes confirmation status from `event_participants` (`confirmed_at`), showing “Confirmed” or “Pending Confirmation.” Joins are cross-referenced with `events` via `event_title`.
- **UI Elements**: Includes sidebar, search bar, profile dropdown, notifications, and responsive history cards with icons and status badges (e.g., green for Confirmed).
- **Error Handling**: Displays database errors and handles empty results with “No [type] history found” messages.

**External Dependencies**:
- Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
- CSS (`assets/css/history.css`).

#### feedback.php (Feedback Page)

**Purpose**: Enables users to submit feedback and view their feedback history with admin responses.

**Functionalities**:
- **Authentication**: Checks login status, redirects to `login.php` if needed, and fetches user data (username, email, profile picture).
- **Tabs**: Features “Submit Feedback” and “Feedback History,” toggled via query parameters.
- **Feedback Submission**: Users select a category (bug, feature, general), provide a 1-5 star rating, write comments (up to 1000 characters), and opt for anonymity. A real-time character counter warns at 900+ characters.
- **Validation**: Ensures valid category, rating (1-5), non-empty comments (<1000 characters), and one submission per 24 hours (checked via `feedback` table). Invalid inputs trigger session-based error messages with preserved form inputs.
- **Database Storage**: Stores feedback in the `feedback` table with `user_id`, `category`, `rating`, `comments`, `is_anonymous`, `submitted_at`, `status`, and `response` (nullable). Logs submissions in `activities` as “Submitted feedback.”
- **Feedback History**: Displays feedback entries with pagination (10 per page), showing category, rating (as stars), comments, anonymity, submission date, status (Submitted, Under Review, Resolved), and admin responses. Users toggle “Show More/Less” for long comments.
- **UI Elements**: Includes a responsive form with tooltips (e.g., for anonymity), a modal for success/error messages, status badges (e.g., yellow for Submitted), sidebar, search bar, and profile dropdown. Feedback cards animate on hover.
- **Error Handling**: Manages database errors, invalid inputs, and session-based messages. Handles empty history with “No feedback history available.”
- **External Assets**: Loads favicon and logo from the `assets` table or defaults to static files.

**External Dependencies**:
- Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
- CSS (inline in `feedback.php`).

#### account_settings.php (Account Settings Page)

**Purpose**: Allows users to update personal account details securely.

**Functionalities**:
- **Authentication**: Verifies login status, redirects to `login.php` if needed, and fetches user data (username, email, phone, names, profile picture, barangay).
- **Account Updates**: Users modify username, email, phone number (optional), first name, and last name. Location fields (barangay, city, province, region, country) are read-only, set during registration.
- **Validation**: Checks for non-empty username, valid email format, valid phone number (if provided, using regex for international formats), and non-empty names. Ensures username/email are unique (excluding the user’s own record).
- **Database Updates**: Updates the `users` table with validated inputs, using PDO prepared statements. Updates session `username` to reflect changes.
- **UI Elements**: Features a responsive form with field-specific error messages, success/error notifications (e.g., “Account settings updated!”), a cancel button to reset inputs, and navigation tabs (Account Settings, Profile, Password & Security, Payment Methods). Includes sidebar, search bar, and profile dropdown. Read-only fields have tooltips explaining their immutability.
- **Error Handling**: Displays field-specific errors (e.g., “Invalid email format”) and general errors (e.g., “Username already taken”) via session storage. Preserves form inputs on errors. Handles database errors gracefully.
- **External Assets**: Loads favicon, logo, and profile picture from the `assets` table or defaults to static files.

**External Dependencies**:
- Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
- CSS (inline in `account_settings.php`).

#### profile.php (Profile Page)

**Purpose**: Enables users to manage their profile picture.

**Functionalities**:
- **Authentication**: Verifies login status, redirects to `login.php` if needed, and fetches user data (username, email, profile picture).
- **Profile Picture Management**: Displays the current profile picture (custom, default from `assets`, or static `default_profile.jpg`). Users upload new images (JPEG, PNG, GIF, <20MB) or remove the custom picture.
- **Validation**: Checks file type (JPEG, PNG, GIF) and size (<20MB). Errors (e.g., “File size must be less than 20MB”) are stored in the session.
- **Database Updates**: Stores uploaded images as binary data in `users.profile_picture` (blob) using PDO’s `PARAM_LOB`. Removal sets `profile_picture` to NULL, reverting to the default.
- **UI Elements**: Includes a responsive form with a file input, live preview (via FileReader API), and buttons for uploading, canceling, or removing. Features sidebar, search bar, profile dropdown, and navigation tabs (Account Settings, Profile, Password & Security, Payment Methods). Success/error messages are displayed.
- **Error Handling**: Handles file reading errors, database errors, and session-based messages. Preserves form state on errors.
- **External Assets**: Loads favicon, logo, and profile picture from the `assets` table or defaults to static files.
- **Security**: Uses PDO prepared statements and validates file types to prevent malicious uploads.

**External Dependencies**:
- Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
- CSS (inline in `profile.php`).

#### password_security.php (Password & Security Page)

**Purpose**: Enables users to change their password securely.

**Functionalities**:
- **Authentication**: Verifies login status, redirects to `login.php` if needed, and fetches user data (username, email, profile picture).
- **Password Change**: Users input their current password, new password, and confirmation. The form includes navigation tabs to Account Settings, Profile, and Payment Methods.
- **Validation**: Verifies the current password against the stored hash, ensures the new password meets strength requirements (minimum 8 characters, at least one uppercase, lowercase, number, and special character), and checks that the new password matches the confirmation. Errors are displayed field-specifically.
- **Database Updates**: Updates the `users` table with the new hashed password using PDO prepared statements.
- **UI Elements**: Features a responsive form with password visibility toggles, a real-time password strength indicator, field-specific error messages, success notifications, sidebar, search bar, profile dropdown, and navigation tabs.
- **Error Handling**: Manages incorrect current passwords, weak passwords, mismatched confirmations, and database errors. Preserves form inputs on errors via session storage.
- **External Assets**: Loads favicon, logo, and profile picture from the `assets` table or defaults to static files.
- **Security**: Uses PDO prepared statements, hashes passwords with `password_hash()`, and verifies passwords with `password_verify()`.

**External Dependencies**:
- Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
- CSS (inline in `password_security.php`).

#### payment_methods.php (Payment Methods Page, Prototype)

**Purpose**: Manages PayPal email for reward withdrawals (prototype).

**Functionalities**:
- **Authentication**: Verifies login status, redirects to `login.php` if needed, and fetches user data (username, email, profile picture, paypal_email).
- **PayPal Email Management**: Displays the current PayPal email (or “Not set”). Users update the email or remove it.
- **Validation**: Ensures the email is non-empty and valid (using `FILTER_VALIDATE_EMAIL`). Errors (e.g., “Please enter a valid email address for PayPal”) are stored in the session.
- **Database Updates**: Stores valid emails in `users.paypal_email` (varchar, nullable) using PDO prepared statements. Removal sets `paypal_email` to NULL.
- **UI Elements**: Includes a responsive form with an email input, current email display, and buttons for updating, canceling, or removing. Features sidebar, search bar, profile dropdown, and navigation tabs (Account Settings, Profile, Password & Security, Payment Methods). Success/error messages are displayed.
- **Error Handling**: Manages database errors and session-based error messages. Preserves form inputs on errors.
- **External Assets**: Loads favicon, logo, and profile picture from the `assets` table or defaults to static files.
- **Security**: Uses PDO prepared statements and sanitizes email inputs.
- **Prototype Note**: Limited to PayPal email management; future versions may add more payment options or PayPal API integration.

**External Dependencies**:
- Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
- CSS (inline in `payment_methods.php`).

#### logout.php (Logout Page)

**Purpose**: Terminates the user’s session and redirects to the landing page.

**Functionalities**:
- **Session Termination**: Clears all session variables (`$_SESSION = []`) and destroys the session using `session_destroy()`.
- **Redirection**: Redirects to `index.php`, ensuring no access to authenticated pages.
- **Security**: Prevents residual session data, protecting against unauthorized access.
- **UI Integration**: Accessible via the profile dropdown on all authenticated pages (e.g., Dashboard, Profile, Payment Methods).

**External Dependencies**:
- None (pure PHP).

### Validator Functionalities

#### validator_dashboard.php (Validator Dashboard Page)

**Purpose**: Provides an overview for validators, showing their assigned barangay, user stats, submission statuses, and recent submissions.

**Functionalities**:
- **Authentication and Authorization**: Restricts access to users with the `eco_validator` role, redirecting unauthorized users to `login.php` or `access_denied.php` if they have a different role (e.g., user or admin).
- **Data Fetching**: Retrieves validator details (username, email, profile picture, barangay) from the `users` and `barangays` tables, and stats (user count, pending/approved/flagged submissions) for the assigned barangay.
- **Profile Picture Handling**: Displays the validator’s profile picture, either from the `profile_picture` blob in the `users` table, a default asset from the `assets` table, or a static fallback (`default_profile.jpg`).
- **Statistics Display**: Shows the number of users in the barangay, pending submissions (status: "pending"), approved submissions (status: "approved"), and flagged submissions (flagged: 1).
- **Recent Submission**: Displays the most recent submission in the barangay, including submission ID, user’s first name, trees planted, photo (if available), latitude/longitude, submission date, and status.
- **Navigation**: Provides sidebar links to the dashboard (`validator_dashboard.php`), pending reviews (`pending_reviews.php`), reviewed submissions (`reviewed_submissions.php`), and barangay planting site (`barangay_designated_site.php`).
- **Search Functionality**: Allows searching for pages (e.g., Dashboard, Pending Reviews) via a search bar with autocomplete suggestions, redirecting to the selected page.
- **UI Elements**: Features a responsive layout with a sidebar, header (with search bar and profile dropdown), stat boxes for submissions, and a table for the recent submission. Includes hover effects, tooltips, and mobile responsiveness.
- **Error Handling**: Displays database errors in a styled error message box if queries fail.

**External Dependencies**:
- Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
- Chart.js (CDN: `https://cdn.jsdelivr.net/npm/chart.js`).
- Inline CSS.

#### pending_reviews.php (Pending Reviews Page)

**Purpose**: Allows validators to review and manage pending tree planting submissions.

**Functionalities**:
- **Authentication and Authorization**: Restricts access to users with the `eco_validator` role, redirecting unauthorized users to `login.php` or `access_denied.php` if they have a different role.
- **Data Fetching**: Retrieves pending submissions (`status='pending'`) for the validator’s barangay from the `submissions` table, joined with `users` for submitter details (username, email). Supports pagination (11 items per page) and ordering by submission date (newest first).
- **Search Functionality**: Enables searching submissions by username or email, persisting the query across pages using session storage (`$_SESSION['validator_search']`) and URL parameters. Uses the PRG pattern for form submissions.
- **Eco Points Calculation**: Calculates eco points per submission: 50 points per tree, with a 20% fairness buffer (1.2 multiplier) and a 10% reward multiplier (1.1), rounded to the nearest integer.
- **Submission Review**:
  - Displays submissions in a table with columns: Submission ID, Submitter (username), Submitted At (formatted date), Location (latitude/longitude with OpenStreetMap link), Photo (clickable to enlarge), Trees Planted, Eco Points, Notes, Status, Flag (icon if flagged), and Actions.
  - Validators can approve or reject submissions via an "Actions" button:
    - **Approve**: Updates the submission status to "approved" in the `submissions` table, adds eco points and trees planted to the user’s record in the `users` table, and disables further actions on the submission in the UI.
    - **Reject**: Requires a rejection reason via a modal, then updates the submission status to "rejected" with the reason in the `submissions` table.
    - Both actions record the validator’s ID (`validated_by`) and timestamp (`validated_at`) in the `submissions` table.
  - Uses the `update_submission.php` service to handle updates via a fetch API call, ensuring transactional integrity.
- **UI Elements**: Features a responsive table with hover effects, pagination, and modals for actions (approve/reject) and image enlargement. Includes a sidebar (Dashboard, Pending Reviews, Reviewed Submissions, Barangay Map), a header search bar (for page navigation), and a profile dropdown (Account, Logout).
- **Error Handling**: Catches PDO and general exceptions, displaying errors in a styled box. Shows “No pending submissions available” if no results are found. Handles fetch API errors with modal feedback.
- **Accessibility**: Includes focus states for buttons, inputs, and links to improve accessibility.

**External Dependencies**:
- Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
- Inline CSS and JavaScript.

#### reviewed_submissions.php (Reviewed Submissions Page)

**Purpose**: Displays a history of reviewed tree planting submissions for the validator’s barangay.

**Functionalities**:
- **Authentication and Authorization**: Restricts access to users with the `eco_validator` role, redirecting unauthorized users to `login.php` or `access_denied.php` if they have a different role.
- **Data Fetching**: Retrieves approved (`status='approved'`) and rejected (`status='rejected'`) submissions for the validator’s barangay from the `submissions` table, joined with `users` for submitter details (username, email). Supports pagination (10 items per page) and ordering by submission date (newest first).
- **Search and Filter Functionality**:
  - Enables searching submissions by username or email, persisting the query across pages using session storage (`$_SESSION['search_query_reviewed']`) and URL parameters. Uses the PRG pattern for form submissions.
  - Provides a status filter (`all`, `approved`, `rejected`) via a dropdown, updating the page URL with the selected value.
- **Eco Points Calculation**: Calculates eco points per submission: 50 points per tree, with a 20% fairness buffer (1.2 multiplier) and a 10% reward multiplier (1.1), rounded to the nearest integer.
- **Real-Time Updates**: Uses AJAX polling every 2 seconds to fetch updated submission data from the `fetch_reviewed.php` service, refreshing the table dynamically without page reloads.
- **Display**:
  - Shows submissions in a table with columns: Submission ID, Submitter (username), Submitted At (formatted date), Location (latitude/longitude with OpenStreetMap link), Photo (clickable to enlarge), Trees Planted, Eco Points, Notes, Status, Rejection Reason (if applicable), and Flag (icon if flagged).
  - Photos are displayed as thumbnails, clickable to enlarge in a modal. Missing photos show "No Photo".
  - Location links open in OpenStreetMap in a new tab.
- **Pagination**: Supports navigation through multiple pages with "Previous" and "Next" links, dynamically adjusting based on total items and current page.
- **UI Elements**: Features a responsive table with hover effects, a custom search bar and status filter, a sidebar (Dashboard, Pending Reviews, Reviewed Submissions, Barangay Map), a header search bar (for page navigation), and a profile dropdown (Account, Logout). Includes an image modal for photo enlargement, accessibility focus states, and mobile responsiveness.
- **Error Handling**: Catches PDO and general exceptions, displaying errors in a styled box. Shows “No reviewed submissions available” if no results are found.
- **Dependencies**: Relies on the `fetch_reviewed.php` service for real-time data updates.

**External Dependencies**:
- Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
- Inline CSS and JavaScript.

#### barangay_designated_site.php (Barangay Designated Site Page)

**Purpose**: Displays the designated planting site for the validator’s barangay.

**Functionalities**:
- **Authentication and Authorization**: Restricts access to users with the `eco_validator` role, redirecting unauthorized users to `login.php` or `access_denied.php` if they have a different role. Includes an additional check to redirect non-validator users to their respective dashboards (e.g., `dashboard.php` for users, `admin_dashboard.php` for admins).
- **Data Fetching**: Retrieves the validator’s barangay ID and details from the `users` and `barangays` tables. Fetches the latest planting site coordinates (latitude, longitude) and update timestamp from the `planting_sites` table, joined with `barangays` for the barangay name, using a function (`getPlantingSite`) that orders by `updated_at` DESC and limits to 1 record.
- **Profile Picture Handling**: Displays the validator’s profile picture, either from the `profile_picture` blob in the `users` table, a default asset from the `assets` table (with MIME type detection for PNG or JPEG), or a static fallback (`default_profile.jpg`).
- **Asset Fetching**: Loads favicon and logo data from the `assets` table (type `favicon` and `logo`) as base64-encoded images, with fallbacks to static files (`favicon.png` and `logo.png`) if no data is found.
- **Display**:
  - Shows a card with details: barangay name, latitude, longitude, and last updated timestamp. If no planting site exists, displays an error message prompting the validator to contact an admin.
  - Includes a link to view the site on OpenStreetMap in a new tab.
  - Embeds an interactive Leaflet map with a custom marker (icon from a CDN) at the planting site coordinates, with a popup displaying the barangay name.
- **Real-Time Updates**: Uses AJAX polling every 5 seconds to fetch updated planting site data from the `fetch_designated_site.php` service, refreshing the card details and map view dynamically. Updates the map marker and view center based on new coordinates.
- **UI Elements**: Features a responsive layout with a sidebar (links to Dashboard, Pending Reviews, Reviewed Submissions, Barangay Map), a header with a search bar (for page navigation) and profile dropdown (Account, Logout), a styled planting site card with animations, and a map container. Includes hover effects, accessibility focus states, and mobile responsiveness (e.g., sidebar moves to bottom, map height adjusts).
- **Error Handling**: Catches PDO exceptions, logging them to the error log and displaying a styled error message. Handles cases where no planting site data is available with a user-friendly message.
- **Dependencies**: Relies on the `fetch_designated_site.php` service for real-time data and uses Leaflet.js for mapping, with a custom icon from a CDN.

**External Dependencies**:
- Font Awesome (CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`).
- Leaflet.js (CDN: `https://unpkg.com/leaflet@1.9.4/dist/leaflet.js` and `leaflet.css`).
- Custom marker icon (CDN: `https://cdn-icons-png.flaticon.com/512/684/684908.png`).
- Inline CSS and JavaScript.

#### services/fetch_designated_site.php (Fetch Designated Site Service)

**Purpose**: Provides real-time planting site data for the `barangay_designated_site.php` page via AJAX requests.

**Functionalities**:
- **Authentication and Authorization**: Checks for a valid session with a `user_id`, returning a 403 Forbidden response with a JSON error if unauthorized.
- **Input Handling**: Accepts a `barangay_id` GET parameter; returns a JSON error if missing.
- **Data Fetching**: Queries the `planting_sites` table, joined with `barangays`, to retrieve the latest planting site data (latitude, longitude, updated_at, barangay name) for the specified barangay, ordered by `updated_at` DESC and limited to 1 record.
- **Response**: Returns a JSON object with the planting site data or an error message if no data is found or the query fails.
- **Error Handling**: Catches PDO and general exceptions, returning a 500 Internal Server Error status with a JSON error message including the exception details.
- **Security**: Ensures session validation to prevent unauthorized access to site data.

**External Dependencies**:
- None (pure PHP with PDO).

#### services/update_submission.php (Update Submission Service)

**Purpose**: Handles updates to submission statuses and user data for validator actions.

**Functionalities**:
- **Input Handling**: Accepts JSON input via POST, expecting `submission_id`, `status` ("approved" or "rejected"), `validated_by`, `validated_at`, and optional fields (`user_id`, `eco_points`, `trees_planted`, `rejection_reason`).
- **Validation**: Ensures valid input and status values, returning an error if invalid.
- **Database Updates**:
  - Updates the `submissions` table with the new status, validator ID, validation timestamp, and rejection reason (if applicable).
  - For approved submissions, updates the `users` table by adding `eco_points` and `trees_planted` to the user’s record.
- **Transaction Management**: Uses PDO transactions to ensure data consistency, rolling back on failure.
- **Response**: Returns a JSON response indicating success or failure with an error message.
- **Error Handling**: Catches PDO and general exceptions, returning error details in the response.

**External Dependencies**:
- None (pure PHP with PDO).

#### services/fetch_reviewed.php (Fetch Reviewed Submissions Service)

**Purpose**: Provides real-time data for reviewed submissions to support AJAX updates in `reviewed_submissions.php`.

**Functionalities**:
- **Authentication and Authorization**: Restricts access to users with the `eco_validator` role, redirecting unauthorized users to `login.php` or `access_denied.php` if they have a different role.
- **Input Handling**: Accepts GET parameters for `barangay_id`, `status` ("all", "approved", "rejected"), and `search` (username or email).
- **Data Fetching**: Retrieves approved and rejected submissions for the specified barangay from the `submissions` table, joined with `users` for submitter details. Filters by status and search query if provided, ordering by submission date (newest first).
- **Eco Points Calculation**: Calculates eco points for each submission: 50 points per tree, with a 20% fairness buffer (1.2 multiplier) and a 10% reward multiplier (1.1), rounded to the nearest integer.
- **Data Formatting**: Encodes photo data as base64 for transmission if present.
- **Response**: Returns a JSON response with the submission data or an error message if the query fails.
- **Error Handling**: Catches PDO and general exceptions, returning a 500 status code with error details in the response.

**External Dependencies**:
- None (pure PHP with PDO).

### Admin Functionalities

#### admin_dashboard.php (Admin Dashboard Page)

**Purpose**: Intended to provide admins with full access to manage the system.

**Functionalities**:
- **Note**: Admin functionalities are not fully implemented. Currently, only partial features exist for user and event management. Full implementation is pending due to time constraints.

#### manage_planting_sites.php (Manage Planting Sites Page)

**Purpose**: Intended to allow admins to manage designated planting sites.

**Functionalities**:
- **Note**: Admin functionalities are not fully implemented. This feature is pending completion.

#### manage_validators.php (Manage Validators Page)

**Purpose**: Intended to allow admins to manage validator roles and assignments.

**Functionalities**:
- **Note**: Admin functionalities are not fully implemented. This feature is pending completion.

#### upload_assets.php (Upload Assets Page)

**Purpose**: Intended to allow admins to upload assets like logos and icons.

**Functionalities**:
- **Note**: Admin functionalities are not fully implemented. This feature is pending completion.

## Security Features

Green Roots implements several security measures to protect user data and ensure safe interactions:

- **Password Security**: Passwords are hashed using PHP’s `password_hash()` with the default algorithm (currently bcrypt). Password verification uses `password_verify()` to ensure secure authentication. The “Password & Security” feature enforces strong passwords (minimum 8 characters, at least one uppercase, lowercase, number, and special character).
- **CSRF Protection**: CSRF tokens are implemented in forms (e.g., login, submission, rewards) to prevent cross-site request forgery attacks.
- **XSS Protection**: Currently, XSS protection is limited. Inputs are sanitized where possible (e.g., email with `FILTER_SANITIZE_EMAIL`), but comprehensive XSS mitigation (e.g., escaping user inputs in HTML output) is planned for future iterations.
- **SQL Injection Prevention**: All database interactions use PDO prepared statements to prevent SQL injection attacks.
- **File Upload Security**: Profile picture uploads are validated for file type (JPEG, PNG, GIF) and size (<20MB) to prevent malicious uploads.
- **Session Security**: The logout feature destroys sessions completely, preventing unauthorized access. Login attempts are limited (5 within 5 minutes) to mitigate brute-force attacks.