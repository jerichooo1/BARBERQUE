# BARBERQUE: A Web-based Appointment System with Haircut Recommendation Using Augmented Reality for Fade Street Hair Studio

BARBERQUE is a web-based barbershop management and appointment booking system developed using **PHP, MySQL, HTML, CSS, and JavaScript**. 
  It is designed to streamline daily barbershop operations by providing an integrated platform for online appointment booking, barber scheduling, walk-in management, payment processing, 
  customer reviews, and administrative tasks.
  The system also features an **Augmented Reality (AR) hairstyle preview**, allowing customers to explore hairstyle options through an interactive facial tracking experience before visiting the barbershop.

## Project Background

BARBERQUE was developed as our **Capstone Project 1** in collaboration with **Fade Street Hair Studio**, a barbershop located in Montalban (Rodriguez), Rizal, Philippines.

The project was initiated to address the challenges associated with traditional barbershop operations, particularly in managing customer appointments, barber schedules, walk-in transactions, and daily administrative tasks. Manual processes can make it difficult to organize bookings, monitor barber availability, track transactions, and maintain accurate customer records.

To address these challenges, our team developed BARBERQUE, a web-based management and appointment booking system designed to streamline the barbershop's daily operations and improve the overall customer experience.

The system provides an integrated platform where customers can conveniently book appointments, select services and preferred barbers, manage their bookings, and make the required downpayment. It also provides dedicated interfaces for barbers, staff, and administrators to manage appointments, walk-ins, schedules, customer feedback, and other operational activities.

Additionally, the project incorporates an **Augmented Reality (AR) Hairstyle Preview** feature, allowing customers to explore different hairstyles through an interactive facial tracking experience.

Through this project, our team aimed to demonstrate how information technology can be applied to improve business processes, reduce manual workload, and provide a more convenient and organized barbershop management experience.

**Project Type:** Academic Capstone Project 1  
**Industry:** Barbershop / Personal Care Services  
**Client:** Fade Street Hair Studio  
**Location:** Montalban (Rodriguez), Rizal, Philippines

## 🎥 Video Walkthrough
Watch the full prototype walkthrough here: https://youtu.be/4Uy-uTsU3pc

## 📄 Prototype Screenshots
View the full PDF documentation of the prototype screens: [Prototype Screenshots (PDF)](./YOUR_FOLDER_NAME/YOUR_PDF_FILENAME.pdf)


## Features

### Customer Module
- Create an account, log in, and manage personal profiles.
- Browse available barbershop services, add-ons, and barbers.
- Book appointments by selecting services, add-ons, preferred barbers, and available schedules.
- Review booking details and submit the required 15% non-refundable GCash downpayment.
- View appointment status and booking history.
- Cancel or reschedule appointments according to booking policies.
- Rate barbers and submit customer reviews.
- Verify email addresses and recover forgotten passwords.
- Preview hairstyles using the AR hairstyle feature.

### Barber Module
- Access a personalized barber dashboard.
- View assigned appointments and schedules.
- Manage appointment-related workflows.
- Submit leave requests.
- View customer ratings and feedback.

### Staff Module
- Access staff dashboard information.
- Assist with appointment management.
- Handle walk-in customer workflows.
- Monitor relevant booking information.

### Administrator Module
- View dashboard summaries for appointments, customers, barbers, services, walk-ins, and revenue.
- Manage barber and staff accounts.
- Add, update, and manage barbershop services and add-ons.
- Manage appointments and walk-in transactions.
- Monitor customer reviews and ratings.
- Generate and view administrative reports.
- Manage shop settings and operational information.
- Manage hairstyle and AR model content.

### Additional Features
- **Augmented Reality Hairstyle Preview** – Interactive hairstyle overlays using facial landmark detection.
- **3D Hairstyle Models** – GLB-format hairstyle assets for the AR experience.
- **Email Integration** – Email verification and password recovery powered by PHPMailer.
- **Responsive Interface** – Customer-facing pages designed for different screen sizes.

## Technology Stack

| Category | Technologies |
|---|---|
| Backend | PHP |
| Database | MySQL / MariaDB |
| Frontend | HTML5, CSS3, JavaScript |
| AR / Facial Tracking | MediaPipe FaceMesh |
| 3D Models | GLB |
| Email Integration | PHPMailer |
| Local Development | XAMPP |
| Package Management | Node.js and npm |

## Project Structure

