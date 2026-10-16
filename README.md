# SmartBill POS – Sales & Inventory Management System

SmartBill POS is a web-based Point of Sale (POS) and inventory management system developed using PHP, MySQL/MariaDB, HTML, CSS, JavaScript, Bootstrap, and XAMPP. It helps manage products, customers, sales transactions, invoices, and sales reports through a simple and user-friendly dashboard.

## Features

### 1. Admin Authentication
- Secure admin login and logout.
- Session-based access control for protected pages.
- Password verification using PHP password hashing.

### 2. Dashboard
- Centralized navigation to the main application modules.
- Easy access to product, customer, POS, invoice, and sales report pages.

### 3. Product Management
- Add new products.
- View product details.
- Edit existing products.
- Delete products.
- Manage product prices, stock quantities, and product images.
- Store product image paths for display in the application.

### 4. Customer Management
- Add and manage customer records.
- View customer information.
- Select customers during sales transactions.

### 5. Point of Sale (POS)
- Select products for a sale.
- Enter product quantities.
- Calculate sale totals.
- Apply discounts.
- Select a customer.
- Choose a payment method.
- Generate sales invoices and record transactions.

### 6. Invoice Management
- Store invoice details in the database.
- Store individual invoice items.
- View recorded invoices and their details.
- Maintain sales transaction records.

### 7. Sales Report
- View sales information through a dedicated Sales Report page.
- Review recorded sales data to help monitor business transactions.

## Technologies Used

| Technology | Purpose |
|---|---|
| PHP | Backend development and application logic |
| MySQL / MariaDB | Database management |
| PDO | Database connectivity |
| HTML5 | Page structure |
| CSS3 | Styling and responsive layout |
| JavaScript | Client-side interactions |
| Bootstrap | Responsive user interface components |
| XAMPP | Local development environment |
| phpMyAdmin | Database administration |
| Git and GitHub | Version control and source code hosting |

## Project Structure

```text
brainbean_task/
│
├── assets/
│   └── css/
│       └── style.css
│
├── config/
│   └── database.php
│
├── includes/
│   └── sidebar.php
│
├── uploads/
│   └── products/
│
├── auth.php
├── index.php
├── login.php
├── logout.php
├── products.php
├── customers.php
├── pos.php
├── invoices.php
├── sales_report.php
├── README.md
└── brainbean_db.sql
```

*Note: The structure above is representative. Include only the files and folders that actually exist in your repository. Keep the database export in your submission if you intend to share it.*

## Requirements

- Windows, Linux, or macOS
- XAMPP or another compatible PHP environment
- PHP with PDO MySQL support
- MySQL or MariaDB
- A modern web browser

## Installation and Setup

### Step 1: Clone the Repository

Open a terminal and run:

```bash
git clone https://github.com/VishalBharwad/SmartBill-POS.git
```

Move the project folder into your XAMPP `htdocs` directory. For a local Windows XAMPP installation, the project path should be:

```text
C:\xampp\htdocs\brainbean_task
```

If the cloned repository has a different folder name, rename it to `brainbean_task` or use its actual folder name in the URL.

### Step 2: Start XAMPP

Open the XAMPP Control Panel and start:

- Apache
- MySQL

If your MariaDB/MySQL server uses a custom port, such as `3307`, use that port in the database configuration.

### Step 3: Create the Database

1. Open phpMyAdmin at `http://localhost/phpmyadmin/`.
2. Create a database named `brainbean_db` if it does not already exist.
3. Select the database.
4. Open the **Import** tab.
5. Import the project's `brainbean_db.sql` file.

If phpMyAdmin is configured for a different database port, connect using the port configured in your XAMPP installation.

### Step 4: Configure the Database Connection

Open `config/database.php` and configure the connection settings for your local environment.

Example using PDO:

```php
<?php

$host = '127.0.0.1';
$port = '3307';
$dbname = 'brainbean_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log($e->getMessage());
    exit('Database connection failed. Please check your configuration.');
}
```

**Important:** Update the port, username, and password to match your environment. This is an example configuration; retain any additional connection logic required by your existing application.

### Step 5: Configure Product Uploads

Ensure the `uploads/products/` directory exists and is writable by the web server. Product image files referenced by the database must also be present in the project directory.

### Step 6: Run the Application

Open the following URL in your browser:

```text
http://localhost/brainbean_task/
```

If the application uses a separate login page, open:

```text
http://localhost/brainbean_task/login.php
```

## Admin Login

Use the following credentials only if they match the administrator account configured in your imported database:

| Field | Value |
|---|---|
| Username | `admin` |
| Password | `Admin@123` |

For security, change the default password before deploying the application anywhere accessible to other users. Do not publish real production credentials.

## Database Tables

The database export includes the following main tables:

| Table | Purpose |
|---|---|
| `admins` | Administrator login details |
| `products` | Product information, pricing, stock, and image paths |
| `customers` | Customer records |
| `invoices` | Invoice and sales transaction details |
| `invoice_items` | Products and quantities associated with each invoice |

## Security Considerations

- Use password hashing and `password_verify()` for administrator authentication.
- Protect restricted pages with session-based authentication.
- Use PDO prepared statements for database queries involving user input.
- Validate and sanitize incoming form data.
- Validate uploaded image types and sizes.
- Do not commit database passwords, private credentials, or production secrets to GitHub.
- Use a separate local configuration file for credentials and provide a safe example configuration in the repository.

## Future Enhancements

Potential improvements include:

- Low-stock notifications.
- Product category management.
- Invoice printing and PDF export.
- Date-range filters and additional sales analytics.
- Multiple user roles and permissions.
- Barcode scanning.
- Improved reporting and dashboard statistics.

## Author

**Vishal Bharwad**

GitHub: [VishalBharwad](https://github.com/VishalBharwad)

## Project Purpose

SmartBill POS was developed as a practical web application project to demonstrate PHP development, relational database design, CRUD operations, session-based authentication, sales processing, invoice management, and reporting.