```text
BARBERQUE/
│
├── ar/                     # AR hairstyle preview module
├── ar-test/                # AR testing pages and assets
├── database/
│   └── barbershop.sql       # MySQL database export
│
├── hairs/                  # 3D hairstyle models (GLB)
├── images/                 # Website images and uploaded media
├── PHPMailer/              # PHPMailer library
│
├── admin_*.php              # Administrator pages and actions
├── barber_*.php             # Barber dashboard and workflows
├── staff_*.php              # Staff dashboard and workflows
│
├── appointments.php        # Customer appointment management
├── select_booking.php      # Booking selection and scheduling
├── payment.php             # Payment and downpayment processing
├── db.php                  # Database connection
├── index.php               # Website homepage
├── login.php               # Customer login
├── register.php            # Customer registration
├── styles.css              # Main stylesheet
└── package.json            # JavaScript dependencies
```

*Note: The structure above highlights the main project directories and files. Additional PHP pages, scripts, and assets are included in the repository.*

## Requirements

Before installing the project, make sure you have the following:

- PHP 8.x recommended
- MySQL 5.7+ or compatible MariaDB version
- Apache Web Server
- XAMPP or another PHP-compatible local development environment
- Node.js and npm for JavaScript dependencies
- A modern web browser with JavaScript support

## Installation and Setup

### 1. Clone the Repository

Open your terminal or command prompt and run:

```bash
git clone https://github.com/YOUR-USERNAME/BARBERQUE.git
```

Navigate to the project directory:

```bash
cd BARBERQUE
```

Alternatively, download the repository as a ZIP file and extract it into your XAMPP `htdocs` directory.

### 2. Set Up the Database

Open phpMyAdmin:

```text
http://localhost/phpmyadmin
```

Create a database named:

```sql
CREATE DATABASE barbershop;
```

### 3. Import the Database

The project includes a database export located at:

```text
database/barbershop.sql
```

**Using phpMyAdmin:**

1. Open phpMyAdmin.
2. Select the `barbershop` database.
3. Navigate to the Import tab.
4. Choose `database/barbershop.sql`.
5. Click Import or Go.
6. Wait until the database import is completed.

**Using MySQL Command Line:**

You can also import the database using:

```bash
mysql -u root -p barbershop < database/barbershop.sql
```

If your MySQL root account does not have a password, simply press Enter when prompted.

### 4. Configure Database Connection

Open `db.php` and configure the database connection according to your local environment.

Example:

```php
<?php
$host = 'localhost';
$dbname = 'barbershop';
$username = 'root';
$password = '';
```

Update the credentials if your MySQL configuration uses a different username or password.

**Security Reminder:** Never commit production database credentials or sensitive configuration files to a public GitHub repository.

### 5. Install JavaScript Dependencies

If the repository includes a `package.json` file, navigate to the project directory and execute:

```bash
npm install
```

This installs the required JavaScript packages defined in the project configuration.

### 6. Configure Email Functionality

If you intend to use email verification and password recovery, configure the SMTP settings used by the PHPMailer integration.

Make sure your SMTP credentials and application passwords are stored securely and are not exposed in the public repository.

### 7. Run the Application

If you are using XAMPP:

1. Copy the BARBERQUE folder into your XAMPP `htdocs` directory.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Make sure the database has been imported successfully.
4. Open your browser and navigate to:

```text
http://localhost/BARBERQUE/
```

The application should now be accessible through your local development environment.

## Configuration Notes

- The database connection is configured in `db.php`.
- The application uses the `Asia/Manila` timezone and configures the MySQL session timezone to UTC+08:00.
- The booking process calculates a 15% non-refundable downpayment based on the booking total.
- Shop details, operating hours, contact information, and booking policies can be configured through the application settings where supported.
- Verify payment processing and administrative payment verification workflows before using the system for actual transactions.

## Testing

The customer-facing application can be tested through manual browser testing.

If the Playwright test suite is included in the repository, it can also be used for automated browser testing of customer workflows, including:

- Customer login validation
- Invalid login credentials
- Empty login field validation
- Homepage content and navigation
- AR camera permission handling

## Security Considerations

Before deploying the application to a production environment:

- Remove real customer information from publicly accessible database exports.
- Never expose database passwords, SMTP credentials, API keys, or other sensitive information.
- Use a restricted database account instead of default root credentials.
- Enable HTTPS for secure communication.
- Validate and sanitize user inputs.
- Implement appropriate file upload validation and permissions.
- Restrict access to administrator, barber, and staff pages based on user roles.
- Disable or remove unnecessary testing and diagnostic pages.
- Maintain regular database backups.
- Verify payment and appointment management permissions.

## Contributing

Contributions, suggestions, and improvements are welcome.

1. Fork this repository.
2. Create a new feature branch:

```bash
git checkout -b feature/your-feature
```

3. Commit your changes:

```bash
git commit -m "Add your feature"
```

4. Push your branch:

```bash
git push origin feature/your-feature
```

5. Submit a Pull Request.

## License

No license has currently been specified for this project. All rights remain with the copyright holder. Please contact the project owner for permission before redistributing or reusing the source code.

---

**BARBERQUE** – Simplifying barbershop bookings and daily operations through technology.
